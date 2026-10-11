<?php
/**
 * Turns a normalised form submission into a Meta event, or into nothing, a hold or an
 * anonymous blocked report.
 *
 * Built beside the WooCommerce hooks rather than inside them: it calls the same free helpers
 * for identity, consent, automated traffic, cookies and attribution, and assembles the same
 * payload shape, without touching PixelFlow_WooCommerce_Cart_Hooks::post_event().
 *
 * @package PixelFlow
 */

// Prevent direct access
if ( ! defined('ABSPATH')) {
    exit;
}

/**
 * Form event dispatcher.
 */
class PixelFlow_Form_Dispatcher
{
    /** Event API origin. */
    private const API_URL = 'https://api.pixelflow.so';

    /** Repeat window for one form and one visitor. */
    private const DEDUPE_WINDOW = 120;

    /** Options row name prefix of a claimed repeat window. */
    private const DEDUPE_PREFIX = 'pf_form_dedupe_';

    /** @var array<string, true> Dedupe keys claimed in this request */
    private static array $claimed_in_request = [];

    /**
     * Entry point for every adapter and for the `pixelflow_track_form` action.
     *
     * Gates run in this order: tracking on, form enabled and present, excluded role, credentials,
     * private IP, consent and automated traffic, repeats, then the two site filters; only after
     * them is the event sent, held or reported.
     *
     * @param mixed  $raw_submission Normalised submission
     * @param string $hook           Hook that delivered it, for the debug log
     * @return string sent|held|blocked|skipped|failed
     */
    public static function dispatch($raw_submission, string $hook = 'pixelflow_track_form'): string
    {
        $submission = pixelflow_sanitize_form_submission($raw_submission);
        if ($submission === null) {
            return 'skipped';
        }

        $general  = self::general_options();
        $form_key = pixelflow_form_key($submission['source'], $submission['form_id']);
        $log      = [
            'hook'     => $hook,
            'form_key' => $form_key,
        ];

        if (empty($general['enabled']) || empty($general['forms_enabled'])) {
            return 'skipped';
        }

        $record = pixelflow_get_form_record($form_key);
        $config = pixelflow_form_effective_config($record, $submission['form_title'], $submission['fields']);
        $log['event'] = $config['event'];

        if ( ! $config['enabled']) {
            return self::finish('skipped', 'FORM IS SWITCHED OFF', $log);
        }

        if ($record !== [] && ! pixelflow_form_exists($submission['source'], $submission['form_id'])) {
            return self::finish('skipped', 'FORM IS MISSING FROM THE SITE', $log);
        }

        // Before consent resolution, so an excluded role is never held either.
        if (pixelflow_current_user_has_excluded_role($general)) {
            return self::finish('skipped', 'USER ROLE IS EXCLUDED FROM TRACKING', $log);
        }

        $credentials = self::credentials();
        if ($credentials === null) {
            return self::finish('skipped', 'SITE ID OR API KEY IS MISSING', $log);
        }

        $ip = pixelflow_get_client_ip_address();
        $log['client_ip'] = self::mask_ip($ip);
        if (pixelflow_is_private_ip($ip)) {
            return self::finish('skipped', 'CLIENT IP IS PRIVATE', $log);
        }

        $identifiers = pixelflow_form_build_customer_data($submission['fields'], $config['map']);
        $log['identifiers'] = $identifiers['present'];

        $payload = self::build_payload($credentials['site_id'], $submission, $config, $identifiers['customer']);

        $ua      = pixelflow_get_client_user_agent();
        $blocked = pixelflow_resolve_blocked_event_reason(
            null,
            null,
            pixelflow_resolve_bot_detail($ua, pixelflow_request_is_speculative_prefetch(), $config['event'])
        );

        $dedupe_key = self::claim_dedupe($form_key);
        if ($dedupe_key === null) {
            return self::finish('skipped', 'REPEATED SUBMISSION INSIDE THE REPEAT WINDOW', $log);
        }

        $outcome = self::act($payload, $submission, $blocked, $credentials, $ua, $log);

        if ( ! self::settled($outcome, $blocked)) {
            self::release_dedupe($dedupe_key);
        }

        return $outcome;
    }

