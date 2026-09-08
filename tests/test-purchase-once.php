<?php
/**
 * Purchase delivery and reporting: exactly one unit per order.
 *
 * The API deduplicates nothing and counts a blocked row like a real event, so an
 * order must produce either one purchase event or one blocked row — never both,
 * and never two of either.
 *
 * Run: php tests/test-purchase-once.php
 */

declare(strict_types=1);

require_once __DIR__ . '/bootstrap-wp-stubs.php';

$_SERVER['REMOTE_ADDR'] = '203.0.113.10';

if ( ! defined('WP_CONTENT_DIR')) {
    define('WP_CONTENT_DIR', sys_get_temp_dir() . '/pf-debug-log-test');
}
if ( ! is_dir(WP_CONTENT_DIR)) {
    mkdir(WP_CONTENT_DIR, 0777, true);
}
if ( ! defined('PIXELFLOW_VERSION')) {
    define('PIXELFLOW_VERSION', '0.0.0-test');
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

$GLOBALS['__pf_test_options']   = [];
$GLOBALS['__pf_test_schedule']  = [];
$GLOBALS['__pf_test_blocked']   = [];
$GLOBALS['__pf_test_now']       = 1757000000;

function add_option($option, $value = '', $deprecated = '', $autoload = 'yes')
{
    if (array_key_exists($option, $GLOBALS['__pf_test_options'])) {
        return false;
    }
    $GLOBALS['__pf_test_options'][$option] = $value;

    return true;
}

function get_option($option, $default = false)
{
    return $GLOBALS['__pf_test_options'][$option] ?? $default;
}

function delete_option($option)
{
    unset($GLOBALS['__pf_test_options'][$option]);

    return true;
}

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

function is_wp_error($thing): bool
{
    return $thing instanceof WP_Error;
}

function __($text, $domain = 'default')
{
    return $text;
}

function wp_remote_post($url, $args = [])
{
    if ( ! empty($GLOBALS['__pf_test_transport_fails'])) {
        return new WP_Error();
    }

    $GLOBALS['__pf_test_blocked'][] = [
        'url'     => $url,
        'payload' => json_decode((string) ($args['body'] ?? '{}'), true),
    ];

    return ['response' => ['code' => 200]];
}

class WP_Error
{
}

/** Minimal stand-in for WC_Order: meta, ids and totals. */
class WC_Order
{
    /** @var array<string, string> */
    public $meta = [];

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
    }

    /** @var array<int, WC_Order_Item_Product> */
    public $items = [];

    public function get_items($type = 'line_item'): array
    {
        return $this->items;
    }

    public function get_currency()
    {
        return 'USD';
    }

    public function get_total()
    {
        return 25.0;
    }

    public function get_shipping_total()
    {
        return 0.0;
    }

    public function get_total_tax()
    {
        return 0.0;
    }

    public function get_customer_id()
    {
        return 0;
    }

    public function get_billing_email()
    {
        return 'buyer@example.test';
    }

    public function get_billing_phone()
    {
        return '5415550123';
    }

    public function get_billing_first_name()
    {
        return 'Pixel';
    }

    public function get_billing_last_name()
    {
        return 'Flow';
    }

    public function get_billing_city()
    {
        return 'Springfield';
    }

    public function get_billing_state()
    {
        return 'OR';
    }

    public function get_billing_postcode()
    {
        return '97477';
    }

    public function get_billing_country()
    {
        return 'US';
    }

    public function get_checkout_order_received_url()
    {
        return 'https://example.test/order-received/' . $this->id;
    }
}

class WC_Product
{
    /** @var string */
    private $sku;

    /** @var float */
    private $price;

    public function __construct(string $sku = 'PF-TEST', float $price = 25.0)
    {
        $this->sku   = $sku;
        $this->price = $price;
    }

    public function get_sku()
    {
        return $this->sku;
    }

    public function get_id()
    {
        return 4242;
    }

    public function get_price()
    {
        return $this->price;
    }

