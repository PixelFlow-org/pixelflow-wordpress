<?php
/**
 * Which outcomes may close the shopper's dedupe window and per-cart guard.
 *
 * Both are committed before the send outcome is known, so anything that did not settle the event
 * used to spend the window the shopper's own request needs: a prefetched `?add-to-cart=` link
 * swallowed the real click's AddToCart, and a speculative prefetch of /checkout closed the
 * per-cart guard so the real InitiateCheckout was never sent for that cart. The guard is keyed on
 * the cart fingerprint and nothing reopens it, so the same holds for a send that never left the
 * site at all: a missing credential, a failed request, a private client IP.
 *
 * Three outcomes settle the event and must keep consuming that state exactly as before: a
 * delivered event, one parked for a pending decision, and one withheld by the decision the
 * shopper made. That is what stops a recipe being queued on every page view and a
 * `/blocked-events` beacon on every request.
 *
 * Run: php tests/test-automation-dedupe-release.php
 */

declare(strict_types=1);

require_once __DIR__ . '/bootstrap-wp-stubs.php';

$_SERVER['REMOTE_ADDR'] = '203.0.113.10';

if ( ! defined('WP_CONTENT_DIR')) {
    define('WP_CONTENT_DIR', sys_get_temp_dir() . '/pf-dedupe-release-test');
}
if ( ! is_dir(WP_CONTENT_DIR)) {
    mkdir(WP_CONTENT_DIR, 0777, true);
}
if ( ! defined('PIXELFLOW_VERSION')) {
    define('PIXELFLOW_VERSION', '0.0.0-test');
}
if ( ! defined('COOKIEPATH')) {
    define('COOKIEPATH', '/');
}

$GLOBALS['__pf_test_options'] = [];
$GLOBALS['__pf_test_posts']   = [];

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
    public function get_error_message()
    {
        return 'transport failure';
    }
}

class WC_Order
{
}

class WC_Order_Item_Product
{
}

/** Minimal stand-in for WC_Product, enough for the AddToCart and cart payloads. */
class WC_Product
{
    public function get_id()
    {
        return 4242;
    }

    public function get_name()
    {
        return 'PF Test Product';
    }

    public function get_sku()
    {
        return 'PF-TEST';
    }

    public function get_price()
    {
        return 25.0;
    }
}

/** In-memory Woo session: the cross-request store the dedupe and the guard live in. */
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

/** One-line cart, enough for InitiateCheckout and for the guard fingerprint. */
class PixelFlow_Test_Cart
{
    public function is_empty(): bool
    {
        return false;
    }

    public function get_cart(): array
    {
        return [
            'line-key' => [
                'product_id'   => 4242,
                'variation_id' => 0,
                'quantity'     => 1,
                'line_total'   => 25.0,
                'data'         => new WC_Product(),
            ],
        ];
    }

    public function get_cart_item($key)
    {
        return $this->get_cart()['line-key'];
    }

    public function get_cart_hash(): string
    {
        return 'cart-hash';
    }

    public function get_applied_coupons(): array
    {
        return [];
    }

    public function get_cart_contents_total(): float
    {
        return 25.0;
    }
}

function WC()
{
    return $GLOBALS['__pf_test_wc'];
}

function esc_url_raw($url, $protocols = null)
{
    return $url;
}

function home_url($path = '')
{
    return 'https://example.test' . $path;
}

function get_current_user_id()
{
    return 0;
}

function wp_parse_url($url, $component = -1)
{
    return $component === -1 ? parse_url($url) : parse_url($url, $component);
}

function wc_get_price_decimals()
{
    return 2;
}

function wc_format_decimal($number, $dp = false, $trim_zeros = false)
{
    return $dp === false ? (string) $number : number_format((float) $number, (int) $dp, '.', '');
}

function wc_get_price_to_display($product, $args = [])
{
    return 25.0;
}

function get_woocommerce_currency()
{
    return 'USD';
}

require_once dirname(__DIR__) . '/includes/helpers.php';
require_once dirname(__DIR__) . '/includes/consent.php';
require_once dirname(__DIR__) . '/includes/blocked-events.php';
require_once dirname(__DIR__) . '/includes/held-events.php';
require_once dirname(__DIR__) . '/includes/woo/hooks/class-woocommerce-hooks.php';

const PF_SITE    = 'wp_0123456789abcdef0123456789abcdef';
const PF_BROWSER = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/140.0 Safari/537.36';

