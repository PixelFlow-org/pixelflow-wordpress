<?php
/**
 * What a site with missing credentials still does.
 *
 * The gate suppresses the two outbound requests, not the hooks. Everything that merely records
 * has to keep running: the data behind it exists only during the buyer's own request, so an
 * empty site id or API key — a rotated key, a paste error, a fresh migration — would otherwise
 * cost every order created in that window its attribution and its consent decision, with nothing
 * left to recover them from once the credentials are back.
 *
 * The held queue is the third case: every disposition in resolve_held_events() empties it, so an
 * unguarded flush on an unconfigured site would drain the visit's events into nothing.
 *
 * The fourth is the deferred blocked-purchase report. `_pf_purchase_blocked_reported` closes an
 * order to all further reporting and nothing reopens it, so it may only ever follow a row that
 * actually left the site — whether the send was stopped by a missing credential or by a failed
 * request.
 *
 * Run: php tests/test-unconfigured-recording.php
 */

declare(strict_types=1);

require_once __DIR__ . '/bootstrap-wp-stubs.php';

$_SERVER['REMOTE_ADDR'] = '203.0.113.10';

if ( ! defined('WP_CONTENT_DIR')) {
    define('WP_CONTENT_DIR', sys_get_temp_dir() . '/pf-unconfigured-recording-test');
}
if ( ! is_dir(WP_CONTENT_DIR)) {
    mkdir(WP_CONTENT_DIR, 0777, true);
}
if ( ! defined('PIXELFLOW_VERSION')) {
    define('PIXELFLOW_VERSION', '0.0.0-test');
}
if ( ! defined('PIXELFLOW_PLUGIN_BASENAME')) {
    define('PIXELFLOW_PLUGIN_BASENAME', 'pixelflow/pixelflow.php');
}
if ( ! defined('COOKIEPATH')) {
    define('COOKIEPATH', '/');
}

$GLOBALS['__pf_test_options'] = [];
$GLOBALS['__pf_test_posts']   = [];
$GLOBALS['__pf_test_orders']  = [];

function get_option($option, $default = false)
{
    return $GLOBALS['__pf_test_options'][$option] ?? $default;
}

function add_option($option, $value = '', $deprecated = '', $autoload = 'yes')
{
    $GLOBALS['__pf_test_options'][$option] = $value;

    return true;
}

function delete_option($option)
{
    unset($GLOBALS['__pf_test_options'][$option]);

    return true;
}

$GLOBALS['__pf_test_schedule'] = [];

function wp_schedule_single_event($timestamp, $hook, $args = [])
{
    $GLOBALS['__pf_test_schedule'][$hook . ':' . json_encode($args)] = $timestamp;

    return true;
}

function wp_next_scheduled($hook, $args = [])
{
    return $GLOBALS['__pf_test_schedule'][$hook . ':' . json_encode($args)] ?? false;
}

function wp_clear_scheduled_hook($hook, $args = [])
{
    unset($GLOBALS['__pf_test_schedule'][$hook . ':' . json_encode($args)]);
}

function is_ssl(): bool
{
    return false;
}

function sanitize_key($key)
{
    return strtolower(preg_replace('/[^a-zA-Z0-9_\-]/', '', (string) $key));
}

function is_wp_error($thing): bool
{
    return $thing instanceof WP_Error;
}

function __($text, $domain = 'default')
{
    return $text;
}

function home_url($path = '')
{
    return 'https://example.test' . $path;
}

function wp_parse_url($url, $component = -1)
{
    return $component === -1 ? parse_url($url) : parse_url($url, $component);
}

function get_current_user_id()
{
    return 0;
}

function wp_remote_post($url, $args = [])
{
    // A case that needs a request the site could not deliver sets __pf_test_transport_fails.
    if ( ! empty($GLOBALS['__pf_test_transport_fails'])) {
        return new WP_Error();
    }

    $GLOBALS['__pf_test_posts'][] = [
        'url'     => $url,
        'payload' => json_decode((string) ($args['body'] ?? '{}'), true),
    ];

    return ['response' => ['code' => 200]];
}