    public function get_name()
    {
        return 'PF Test Product';
    }
}

class WC_Order_Item_Product
{
    /** @var WC_Product */
    private $product;

    public function __construct(?WC_Product $product = null)
    {
        $this->product = $product ?? new WC_Product();
    }

    public function get_product()
    {
        return $this->product;
    }

    public function get_product_id()
    {
        return 4242;
    }

    public function get_variation_id()
    {
        return 0;
    }

    public function get_quantity()
    {
        return 1;
    }

    public function get_name()
    {
        return 'PF Test Product';
    }

    public function get_total()
    {
        return 25.0;
    }

    public function get_subtotal()
    {
        return 25.0;
    }
}

/**
 * Orders the full purchase hook can run against.
 *
 * The cases that drive `pf_purchase_hook` end to end need an order with line
 * items and a billing address; the marker-level cases do not, so the plain
 * `WC_Order` above stays as it was and this registry only holds the orders a
 * full run needs.
 *
 * @var array<int, WC_Order>
 */
$GLOBALS['__pf_test_orders'] = [];

function wc_get_price_decimals()
{
    return 2;
}

function wc_format_decimal($number, $dp = false, $trim_zeros = false)
{
    return $dp === false ? (string) $number : number_format((float) $number, (int) $dp, '.', '');
}

function home_url($path = '')
{
    return 'https://example.test' . $path;
}

function wp_parse_url($url, $component = -1)
{
    return $component === -1 ? parse_url($url) : parse_url($url, $component);
}

function wc_get_order($order_id)
{
    return $GLOBALS['__pf_test_orders'][(int) $order_id] ?? false;
}

/** Registers an order with one reportable line, ready for `pf_purchase_hook`. */
function pf_purchasable_order(int $id): WC_Order
{
    $order = new WC_Order($id);
    $order->items = [new WC_Order_Item_Product()];
    $GLOBALS['__pf_test_orders'][$id] = $order;

    return $order;
}

require_once dirname(__DIR__) . '/includes/helpers.php';
require_once dirname(__DIR__) . '/includes/consent.php';
require_once dirname(__DIR__) . '/includes/blocked-events.php';
require_once dirname(__DIR__) . '/includes/held-events.php';
require_once dirname(__DIR__) . '/includes/woo/hooks/class-woocommerce-hooks.php';

/**
 * Calls a private method on the hooks class.
 *
 * @param object $target Instance to call on
 * @param string $method Method name
 * @param array  $args   Arguments
 * @return mixed
 */
function pf_call($target, string $method, array $args = [])
{
    $reflection = new ReflectionMethod(PixelFlow_WooCommerce_Cart_Hooks::class, $method);
    $reflection->setAccessible(true);

    return $reflection->invokeArgs($target, $args);
}

function pf_hooks(array $options = []): PixelFlow_WooCommerce_Cart_Hooks
{
    return new PixelFlow_WooCommerce_Cart_Hooks('https://example.test/api', 'k', 'site', $options);
}

$failures = [];
$passes   = 0;

/**
 * @param string   $label
 * @param callable $fn
 */