/**
 * A fresh hooks instance, standing for a new request: the in-request guards start empty
 * while the Woo session carries over, which is exactly the state this file is about.
 *
 * @return PixelFlow_WooCommerce_Cart_Hooks
 */
function pf_request(): PixelFlow_WooCommerce_Cart_Hooks
{
    return new PixelFlow_WooCommerce_Cart_Hooks(
        'https://example.test/api',
        'k',
        PF_SITE,
        ['woo_debug_enabled' => 1]
    );
}

/** The same request on a site whose credentials are missing: nothing can leave it. */
function pf_unconfigured_request(): PixelFlow_WooCommerce_Cart_Hooks
{
    return new PixelFlow_WooCommerce_Cart_Hooks(
        'https://example.test/api',
        '',
        '',
        ['woo_debug_enabled' => 1]
    );
}

/** Events that reached /event, by name. */
function pf_sent_events(): array
{
    $names = [];
    foreach ($GLOBALS['__pf_test_posts'] as $post) {
        if (substr((string) $post['url'], -6) === '/event') {
            $names[] = (string) ($post['payload']['eventData']['eventName'] ?? '');
        }
    }

    return $names;
}

/** Rows that reached /blocked-events. */
function pf_blocked_rows(): array
{
    $rows = [];
    foreach ($GLOBALS['__pf_test_posts'] as $post) {
        if (substr((string) $post['url'], -15) === '/blocked-events') {
            $rows[] = $post['payload']['blocked'][0] ?? [];
        }
    }

    return $rows;
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

$failures = [];
$passes   = 0;

/**
 * @param string   $label
 * @param callable $fn
 */
function pf_run_dedupe_case(string $label, callable $fn, array &$failures, int &$passes): void
{
    $_COOKIE = [];
    $_GET    = [];
    unset($_SERVER['HTTP_SEC_PURPOSE'], $_SERVER['HTTP_PURPOSE'], $_SERVER['HTTP_X_PURPOSE']);
    $_SERVER['REQUEST_METHOD']  = 'POST';
    $_SERVER['HTTP_USER_AGENT'] = PF_BROWSER;

    $_SERVER['REMOTE_ADDR'] = '203.0.113.10';

    $GLOBALS['__pf_test_posts']           = [];
    $GLOBALS['__pf_test_transport_fails'] = false;
    $GLOBALS['__pf_test_filters']         = [];
    $GLOBALS['__pf_test_options']  = ['pixelflow_debug_log_key' => 'dedupe'];
    $GLOBALS['__pf_test_products'] = [4242 => new WC_Product()];
    $GLOBALS['__pf_test_wc']       = (object) [
        'session' => new PixelFlow_Test_Woo_Session(),
        'cart'    => new PixelFlow_Test_Cart(),
    ];

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
// AddToCart.
// ---------------------------------------------------------------------

pf_run_dedupe_case(
    'A prefetched add-to-cart leaves the dedupe window to the shopper\'s own click',
    /** @return bool|string */
    function () {
        // The browser speculatively follows the `?add-to-cart=` link; the shopper then clicks it
        // for real, well inside the five-second window.
        $_SERVER['HTTP_SEC_PURPOSE'] = 'prefetch';
        pf_request()->pf_add_to_cart_hook('line-key', 4242, 1);

        if (pf_sent_events() !== []) {
            return 'the prefetch itself produced an event';
        }

        unset($_SERVER['HTTP_SEC_PURPOSE']);
        pf_request()->pf_add_to_cart_hook('line-key', 4242, 1);

        return pf_sent_events() === ['AddToCart']
            ? true
            : 'the shopper\'s AddToCart was swallowed by the prefetch: ' . json_encode(pf_sent_events());
    },
    $failures,
    $passes
);

pf_run_dedupe_case(
    'A cookieless add-to-cart leaves the dedupe window to the shopper\'s own click',
    /** @return bool|string */
    function () {
        // Same shape through the other automation rule: a crawler follows the link, the shopper
        // (who does carry the visitor cookie) clicks it a moment later.
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_GET['add-to-cart']       = '4242';
        pf_request()->pf_add_to_cart_hook('line-key', 4242, 1);

        if (pf_sent_events() !== []) {
            return 'the crawler itself produced an event';
        }

        $_COOKIE['_pf_uid'] = '1757000000.123';
        pf_request()->pf_add_to_cart_hook('line-key', 4242, 1);

        return pf_sent_events() === ['AddToCart']
            ? true
            : 'the shopper\'s AddToCart was swallowed by the crawler: ' . json_encode(pf_sent_events());
    },
    $failures,
    $passes
);

pf_run_dedupe_case(
    'The quantity-update hook releases the shared window too',
    /** @return bool|string */
    function () {
        // For a product already in the cart WooCommerce updates the quantity instead of adding a
        // line, so the prefetch lands on this hook and burns the key the other one shares.
        $_SERVER['HTTP_SEC_PURPOSE'] = 'prefetch';
        pf_request()->pf_cart_item_quantity_update_hook('line-key', 2, 1, new PixelFlow_Test_Cart());

        if (pf_sent_events() !== []) {
            return 'the prefetch itself produced an event';
        }

        unset($_SERVER['HTTP_SEC_PURPOSE']);
        pf_request()->pf_add_to_cart_hook('line-key', 4242, 1);

        return pf_sent_events() === ['AddToCart']
            ? true
            : 'the shopper\'s AddToCart was swallowed by the prefetch: ' . json_encode(pf_sent_events());
    },
    $failures,
    $passes
);

pf_run_dedupe_case(
    'A pending decision keeps consuming the dedupe window, so one hold is queued, not one per request',
    /** @return bool|string */
    function () {
        $_COOKIE['_pf_no_consent_decision'] = 'true';

        pf_request()->pf_add_to_cart_hook('line-key', 4242, 1);
        pf_request()->pf_add_to_cart_hook('line-key', 4242, 1);

        $queue = pixelflow_get_held_woo_events();
        if (count($queue) !== 1) {
            return 'the hold was queued ' . count($queue) . ' times';
        }

        return pf_sent_events() === [] ? true : 'an event was sent while the decision was pending';
    },
    $failures,
    $passes
);

pf_run_dedupe_case(
    'A declined decision keeps consuming the dedupe window, so one beacon is sent, not one per request',
    /** @return bool|string */
    function () {
        $_COOKIE['_pf_consent'] = pf_consent_cookie('denied');

        pf_request()->pf_add_to_cart_hook('line-key', 4242, 1);
        pf_request()->pf_add_to_cart_hook('line-key', 4242, 1);

        $rows = pf_blocked_rows();
        if (count($rows) !== 1 || ($rows[0]['reason'] ?? null) !== 'denied') {
            return 'unexpected blocked rows: ' . json_encode($rows);
        }

        return true;
    },
    $failures,
    $passes
);

pf_run_dedupe_case(
    'One request still produces at most one AddToCart, automation classification included',
    /** @return bool|string */
    function () {
        // The in-request guard is committed at check time and must stay committed: classic
        // WooCommerce fires both add-to-cart hooks in a single request.
        $_SERVER['HTTP_SEC_PURPOSE'] = 'prefetch';
        $request = pf_request();
        $request->pf_add_to_cart_hook('line-key', 4242, 1);
        $request->pf_cart_item_quantity_update_hook('line-key', 2, 1, new PixelFlow_Test_Cart());

        $rows = pf_blocked_rows();

        return count($rows) === 1
            ? true
            : 'one request produced ' . count($rows) . ' blocked rows: ' . json_encode($rows);
    },
    $failures,
    $passes
);

// ---------------------------------------------------------------------
// InitiateCheckout.
// ---------------------------------------------------------------------

pf_run_dedupe_case(
    'A prefetched checkout leaves the per-cart guard to the shopper\'s own click',
    /** @return bool|string */
    function () {
        $_SERVER['HTTP_SEC_PURPOSE'] = 'prefetch';
        pf_request()->pf_initiate_checkout_hook();

        if (pf_sent_events() !== []) {
            return 'the prefetch itself produced an event';
        }
        if ( ! empty(WC()->session->get('pf_last_checkout_guard_key'))) {
            return 'the prefetch closed the guard for this cart';
        }

        unset($_SERVER['HTTP_SEC_PURPOSE']);
        pf_request()->pf_initiate_checkout_hook();

        return pf_sent_events() === ['InitiateCheckout']
            ? true
            : 'the shopper\'s InitiateCheckout was never sent for this cart: ' . json_encode(pf_sent_events());
    },
    $failures,
    $passes
);

pf_run_dedupe_case(
    'A pending decision still closes the guard, so one hold is queued for the cart',
    /** @return bool|string */
    function () {
        $_COOKIE['_pf_no_consent_decision'] = 'true';

        pf_request()->pf_initiate_checkout_hook();
        if (empty(WC()->session->get('pf_last_checkout_guard_key'))) {
            return 'a consent hold stopped closing the guard';
        }

        pf_request()->pf_initiate_checkout_hook();

        $queue = pixelflow_get_held_woo_events();

        return count($queue) === 1 ? true : 'the hold was queued ' . count($queue) . ' times';
    },
    $failures,
    $passes
);

pf_run_dedupe_case(
    'A genuine InitiateCheckout still closes the guard for the cart',
    /** @return bool|string */
    function () {
        pf_request()->pf_initiate_checkout_hook();
        pf_request()->pf_initiate_checkout_hook();

        return pf_sent_events() === ['InitiateCheckout']
            ? true
            : 'the cart reported InitiateCheckout more than once: ' . json_encode(pf_sent_events());
    },
    $failures,
    $passes
);

// ---------------------------------------------------------------------
// Sends that never left the site settle nothing.
// ---------------------------------------------------------------------

pf_run_dedupe_case(
    'A checkout reached while the credentials are missing still reports once they are back',
    /** @return bool|string */
    function () {
        // An API key being rotated: the shopper reaches the checkout during the window. The guard
        // is keyed on the cart fingerprint and nothing reopens it, so closing it here would lose
        // this cart's InitiateCheckout for good.
        pf_unconfigured_request()->pf_initiate_checkout_hook();

        if (pf_sent_events() !== []) {
            return 'an unconfigured site sent an event';
        }
        if ( ! empty(WC()->session->get('pf_last_checkout_guard_key'))) {
            return 'a send that never left the site closed the guard for this cart';
        }

        pf_request()->pf_initiate_checkout_hook();

        return pf_sent_events() === ['InitiateCheckout']
            ? true
            : 'the cart never reported InitiateCheckout: ' . json_encode(pf_sent_events());
    },
    $failures,
    $passes
);

pf_run_dedupe_case(
    'A checkout whose request failed in transit still reports on the shopper\'s next load',
    /** @return bool|string */
    function () {
        $GLOBALS['__pf_test_transport_fails'] = true;
        pf_request()->pf_initiate_checkout_hook();

        if ( ! empty(WC()->session->get('pf_last_checkout_guard_key'))) {
            return 'a failed request closed the guard for this cart';
        }

        $GLOBALS['__pf_test_transport_fails'] = false;
        pf_request()->pf_initiate_checkout_hook();

        return pf_sent_events() === ['InitiateCheckout']
            ? true
            : 'the cart never reported InitiateCheckout: ' . json_encode(pf_sent_events());
    },
    $failures,
    $passes
);

pf_run_dedupe_case(
    'An add-to-cart made while the credentials are missing leaves its dedupe window open',
    /** @return bool|string */
    function () {
        pf_unconfigured_request()->pf_add_to_cart_hook('line-key', 4242, 1);

        if (pf_sent_events() !== []) {
            return 'an unconfigured site sent an event';
        }

        pf_request()->pf_add_to_cart_hook('line-key', 4242, 1);

        return pf_sent_events() === ['AddToCart']
            ? true
            : 'the shopper\'s AddToCart was swallowed by a send that never happened: '
                . json_encode(pf_sent_events());
    },
    $failures,
    $passes
);

pf_run_dedupe_case(
    'An add-to-cart skipped for a private client IP leaves its dedupe window open',
    /** @return bool|string */
    function () {
        // The third silent skip: nothing about the shopper was decided, so the window is not
        // this request's to spend.
        $_SERVER['REMOTE_ADDR'] = '192.168.1.10';
        pf_request()->pf_add_to_cart_hook('line-key', 4242, 1);

        if (pf_sent_events() !== []) {
            return 'an event was sent for a private client IP';
        }

        $_SERVER['REMOTE_ADDR'] = '203.0.113.10';
        pf_request()->pf_add_to_cart_hook('line-key', 4242, 1);

        return pf_sent_events() === ['AddToCart']
            ? true
            : 'the window was spent by a skipped send: ' . json_encode(pf_sent_events());
    },
    $failures,
    $passes
);

echo "\n{$passes} passed, " . count($failures) . " failed\n";

exit(count($failures) > 0 ? 1 : 0);