function wp_remote_retrieve_response_code($response)
{
    return is_array($response) ? ($response['response']['code'] ?? '') : '';
}

function wp_remote_retrieve_response_message($response)
{
    return is_array($response) ? ($response['response']['message'] ?? '') : '';
}

function wp_remote_retrieve_body($response)
{
    return is_array($response) ? ($response['body'] ?? '') : '';
}

class WP_Error
{
}

class WC_Product
{
}

class WC_Order_Item_Product
{
}

/** Minimal stand-in for WC_Order: meta is the whole point of these cases. */
class WC_Order
{
    /** @var array<string, string> */
    public $meta = [];

    /** @var int */
    public $saves = 0;

    /** @var int */
    private $id;

    public function __construct(int $id)
    {
        $this->id = $id;
    }

    public function get_id(): int
    {
        return $this->id;
    }

    public function get_meta($key, $single = true)
    {
        return $this->meta[$key] ?? '';
    }

    public function update_meta_data($key, $value): void
    {
        $this->meta[$key] = $value;
    }

    public function delete_meta_data($key): void
    {
        unset($this->meta[$key]);
    }

    public function save(): void
    {
        $this->saves++;
    }

    public function get_customer_id()
    {
        return 0;
    }
}

/** In-memory stand-in for the Woo session. */
class PixelFlow_Test_Woo_Session
{
    /** @var array<string, mixed> */
    private $data = [];

    public function get(string $key)
    {
        return $this->data[$key] ?? null;
    }

    public function set(string $key, $value): void
    {
        $this->data[$key] = $value;
    }

    public function get_customer_id(): string
    {
        return 'test-customer';
    }

    public function save_data(): void
    {
    }
}

function WC()
{
    return $GLOBALS['__pf_test_wc'];
}

function check_ajax_referer($action = -1, $query_arg = false, $stop = true)
{
    return true;
}

function wp_send_json_success($data = null, $status_code = null)
{
    $GLOBALS['__pf_test_json_success'] = true;
}

function wc_get_order($order_id)
{
    return $GLOBALS['__pf_test_orders'][(int) $order_id] ?? false;
}

/**
 * The consent recorder only asks for orders it can already reach through the request; this
 * harness ties the order to the session instead, so the query returns nothing.
 *
 * @param array $args Query arguments
 * @return array<int, int>
 */
function wc_get_orders($args = [])
{
    return [];
}

require_once dirname(__DIR__) . '/includes/helpers.php';
require_once dirname(__DIR__) . '/includes/consent.php';
require_once dirname(__DIR__) . '/includes/blocked-events.php';
require_once dirname(__DIR__) . '/includes/held-events.php';
require_once dirname(__DIR__) . '/includes/woo/hooks/class-woocommerce-hooks.php';

const PF_SITE = 'wp_0123456789abcdef0123456789abcdef';

/** Hooks as an unconfigured site loads them: no site id, no API key. */
function pf_unconfigured_hooks(): PixelFlow_WooCommerce_Cart_Hooks
{
    return new PixelFlow_WooCommerce_Cart_Hooks('https://example.test/api', '', '', []);
}

/** Hooks as a configured site loads them. */
function pf_configured_hooks(): PixelFlow_WooCommerce_Cart_Hooks
{
    return new PixelFlow_WooCommerce_Cart_Hooks('https://example.test/api', 'k', PF_SITE, []);
}

/**
 * Encodes a `_pf_consent` cookie the way the browser script writes it.
 *
 * @param string $state granted|denied
 * @return string
 */
function pf_consent_cookie(string $state): string
{
    return base64_encode(
        (string) wp_json_encode(['s' => $state, 't' => 1757000000000, 'src' => 'api', 'v' => 1])
    );
}