function pf_run_once_case(string $label, callable $fn, array &$failures, int &$passes): void
{
    $GLOBALS['__pf_test_options']  = [];
    $GLOBALS['__pf_test_schedule'] = [];
    $GLOBALS['__pf_test_blocked']  = [];

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

pf_run_once_case(
    'Two concurrent hooks: only one takes the delivery claim',
    /** @return bool|string */
    function () {
        $thankyou = pf_hooks();
        $webhook  = pf_hooks();

        if (pf_call($thankyou, 'claim_purchase_delivery', [77]) !== true) {
            return 'the first hook must take the claim';
        }
        if (pf_call($webhook, 'claim_purchase_delivery', [77]) !== false) {
            return 'the second hook must lose the race and send nothing';
        }

        return true;
    },
    $failures,
    $passes
);

pf_run_once_case(
    'A claim abandoned by a crashed request is taken over and removed',
    /** @return bool|string */
    function () {
        $hooks = pf_hooks();
        pf_call($hooks, 'claim_purchase_delivery', [78]);

        // The request that took it died here; rewind the stored timestamp past
        // the abandonment interval.
        $ttl = pf_call($hooks, 'get_claim_ttl');
        $GLOBALS['__pf_test_options']['pixelflow_purchase_claim_78'] = (string) (time() - $ttl - 1);

        if (pf_call(pf_hooks(), 'claim_purchase_delivery', [78]) !== true) {
            return 'an abandoned claim must not block the order forever';
        }

        return true;
    },
    $failures,
    $passes
);

pf_run_once_case(
    'A raised request timeout widens the abandonment interval',
    /** @return bool|string */
    function () {
        $default = pf_call(pf_hooks(), 'get_claim_ttl');
        if ($default !== 300) {
            return 'expected the five-minute floor by default, got ' . $default;
        }

        $GLOBALS['__pf_test_filters']['pixelflow_request_timeout'] = 120;
        try {
            $raised = pf_call(pf_hooks(), 'get_claim_ttl');
        } finally {
            unset($GLOBALS['__pf_test_filters']['pixelflow_request_timeout']);
        }

        if ($raised !== 1200) {
            return 'a raised timeout must widen the interval, got ' . $raised;
        }

        return true;
    },
    $failures,
    $passes
);

pf_run_once_case(
    'The claim is released once the outcome is recorded',
    /** @return bool|string */
    function () {
        $hooks = pf_hooks();
        pf_call($hooks, 'claim_purchase_delivery', [79]);
        pf_call($hooks, 'release_purchase_delivery_claim', [79]);

        if (pf_call(pf_hooks(), 'claim_purchase_delivery', [79]) !== true) {
            return 'a released claim must be available again';
        }

        return true;
    },
    $failures,
    $passes
);

pf_run_once_case(
    'A blocked purchase is not reported at the moment of the skip',
    /** @return bool|string */
    function () {
        $order = new WC_Order(80);
        pf_call(pf_hooks(), 'defer_blocked_purchase_report', [$order, ['reason' => 'denied']]);

        if ($GLOBALS['__pf_test_blocked'] !== []) {
            return 'nothing may be POSTed while the decision can still change';
        }
        if ((string) $order->get_meta('_pf_purchase_blocked') === '') {
            return 'the reason must be recorded on the order';
        }
        if (wp_next_scheduled('pixelflow_report_blocked_purchase', [80]) === false) {
            return 'the report must be scheduled';
        }

        return true;
    },
    $failures,
    $passes
);

pf_run_once_case(
    'Repeated hooks before the window closes do not reschedule or duplicate',
    /** @return bool|string */
    function () {
        $order = new WC_Order(81);
        $hooks = pf_hooks();

        pf_call($hooks, 'defer_blocked_purchase_report', [$order, ['reason' => 'denied']]);
        $first = wp_next_scheduled('pixelflow_report_blocked_purchase', [81]);

        pf_call($hooks, 'defer_blocked_purchase_report', [$order, ['reason' => 'denied']]);
        pf_call($hooks, 'defer_blocked_purchase_report', [$order, ['reason' => 'denied']]);

        if (wp_next_scheduled('pixelflow_report_blocked_purchase', [81]) !== $first) {
            return 'further status changes must not move the report';
        }
        if ($GLOBALS['__pf_test_blocked'] !== []) {
            return 'no row may be POSTed before the window closes';
        }

        return true;
    },
    $failures,
    $passes
);

pf_run_once_case(
    'A grant inside the window cancels the report',
    /** @return bool|string */
    function () {
        $order = new WC_Order(82);
        $hooks = pf_hooks();

        pf_call($hooks, 'defer_blocked_purchase_report', [$order, ['reason' => 'no_decision']]);
        pf_call($hooks, 'cancel_blocked_purchase_report', [$order]);

        if ((string) $order->get_meta('_pf_purchase_blocked') !== '') {
            return 'the marker must be dropped when the purchase is delivered instead';
        }
        if (wp_next_scheduled('pixelflow_report_blocked_purchase', [82]) !== false) {
            return 'the scheduled report must be cleared';
        }
        if ($GLOBALS['__pf_test_blocked'] !== []) {
            return 'a delivered order must not also report a blocked row';
        }

        return true;
    },
    $failures,
    $passes
);

/** The consent cookie the storefront script writes, in the shape the plugin decodes. */
function pf_consent_cookie(string $state): string
{
    return base64_encode((string) json_encode([
        's'   => $state,
        't'   => $GLOBALS['__pf_test_now'],
        'src' => 'complianz',
        'v'   => 1,
    ]));
}

/**
 * Puts the current request in the buyer's shoes: the visitor id on the request
 * matches the one recorded on the order, which is what makes the request own it.
 */
function pf_become_owner(WC_Order $order, string $consentState): void
{
    $order->update_meta_data('_pf_cookie__pf_uid', 'visitor-1');
    $_COOKIE['_pf_uid']     = 'visitor-1';
    $_COOKIE['_pf_consent'] = pf_consent_cookie($consentState);
}

function pf_forget_visitor(): void
{
    unset($_COOKIE['_pf_uid'], $_COOKIE['_pf_consent'], $_COOKIE['_pf_no_consent_decision']);
}

/** Real events carry eventData; blocked beacons carry a `blocked` list instead. */
function pf_posted_events(): array
{
    return array_values(array_filter(
        $GLOBALS['__pf_test_blocked'],
        static fn ($post) => isset($post['payload']['eventData'])
    ));
}

function pf_posted_blocked_rows(): array
{
    return array_values(array_filter(
        $GLOBALS['__pf_test_blocked'],
        static fn ($post) => isset($post['payload']['blocked'])
    ));
}

pf_run_once_case(
    'A grant inside the window delivers the purchase and cancels the report',
    /** @return bool|string */
    function () {
        $order = pf_purchasable_order(84);
        $hooks = pf_hooks();

        // The buyer declined at checkout: the report is deferred, nothing is sent.
        pf_call($hooks, 'defer_blocked_purchase_report', [$order, ['reason' => 'denied']]);
        $GLOBALS['__pf_test_blocked'] = [];

        // The buyer changes their mind on their own order-received page. This
        // goes through the whole hook rather than calling the cancellation
        // directly, because the point is that the purchase is *delivered* — a
        // cancelled report on its own would leave the order sending nothing.
        pf_become_owner($order, 'granted');
        try {
            pf_hooks()->pf_purchase_hook(84);
        } finally {
            pf_forget_visitor();
        }

        $events = pf_posted_events();
        if (count($events) !== 1) {
            return 'the grant must deliver exactly one purchase, got ' . count($events);
        }
        if (($events[0]['payload']['eventData']['eventName'] ?? '') !== 'Purchase') {
            return 'the delivered event is not a Purchase';
        }
        if (pf_posted_blocked_rows() !== []) {
            return 'a delivered order must not also report a blocked row';
        }
        if ((string) $order->get_meta('_pf_purchase_sent', true) !== '1') {
            return 'the order was not recorded as delivered';
        }
        if ((string) $order->get_meta('_pf_purchase_blocked', true) !== '') {
            return 'the deferred marker must be dropped once the purchase is delivered';
        }
        if (wp_next_scheduled('pixelflow_report_blocked_purchase', [84]) !== false) {
            return 'the scheduled report must be cleared';
        }

        return true;
    },
    $failures,
    $passes
);

pf_run_once_case(
    'A grant arriving after the report has been sent sends nothing',
    /** @return bool|string */
    function () {
        $order = pf_purchasable_order(85);
        $hooks = pf_hooks();

        // The window passed with no grant, so the blocked row was reported and
        // the backend has already counted one unit for this order.
        pf_call($hooks, 'defer_blocked_purchase_report', [$order, ['reason' => 'denied']]);
        $marker = json_decode((string) $order->get_meta('_pf_purchase_blocked', true), true);
        $marker['due'] = $GLOBALS['__pf_test_now'] - 1;
        $order->update_meta_data('_pf_purchase_blocked', (string) json_encode($marker));
        pf_call($hooks, 'defer_blocked_purchase_report', [$order, ['reason' => 'denied']]);

        if ((string) $order->get_meta('_pf_purchase_blocked_reported', true) !== '1') {
            return 'the blocked row was never reported, so the case under test never arises';
        }
        $GLOBALS['__pf_test_blocked'] = [];

        // The buyer grants afterwards. The order is closed: a purchase now would
        // be the second unit of traffic the whole design exists to prevent.
        pf_become_owner($order, 'granted');
        try {
            pf_hooks()->pf_purchase_hook(85);
        } finally {
            pf_forget_visitor();
        }

        if (pf_posted_events() !== []) {
            return 'a purchase was sent for an order whose blocked row was already reported';
        }
        if (pf_posted_blocked_rows() !== []) {
            return 'a second blocked row was reported';
        }
        if ((string) $order->get_meta('_pf_purchase_sent', true) !== '') {
            return 'the closed order was marked as delivered';
        }

        return true;
    },
    $failures,
    $passes
);

pf_run_once_case(
    'An overdue report is sent by a later hook, exactly once',
    /** @return bool|string */
    function () {
        $order = new WC_Order(83);
        $hooks = pf_hooks();

        pf_call($hooks, 'defer_blocked_purchase_report', [$order, ['reason' => 'denied']]);

        // The scheduler never ran: rewind the deadline past now.
        $marker        = json_decode((string) $order->get_meta('_pf_purchase_blocked'), true);
        $marker['due'] = time() - 1;
        $order->update_meta_data('_pf_purchase_blocked', (string) wp_json_encode($marker));

        pf_call($hooks, 'defer_blocked_purchase_report', [$order, ['reason' => 'denied']]);
        pf_call($hooks, 'defer_blocked_purchase_report', [$order, ['reason' => 'denied']]);

        if (count($GLOBALS['__pf_test_blocked']) !== 1) {
            return 'expected exactly one blocked row, got ' . count($GLOBALS['__pf_test_blocked']);
        }
        $row = $GLOBALS['__pf_test_blocked'][0]['payload']['blocked'][0] ?? [];
        if (($row['eventType'] ?? '') !== 'Purchase' || ($row['reason'] ?? '') !== 'denied') {
            return 'the row must describe the declined purchase';
        }
        if (isset($row['due'])) {
            return 'the internal deadline must not reach the API';
        }
        if ((string) $order->get_meta('_pf_purchase_blocked_reported') !== '1') {
            return 'the order must be closed once reported';
        }

        return true;
    },
    $failures,
    $passes
);

pf_run_once_case(
    'A reported order is closed to the scheduler as well',
    /** @return bool|string */
    function () {
        $order = new WC_Order(84);
        $order->update_meta_data('_pf_purchase_blocked_reported', '1');
        $order->update_meta_data('_pf_purchase_blocked', (string) wp_json_encode(['reason' => 'denied', 'due' => time() - 1]));

        pf_call(pf_hooks(), 'defer_blocked_purchase_report', [$order, ['reason' => 'denied']]);

        if ($GLOBALS['__pf_test_blocked'] !== []) {
            return 'an order that already reported must not report again';
        }

        return true;
    },
    $failures,
    $passes
);

pf_run_once_case(
    'A transport failure is not recorded as a delivery',
    /** @return bool|string */
    function () {
        $GLOBALS['__pf_test_transport_fails'] = true;

        try {
            $outcome = pf_call(pf_hooks(), 'dispatch_event_post', [
                ['siteId' => 'site', 'eventData' => ['eventName' => 'Purchase']],
                'Purchase',
                'Mozilla/5.0',
            ]);
        } finally {
            unset($GLOBALS['__pf_test_transport_fails']);
        }

        if ($outcome !== 'failed') {
            return 'an unreachable API must not report the event as sent, got ' . $outcome;
        }

        return true;
    },
    $failures,
    $passes
);

pf_run_once_case(
    'A reachable API is recorded as a delivery',
    /** @return bool|string */
    function () {
        $outcome = pf_call(pf_hooks(), 'dispatch_event_post', [
            ['siteId' => 'site', 'eventData' => ['eventName' => 'Purchase']],
            'Purchase',
            'Mozilla/5.0',
        ]);

        if ($outcome !== 'sent') {
            return 'expected the send to be recorded, got ' . $outcome;
        }

        return true;
    },
    $failures,
    $passes
);

pf_run_once_case(
    'A private client IP is skipped without recording anything',
    /** @return bool|string */
    function () {
        $previous               = $_SERVER['REMOTE_ADDR'];
        $_SERVER['REMOTE_ADDR'] = '10.0.0.5';

        try {
            $outcome = pf_call(pf_hooks(), 'dispatch_event_post', [
                ['siteId' => 'site', 'eventData' => ['eventName' => 'Purchase']],
                'Purchase',
                'Mozilla/5.0',
            ]);
        } finally {
            $_SERVER['REMOTE_ADDR'] = $previous;
        }

        if ($outcome !== 'skipped') {
            return 'a private address must skip rather than deliver, got ' . $outcome;
        }
        if ($GLOBALS['__pf_test_blocked'] !== []) {
            return 'a private-IP skip must not beacon';
        }

        return true;
    },
    $failures,
    $passes
);

pf_run_once_case(
    'A blocked-events row is written to the debug log',
    /** @return bool|string */
    function () {
        $GLOBALS['__pf_test_options']['pixelflow_debug_log_key'] = 'testkey';
        $log = pixelflow_get_debug_log_path();
        if ($log === '') {
            return 'the harness could not resolve a log path';
        }
        if (file_exists($log)) {
            unlink($log);
        }

        $hooks = pf_hooks(['woo_debug_enabled' => 1]);
        pf_call($hooks, 'post_blocked_event', ['Purchase', ['reason' => 'denied']]);

        if ( ! file_exists($log)) {
            return 'the blocked row left no trace in the debug log';
        }
        $contents = (string) file_get_contents($log);
        unlink($log);

        if (strpos($contents, 'BLOCKED_EVENTS Purchase') === false) {
            return 'the entry must name the blocked event';
        }
        if (strpos($contents, '"reason": "denied"') === false) {
            return 'the entry must carry the payload that was POSTed';
        }

        return true;
    },
    $failures,
    $passes
);

pf_run_once_case(
    'A blocked row that cannot be built is logged rather than dropped silently',
    /** @return bool|string */
    function () {
        $GLOBALS['__pf_test_options']['pixelflow_debug_log_key'] = 'testkey';
        $log = pixelflow_get_debug_log_path();
        if (file_exists($log)) {
            unlink($log);
        }

        $hooks = pf_hooks(['woo_debug_enabled' => 1]);
        pf_call($hooks, 'post_blocked_event', ['Purchase', ['reason' => 'not-a-known-reason']]);

        if ( ! file_exists($log)) {
            return 'an unreportable row must still be visible in the log';
        }
        $contents = (string) file_get_contents($log);
        unlink($log);

        if (strpos($contents, 'COULD NOT BE BUILT') === false) {
            return 'the entry must say why nothing was sent';
        }

        return true;
    },
    $failures,
    $passes
);

pf_run_once_case(
    'The purchase event id is derived from the order and the event name',
    /** @return bool|string */
    function () {
        $hooks = pf_hooks();
        $a     = pf_call($hooks, 'purchase_event_id', [90, 'Purchase']);
        $b     = pf_call($hooks, 'purchase_event_id', [90, 'Purchase']);
        $c     = pf_call($hooks, 'purchase_event_id', [91, 'Purchase']);
        $d     = pf_call($hooks, 'purchase_event_id', [90, 'Refund']);

        if ($a !== $b) {
            return 'the same order must always produce the same id';
        }
        if ($a === $c || $a === $d) {
            return 'a different order or event type must produce a different id';
        }

        return true;
    },
    $failures,
    $passes
);

// ---------------------------------------------------------------------
// Whose request moves the order, and whose identity the event may carry.
//
// An order reaches a purchasing status in whatever request moved it — a staff
// change in wp-admin, automation over WP-CLI — and those requests carry an
// identity that is not the buyer's. The purchase must still go out, built from
// the order alone.
// ---------------------------------------------------------------------

pf_run_once_case(
    'Purchase hooks are registered even when the admin and cache-warmer guards both trip',
    /** @return bool|string */
    function () {
        $GLOBALS['__pf_admin']        = true;
        $previous                     = $_SERVER['REMOTE_ADDR'];
        $_SERVER['REMOTE_ADDR']       = '127.0.0.1';   // trips the cache-warmer guard too
        $GLOBALS['__pf_test_actions'] = [];

        pf_call(pf_hooks(), 'init_hooks');

        $GLOBALS['__pf_admin']  = false;
        $_SERVER['REMOTE_ADDR'] = $previous;

        $registered = static function (string $hook): bool {
            foreach ($GLOBALS['__pf_test_actions'][$hook] ?? [] as $registration) {
                if (is_array($registration['callback']) && $registration['callback'][1] === 'pf_purchase_hook') {
                    return true;
                }
            }

            return false;
        };

        if ( ! $registered('woocommerce_order_status_processing')) {
            return 'the processing hook is not registered, so a staff status change runs nothing';
        }
        if ( ! $registered('woocommerce_order_status_completed')) {
            return 'the completed hook is not registered';
        }

        return true;
    },
    $failures,
    $passes
);

pf_run_once_case(
    'Storefront hooks stay out of an admin request',
    /** @return bool|string */
    function () {
        $GLOBALS['__pf_admin']        = true;
        $GLOBALS['__pf_test_actions'] = [];

        pf_call(pf_hooks(), 'init_hooks');

        $GLOBALS['__pf_admin'] = false;

        foreach (['woocommerce_add_to_cart', 'woocommerce_before_checkout_form', 'woocommerce_thankyou'] as $hook) {
            if ( ! empty($GLOBALS['__pf_test_actions'][$hook])) {
                return $hook . ' leaked into the admin request';
            }
        }

        return true;
    },
    $failures,
    $passes
);

pf_run_once_case(
    "A staff status change sends the order's own IP and user agent, never the staff member's",
    /** @return bool|string */
    function () {
        pf_forget_visitor();
        $previous_ip                  = $_SERVER['REMOTE_ADDR'];
        $previous_ua                  = $_SERVER['HTTP_USER_AGENT'] ?? null;
        $_SERVER['REMOTE_ADDR']       = '192.0.2.55';                 // the staff member's
        $_SERVER['HTTP_USER_AGENT']   = 'Mozilla/5.0 (staff browser)';
        $_COOKIE['_fbp']              = 'fb.1.staff.999';             // the staff member's, too

        $order = pf_purchasable_order(301);
        $order->update_meta_data('_pf_cookie__pf_uid', 'visitor-1');  // the buyer's, not this request's
        $order->update_meta_data('_pf_cookie__pf_consent', pf_consent_cookie('granted'));
        $order->update_meta_data('_pf_client_ip', '198.51.100.7');
        $order->update_meta_data('_pf_client_ua', 'Mozilla/5.0 (buyer)');

        pf_hooks()->pf_purchase_hook(301);

        unset($_COOKIE['_fbp']);
        $_SERVER['REMOTE_ADDR'] = $previous_ip;
        if ($previous_ua === null) {
            unset($_SERVER['HTTP_USER_AGENT']);
        } else {
            $_SERVER['HTTP_USER_AGENT'] = $previous_ua;
        }

        $events = pf_posted_events();
        if (count($events) !== 1) {
            return 'expected exactly one purchase, got ' . count($events);
        }

        $customer = $events[0]['payload']['eventData']['customerData'] ?? [];
        if (($customer['client_ip_address'] ?? null) !== '198.51.100.7') {
            return "the order's own IP was not used: " . json_encode($customer);
        }
        if (($customer['client_user_agent'] ?? null) !== 'Mozilla/5.0 (buyer)') {
            return "the order's own user agent was not used: " . json_encode($customer);
        }
        if (($events[0]['payload']['eventData']['fbp'] ?? null) === 'fb.1.staff.999') {
            return "the staff member's _fbp was attributed to the buyer";
        }

        return true;
    },
    $failures,
    $passes
);

pf_run_once_case(
    'An order with no identity of its own goes out without one, rather than with the requester\'s',
    /** @return bool|string */
    function () {
        pf_forget_visitor();
        $previous_ip                = $_SERVER['REMOTE_ADDR'];
        $previous_ua                = $_SERVER['HTTP_USER_AGENT'] ?? null;
        $_SERVER['REMOTE_ADDR']     = '192.0.2.55';
        $_SERVER['HTTP_USER_AGENT'] = 'Mozilla/5.0 (staff browser)';

        // No _pf_client_ip, no _pf_client_ua: an order placed before the plugin was
        // installed, or one created by an importer.
        $order = pf_purchasable_order(302);
        $order->update_meta_data('_pf_cookie__pf_consent', pf_consent_cookie('granted'));

        pf_hooks()->pf_purchase_hook(302);

        $_SERVER['REMOTE_ADDR'] = $previous_ip;
        if ($previous_ua === null) {
            unset($_SERVER['HTTP_USER_AGENT']);
        } else {
            $_SERVER['HTTP_USER_AGENT'] = $previous_ua;
        }

        $events = pf_posted_events();
        if (count($events) !== 1) {
            return 'expected exactly one purchase, got ' . count($events);
        }

        $customer = $events[0]['payload']['eventData']['customerData'] ?? [];
        if (isset($customer['client_ip_address'])) {
            return "the requester's IP stood in for the buyer's: " . $customer['client_ip_address'];
        }
        if (isset($customer['client_user_agent'])) {
            return "the requester's user agent stood in for the buyer's: " . $customer['client_user_agent'];
        }

        return true;
    },
    $failures,
    $passes
);

pf_run_once_case(
    "Automation moving the order is not mistaken for a bot visitor",
    /** @return bool|string */
    function () {
        pf_forget_visitor();
        $previous_ip                = $_SERVER['REMOTE_ADDR'];
        $previous_ua                = $_SERVER['HTTP_USER_AGENT'] ?? null;
        $_SERVER['REMOTE_ADDR']     = '192.0.2.55';
        // An ERP or a cron script moving orders. Read as the visitor's agent, this
        // matches the bot signatures and would bury a real purchase in a blocked row.
        $_SERVER['HTTP_USER_AGENT'] = 'python-requests/2.31';

        $order = pf_purchasable_order(303);
        $order->update_meta_data('_pf_cookie__pf_consent', pf_consent_cookie('granted'));
        $order->update_meta_data('_pf_client_ip', '198.51.100.7');
        $order->update_meta_data('_pf_client_ua', 'Mozilla/5.0 (buyer)');

        pf_hooks()->pf_purchase_hook(303);

        $_SERVER['REMOTE_ADDR'] = $previous_ip;
        if ($previous_ua === null) {
            unset($_SERVER['HTTP_USER_AGENT']);
        } else {
            $_SERVER['HTTP_USER_AGENT'] = $previous_ua;
        }

        if (pf_posted_blocked_rows() !== []) {
            return 'the purchase was reported as blocked: ' . json_encode(pf_posted_blocked_rows());
        }
        if (count(pf_posted_events()) !== 1) {
            return 'expected exactly one purchase, got ' . count(pf_posted_events());
        }

        return true;
    },
    $failures,
    $passes
);

echo "\n{$passes} passed, " . count($failures) . " failed\n";

exit(count($failures) > 0 ? 1 : 0);
