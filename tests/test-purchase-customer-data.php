<?php
/**
 * The shape of a Purchase payload's customerData, and where its fbc comes from.
 *
 * `build_customer_data_from_order()` can now return nothing at all — a staff-created phone order
 * with no billing details, reported by a request that is not the buyer's — and an empty PHP array
 * serialises as `[]`, not `{}`. AddToCart and InitiateCheckout already set the key only when they
 * have something to put in it, and the ingest accepts an event without it.
 *
 * Run: php tests/test-purchase-customer-data.php
 */

declare(strict_types=1);

require_once __DIR__ . '/bootstrap-wp-stubs.php';

$_SERVER['REMOTE_ADDR'] = '203.0.113.10';

if ( ! defined('WP_CONTENT_DIR')) {
    define('WP_CONTENT_DIR', sys_get_temp_dir() . '/pf-purchase-customer-data-test');
}
if ( ! is_dir(WP_CONTENT_DIR)) {
    mkdir(WP_CONTENT_DIR, 0777, true);
}
if ( ! defined('PIXELFLOW_VERSION')) {
    define('PIXELFLOW_VERSION', '0.0.0-test');
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
    if (array_key_exists($option, $GLOBALS['__pf_test_options'])) {
        return false;
    }
    $GLOBALS['__pf_test_options'][$option] = $value;

    return true;
}

function delete_option($option)
{
    unset($GLOBALS['__pf_test_options'][$option]);

    return true;
}

function wp_schedule_single_event($timestamp, $hook, $args = [])
{
    return true;
}

function wp_next_scheduled($hook, $args = [])
{
    return false;
}

