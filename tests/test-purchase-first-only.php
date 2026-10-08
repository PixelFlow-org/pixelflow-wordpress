<?php
/**
 * "Only the customer's first purchase": the previous-order check behind Purchase.
 *
 * `wc_get_orders()` is an in-memory stand-in that honours the arguments the check passes —
 * customer, status, type, exclude, date_created, limit, return, meta_query and field_query —
 * with the two legacy-storage behaviours the check depends on, both read in WooCommerce's
 * source and observed on the live test site: a caller's meta_query is dropped (only the
 * `woocommerce_order_data_store_cpt_get_orders_query` filter can add clauses), and the
 * customer clause is appended to the top level of meta_query, so a top-level OR there would
 * match other customers. The real SQL is exercised by the live suite on both storages.
 *
 * Run: php tests/test-purchase-first-only.php
 */

declare(strict_types=1);

require_once __DIR__ . '/bootstrap-wp-stubs.php';

$_SERVER['REMOTE_ADDR'] = '203.0.113.10';

if ( ! defined('WP_CONTENT_DIR')) {
    define('WP_CONTENT_DIR', sys_get_temp_dir() . '/pf-first-purchase-test');
}
if ( ! is_dir(WP_CONTENT_DIR)) {
    mkdir(WP_CONTENT_DIR, 0777, true);
}
if ( ! defined('PIXELFLOW_VERSION')) {
    define('PIXELFLOW_VERSION', '0.0.0-test');
}
if ( ! defined('DAY_IN_SECONDS')) {
    define('DAY_IN_SECONDS', 86400);
}

$GLOBALS['__pf_test_options'] = [];
$GLOBALS['__pf_test_schedule'] = [];
$GLOBALS['__pf_test_blocked'] = [];
$GLOBALS['__pf_test_now'] = time();
$GLOBALS['__pf_test_orders'] = [];
$GLOBALS['__pf_test_queries'] = [];
$GLOBALS['__pf_test_hpos'] = false;

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

function is_email($email)
{
    return filter_var($email, FILTER_VALIDATE_EMAIL) !== false ? $email : false;
}

function has_filter($tag, $callback = false)
{
    return isset($GLOBALS['__pf_test_filter_callbacks'][$tag]) || isset($GLOBALS['__pf_test_filters'][$tag]);
}

function wp_remote_post($url, $args = [])
{
    $GLOBALS['__pf_test_blocked'][] = [
        'url'     => $url,
        'payload' => json_decode((string) ($args['body'] ?? '{}'), true),
    ];

    return ['response' => ['code' => 200]];
}

class WP_Error
{
}

// phpcs:ignore Squiz.PHP.Eval.Discouraged -- a namespaced stand-in in an otherwise global test file
eval('namespace Automattic\WooCommerce\Utilities; class OrderUtil { public static function custom_orders_table_usage_is_enabled(): bool { return ! empty($GLOBALS["__pf_test_hpos"]); } }');

/** Stand-in for WC_Order: identity, status, total, creation time and meta. */
class WC_Order
{
    /** @var array<string, mixed> */
    public $meta = [];

    /** @var array<int, WC_Order_Item_Product> */
    public $items = [];

    public int $id;
    public int $customer_id;
    public string $email;
    public float $total;
    public string $status;
    public int $created;