/** Queues one AddToCart recipe in the session. */
function pf_queue_recipe(): void
{
    $payload = [
        'siteId'    => PF_SITE,
        'eventData' => [
            'event_id'       => 'atc-1',
            'eventName'      => 'AddToCart',
            'eventTime'      => 1757000000,
            'additionalData' => [
                'currency'    => 'EUR',
                'value'       => 10.0,
                'contentType' => 'product',
                'contents'    => [['id' => '17', 'quantity' => 1, 'item_price' => 10.0]],
            ],
        ],
    ];

    pixelflow_enqueue_held_woo_event(pixelflow_held_event_recipe_from_payload($payload, 17, 0));
}

/** Registers an order the hooks can look up. */
function pf_order(int $id): WC_Order
{
    $order = new WC_Order($id);
    $GLOBALS['__pf_test_orders'][$id] = $order;

    return $order;
}

$failures = [];
$passes   = 0;

/**
 * @param string   $label
 * @param callable $fn
 */
function pf_run_unconfigured_case(string $label, callable $fn, array &$failures, int &$passes): void
{
    $_COOKIE = [];
    $_GET    = [];
    $_SERVER['REQUEST_METHOD']  = 'GET';
    $_SERVER['HTTP_USER_AGENT'] = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) Chrome/140.0 Safari/537.36';

    $GLOBALS['__pf_test_posts']           = [];
    $GLOBALS['__pf_test_filters']         = [];
    $GLOBALS['__pf_test_options']         = [];
    $GLOBALS['__pf_test_orders']          = [];
    $GLOBALS['__pf_test_schedule']        = [];
    $GLOBALS['__pf_test_transport_fails'] = false;
    $GLOBALS['__pf_test_wc']              = (object) ['session' => new PixelFlow_Test_Woo_Session()];

    try {
        $result = $fn();
    } catch (\Throwable $e) {
        $result = sprintf('%s: %s', get_class($e), $e->getMessage());
    }

    if ($result === true) {
        $passes++;
        echo "PASS  {$label}\n";
    } else {
        $failures[] = "{$label}: {$result}";
        echo "FAIL  {$label}\n      {$result}\n";
    }
}

// ---------------------------------------------------------------------
// The held queue is kept, not drained.
// ---------------------------------------------------------------------

pf_run_unconfigured_case(
    'A granted decision does not flush the queue away while the credentials are missing',
    /** @return bool|string */
    function () {
        pf_queue_recipe();
        $_COOKIE[PIXELFLOW_CONSENT_COOKIE_NAME] = pf_consent_cookie('granted');

        pf_unconfigured_hooks()->resolve_held_events_on_page_view();

        if ($GLOBALS['__pf_test_posts'] !== []) {
            return 'a request left an unconfigured site: '
                . json_encode(array_column($GLOBALS['__pf_test_posts'], 'url'));
        }

        return count(pixelflow_get_held_woo_events()) === 1
            ? true
            : 'the visit\'s held events were discarded instead of kept';
    },
    $failures,
    $passes
);

pf_run_unconfigured_case(
    'A declined decision does not beacon the queue away either',
    /** @return bool|string */
    function () {
        // The deny branch reports the queued event names and then clears the queue, so it drains
        // the visit just as the flush does.
        pf_queue_recipe();
        $_COOKIE[PIXELFLOW_CONSENT_COOKIE_NAME] = pf_consent_cookie('denied');

        pf_unconfigured_hooks()->resolve_held_events_on_page_view();

        if ($GLOBALS['__pf_test_posts'] !== []) {
            return 'a beacon left an unconfigured site: '
                . json_encode(array_column($GLOBALS['__pf_test_posts'], 'url'));
        }

        return count(pixelflow_get_held_woo_events()) === 1
            ? true
            : 'the queue was cleared without anything being reported';
    },
    $failures,
    $passes
);

pf_run_unconfigured_case(
    'The AJAX flush route cannot drain the queue either',
    /** @return bool|string */
    function () {
        // The second entry point into the flush: the storefront script calls it the moment the
        // shopper grants, and it reaches resolve_held_events() the same way a page view does.
        pf_queue_recipe();
        $_COOKIE[PIXELFLOW_CONSENT_COOKIE_NAME] = pf_consent_cookie('granted');

        pf_unconfigured_hooks()->ajax_resolve_held_events();

        if ($GLOBALS['__pf_test_posts'] !== []) {
            return 'a request left an unconfigured site: '
                . json_encode(array_column($GLOBALS['__pf_test_posts'], 'url'));
        }

        return count(pixelflow_get_held_woo_events()) === 1
            ? true
            : 'the AJAX route drained the queue an unconfigured site cannot deliver';
    },
    $failures,
    $passes
);