    /**
     * Runs the site filters, then sends, holds or reports.
     *
     * @param array      $payload     Assembled payload
     * @param array      $submission  Normalised submission
     * @param array|null $blocked     Reason row, or null to send
     * @param array      $credentials site_id and api_key
     * @param string     $ua          Client user agent
     * @param array      $log         Debug-log context
     * @return string
     */
    private static function act(array $payload, array $submission, ?array $blocked, array $credentials, string $ua, array $log): string
    {
        /**
         * Suppresses the event for a single submission.
         *
         * Runs once, on the submitting request, before the send-or-hold decision; a held
         * submission is not filtered again when it is sent after a grant.
         *
         * @param bool  $send       Whether to go on
         * @param array $submission Normalised submission (raw values, never stored)
         * @param array $payload    Assembled payload
         */
        if (apply_filters('pixelflow_should_send_form_event', true, $submission, $payload) === false) {
            return self::finish('skipped', 'SUPPRESSED BY pixelflow_should_send_form_event', $log, $payload);
        }

        $payload = self::apply_event_filter($payload, $submission);
        $log['event'] = $payload['eventData']['eventName'];
        $event_name   = (string) $payload['eventData']['eventName'];

        if ($blocked !== null && ($blocked['reason'] ?? '') === 'no_decision') {
            $recipe = pixelflow_held_form_recipe_from_payload($payload);
            $token  = $recipe !== null ? pixelflow_held_form_token_for_hold() : null;
            if ($token !== null) {
                pixelflow_enqueue_held_form_event($token, $recipe);

                return self::finish('held', 'EVENT SENDING HELD UNTIL CONSENT IS GRANTED OR THE VISIT ENDS', $log, $payload);
            }
        }

        if ($blocked !== null) {
            self::post_blocked($credentials, $event_name, $blocked, $log);

            return self::finish(
                'blocked',
                'EVENT SENDING SKIPPED (' . ($blocked['reason'] ?? 'unknown')
                    . (isset($blocked['detail']) ? '; ' . $blocked['detail'] : '') . ')',
                $log,
                $payload
            );
        }

        return self::post_event($payload, $credentials, $ua, $log);
    }

    /**
     * The event payload in the shape WooCommerce events use.
     *
     * @param string $site_id     Site external id
     * @param array  $submission  Normalised submission
     * @param array  $config      Effective configuration
     * @param array  $customer    Hashed identifiers
     * @return array
     */
    private static function build_payload(string $site_id, array $submission, array $config, array $customer): array
    {
        $additional = ['contentName' => $submission['form_title']];
        if ($config['value'] !== null) {
            $additional['value'] = $config['value'];
        }

        $payload = [
            'siteId'    => $site_id,
            'eventData' => [
                'event_id'       => uniqid('', true),
                'eventName'      => $config['event'],
                'eventTime'      => time(),
                'actionSource'   => 'website',
                'siteURL'        => pixelflow_get_site_url(),
                'additionalData' => $additional,
            ],
        ];

        $utm = pixelflow_get_utm_params_from_cookie();
        if ( ! empty($utm)) {
            $payload['eventData']['utm_params'] = $utm;
        }
        pixelflow_append_cookie_params($payload);
        pixelflow_append_attribution_from_cookie($payload);

        $context = [
            'source'   => $submission['source'],
            'form_id'  => $submission['form_id'],
            'form_key' => pixelflow_form_key($submission['source'], $submission['form_id']),
        ];
        /** This filter is documented in includes/woo/hooks/class-woocommerce-hooks.php */
        $external_id = apply_filters(
            'pixelflow_external_id',
            pixelflow_resolve_external_id($site_id),
            $context
        );
        if (is_string($external_id) && $external_id !== '') {
            $customer['external_id'] = $external_id;
        }
        if ($customer !== []) {
            $payload['eventData']['customerData'] = $customer;
        }

        pixelflow_append_consent_to_payload($payload);

        return $payload;
    }