    public function __construct(int $id, array $props = [])
    {
        $this->id          = $id;
        $this->customer_id = (int) ($props['customer_id'] ?? 0);
        $this->email       = (string) ($props['email'] ?? 'buyer@example.test');
        $this->total       = (float) ($props['total'] ?? 25.0);
        $this->status      = (string) ($props['status'] ?? 'completed');
        $this->created     = (int) ($props['created'] ?? $GLOBALS['__pf_test_now']);
        $this->meta        = $props['meta'] ?? [];
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

    public function get_items($type = 'line_item'): array
    {
        return $this->items;
    }

    public function get_status()
    {
        return $this->status;
    }

    public function get_date_created()
    {
        return new DateTime('@' . $this->created);
    }

    public function get_currency()
    {
        return 'USD';
    }

    public function get_total()
    {
        return $this->total;
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
        return $this->customer_id;
    }

    public function get_billing_email()
    {
        return $this->email;
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

    public function __construct(string $sku = 'PF-TEST')
    {
        $this->sku = $sku;
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
        return 25.0;
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

/** Registers an order with one reportable line. */
function pf_order(int $id, array $props = []): WC_Order
{
    $order        = new WC_Order($id, $props);
    $order->items = [new WC_Order_Item_Product(isset($props['sku']) ? new WC_Product($props['sku']) : null)];
    $GLOBALS['__pf_test_orders'][$id] = $order;

    return $order;
}

/** The value a clause reads from an order, and whether it exists at all. */
function pf_order_field(WC_Order $order, array $clause): array
{
    if (isset($clause['field'])) {
        return [true, $order->total];
    }
    $key = $clause['key'];
    if ($key === '_order_total' && empty($GLOBALS['__pf_test_hpos'])) {
        return [true, (string) $order->total];
    }

    return [array_key_exists($key, $order->meta), $order->meta[$key] ?? null];
}

function pf_match_clause(WC_Order $order, array $clause): bool
{
    if (isset($clause['__customer'])) {
        foreach ($clause['__customer'] as $value) {
            if (is_int($value) && $value === $order->customer_id) {
                return true;
            }
            if (is_string($value) && strtolower($value) === strtolower($order->email)) {
                return true;
            }
        }

        return false;
    }

    if (isset($clause['key']) || isset($clause['field'])) {
        [$exists, $actual] = pf_order_field($order, $clause);
        $compare = strtoupper($clause['compare'] ?? '=');
        if ($compare === 'NOT EXISTS') {
            return ! $exists;
        }
        if ( ! $exists) {
            return false;
        }
        $numeric  = stripos((string) ($clause['type'] ?? ''), 'DECIMAL') === 0;
        $expected = $clause['value'] ?? null;
        if ($numeric) {
            $actual   = (float) $actual;
            $expected = (float) $expected;
        } else {
            $actual   = (string) $actual;
            $expected = (string) $expected;
        }
        switch ($compare) {
            case '>':
                return $actual > $expected;
            case '<=':
                return $actual <= $expected;
            default:
                return $actual === $expected;
        }
    }

    $relation = strtoupper($clause['relation'] ?? 'AND');
    $children = array_filter($clause, static fn ($c, $k) => $k !== 'relation' && is_array($c), ARRAY_FILTER_USE_BOTH);
    if ($children === []) {
        return true;
    }
    foreach ($children as $child) {
        $hit = pf_match_clause($order, $child);
        if ($relation === 'OR' && $hit) {
            return true;
        }
        if ($relation === 'AND' && ! $hit) {
            return false;
        }
    }

    return $relation === 'AND';
}

function wc_get_orders($args = [])
{
    $GLOBALS['__pf_test_queries'][] = $args;

    if (empty($GLOBALS['__pf_test_hpos'])) {
        // As WC_Data_Store_WP::get_wp_query_args() does: a caller's meta_query is dropped, the
        // customer clause joins the top level, and only the CPT query filter can add clauses.
        $wp_query_args = ['meta_query' => []];
        if (isset($args['customer'])) {
            $wp_query_args['meta_query'][] = ['__customer' => (array) $args['customer']];
        }
        foreach ($GLOBALS['__pf_test_added_filters']['woocommerce_order_data_store_cpt_get_orders_query'] ?? [] as $callback) {
            $wp_query_args = $callback($wp_query_args, $args);
        }
        $meta_query = $wp_query_args['meta_query'];
    } else {
        $meta_query = $args['meta_query'] ?? [];
        if (isset($args['customer'])) {
            $meta_query = ['relation' => 'AND', $meta_query, ['__customer' => (array) $args['customer']]];
        }
    }

    $matches = [];
    foreach ($GLOBALS['__pf_test_orders'] as $order) {
        if (isset($args['status']) && ! in_array($order->status, (array) $args['status'], true)) {
            continue;
        }
        if (in_array($order->id, $args['exclude'] ?? [], true)) {
            continue;
        }
        if (isset($args['date_created'])) {
            if ( ! preg_match('/^>=(\d+)$/', (string) $args['date_created'], $m)) {
                throw new RuntimeException('unexpected date_created ' . $args['date_created']);
            }
            if ($order->created < (int) $m[1]) {
                continue;
            }
        }
        if ($meta_query !== [] && ! pf_match_clause($order, $meta_query)) {
            continue;
        }
        if (isset($args['field_query']) && ! pf_match_clause($order, $args['field_query'])) {
            continue;
        }
        $matches[] = $order;
    }

    usort($matches, static fn ($a, $b) => [$b->created, $b->id] <=> [$a->created, $a->id]);
    if (isset($args['limit']) && $args['limit'] > 0) {
        $matches = array_slice($matches, 0, $args['limit']);
    }

    return ($args['return'] ?? '') === 'ids' ? array_map(static fn ($o) => $o->id, $matches) : $matches;
}

require_once dirname(__DIR__) . '/includes/helpers.php';
require_once dirname(__DIR__) . '/includes/consent.php';
require_once dirname(__DIR__) . '/includes/blocked-events.php';
require_once dirname(__DIR__) . '/includes/held-events.php';
require_once dirname(__DIR__) . '/includes/woo/hooks/class-woocommerce-hooks.php';

function pf_call($target, string $method, array $args = [])
{
    $reflection = new ReflectionMethod(PixelFlow_WooCommerce_Cart_Hooks::class, $method);
    $reflection->setAccessible(true);

    return $reflection->invokeArgs($target, $args);
}

/** Hooks with the setting on (and ignore-free on, all time) unless overridden. */
function pf_hooks(array $options = []): PixelFlow_WooCommerce_Cart_Hooks
{
    return new PixelFlow_WooCommerce_Cart_Hooks('https://example.test/api', 'k', 'site', $options + [
        'woo_purchase_first_only'             => 1,
        'woo_purchase_first_only_lookback'    => 'all',
        'woo_purchase_first_only_days'        => 60,
        'woo_purchase_first_only_ignore_free' => 1,
        'woo_debug_enabled'                   => 1,
    ]);
}

function pf_withholds(WC_Order $order, array $options = []): bool
{
    return pf_call(pf_hooks($options), 'first_purchase_withholds', [$order]);
}

function pf_decision(WC_Order $order): string
{
    return (string) $order->get_meta('_pf_purchase_first_only', true);
}

/** The consent cookie the storefront script writes. */
function pf_consent_cookie(string $state): string
{
    return base64_encode((string) json_encode([
        's'   => $state,
        't'   => $GLOBALS['__pf_test_now'],
        'src' => 'complianz',
        'v'   => 1,
    ]));
}

/** Runs the whole Purchase hook as the buyer's own request, with the given consent. */
function pf_purchase_as_buyer(WC_Order $order, array $options = [], string $consent = 'granted'): void
{
    $order->update_meta_data('_pf_cookie__pf_uid', 'visitor-1');
    $_COOKIE['_pf_uid']     = 'visitor-1';
    $_COOKIE['_pf_consent'] = pf_consent_cookie($consent);
    try {
        pf_hooks($options)->pf_purchase_hook($order->id);
    } finally {
        unset($_COOKIE['_pf_uid'], $_COOKIE['_pf_consent']);
    }
}

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

/** Debug-log entries written since the case started. */
function pf_log_entries(): array
{
    $path = pixelflow_get_debug_log_path();
    if ($path === '' || ! file_exists($path)) {
        return [];
    }
    $entries = [];
    foreach (explode("\n---\n", (string) file_get_contents($path)) as $chunk) {
        $decoded = json_decode(trim($chunk), true);
        if (is_array($decoded)) {
            $entries[] = $decoded;
        }
    }

    return $entries;
}

function pf_log_hooks(): array
{
    return array_map(static fn ($e) => $e['hook'] ?? '', pf_log_entries());
}

function pf_days_ago(float $days): int
{
    return (int) ($GLOBALS['__pf_test_now'] - $days * DAY_IN_SECONDS);
}

$failures = [];
$passes   = 0;

function pf_case(string $label, callable $fn, array &$failures, int &$passes): void
{
    $GLOBALS['__pf_test_options']         = ['pixelflow_debug_log_key' => 'firstpurchasetest'];
    $GLOBALS['__pf_test_schedule']        = [];
    $GLOBALS['__pf_test_blocked']         = [];
    $GLOBALS['__pf_test_orders']          = [];
    $GLOBALS['__pf_test_queries']         = [];
    $GLOBALS['__pf_test_hpos']            = false;
    $GLOBALS['__pf_test_filter_callbacks'] = [];
    $GLOBALS['__pf_test_now']             = time();
    $log = pixelflow_get_debug_log_path();
    if ($log !== '' && file_exists($log)) {
        unlink($log);
    }

    foreach ([false, true] as $hpos) {
        $GLOBALS['__pf_test_orders']        = [];
        $GLOBALS['__pf_test_queries']       = [];
        $GLOBALS['__pf_test_blocked']       = [];
        $GLOBALS['__pf_test_schedule']      = [];
        $GLOBALS['__pf_test_added_filters'] = [];
        $GLOBALS['__pf_test_hpos']          = $hpos;
        if ($log !== '' && file_exists($log)) {
            unlink($log);
        }
        $storage = $hpos ? 'HPOS' : 'legacy';

        try {
            $result = $fn();
        } catch (\Throwable $e) {
            $result = sprintf('%s: %s', get_class($e), $e->getMessage());
        }

        if ($result === true) {
            $passes++;
            echo "PASS  [{$storage}] {$label}\n";
        } else {
            $failures[] = "[{$storage}] {$label}: {$result}";
            echo "FAIL  [{$storage}] {$label}\n      {$result}\n";
        }
    }
    $GLOBALS['__pf_test_filter_callbacks'] = [];
}

// ---------------------------------------------------------------------------
// Setting off, Purchase disabled, upgrade
// ---------------------------------------------------------------------------

pf_case('Setting off: a second order sends, with no lookup and no meta', function () {
    pf_order(1, ['created' => pf_days_ago(10)]);
    $second = pf_order(2);

    pf_purchase_as_buyer($second, ['woo_purchase_first_only' => 0]);

    if (count(pf_posted_events()) !== 1) {
        return 'expected one Purchase, got ' . count(pf_posted_events());
    }
    if (pf_decision($second) !== '') {
        return 'no first-purchase meta may be written while the setting is off';
    }
    if ($GLOBALS['__pf_test_queries'] !== []) {
        return 'no order lookup may run while the setting is off';
    }

    return true;
}, $failures, $passes);

pf_case('Upgrade with no saved keys reads as off', function () {
    pf_order(1, ['created' => pf_days_ago(10)]);
    $second = pf_order(2);

    $hooks = new PixelFlow_WooCommerce_Cart_Hooks('https://example.test/api', 'k', 'site', []);
    if (pf_call($hooks, 'first_purchase_withholds', [$second]) !== false) {
        return 'an install without the keys must behave as before';
    }

    return pf_decision($second) === '' ? true : 'meta written with no saved keys';
}, $failures, $passes);

pf_case('Purchase disabled: nothing sent and no lookup', function () {
    pf_order(1, ['created' => pf_days_ago(10)]);
    $second = pf_order(2);

    pf_purchase_as_buyer($second, ['woo_disable_purchase' => 1]);

    if ($GLOBALS['__pf_test_blocked'] !== []) {
        return 'nothing may be posted with Purchase disabled';
    }

    return $GLOBALS['__pf_test_queries'] === [] ? true : 'a lookup ran with Purchase disabled';
}, $failures, $passes);

// ---------------------------------------------------------------------------
// The customer's first order, renewals, the suppressed path end to end
// ---------------------------------------------------------------------------

pf_case('First order sends and is recorded first', function () {
    $first = pf_order(1);

    pf_purchase_as_buyer($first);

    if (count(pf_posted_events()) !== 1) {
        return 'the first order must send Purchase';
    }

    return pf_decision($first) === 'first' ? true : 'decision recorded as ' . pf_decision($first);
}, $failures, $passes);

pf_case('Renewal suppressed: no /event, no /blocked-events, no _pf_purchase_sent, logged', function () {
    pf_order(1, ['created' => pf_days_ago(30)]);
    $renewal = pf_order(2, ['status' => 'processing']);

    pf_purchase_as_buyer($renewal);

    if ($GLOBALS['__pf_test_blocked'] !== []) {
        return 'a suppressed order must post nothing, got ' . count($GLOBALS['__pf_test_blocked']);
    }
    if ((string) $renewal->get_meta('_pf_purchase_sent', true) !== '') {
        return '_pf_purchase_sent must stay unset';
    }
    if (pf_decision($renewal) !== 'skipped:1') {
        return 'decision recorded as ' . pf_decision($renewal);
    }
    $skips = array_values(array_filter(pf_log_entries(), static fn ($e) => ($e['hook'] ?? '') === 'FIRST_PURCHASE_SKIP'));
    if (count($skips) !== 1 || ($skips[0]['payload'] ?? []) !== ['order_id' => 2, 'matched_order_id' => 1]) {
        return 'expected one FIRST_PURCHASE_SKIP entry naming both orders, got ' . json_encode($skips);
    }

    return true;
}, $failures, $passes);

pf_case('Suppressed with consent declined: no blocked row either', function () {
    pf_order(1, ['created' => pf_days_ago(30)]);
    $renewal = pf_order(2);

    pf_purchase_as_buyer($renewal, [], 'denied');

    if (pf_posted_blocked_rows() !== [] || pf_posted_events() !== []) {
        return 'nothing may be posted for a suppressed order';
    }

    return wp_next_scheduled('pixelflow_report_blocked_purchase', [2]) === false
        ? true : 'no blocked report may be scheduled for a suppressed order';
}, $failures, $passes);

pf_case('Without the debug log enabled, a skip writes no entry', function () {
    pf_order(1, ['created' => pf_days_ago(30)]);
    $renewal = pf_order(2);

    pf_withholds($renewal, ['woo_debug_enabled' => 0]);

    return pf_log_entries() === [] ? true : 'log written with debug off';
}, $failures, $passes);

// ---------------------------------------------------------------------------
// Same customer
// ---------------------------------------------------------------------------

pf_case('Same email in a different case and with spaces is the same customer', function () {
    pf_order(1, ['email' => 'buyer@example.test', 'created' => pf_days_ago(5)]);
    $later = pf_order(2, ['email' => ' Buyer@Example.test ']);

    return pf_withholds($later) ? true : 'the later order must be suppressed';
}, $failures, $passes);

pf_case('Same user id with an empty email is the same customer', function () {
    pf_order(1, ['customer_id' => 7, 'email' => 'seven@example.test', 'created' => pf_days_ago(5)]);
    $later = pf_order(2, ['customer_id' => 7, 'email' => '']);

    return pf_withholds($later) ? true : 'the later order must be suppressed';
}, $failures, $passes);

pf_case('An invalid email keeps the user-id match', function () {
    pf_order(1, ['customer_id' => 7, 'email' => 'seven@example.test', 'created' => pf_days_ago(5)]);
    $later = pf_order(2, ['customer_id' => 7, 'email' => 'n/a']);

    if ( ! pf_withholds($later)) {
        return 'the order must be matched by user id';
    }
    $customer = $GLOBALS['__pf_test_queries'][1]['customer'] ?? null;

    return $customer === [7] ? true : 'the invalid email must be left out of the query, got ' . json_encode($customer);
}, $failures, $passes);

pf_case('No identity: sent, recorded first, logged', function () {
    pf_order(1, ['customer_id' => 0, 'email' => '', 'created' => pf_days_ago(5)]);
    $order = pf_order(2, ['customer_id' => 0, 'email' => '']);

    if (pf_withholds($order)) {
        return 'an order with no identity must be sent';
    }
    if (pf_decision($order) !== 'first') {
        return 'decision recorded as ' . pf_decision($order);
    }
    if ( ! in_array('FIRST_PURCHASE_NO_CUSTOMER', pf_log_hooks(), true)) {
        return 'the log must record the missing customer';
    }

    return $GLOBALS['__pf_test_queries'] === [] ? true : 'no lookup may run without an identity';
}, $failures, $passes);

pf_case("Another customer's paid order does not count", function () {
    pf_order(1, ['customer_id' => 9, 'email' => 'other@example.test', 'created' => pf_days_ago(5)]);
    $order = pf_order(2, ['customer_id' => 7, 'email' => 'seven@example.test']);

    return ! pf_withholds($order) ? true : "another customer's order suppressed this one";
}, $failures, $passes);

pf_case("Another customer's order does not count with ignore-free off either", function () {
    pf_order(1, ['customer_id' => 9, 'email' => 'other@example.test', 'created' => pf_days_ago(5)]);
    $order = pf_order(2, ['customer_id' => 7, 'email' => 'seven@example.test']);

    return ! pf_withholds($order, ['woo_purchase_first_only_ignore_free' => 0])
        ? true : "another customer's order suppressed this one";
}, $failures, $passes);

// ---------------------------------------------------------------------------
// Which other orders count
// ---------------------------------------------------------------------------

pf_case('On-hold, pending, failed and cancelled orders do not count', function () {
    $id = 1;
    foreach (['on-hold', 'pending', 'failed', 'cancelled'] as $status) {
        pf_order($id++, ['status' => $status, 'created' => pf_days_ago(5)]);
    }
    $order = pf_order(10);

    return ! pf_withholds($order) ? true : 'an unpaid status counted';
}, $failures, $passes);

pf_case('A refunded order counts', function () {
    pf_order(1, ['status' => 'refunded', 'created' => pf_days_ago(5)]);

    return pf_withholds(pf_order(2)) ? true : 'a refunded order must count';
}, $failures, $passes);

pf_case('A later-created order counts (staff complete an old pending order)', function () {
    $old = pf_order(1, ['status' => 'pending', 'created' => pf_days_ago(3)]);
    pf_order(2, ['status' => 'completed', 'created' => pf_days_ago(2), 'meta' => ['_pf_purchase_first_only' => 'first']]);
    $old->status = 'completed';

    if ( ! pf_withholds($old)) {
        return 'the later paid order must count';
    }

    return pf_decision($old) === 'skipped:2' ? true : 'decision recorded as ' . pf_decision($old);
}, $failures, $passes);

pf_case('An on-hold order sent from the thank-you page does not block a card order, and sends nothing more once paid', function () {
    $transfer = pf_order(1, ['status' => 'on-hold', 'created' => pf_days_ago(3)]);
    pf_purchase_as_buyer($transfer);
    if (count(pf_posted_events()) !== 1 || pf_decision($transfer) !== 'first') {
        return 'the on-hold order must send from the thank-you page and be recorded first';
    }

    $card = pf_order(2, ['status' => 'processing', 'created' => pf_days_ago(2)]);
    pf_purchase_as_buyer($card);
    if (count(pf_posted_events()) !== 2) {
        return 'the card order must send, because an on-hold order does not count';
    }

    $transfer->status = 'processing';
    pf_purchase_as_buyer($transfer);

    return count(pf_posted_events()) === 2 ? true : 'the paid transfer must send nothing more';
}, $failures, $passes);

pf_case('An order of only excluded SKUs still counts', function () {
    pf_order(1, ['sku' => 'PF-EXCLUDED', 'total' => 50, 'created' => pf_days_ago(5)]);

    return pf_withholds(pf_order(2), ['woo_excluded_skus' => ['PF-EXCLUDED']])
        ? true : 'an order that never sent Purchase must still count';
}, $failures, $passes);

// ---------------------------------------------------------------------------
// The window
// ---------------------------------------------------------------------------

pf_case('Window boundary: 60 days minus a minute counts, 61 days does not', function () {
    $days = ['woo_purchase_first_only_lookback' => 'days', 'woo_purchase_first_only_days' => 60];

    pf_order(1, ['created' => pf_days_ago(60) + 60]);
    if ( ! pf_withholds(pf_order(2), $days)) {
        return 'an order inside the 60-day window must count';
    }
    $cutoff = (int) substr((string) ($GLOBALS['__pf_test_queries'][1]['date_created'] ?? ''), 2);
    if (abs($cutoff - (time() - 60 * DAY_IN_SECONDS)) > 5) {
        return 'the query must ask for orders created at or after now - 60 days, got ' . $cutoff;
    }

    $GLOBALS['__pf_test_orders'] = [];
    pf_order(3, ['created' => pf_days_ago(61)]);

    return ! pf_withholds(pf_order(4), $days) ? true : 'an order 61 days old must not count';
}, $failures, $passes);

pf_case('A huge N sends no date clause and behaves as all', function () {
    pf_order(1, ['created' => pf_days_ago(5000)]);
    foreach ([1000000000000, PHP_INT_MAX] as $i => $n) {
        $GLOBALS['__pf_test_queries'] = [];
        $order = pf_order(10 + $i);
        if ( ! pf_withholds($order, ['woo_purchase_first_only_lookback' => 'days', 'woo_purchase_first_only_days' => $n])) {
            return "N = {$n} must count every order";
        }
        foreach ($GLOBALS['__pf_test_queries'] as $query) {
            if (isset($query['date_created'])) {
                return "N = {$n} must not send a date clause";
            }
        }
    }

    return true;
}, $failures, $passes);

// ---------------------------------------------------------------------------
// Amount paid and the filter
// ---------------------------------------------------------------------------

pf_case('Ignore-free on: a free previous order does not count', function () {
    pf_order(1, ['total' => 0, 'created' => pf_days_ago(5)]);

    return ! pf_withholds(pf_order(2)) ? true : 'a free order counted';
}, $failures, $passes);

pf_case('Ignore-free off: a free previous order counts', function () {
    pf_order(1, ['total' => 0, 'created' => pf_days_ago(5)]);

    return pf_withholds(pf_order(2), ['woo_purchase_first_only_ignore_free' => 0]) ? true : 'a free order must count';
}, $failures, $passes);

pf_case('Frisbii: total 0 with _real_total 29 counts as paid', function () {
    pf_order(1, ['total' => 0, 'created' => pf_days_ago(5), 'meta' => ['_real_total' => '29']]);

    return pf_withholds(pf_order(2)) ? true : 'the order paid through _real_total must count';
}, $failures, $passes);

pf_case('Filter returning 0 makes a paid order free', function () {
    pf_order(1, ['total' => 40, 'created' => pf_days_ago(5)]);
    $GLOBALS['__pf_test_filter_callbacks']['pixelflow_order_amount_paid'] = static fn ($amount, $order) => 0;

    return ! pf_withholds(pf_order(2)) ? true : 'the filter must demote the order';
}, $failures, $passes);

pf_case('Filter value forms: "0" and -5 are free, null and "n/a" are ignored', function () {
    pf_order(1, ['total' => 40, 'created' => pf_days_ago(5)]);
    $id = 10;
    foreach (['0' => false, '-5' => false, 'null' => true, 'n/a' => true] as $raw => $counts) {
        $value = ['0' => '0', '-5' => -5, 'null' => null, 'n/a' => 'n/a'][$raw];
        $GLOBALS['__pf_test_filter_callbacks']['pixelflow_order_amount_paid'] = static fn ($amount, $order) => $value;
        if (pf_withholds(pf_order($id++)) !== $counts) {
            return "filter value {$raw}: expected the order " . ($counts ? 'to count' : 'not to count');
        }
    }

    return true;
}, $failures, $passes);

pf_case('Filter returning 15 promotes a total-0 order without _real_total', function () {
    pf_order(1, ['total' => 0, 'created' => pf_days_ago(5)]);
    $GLOBALS['__pf_test_filter_callbacks']['pixelflow_order_amount_paid'] = static fn ($amount, $order) => $order->id === 1 ? 15 : $amount;

    return pf_withholds(pf_order(2)) ? true : 'the filter must promote the order';
}, $failures, $passes);

pf_case('Filter returning 15 promotes a total-0 order whose _real_total is "0"', function () {
    pf_order(1, ['total' => 0, 'created' => pf_days_ago(5), 'meta' => ['_real_total' => '0']]);
    $GLOBALS['__pf_test_filter_callbacks']['pixelflow_order_amount_paid'] = static fn ($amount, $order) => $order->id === 1 ? 15 : $amount;

    return pf_withholds(pf_order(2)) ? true : 'the filter must promote the order';
}, $failures, $passes);

pf_case('No filter hooked: the promotion query is not run', function () {
    pf_order(1, ['total' => 0, 'created' => pf_days_ago(5)]);
    pf_withholds(pf_order(2));

    foreach ($GLOBALS['__pf_test_queries'] as $query) {
        if (strpos(json_encode($query), 'NOT EXISTS') !== false) {
            return 'the promotion query ran without a hooked filter';
        }
    }

    return true;
}, $failures, $passes);

pf_case('25 free orders newer than a paid one still block', function () {
    pf_order(1, ['total' => 40, 'created' => pf_days_ago(100)]);
    for ($i = 0; $i < 25; $i++) {
        pf_order(10 + $i, ['total' => 0, 'created' => pf_days_ago(50 - $i)]);
    }

    return pf_withholds(pf_order(99)) ? true : 'the paid order behind the free ones must count';
}, $failures, $passes);

pf_case('A filter marking 21 paid orders free lets the order send', function () {
    for ($i = 0; $i < 21; $i++) {
        pf_order(10 + $i, ['total' => 40, 'created' => pf_days_ago(50 - $i)]);
    }
    $GLOBALS['__pf_test_filter_callbacks']['pixelflow_order_amount_paid'] = static fn ($amount, $order) => 0;

    return ! pf_withholds(pf_order(99)) ? true : 'past the limit nothing may count';
}, $failures, $passes);

// ---------------------------------------------------------------------------
// The decision is taken once
// ---------------------------------------------------------------------------

pf_case('A skipped order stays skipped after the matching order is cancelled', function () {
    $original = pf_order(1, ['created' => pf_days_ago(30)]);
    $renewal  = pf_order(2, ['status' => 'processing']);
    pf_withholds($renewal);
    $original->status = 'cancelled';
    $renewal->status  = 'completed';
    $GLOBALS['__pf_test_queries'] = [];

    if ( ! pf_withholds($renewal)) {
        return 'the recorded skip must hold';
    }

    return $GLOBALS['__pf_test_queries'] === [] ? true : 'a recorded decision must not look up orders again';
}, $failures, $passes);

pf_case('A first order held for consent still sends after a later order was suppressed because of it', function () {
    // The decision is taken on the first pass; the Purchase itself is not sent yet (as when
    // it waits for the buyer's consent decision).
    $first = pf_order(1, ['created' => pf_days_ago(2)]);
    if (pf_withholds($first) || pf_decision($first) !== 'first') {
        return 'the first order must be recorded first';
    }

    $second = pf_order(2);
    if ( ! pf_withholds($second)) {
        return 'the second order must be suppressed because of the first';
    }

    pf_purchase_as_buyer($first, [], 'granted');

    return count(pf_posted_events()) === 1 ? true : 'the held first order must send once consent is granted';
}, $failures, $passes);

pf_case('A skipped order stays skipped after the setting is turned off', function () {
    pf_order(1, ['created' => pf_days_ago(30)]);
    $renewal = pf_order(2);
    pf_withholds($renewal);

    return pf_withholds($renewal, ['woo_purchase_first_only' => 0]) ? true : 'the skip must hold with the setting off';
}, $failures, $passes);

pf_case('No mutual suppression: X held while the setting was off, Y skipped:X, X then sends', function () {
    $x = pf_order(1, ['created' => pf_days_ago(3)]);
    $y = pf_order(2, ['created' => pf_days_ago(2)]);
    if ( ! pf_withholds($y)) {
        return 'Y must be suppressed because of X';
    }
    if (pf_decision($y) !== 'skipped:1') {
        return 'Y recorded as ' . pf_decision($y);
    }

    if (pf_withholds($x)) {
        return 'X must send: Y was suppressed because of it';
    }

    return pf_decision($x) === 'first' ? true : 'X recorded as ' . pf_decision($x);
}, $failures, $passes);

pf_case('No suppression through a chain: X <- Y <- Z, X then sends', function () {
    $x = pf_order(1, ['created' => pf_days_ago(3)]);
    $y = pf_order(2, ['created' => pf_days_ago(2)]);
    pf_withholds($y);
    $z = pf_order(3, ['created' => pf_days_ago(1)]);
    pf_withholds($z);
    if (pf_decision($y) !== 'skipped:1' || pf_decision($z) !== 'skipped:2') {
        return 'setup: Y ' . pf_decision($y) . ', Z ' . pf_decision($z);
    }

    return ! pf_withholds($x) && pf_decision($x) === 'first' ? true : 'X recorded as ' . pf_decision($x);
}, $failures, $passes);

pf_case('A renewal skipped because of the original still counts for the next renewal inside 60 days', function () {
    $days = ['woo_purchase_first_only_lookback' => 'days', 'woo_purchase_first_only_days' => 60];
    pf_order(1, ['created' => pf_days_ago(90), 'meta' => ['_pf_purchase_first_only' => 'first']]);
    pf_order(2, ['created' => pf_days_ago(30), 'meta' => ['_pf_purchase_first_only' => 'skipped:1']]);
    $next = pf_order(3);

    if ( ! pf_withholds($next, $days)) {
        return 'the earlier renewal must count';
    }

    return pf_decision($next) === 'skipped:2' ? true : 'recorded as ' . pf_decision($next);
}, $failures, $passes);

// ---------------------------------------------------------------------------
// Settings sanitizer (storage-independent; each case runs twice)
// ---------------------------------------------------------------------------

function pf_sanitize(array $input, array $stored): array
{
    return pixelflow_sanitize_first_purchase_options($input, $stored);
}

pf_case('Sanitizer: invalid day counts keep the stored lookback and days', function () {
    $stored = ['woo_purchase_first_only_lookback' => 'days', 'woo_purchase_first_only_days' => 90];
    foreach (['', '0', '-5', '7.5', '7.0', '1e3'] as $raw) {
        $out = pf_sanitize(['woo_purchase_first_only_lookback' => 'days', 'woo_purchase_first_only_days' => $raw], $stored);
        if ($out['woo_purchase_first_only_lookback'] !== 'days' || $out['woo_purchase_first_only_days'] !== 90) {
            return "N {$raw}: got " . json_encode($out);
        }
    }

    return true;
}, $failures, $passes);

pf_case('Sanitizer: valid values are saved; " 007 " reads as 7; huge N is capped', function () {
    $out = pf_sanitize(['woo_purchase_first_only_lookback' => 'days', 'woo_purchase_first_only_days' => ' 007 '], []);
    if ($out['woo_purchase_first_only_lookback'] !== 'days' || $out['woo_purchase_first_only_days'] !== 7) {
        return 'got ' . json_encode($out);
    }
    $out = pf_sanitize(['woo_purchase_first_only_lookback' => 'days', 'woo_purchase_first_only_days' => '99999999999999999999999'], []);

    return $out['woo_purchase_first_only_days'] === PHP_INT_MAX ? true : 'huge N stored as ' . json_encode($out);
}, $failures, $passes);

pf_case('Sanitizer: lookback all with an empty N keeps the stored N (60 when none)', function () {
    $out = pf_sanitize(['woo_purchase_first_only_lookback' => 'all', 'woo_purchase_first_only_days' => ''], ['woo_purchase_first_only_days' => 90]);
    if ($out['woo_purchase_first_only_lookback'] !== 'all' || $out['woo_purchase_first_only_days'] !== 90) {
        return 'got ' . json_encode($out);
    }
    $out = pf_sanitize(['woo_purchase_first_only_lookback' => 'all', 'woo_purchase_first_only_days' => ''], []);

    return $out['woo_purchase_first_only_days'] === 60 ? true : 'got ' . json_encode($out);
}, $failures, $passes);

pf_case('Sanitizer: missing keys, only one key, or an unknown lookback keep the stored window', function () {
    $stored = ['woo_purchase_first_only_lookback' => 'days', 'woo_purchase_first_only_days' => 90];
    $inputs = [
        'no keys'       => [],
        'unknown'       => ['woo_purchase_first_only_lookback' => 'weekly', 'woo_purchase_first_only_days' => '30'],
        'only N'        => ['woo_purchase_first_only_days' => '30'],
        'only lookback' => ['woo_purchase_first_only_lookback' => 'all'],
    ];
    foreach ($inputs as $label => $input) {
        $out = pf_sanitize($input, $stored);
        if ($out['woo_purchase_first_only_lookback'] !== 'days' || $out['woo_purchase_first_only_days'] !== 90) {
            return "{$label}: got " . json_encode($out);
        }
    }
    $out = pf_sanitize([], []);

    return $out['woo_purchase_first_only_lookback'] === 'all' && $out['woo_purchase_first_only_days'] === 60
        ? true : 'defaults with nothing stored: ' . json_encode($out);
}, $failures, $passes);

pf_case('Sanitizer: a save without ignore-free keeps it on, and stores it on when nothing was stored', function () {
    if (pf_sanitize([], ['woo_purchase_first_only_ignore_free' => 1])['woo_purchase_first_only_ignore_free'] !== 1) {
        return 'a stored on must stay on';
    }
    if (pf_sanitize([], ['woo_purchase_first_only_ignore_free' => 0])['woo_purchase_first_only_ignore_free'] !== 0) {
        return 'a stored off must stay off';
    }
    if (pf_sanitize([], [])['woo_purchase_first_only_ignore_free'] !== 1) {
        return 'with nothing stored it must be saved on';
    }

    return pf_sanitize(['woo_purchase_first_only_ignore_free' => '0'], [])['woo_purchase_first_only_ignore_free'] === 0
        ? true : 'an explicit off must be saved';
}, $failures, $passes);

$log = pixelflow_get_debug_log_path();
if ($log !== '' && file_exists($log)) {
    unlink($log);
}

echo "\n" . $passes . ' passed, ' . count($failures) . " failed\n";
exit(count($failures) > 0 ? 1 : 0);
