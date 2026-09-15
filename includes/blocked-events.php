<?php
/**
 * Anonymous blocked-events beacon for WooCommerce skips (hold, deny, bot).
 *
 * @package PixelFlow
 */

// Prevent direct access
if ( ! defined('ABSPATH')) {
    exit;
}

/** Reasons the plugin may POST to /blocked-events. GPC is script-only. */
const PIXELFLOW_BLOCKED_EVENT_REASONS = [
    'denied',
    'no_decision',
    'bot',
];

/**
 * Consent sources the blocked-events ingest allow-list accepts.
 * Excludes consent_mode_disabled: a Disabled site still sends /event, never this beacon.
 */
const PIXELFLOW_BLOCKED_EVENT_CONSENT_SOURCES = [
    'gcm',
    'cookieyes',
    'cookiebot',
    'complianz',
    'squarespace',
    'onetrust',
    'api',
    'gpc',
    'cache',
];

/**
 * Returns the first bot-pattern substring that matched, for anonymous telemetry detail.
 *
 * @param string             $user_agent Client user agent (never stored on the row)
 * @param array<int, string> $exempt     Patterns to skip, so an agent matching both an exempt
 *                                       and a listed signature still reports the listed one
 * @return string|null Matched pattern, or null when the agent is not a bot
 */
function pixelflow_get_bot_detail_pattern(string $user_agent, array $exempt = []): ?string
{
    if ( ! defined('PIXELFLOW_BOT_PATTERNS')) {
        return null;
    }

    $bot_patterns = apply_filters('pixelflow_useragent_bot_patterns', PIXELFLOW_BOT_PATTERNS);
    if ( ! is_array($bot_patterns)) {
        return null;
    }

    $lower_ua = strtolower($user_agent);
    foreach ($bot_patterns as $pattern) {
        $pattern = (string) $pattern;
        if ($pattern === '' || in_array($pattern, $exempt, true)) {
            continue;
        }
        if (strpos($lower_ua, $pattern) !== false) {
            return $pattern;
        }
    }

    return null;
}

/**
 * Reports whether the current request declares itself as speculative prefetch or prerender.
 *
 * Chrome sends `Sec-Purpose: prefetch` (optionally `;prerender`); older browsers and some
 * crawlers send the legacy `Purpose: prefetch`. No human has acted on such a request.
 *
 * @return bool
 */
function pixelflow_request_is_speculative_prefetch(): bool
{
    // Safari's legacy header says `preview` rather than `prefetch`, so matching only the modern
    // values would leave X-Purpose read but never matched.
    $headers = [
        'HTTP_SEC_PURPOSE' => ['prefetch', 'prerender'],
        'HTTP_PURPOSE'     => ['prefetch', 'prerender'],
        'HTTP_X_PURPOSE'   => ['prefetch', 'prerender', 'preview'],
    ];

    foreach ($headers as $key => $markers) {
        if ( ! isset($_SERVER[$key]) || ! is_string($_SERVER[$key])) {
            continue;
        }

        $value = strtolower(sanitize_text_field(wp_unslash($_SERVER[$key])));
        foreach ($markers as $marker) {
            if (strpos($value, $marker) !== false) {
                return true;
            }
        }
    }

    return false;
}

/**
 * Decides the cause reported for an automated-client suppression from the request's own
 * evidence: a matched user-agent signature, else a prefetch header.
 *
 * A rule supplied by the calling event path is deliberately NOT resolved here. Those rules infer
 * automation from the *absence* of something, and an absence can have an innocent explanation
 * that only the consent state knows about — so they are ranked after the consent checks, in
 * pixelflow_resolve_blocked_event_reason().
 *
 * Three rules can fire on the same request — a crawler following an add-to-cart link matches the
 * signature list and the cookieless rule at once — so the cause is decided here rather than by
 * whichever branch happens to run first, which would tie the backend's (reason, detail) breakdown
 * to call order. Most specific wins: a matched signature names an actual client, a prefetch header
 * names a browser behaviour, and the caller-supplied rule is an inference from absence.
 *
 * The prefetch signal arrives as a resolved boolean rather than being read from $_SERVER here, so
 * the buyer-trust gate stays at the one call site that knows whether the request is the buyer's.
 *
 * @param string $user_agent  Agent of whoever the event is about
 * @param bool   $is_prefetch Whether the request declared itself as speculative prefetch
 * @param string $event_name  Catalog event name, so a Purchase can spare the generic client
 *                            libraries; every other signature and the prefetch rule still apply
 * @return string|null The cause, or null when the request's own evidence says nothing
 */
