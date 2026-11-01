<?php
/**
 * The excluded-roles setting on server-side WooCommerce events.
 *
 * A signed-in user with an excluded role produces no event, no blocked report and no hold from
 * their own request. Requests that are not theirs, and the replay of events held earlier, are not
 * affected: the setting is about who is acting now, not about the order.
 *
 * Run: php tests/test-woo-excluded-roles.php
 */

declare(strict_types=1);

require_once __DIR__ . '/bootstrap-wp-stubs.php';

$_SERVER['REMOTE_ADDR'] = '203.0.113.10';

if ( ! defined('WP_CONTENT_DIR')) {
    define('WP_CONTENT_DIR', sys_get_temp_dir() . '/pf-woo-excluded-roles-test');
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

function sanitize_key($key)
{
    return strtolower(preg_replace('/[^a-zA-Z0-9_\-]/', '', (string) $key));
}

function is_ssl(): bool
{
    return false;
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

function wc_get_price_decimals()
{
    return 2;
}

function wc_format_decimal($number, $dp = false, $trim_zeros = false)
{
    return $dp === false ? (string) $number : number_format((float) $number, (int) $dp, '.', '');
}

function wc_get_order($order_id)
{
    return $GLOBALS['__pf_test_orders'][(int) $order_id] ?? false;
}

function wp_remote_post($url, $args = [])
{
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

/** Minimal stand-in for WC_Order, enough to drive pf_purchase_hook(). */
class WC_Order
{
    /** @var array<string, string> */
    public $meta = [];

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

/** In-memory stand-in for the Woo session the hold queue lives in. */
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

require_once dirname(__DIR__) . '/includes/helpers.php';
require_once dirname(__DIR__) . '/includes/consent.php';
require_once dirname(__DIR__) . '/includes/blocked-events.php';
require_once dirname(__DIR__) . '/includes/held-events.php';
require_once dirname(__DIR__) . '/includes/woo/hooks/class-woocommerce-hooks.php';

const PF_SITE = 'wp_0123456789abcdef0123456789abcdef';

function pf_hooks(array $options = ['excluded_user_roles' => ['administrator']]): PixelFlow_WooCommerce_Cart_Hooks
{
    return new PixelFlow_WooCommerce_Cart_Hooks('https://example.test/api', 'k', PF_SITE, $options);
}

function pf_post_event(PixelFlow_WooCommerce_Cart_Hooks $hooks, array $payload, array $context = []): string
{
    $reflection = new ReflectionMethod(PixelFlow_WooCommerce_Cart_Hooks::class, 'post_event');
    $reflection->setAccessible(true);

    return (string) $reflection->invoke($hooks, $payload, $context);
}

function pf_payload(string $event_name): array
{
    return [
        'siteId'    => PF_SITE,
        'eventData' => [
            'event_id'       => 'event-1',
            'eventName'      => $event_name,
            'eventTime'      => 1757000000,
            'customerData'   => [],
            'additionalData' => [
                'currency'    => 'USD',
                'value'       => 25.0,
                'contentType' => 'product',
                'contents'    => [['id' => '4242', 'quantity' => 1, 'item_price' => 25.0]],
            ],
        ],
    ];
}

/** @param string[] $roles */
function pf_sign_in(array $roles): void
{
    $GLOBALS['__pf_user'] = (object) ['roles' => $roles];
}

function pf_consent_cookie(string $state): string
{
    return base64_encode(
        (string) wp_json_encode(['s' => $state, 't' => 1757000000000, 'src' => 'api', 'v' => 1])
    );
}

function pf_grant_consent(): void
{
    $_COOKIE[PIXELFLOW_CONSENT_COOKIE_NAME] = pf_consent_cookie('granted');
}

/** @return array<int, array> Posts to the given endpoint suffix */
function pf_posts_to(string $suffix): array
{
    return array_values(array_filter(
        $GLOBALS['__pf_test_posts'],
        static fn ($post) => substr((string) $post['url'], -strlen($suffix)) === $suffix
    ));
}

/** An order with one reportable line that the current visitor's cookie ties to them. */
function pf_order_owned_by_visitor(int $id): WC_Order
{
    $order        = new WC_Order($id);
    $order->items = [new WC_Order_Item_Product()];
    $order->update_meta_data('_pf_cookie__pf_uid', 'visitor-1');
    $order->update_meta_data('_pf_cookie__pf_consent', pf_consent_cookie('granted'));
    $GLOBALS['__pf_test_orders'][$id] = $order;

    $_COOKIE['_pf_uid'] = 'visitor-1';

    return $order;
}

/** What a later request that is not the buyer's looks like: no user, none of the buyer's cookies. */
function pf_forget_request(): void
{
    $GLOBALS['__pf_user'] = null;
    unset($_COOKIE['_pf_uid']);
}

/**
 * @return string|null Null when nothing was sent or reported, otherwise what was
 */
function pf_nothing_left_the_site(): ?string
{
    if (pf_posts_to('/event') !== []) {
        return 'an event was sent';
    }
    if (pf_posts_to('/blocked-events') !== []) {
        return 'a blocked report was sent';
    }

    return null;
}

$failures = [];
$passes   = 0;

/**
 * @param string   $label
 * @param callable $fn
 */
function pf_run_role_case(string $label, callable $fn, array &$failures, int &$passes): void
{
    $_COOKIE = [];
    $_GET    = [];
    $_SERVER['REQUEST_METHOD']  = 'POST';
    $_SERVER['HTTP_USER_AGENT'] = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) Chrome/140.0 Safari/537.36';

    $GLOBALS['__pf_user']         = null;
    $GLOBALS['__pf_test_posts']   = [];
    $GLOBALS['__pf_test_orders']  = [];
    $GLOBALS['__pf_test_options'] = ['pixelflow_debug_log_key' => 'excludedroles'];
    $GLOBALS['__pf_test_wc']      = (object) ['session' => new PixelFlow_Test_Woo_Session()];

    $path = pixelflow_get_debug_log_path();
    if ($path !== '' && file_exists($path)) {
        unlink($path);
    }

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
// The excluded user's own request.
// ---------------------------------------------------------------------

foreach (['AddToCart', 'InitiateCheckout', 'Purchase'] as $event_name) {
    pf_run_role_case(
        "Excluded administrator: {$event_name} from their own request is skipped",
        /** @return bool|string */
        function () use ($event_name) {
            pf_sign_in(['administrator']);
            pf_grant_consent();

            $outcome = pf_post_event(pf_hooks(), pf_payload($event_name), ['allow_live' => true]);

            if ($outcome !== 'skipped') {
                return "expected the skipped outcome, got {$outcome}";
            }

            return pf_nothing_left_the_site() ?? true;
        },
        $failures,
        $passes
    );
}

pf_run_role_case(
    'Excluded administrator with several roles is excluded by any of them',
    /** @return bool|string */
    function () {
        pf_sign_in(['customer', 'administrator']);
        pf_grant_consent();

        return pf_post_event(pf_hooks(), pf_payload('AddToCart')) === 'skipped'
            ? (pf_nothing_left_the_site() ?? true)
            : 'a second role did not count';
    },
    $failures,
    $passes
);

foreach (['AddToCart', 'InitiateCheckout'] as $event_name) {
    pf_run_role_case(
        "Excluded administrator: {$event_name} under an unanswered banner is not held",
        /** @return bool|string */
        function () use ($event_name) {
            pf_sign_in(['administrator']);
            $_COOKIE[PIXELFLOW_NO_CONSENT_DECISION_COOKIE_NAME] = PIXELFLOW_NO_CONSENT_DECISION_COOKIE_VALUE;

            $outcome = pf_post_event(pf_hooks(), pf_payload($event_name));

            if ($outcome !== 'skipped') {
                return "expected the skipped outcome, got {$outcome}";
            }
            if (pixelflow_get_held_woo_events() !== []) {
                return 'the event was queued for later';
            }

            return pf_nothing_left_the_site() ?? true;
        },
        $failures,
        $passes
    );
}

pf_run_role_case(
    'Excluded administrator under declined consent sends no blocked report',
    /** @return bool|string */
    function () {
        pf_sign_in(['administrator']);
        $_COOKIE[PIXELFLOW_CONSENT_COOKIE_NAME] = pf_consent_cookie('denied');

        pf_post_event(pf_hooks(), pf_payload('AddToCart'));

        return pf_nothing_left_the_site() ?? true;
    },
    $failures,
    $passes
);

pf_run_role_case(
    'The skip is recorded in the debug log when debug logging is on',
    /** @return bool|string */
    function () {
        pf_sign_in(['administrator']);
        pf_grant_consent();

        pf_post_event(
            pf_hooks(['excluded_user_roles' => ['administrator'], 'woo_debug_enabled' => 1]),
            pf_payload('AddToCart')
        );

        $path = pixelflow_get_debug_log_path();
        $log  = $path !== '' && file_exists($path) ? (string) file_get_contents($path) : '';

        return strpos($log, 'USER ROLE IS EXCLUDED FROM TRACKING') !== false
            ? true
            : 'the debug log does not record the reason';
    },
    $failures,
    $passes
);

// ---------------------------------------------------------------------
// Who is not affected.
// ---------------------------------------------------------------------

pf_run_role_case(
    'A role that is not excluded still sends',
    /** @return bool|string */
    function () {
        pf_sign_in(['customer']);
        pf_grant_consent();

        $outcome = pf_post_event(pf_hooks(), pf_payload('AddToCart'));

        return $outcome === 'sent' && count(pf_posts_to('/event')) === 1
            ? true
            : "expected one event, got {$outcome}";
    },
    $failures,
    $passes
);

pf_run_role_case(
    'A guest still sends',
    /** @return bool|string */
    function () {
        pf_grant_consent();

        $outcome = pf_post_event(pf_hooks(), pf_payload('AddToCart'));

        return $outcome === 'sent' && count(pf_posts_to('/event')) === 1
            ? true
            : "expected one event, got {$outcome}";
    },
    $failures,
    $passes
);

pf_run_role_case(
    'With no roles excluded, an administrator still sends',
    /** @return bool|string */
    function () {
        pf_sign_in(['administrator']);
        pf_grant_consent();

        $outcome = pf_post_event(pf_hooks([]), pf_payload('AddToCart'));

        return $outcome === 'sent' ? true : "expected one event, got {$outcome}";
    },
    $failures,
    $passes
);

pf_run_role_case(
    'A request that is not the buyer\'s is not affected by the signed-in user\'s role',
    /** @return bool|string */
    function () {
        pf_sign_in(['administrator']);
        pf_grant_consent();

        $outcome = pf_post_event(pf_hooks(), pf_payload('Purchase'), ['allow_live' => false]);

        return $outcome === 'sent' && count(pf_posts_to('/event')) === 1
            ? true
            : "expected one event, got {$outcome}";
    },
    $failures,
    $passes
);

pf_run_role_case(
    'Events held earlier are replayed on an excluded user\'s request',
    /** @return bool|string */
    function () {
        pixelflow_enqueue_held_woo_event(
            pixelflow_held_event_recipe_from_payload(pf_payload('AddToCart'), 4242, 0)
        );
        pf_sign_in(['administrator']);
        pf_grant_consent();

        pf_hooks()->resolve_held_events_on_page_view();

        if (count(pf_posts_to('/event')) !== 1) {
            return 'the held event was not replayed';
        }

        return pixelflow_get_held_woo_events() === [] ? true : 'the queue was not emptied';
    },
    $failures,
    $passes
);

// ---------------------------------------------------------------------
// Purchase, through the whole hook.
// ---------------------------------------------------------------------

pf_run_role_case(
    'An excluded buyer\'s own status change sends nothing and leaves the order undelivered',
    /** @return bool|string */
    function () {
        $order = pf_order_owned_by_visitor(101);
        pf_sign_in(['administrator']);
        pf_grant_consent();

        pf_hooks()->pf_purchase_hook(101);

        $leaked = pf_nothing_left_the_site();
        if ($leaked !== null) {
            return $leaked;
        }
        if ((string) $order->get_meta('_pf_purchase_sent', true) !== '') {
            return 'the order was recorded as delivered';
        }
        if (array_key_exists('pixelflow_purchase_claim_101', $GLOBALS['__pf_test_options'])) {
            return 'the delivery claim was not released';
        }

        return true;
    },
    $failures,
    $passes
);

pf_run_role_case(
    'A later webhook sends the Purchase for an order skipped earlier',
    /** @return bool|string */
    function () {
        $order = pf_order_owned_by_visitor(102);
        pf_sign_in(['administrator']);
        pf_grant_consent();
        pf_hooks()->pf_purchase_hook(102);
        if (pf_posts_to('/event') !== []) {
            return 'the first request should have sent nothing';
        }

        pf_forget_request();
        pf_hooks()->pf_purchase_hook(102);

        $events = pf_posts_to('/event');
        if (count($events) !== 1 || ($events[0]['payload']['eventData']['eventName'] ?? '') !== 'Purchase') {
            return 'expected one Purchase from the later request, got ' . count($events);
        }

        return (string) $order->get_meta('_pf_purchase_sent', true) === '1'
            ? true
            : 'the order was not recorded as delivered';
    },
    $failures,
    $passes
);

pf_run_role_case(
    'Excluded staff completing another customer\'s order still send its Purchase',
    /** @return bool|string */
    function () {
        $order = pf_order_owned_by_visitor(103);
        pf_forget_request();
        pf_sign_in(['administrator']);

        pf_hooks()->pf_purchase_hook(103);

        $events = pf_posts_to('/event');
        if (count($events) !== 1 || ($events[0]['payload']['eventData']['eventName'] ?? '') !== 'Purchase') {
            return 'expected one Purchase, got ' . count($events);
        }

        return (string) $order->get_meta('_pf_purchase_sent', true) === '1'
            ? true
            : 'the order was not recorded as delivered';
    },
    $failures,
    $passes
);

echo sprintf("\nExcluded roles on WooCommerce events: %d passed, %d failed\n", $passes, count($failures));

exit($failures === [] ? 0 : 1);