    /**
     * Applies `pixelflow_form_event` to the event name, the value and the customer data.
     *
     * @param array $payload    Assembled payload
     * @param array $submission Normalised submission
     * @return array
     */
    private static function apply_event_filter(array $payload, array $submission): array
    {
        $additional = $payload['eventData']['additionalData'];
        $event      = [
            'eventName'    => $payload['eventData']['eventName'],
            'value'        => $additional['value'] ?? null,
            'customerData' => $payload['eventData']['customerData'] ?? [],
        ];

        /**
         * Changes the event name, the static value or the customer data before the event is
         * sent or held. The name is not checked against the standard catalogue: a site's own
         * code is trusted with it.
         *
         * @param array $event      eventName, value (float|null) and customerData (hashed)
         * @param array $submission Normalised submission (raw values, never stored)
         */
        $filtered = apply_filters('pixelflow_form_event', $event, $submission);
        if ( ! is_array($filtered)) {
            return $payload;
        }

        if (isset($filtered['eventName']) && is_string($filtered['eventName']) && trim($filtered['eventName']) !== '') {
            $payload['eventData']['eventName'] = trim($filtered['eventName']);
        }

        if (array_key_exists('value', $filtered)) {
            if (is_numeric($filtered['value'])) {
                $additional['value'] = (float) $filtered['value'];
            } else {
                unset($additional['value']);
            }
            $payload['eventData']['additionalData'] = $additional;
        }

        if (isset($filtered['customerData']) && is_array($filtered['customerData'])) {
            $customer = [];
            foreach ($filtered['customerData'] as $key => $value) {
                if (is_string($key) && is_scalar($value) && (string) $value !== '') {
                    $customer[$key] = (string) $value;
                }
            }
            if ($customer === []) {
                unset($payload['eventData']['customerData']);
            } else {
                $payload['eventData']['customerData'] = $customer;
            }
        }

        return $payload;
    }

    /**
     * POSTs /event with the sending request's IP, user agent and location cookie.
     *
     * Location fills only the keys the form did not provide; it is read here, on the request
     * that sends, so a held submission flushed after a grant takes it from that request.
     *
     * @param array  $payload     Payload
     * @param array  $credentials site_id and api_key
     * @param string $ua          Client user agent
     * @param array  $log         Debug-log context
     * @return string sent|skipped|failed
     */
    private static function post_event(array $payload, array $credentials, string $ua, array $log): string
    {
        $ip = pixelflow_get_client_ip_address();
        if (pixelflow_is_private_ip($ip)) {
            return self::finish('skipped', 'CLIENT IP IS PRIVATE', $log, $payload);
        }

        if ( ! isset($payload['eventData']['customerData']) || ! is_array($payload['eventData']['customerData'])) {
            $payload['eventData']['customerData'] = [];
        }
        $from_cookie = pixelflow_append_location_from_cookie($payload['eventData']['customerData']);
        if ($from_cookie !== []) {
            $log['identifiers'] = array_values(array_unique(array_merge((array) ($log['identifiers'] ?? []), $from_cookie)));
        }
        if ($ua !== '') {
            $payload['eventData']['customerData']['client_user_agent'] = $ua;
        }
        $payload['eventData']['customerData']['client_ip_address'] = $ip;

        $response = wp_remote_post(
            self::API_URL . '/event',
            [
                'method'      => 'POST',
                'timeout'     => self::timeout($credentials['site_id']),
                'blocking'    => false,
                'sslverify'   => true,
                'redirection' => 0,
                'headers'     => [
                    'Content-Type' => 'application/json',
                    'api-key'      => $credentials['api_key'],
                ],
                'body'        => wp_json_encode($payload),
                'data_format' => 'body',
            ]
        );

        if (is_wp_error($response)) {
            return self::finish('failed', 'TRANSPORT ERROR: ' . $response->get_error_message(), $log, $payload);
        }

        return self::finish('sent', 'SENT', $log, $payload, self::response_summary($response));
    }