function pixelflow_resolve_bot_detail(string $user_agent, bool $is_prefetch = false, string $event_name = ''): ?string
{
    // The one place the Purchase exemption lives: an order in the database is evidence a human
    // paid, and a suppressed Purchase is closed permanently, so the three generic HTTP client
    // libraries a store may integrate with do not decide that event.
    $exempt = $event_name === 'Purchase' && defined('PIXELFLOW_PURCHASE_EXEMPT_BOT_PATTERNS')
        ? PIXELFLOW_PURCHASE_EXEMPT_BOT_PATTERNS
        : [];

    $pattern = pixelflow_get_bot_detail_pattern($user_agent, $exempt);
    if ($pattern !== null && $pattern !== '') {
        return $pattern;
    }

    return $is_prefetch ? 'prefetch_header' : null;
}

/**
 * Maps a Woo skip to the script's blocked-event reason. Bot wins so a crawler
 * with an unanswered banner is counted as bot, not no_decision.
 *
 * @param string|null $consent_cookie_raw Saved `_pf_consent` from order meta
 * @param string|null $no_decision_raw    Saved `_pf_no_consent_decision` from order meta
 * @param string|null $bot_detail         Cause from the request's own evidence (user agent or
 *                                        prefetch header), or null when it says nothing
 * @param string|null $source_cookie_raw  Saved `_pf_consent_source` from order meta
 * @param bool        $allow_live         False for an order-scoped event in a request that is not the buyer's
 * @param string|null $inferred_bot_rule  Rule the calling path inferred from an absence — ranked
 *                                        below a consent hold or a decline, because a pending or
 *                                        declined banner explains that absence innocently
 * @return array{reason: string, detail?: string, consentSource?: string}|null
 */
function pixelflow_resolve_blocked_event_reason(?string $consent_cookie_raw = null, ?string $no_decision_raw = null, ?string $bot_detail = null, ?string $source_cookie_raw = null, bool $allow_live = true, ?string $inferred_bot_rule = null): ?array
{
    if ($bot_detail !== null && $bot_detail !== '') {
        return [
            'reason' => 'bot',
            'detail' => $bot_detail,
        ];
    }

    if (pixelflow_has_no_consent_decision_hold($no_decision_raw, $allow_live)) {
        return pixelflow_blocked_event_row_with_source('no_decision', $consent_cookie_raw, $source_cookie_raw);
    }

    $consent = pixelflow_resolve_event_consent_block($consent_cookie_raw, $allow_live);
    if ($consent !== null && $consent['state'] === 'denied') {
        $row = [ 'reason' => 'denied' ];
        $source = pixelflow_sanitize_blocked_consent_source(
            isset($consent['source']) && is_string($consent['source']) ? $consent['source'] : ''
        );
        if ($source !== null) {
            $row['consentSource'] = $source;
        }

        return $row;
    }

    // Last: a rule the caller inferred from an absence. A crawler running no JavaScript carries
    // no cookies at all — including the consent ones — so it still lands here and is still
    // filtered. A shopper who has not answered the banner yet carries the hold cookie and was
    // already returned above, as no_decision, which is what lets the event be held and replayed
    // on a grant instead of being dropped as automation.
    if ($inferred_bot_rule !== null && $inferred_bot_rule !== '') {
        return [
            'reason' => 'bot',
            'detail' => $inferred_bot_rule,
        ];
    }

    return null;
}

/**
 * Builds a hold/no_decision row and attaches a known CMP source when one exists.
 *
 * @param string      $reason             Blocked-event reason
 * @param string|null $consent_cookie_raw Cookie used to look up an optional source
 * @param string|null $source_cookie_raw  Saved `_pf_consent_source` from order meta
 * @return array{reason: string, consentSource?: string}
 */
function pixelflow_blocked_event_row_with_source(string $reason, ?string $consent_cookie_raw, ?string $source_cookie_raw = null): array
{
    $row    = [ 'reason' => $reason ];
    $source = pixelflow_blocked_event_consent_source($consent_cookie_raw, $source_cookie_raw);
    if ($source !== null) {
        $row['consentSource'] = $source;
    }

    return $row;
}

/**
 * Consent source for a blocked row: decision cookie first, then CMP source cookie.
 *
 * @param string|null $consent_cookie_raw Saved `_pf_consent` from order meta
 * @param string|null $source_cookie_raw  Saved `_pf_consent_source` from order meta
 * @return string|null Wire source, or null when unknown or not allow-listed
 */