pf_run_unconfigured_case(
    'The kept queue flushes on the next page view once the credentials are back',
    /** @return bool|string */
    function () {
        pf_queue_recipe();
        $_COOKIE[PIXELFLOW_CONSENT_COOKIE_NAME] = pf_consent_cookie('granted');

        // The page view during the outage...
        pf_unconfigured_hooks()->resolve_held_events_on_page_view();
        // ...and the next one, with the key pasted back in. Same session throughout.
        pf_configured_hooks()->resolve_held_events_on_page_view();

        $events = array_values(array_filter(
            $GLOBALS['__pf_test_posts'],
            static function ($post) {
                return substr((string) $post['url'], -6) === '/event';
            }
        ));

        if (count($events) !== 1) {
            return 'expected one dispatch once configured, got ' . count($events);
        }
        if (($events[0]['payload']['eventData']['event_id'] ?? null) !== 'atc-1') {
            return 'the dispatched event was not the held one: ' . json_encode($events[0]['payload']);
        }

        return pixelflow_get_held_woo_events() === []
            ? true
            : 'a flushed queue must be empty afterwards';
    },
    $failures,
    $passes
);

// ---------------------------------------------------------------------
// The recorders keep recording.
// ---------------------------------------------------------------------

pf_run_unconfigured_case(
    'An order placed while the credentials are missing still keeps the buyer\'s tracking data',
    /** @return bool|string */
    function () {
        // Written once, during the buyer's own request: by the time the order changes status the
        // cookies are gone, so anything missed here is missed for good.
        $order = pf_order(501);
        $_COOKIE['_pf_uid']         = '1757000000.123';
        $_COOKIE['_fbp']            = 'fb.1.1757000000.98765';
        $_COOKIE['_pf_attribution'] = '{"source":"facebook"}';

        pf_unconfigured_hooks()->pf_save_tracking_cookies_to_order(501);

        foreach (
            [
                '_pf_cookie__pf_uid'         => '1757000000.123',
                '_pf_cookie__fbp'            => 'fb.1.1757000000.98765',
                '_pf_cookie__pf_attribution' => '{"source":"facebook"}',
            ] as $key => $expected
        ) {
            if (($order->meta[$key] ?? null) !== $expected) {
                return "{$key} was not recorded: " . json_encode($order->meta);
            }
        }

        if (($order->meta['_pf_client_ip'] ?? '') === '' || ($order->meta['_pf_client_ua'] ?? '') === '') {
            return 'the buyer\'s request fingerprint was not recorded: ' . json_encode($order->meta);
        }

        return ($order->meta['_pf_session_customer_id'] ?? null) === 'test-customer'
            ? true
            : 'the session owner was not recorded: ' . json_encode($order->meta);
    },
    $failures,
    $passes
);

pf_run_unconfigured_case(
    'A consent decision made while the credentials are missing still reaches the open order',
    /** @return bool|string */
    function () {
        // The decision is the gate a later background purchase hook reads; losing it means the
        // order is judged on whatever was recorded at checkout, forever.
        $order = pf_order(502);
        $GLOBALS['__pf_test_wc']->session->set('order_awaiting_payment', 502);
        $_COOKIE[PIXELFLOW_CONSENT_COOKIE_NAME] = pf_consent_cookie('granted');

        pf_unconfigured_hooks()->record_consent_decision_on_open_orders();

        $stored = $order->meta['_pf_cookie__pf_consent'] ?? '';
        if ($stored === '') {
            return 'the decision was not recorded on the order: ' . json_encode($order->meta);
        }

        $decoded = pixelflow_decode_consent_cookie($stored);

        return ($decoded['state'] ?? null) === 'granted'
            ? true
            : 'the recorded decision is not the one the buyer made: ' . json_encode($decoded);
    },
    $failures,
    $passes
);