    /**
     * POSTs one anonymous blocked-events row.
     *
     * @param array  $credentials site_id and api_key
     * @param string $event_name  Event name
     * @param array  $row         Reason row
     * @param array  $log         Debug-log context
     * @return void
     */
    private static function post_blocked(array $credentials, string $event_name, array $row, array $log): void
    {
        $payload = pixelflow_build_blocked_events_payload($credentials['site_id'], $event_name, $row);
        if ($payload === null) {
            return;
        }

        $response = pixelflow_post_blocked_events(self::API_URL, $credentials['api_key'], $payload);

        // Logged as its own entry, as the WooCommerce path logs its beacons, so a reader of the
        // log can tell a report that left the site from an event that was merely refused.
        if (isset($payload['client_ip_address'])) {
            $payload['client_ip_address'] = self::mask_ip((string) $payload['client_ip_address']);
        }
        self::write_log_entry([
            'hook'     => 'FORM BLOCKED_EVENTS ' . $event_name,
            'form'     => $log['form_key'] ?? '',
            'payload'  => $payload,
            'response' => is_wp_error($response) ? ['wp_error' => $response->get_error_message()] : self::response_summary($response),
        ]);
    }

    /**
     * The transport's answer as the WooCommerce debug log records it.
     *
     * @param mixed $response wp_remote_post() result
     * @return mixed
     */
    private static function response_summary($response)
    {
        if (is_array($response)) {
            return [
                'code'    => $response['response']['code'] ?? '',
                'message' => $response['response']['message'] ?? '',
            ];
        }

        return $response;
    }

    /**
     * Sends, reports or keeps a visitor's held form events according to the current cookies.
     *
     * A grant sends each recipe with its original time and stored hashes, taking browser and
     * click identifiers and attribution from this request, since the pixels often write their
     * cookies only after the grant. The site filters are not run again.
     *
     * @return void
     */
    public static function resolve_held(): void
    {
        $token = pixelflow_held_form_token();
        if ($token === null) {
            return;
        }

        $queue = pixelflow_get_held_form_events($token);
        if ($queue === []) {
            pixelflow_sync_held_form_events_cookie(null);
            return;
        }

        // Without credentials nothing can be delivered, and every disposition empties the queue.
        $credentials = self::credentials();
        if ($credentials === null) {
            return;
        }

        $disposition = pixelflow_held_events_disposition();
        if ($disposition === 'keep') {
            return;
        }

        pixelflow_clear_held_form_events($token);
        $log = ['hook' => 'held form flush'];

        foreach ($queue as $recipe) {
            $event_name = (string) $recipe['eventName'];
            if ($disposition === 'send') {
                self::post_event(
                    self::rebuild_held_payload($credentials['site_id'], $recipe),
                    $credentials,
                    pixelflow_get_client_user_agent(),
                    $log + ['event' => $event_name, 'client_ip' => self::mask_ip(pixelflow_get_client_ip_address())]
                );
                continue;
            }

            $row = pixelflow_blocked_event_row_with_source($disposition === 'deny' ? 'denied' : 'no_decision', null);
            if ($disposition === 'deny') {
                $denied = pixelflow_resolve_blocked_event_reason();
                if ($denied !== null && ($denied['reason'] ?? '') === 'denied') {
                    $row = $denied;
                }
            }
            self::post_blocked($credentials, $event_name, $row, $log);
            self::finish('blocked', 'HELD EVENT REPORTED (' . $row['reason'] . ')', $log + ['event' => $event_name]);
        }
    }