function pixelflow_blocked_event_consent_source(?string $consent_cookie_raw, ?string $source_cookie_raw = null): ?string
{
    $consent = pixelflow_resolve_event_consent_block($consent_cookie_raw);
    if ($consent !== null && isset($consent['source']) && is_string($consent['source'])) {
        $from_decision = pixelflow_sanitize_blocked_consent_source($consent['source']);
        if ($from_decision !== null) {
            return $from_decision;
        }
    }

    $from_source = pixelflow_get_consent_source_from_cookie($source_cookie_raw);

    return $from_source !== null ? pixelflow_sanitize_blocked_consent_source($from_source) : null;
}

/**
 * Drops sources the blocked-events ingest would 400 (notably consent_mode_disabled).
 *
 * @param string $source Candidate wire source
 * @return string|null Allow-listed source, or null to omit the field
 */
function pixelflow_sanitize_blocked_consent_source(string $source): ?string
{
    if ( ! in_array($source, PIXELFLOW_BLOCKED_EVENT_CONSENT_SOURCES, true)) {
        return null;
    }

    return $source;
}

/**
 * Builds the anonymous POST /blocked-events body. Strips fields the reason must not carry.
 *
 * @param string $site_id    External site id
 * @param string $event_type Catalog event name (AddToCart, InitiateCheckout, Purchase)
 * @param array  $row        Reason row from pixelflow_resolve_blocked_event_reason()
 * @return array{siteId: string, blocked: array<int, array<string, string>>, client_ip_address?: string}|null
 */
function pixelflow_build_blocked_events_payload(string $site_id, string $event_type, array $row): ?array
{
    $site_id    = trim($site_id);
    $event_type = trim($event_type);
    if ($site_id === '' || $event_type === '') {
        return null;
    }

    $reason = isset($row['reason']) && is_string($row['reason']) ? $row['reason'] : '';
    if ( ! in_array($reason, PIXELFLOW_BLOCKED_EVENT_REASONS, true)) {
        return null;
    }

    $entry = [
        'eventType' => $event_type,
        'reason'    => $reason,
    ];

    if ($reason === 'bot') {
        $detail = isset($row['detail']) && is_string($row['detail']) ? trim($row['detail']) : '';
        if ($detail !== '') {
            $entry['detail'] = $detail;
        }
    }

    if ($reason === 'denied' || $reason === 'no_decision') {
        $raw_source = isset($row['consentSource']) && is_string($row['consentSource']) ? $row['consentSource'] : '';
        $source     = pixelflow_sanitize_blocked_consent_source($raw_source);
        if ($source !== null) {
            $entry['consentSource'] = $source;
        }
    }

    $payload = [
        'siteId'  => $site_id,
        'blocked' => [ $entry ],
    ];

    $ip = function_exists('pixelflow_get_client_ip_address')
        ? pixelflow_get_client_ip_address()
        : '';
    if ($ip !== '' && function_exists('pixelflow_is_private_ip') && ! pixelflow_is_private_ip($ip)) {
        $payload['client_ip_address'] = $ip;
    }

    return $payload;
}

/**
 * Fire-and-forget POST of an already-built blocked-events payload.
 *
 * @param string $api_url Event API origin (no path)
 * @param string $api_key Snippet API key (same as POST /event)
 * @param array  $payload Body from pixelflow_build_blocked_events_payload()
 * @return array|WP_Error|null Transport result, or null when nothing was sent
 */
function pixelflow_post_blocked_events(string $api_url, string $api_key, array $payload)
{
    $api_url = rtrim($api_url, '/');
    $api_key = trim($api_key);
    if ($api_url === '' || $api_key === '') {
        return null;
    }

    if ( ! isset($payload['siteId'], $payload['blocked']) || ! is_array($payload['blocked']) || $payload['blocked'] === []) {
        return null;
    }

    $timeout = (int) apply_filters('pixelflow_request_timeout', 5, $payload['siteId']);
    if ($timeout <= 0) {
        $timeout = 5;
    }

    return wp_remote_post(
        $api_url . '/blocked-events',
        [
            'method'      => 'POST',
            'timeout'     => $timeout,
            'blocking'    => false,
            'sslverify'   => true,
            'redirection' => 0,
            'headers'     => [
                'Content-Type' => 'application/json',
                'api-key'      => $api_key,
            ],
            'body'        => wp_json_encode($payload),
            'data_format' => 'body',
        ]
    );
}