function wp_clear_scheduled_hook($hook, $args = [])
{
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
    $GLOBALS['__pf_test_posts'][] = [
        'url'     => $url,
        'body'    => (string) ($args['body'] ?? ''),
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

/** Stand-in for WC_Order: a phone order has no billing details of its own. */
class WC_Order
{
    /** @var array<string, string> */
    public $meta = [];

    /** @var array<string, string> */
    public $billing = [];

    /** @var array<int, WC_Order_Item_Product> */
    public $items = [];

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
        return $this->billing['email'] ?? '';
    }

    public function get_billing_phone()
    {
        return $this->billing['phone'] ?? '';
    }

    public function get_billing_first_name()
    {
        return $this->billing['first_name'] ?? '';
    }

    public function get_billing_last_name()
    {
        return $this->billing['last_name'] ?? '';
    }

    public function get_billing_city()
    {
        return $this->billing['city'] ?? '';
    }

    public function get_billing_state()
    {
        return $this->billing['state'] ?? '';
    }

    public function get_billing_postcode()
    {
        return $this->billing['postcode'] ?? '';
    }

    public function get_billing_country()
    {
        return $this->billing['country'] ?? '';
    }

    public function get_checkout_order_received_url()
    {
        return 'https://example.test/order-received/' . $this->id;
    }
}

class WC_Product
{
    public function get_sku()
    {
        return 'PF-TEST';
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
    public function get_product()
    {
        return new WC_Product();
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

function get_current_user_id()
{
    return 0;
}

function wc_get_order($order_id)
{
    return $GLOBALS['__pf_test_orders'][(int) $order_id] ?? false;
}

require_once dirname(__DIR__) . '/includes/helpers.php';
require_once dirname(__DIR__) . '/includes/consent.php';
require_once dirname(__DIR__) . '/includes/blocked-events.php';
require_once dirname(__DIR__) . '/includes/held-events.php';
require_once dirname(__DIR__) . '/includes/woo/hooks/class-woocommerce-hooks.php';

const PF_SITE = 'wp_0123456789abcdef0123456789abcdef';

function pf_hooks(): PixelFlow_WooCommerce_Cart_Hooks
{
    return new PixelFlow_WooCommerce_Cart_Hooks('https://example.test/api', 'k', PF_SITE, []);
}

/** Registers an order with one reportable line and no billing details. */
function pf_phone_order(int $id): WC_Order
{
    $order        = new WC_Order($id);
    $order->items = [new WC_Order_Item_Product()];
    $GLOBALS['__pf_test_orders'][$id] = $order;

    return $order;
}

/** The last event that reached /event, or null. */
function pf_last_event(): ?array
{
    foreach (array_reverse($GLOBALS['__pf_test_posts']) as $post) {
        if (substr((string) $post['url'], -6) === '/event') {
            return $post;
        }
    }

    return null;
}

$failures = [];
$passes   = 0;

/**
 * @param string   $label
 * @param callable $fn
 */
function pf_run_customer_data_case(string $label, callable $fn, array &$failures, int &$passes): void
{
    $_COOKIE = [];
    $_GET    = [];
    $_SERVER['REQUEST_METHOD'] = 'GET';
    unset($_SERVER['HTTP_USER_AGENT']);

    $GLOBALS['__pf_test_posts']   = [];
    $GLOBALS['__pf_test_filters'] = [];
    $GLOBALS['__pf_test_options'] = [];
    $GLOBALS['__pf_test_orders']  = [];

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
// customerData is omitted rather than serialised as a JSON array.
// ---------------------------------------------------------------------

pf_run_customer_data_case(
    'A staff-created order with nothing to identify the buyer omits customerData',
    /** @return bool|string */
    function () {
        // Marked processing from wp-admin: no billing details, no saved request of the buyer's
        // own, and no visitor id to derive an external_id from.
        pf_phone_order(401);
        pf_hooks()->pf_purchase_hook(401);

        $event = pf_last_event();
        if ($event === null) {
            return 'no Purchase was sent';
        }
        if (strpos((string) $event['body'], '"customerData":[]') !== false) {
            return 'customerData serialised as a JSON array: ' . $event['body'];
        }

        return array_key_exists('customerData', $event['payload']['eventData'])
            ? 'customerData was sent empty: ' . $event['body']
            : true;
    },
    $failures,
    $passes
);

pf_run_customer_data_case(
    'An order with billing details still carries customerData',
    /** @return bool|string */
    function () {
        $order = pf_phone_order(402);
        $order->billing['email'] = 'buyer@example.test';

        pf_hooks()->pf_purchase_hook(402);

        $event = pf_last_event();
        if ($event === null) {
            return 'no Purchase was sent';
        }

        return ! empty($event['payload']['eventData']['customerData']['em'])
            ? true
            : 'the hashed email did not reach the payload: ' . $event['body'];
    },
    $failures,
    $passes
);

// ---------------------------------------------------------------------
// fbc: order meta first, the buyer's own live cookie second.
// ---------------------------------------------------------------------

pf_run_customer_data_case(
    'fbc saved on the order at creation reaches the Purchase',
    /** @return bool|string */
    function () {
        $order = pf_phone_order(403);
        $order->meta['_pf_cookie__fbc'] = 'fb.1.1757000000.saved';

        pf_hooks()->pf_purchase_hook(403);

        $event = pf_last_event();

        return ($event['payload']['eventData']['fbc'] ?? null) === 'fb.1.1757000000.saved'
            ? true
            : 'fbc did not reach the payload: ' . ($event['body'] ?? 'no event');
    },
    $failures,
    $passes
);

pf_run_customer_data_case(
    'The buyer\'s live fbc cookie reaches the Purchase when the request is theirs',
    /** @return bool|string */
    function () {
        $order = pf_phone_order(404);
        // The visitor id ties this request to the order, which is what allows its cookies to
        // speak for the buyer.
        $order->meta['_pf_cookie__pf_uid'] = '1757000000.123';
        $_COOKIE['_pf_uid'] = '1757000000.123';
        $_COOKIE['_fbc']    = 'fb.1.1757000000.live';

        pf_hooks()->pf_purchase_hook(404);

        $event = pf_last_event();

        return ($event['payload']['eventData']['fbc'] ?? null) === 'fb.1.1757000000.live'
            ? true
            : 'fbc did not reach the payload: ' . ($event['body'] ?? 'no event');
    },
    $failures,
    $passes
);

pf_run_customer_data_case(
    'A stranger\'s fbc cookie stays off the Purchase',
    /** @return bool|string */
    function () {
        pf_phone_order(405);
        $_COOKIE['_fbc'] = 'fb.1.1757000000.stranger';

        pf_hooks()->pf_purchase_hook(405);

        $event = pf_last_event();

        return isset($event['payload']['eventData']['fbc'])
            ? 'someone else\'s cookie was attributed to the buyer: ' . $event['body']
            : true;
    },
    $failures,
    $passes
);

echo "\n{$passes} passed, " . count($failures) . " failed\n";

exit(count($failures) > 0 ? 1 : 0);