// ---------------------------------------------------------------------
// A blocked purchase is not closed by a report nobody received.
// ---------------------------------------------------------------------

/**
 * An order whose purchase was blocked and whose report is already due.
 *
 * @param int $id Order id
 * @return WC_Order
 */
function pf_order_with_due_blocked_report(int $id): WC_Order
{
    $order = pf_order($id);
    $order->meta['_pf_purchase_blocked'] = (string) wp_json_encode([
        'reason' => 'bot',
        'detail' => 'python-requests',
        'due'    => time() - 60,
    ]);

    return $order;
}

/** Whether a retry is armed for this order's blocked report. */
function pf_retry_is_armed(int $order_id): bool
{
    return wp_next_scheduled('pixelflow_report_blocked_purchase', [$order_id]) !== false;
}

pf_run_unconfigured_case(
    'A blocked report that could not be sent leaves the order open and re-arms its retry',
    /** @return bool|string */
    function () {
        // The scheduler fires during the credential outage. _pf_purchase_blocked_reported is
        // permanent, so writing it here would close the order to every future report.
        $order = pf_order_with_due_blocked_report(601);

        pf_unconfigured_hooks()->report_blocked_purchase(601);

        if ($GLOBALS['__pf_test_posts'] !== []) {
            return 'a beacon left an unconfigured site: '
                . json_encode(array_column($GLOBALS['__pf_test_posts'], 'url'));
        }
        if (($order->meta['_pf_purchase_blocked_reported'] ?? '') !== '') {
            return 'the order was closed by a report that was never sent';
        }
        if (($order->meta['_pf_purchase_blocked'] ?? '') === '') {
            return 'the stored reason was discarded, so there is nothing left to report';
        }

        return pf_retry_is_armed(601) ? true : 'no retry was left to carry the report';
    },
    $failures,
    $passes
);

pf_run_unconfigured_case(
    'The re-armed retry reports the order once the credentials are back, and closes it then',
    /** @return bool|string */
    function () {
        $order = pf_order_with_due_blocked_report(602);

        pf_unconfigured_hooks()->report_blocked_purchase(602);
        pf_configured_hooks()->report_blocked_purchase(602);

        $beacons = array_values(array_filter(
            $GLOBALS['__pf_test_posts'],
            static function ($post) {
                return substr((string) $post['url'], -15) === '/blocked-events';
            }
        ));

        if (count($beacons) !== 1) {
            return 'expected exactly one blocked row once configured, got ' . count($beacons);
        }
        if (($beacons[0]['payload']['blocked'][0]['reason'] ?? null) !== 'bot') {
            return 'the reported row is not the stored one: ' . json_encode($beacons[0]['payload']);
        }
        if (($order->meta['_pf_purchase_blocked_reported'] ?? '') !== '1') {
            return 'a delivered report must close the order';
        }
        if (($order->meta['_pf_purchase_blocked'] ?? '') !== '') {
            return 'the marker was left behind after a delivered report';
        }

        return pf_retry_is_armed(602) ? 'a retry outlived the report it was armed for' : true;
    },
    $failures,
    $passes
);

pf_run_unconfigured_case(
    'A blocked report that failed in transit is not marked as reported either',
    /** @return bool|string */
    function () {
        // Same rule, the other silent failure: the site is configured but the request never
        // reached the API.
        $order = pf_order_with_due_blocked_report(603);
        $GLOBALS['__pf_test_transport_fails'] = true;

        pf_configured_hooks()->report_blocked_purchase(603);

        if (($order->meta['_pf_purchase_blocked_reported'] ?? '') !== '') {
            return 'a failed request closed the order';
        }

        return pf_retry_is_armed(603) ? true : 'no retry was left to carry the report';
    },
    $failures,
    $passes
);

echo "\n{$passes} passed, " . count($failures) . " failed\n";

exit(count($failures) > 0 ? 1 : 0);