    /**
     * A full payload from a held recipe, with no currency.
     *
     * @param string $site_id Site external id
     * @param array  $recipe  Held recipe
     * @return array
     */
    private static function rebuild_held_payload(string $site_id, array $recipe): array
    {
        $additional = [];
        if (isset($recipe['contentName']) && (string) $recipe['contentName'] !== '') {
            $additional['contentName'] = (string) $recipe['contentName'];
        }
        if (isset($recipe['value']) && is_numeric($recipe['value'])) {
            $additional['value'] = (float) $recipe['value'];
        }

        $payload = [
            'siteId'    => $site_id,
            'eventData' => [
                'event_id'       => (string) $recipe['event_id'],
                'eventName'      => (string) $recipe['eventName'],
                'eventTime'      => isset($recipe['eventTime']) ? (int) $recipe['eventTime'] : time(),
                'actionSource'   => 'website',
                'siteURL'        => pixelflow_get_site_url(),
                'additionalData' => $additional,
            ],
        ];

        $utm = pixelflow_get_utm_params_from_cookie();
        if ( ! empty($utm)) {
            $payload['eventData']['utm_params'] = $utm;
        }
        pixelflow_append_cookie_params($payload);
        pixelflow_append_attribution_from_cookie($payload);

        $customer = isset($recipe['customerData']) && is_array($recipe['customerData'])
            ? pixelflow_sanitize_held_customer_data($recipe['customerData'])
            : [];
        if ($customer !== []) {
            $payload['eventData']['customerData'] = $customer;
        }
        pixelflow_append_consent_to_payload($payload);

        return $payload;
    }

    /**
     * Claims the repeat window for this form and visitor, or reports it already taken.
     *
     * Claimed before the send and released when the outcome settled nothing, as the WooCommerce
     * dedupe is. The claim is one `INSERT IGNORE` of an options row: `option_name` carries a
     * unique index, so of two concurrent requests of one double click exactly one inserts it.
     * A read followed by a write, as a transient would be, lets both pass.
     *
     * @param string $form_key Form key
     * @return string|null The claimed key, or null when the window is closed
     */
    private static function claim_dedupe(string $form_key): ?string
    {
        global $wpdb;

        // The visitor id exists only after consent, so before it the client IP stands in. The
        // hold token is deliberately not used: the first held submission has none yet, and a
        // double click whose second request already carried one would get a second window.
        $visitor_id = pixelflow_form_visitor_id();
        $who        = $visitor_id !== null ? 'v:' . $visitor_id : 'ip:' . pixelflow_get_client_ip_address();
        $key        = self::DEDUPE_PREFIX . md5($form_key . '|' . $who);

        if (isset(self::$claimed_in_request[$key])) {
            return null;
        }

        // Closed windows are deleted before the claim, which is also the only cleanup the rows
        // get, so an expired window of this visitor never blocks the insert.
        $now = time();
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- an atomic claim needs the raw insert, no cache applies
        $wpdb->query($wpdb->prepare(
            "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s AND option_value < %d",
            $wpdb->esc_like(self::DEDUPE_PREFIX) . '%',
            $now - self::DEDUPE_WINDOW
        ));
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- an atomic claim needs the raw insert, no cache applies
        $inserted = $wpdb->query($wpdb->prepare(
            "INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'no')",
            $key,
            (string) $now
        ));

        // 0 rows: another request holds the window. A failed query (false) lets the submission
        // through, as a transient that could not be written did.
        if ($inserted === 0) {
            return null;
        }

        self::$claimed_in_request[$key] = true;

        return $key;
    }

    /**
     * Reopens the repeat window for the visitor's next submission.
     *
     * @param string $key Claimed key
     * @return void
     */
    private static function release_dedupe(string $key): void
    {
        global $wpdb;

        unset(self::$claimed_in_request[$key]);
        $wpdb->delete($wpdb->options, ['option_name' => $key]); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- the row claim_dedupe() inserted
    }

    /**
     * Whether an outcome settles the submission for this visitor, as send_settled_the_event()
     * decides for WooCommerce: sent and held do, blocked does unless the request was automated.
     *
     * @param string     $outcome Outcome
     * @param array|null $blocked Reason row
     * @return bool
     */
    private static function settled(string $outcome, ?array $blocked): bool
    {
        if ($outcome === 'sent' || $outcome === 'held') {
            return true;
        }

        return $outcome === 'blocked' && $blocked !== null && ($blocked['reason'] ?? '') !== 'bot';
    }

    /**
     * Writes the debug-log entry and returns the outcome.
     *
     * The entry names the identifiers that were present; the payload it carries holds only
     * hashes, and its client IP is masked. No raw field value reaches it.
     *
     * @param string     $outcome Outcome
     * @param string     $reason  What happened
     * @param array      $log     Context: hook, form_key, event, identifiers, client_ip
     * @param array|null $payload  Payload, when one was assembled
     * @param mixed      $response Transport answer for a sent event; the reason otherwise
     * @return string
     */
    private static function finish(string $outcome, string $reason, array $log, ?array $payload = null, $response = null): string
    {
        if ($payload !== null && isset($payload['eventData']['customerData']['client_ip_address'])) {
            $payload['eventData']['customerData']['client_ip_address'] = self::mask_ip(
                (string) $payload['eventData']['customerData']['client_ip_address']
            );
        }

        $entry = [
            'hook'        => 'FORM ' . ($log['hook'] ?? ''),
            'form'        => $log['form_key'] ?? '',
            'event'       => $log['event'] ?? '',
            'outcome'     => $outcome,
            'reason'      => $reason,
            'identifiers' => $log['identifiers'] ?? [],
            'client_ip'   => $log['client_ip'] ?? '',
        ];
        if ($payload !== null) {
            $entry['payload'] = $payload;
        }
        $entry['response'] = $response ?? $reason;

        self::write_log_entry($entry);

        return $outcome;
    }

    /**
     * Appends one entry to the shared debug log while form debug logging is on.
     *
     * @param array $entry Entry without time and version
     * @return void
     */
    private static function write_log_entry(array $entry): void
    {
        $general = self::general_options();
        if (empty($general['forms_debug_enabled'])) {
            return;
        }

        $log_file = pixelflow_get_debug_log_path();
        if ($log_file === '') {
            return;
        }

        $entry = [
            'time'    => gmdate('Y-m-d H:i:s'),
            'version' => defined('PIXELFLOW_VERSION') ? PIXELFLOW_VERSION : '',
        ] + $entry;

        pixelflow_write_debug_log_entry($log_file, wp_json_encode($entry, JSON_PRETTY_PRINT) . "\n---\n");
    }

    /**
     * Masks the final octet exactly as the WooCommerce debug log does.
     *
     * @param string $ip Client IP
     * @return string
     */
    private static function mask_ip(string $ip): string
    {
        return $ip !== '' ? substr($ip, 0, -4) . '****' : '';
    }

    /**
     * @return array<string, mixed>
     */
    private static function general_options(): array
    {
        $options = get_option('pixelflow_general_options', []);

        return is_array($options) ? $options : [];
    }

    /**
     * @return array{site_id: string, api_key: string}|null Null when either is missing
     */
    private static function credentials(): ?array
    {
        $params  = get_option('pixelflow_script_params', []);
        $site_id = is_array($params) && isset($params['siteExternalId']) ? trim((string) $params['siteExternalId']) : '';
        $api_key = is_array($params) && isset($params['apiKey']) ? trim((string) $params['apiKey']) : '';

        return $site_id !== '' && $api_key !== '' ? ['site_id' => $site_id, 'api_key' => $api_key] : null;
    }

    /**
     * @param string $site_id Site external id
     * @return int
     */
    private static function timeout(string $site_id): int
    {
        $timeout = (int) apply_filters('pixelflow_request_timeout', 5, $site_id);

        return $timeout > 0 ? $timeout : 5;
    }

    /**
     * Test seam: forgets the in-request dedupe claims.
     *
     * @return void
     */
    public static function reset_request_state(): void
    {
        self::$claimed_in_request = [];
    }
}
