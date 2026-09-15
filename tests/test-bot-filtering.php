<?php
/**
 * Automation filtering: which requests are classified as automated, under which cause,
 * and whether the site's own debug log says so accurately.
 *
 * Three rules reach the same `bot` reason — the user-agent signature list, the prefetch headers
 * and the cookieless add-to-cart GET — so the tests pin both the precedence between them and the
 * wording of the log line, which used to assert a user-agent match for all three.
 *
 * Run: php tests/test-bot-filtering.php
 */

declare(strict_types=1);

require_once __DIR__ . '/bootstrap-wp-stubs.php';

$_SERVER['REMOTE_ADDR'] = '203.0.113.10';

if ( ! defined('WP_CONTENT_DIR')) {
    define('WP_CONTENT_DIR', sys_get_temp_dir() . '/pf-bot-filtering-test');
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
        return 'error';
    }
}

/** Minimal stand-in for WC_Order. */
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
}

/** Minimal stand-in for WC_Product, enough for build_additional_data(). */
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

/** The cart the quantity-update hook reads the changed line from. */
class PF_Test_Cart
{
    public function get_cart_item($key)
    {
        return ['product_id' => 4242, 'variation_id' => 0, 'quantity' => 2];
    }
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
 * @param object $target
 * @param string $method
 * @param array  $args
 * @return mixed
 */
function pf_call($target, string $method, array $args = [])
{
    $reflection = new ReflectionMethod(PixelFlow_WooCommerce_Cart_Hooks::class, $method);
    $reflection->setAccessible(true);

    return $reflection->invokeArgs($target, $args);
}

/** Hooks instance with the debug log on, so the log wording can be asserted. */
function pf_hooks(): PixelFlow_WooCommerce_Cart_Hooks
{
    return new PixelFlow_WooCommerce_Cart_Hooks(
        'https://example.test/api',
        'k',
        PF_SITE,
        ['woo_debug_enabled' => 1]
    );
}

/**
 * @param string $event_name
 * @return array
 */
function pf_payload(string $event_name): array
{
    return [
        'siteId'    => PF_SITE,
        'eventData' => [
            'eventName'    => $event_name,
            'eventTime'    => 1757000000,
            'customerData' => [],
        ],
    ];
}

/** The contents of the debug log written during the current case. */
function pf_debug_log(): string
{
    $path = pixelflow_get_debug_log_path();

    return $path !== '' && file_exists($path) ? (string) file_get_contents($path) : '';
}

/** The blocked row of the last /blocked-events POST, or null when none was sent. */
function pf_last_blocked_row(): ?array
{
    foreach (array_reverse($GLOBALS['__pf_test_posts']) as $post) {
        if (substr((string) $post['url'], -15) === '/blocked-events') {
            return $post['payload']['blocked'][0] ?? [];
        }
    }

    return null;
}

/** Whether any event reached /event. */
function pf_event_was_sent(): bool
{
    foreach ($GLOBALS['__pf_test_posts'] as $post) {
        if (substr((string) $post['url'], -6) === '/event') {
            return true;
        }
    }

    return false;
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
function pf_run_bot_case(string $label, callable $fn, array &$failures, int &$passes): void
{
    $_COOKIE = [];
    $_GET    = [];
    unset(
        $_SERVER['HTTP_SEC_PURPOSE'],
        $_SERVER['HTTP_PURPOSE'],
        $_SERVER['HTTP_X_PURPOSE'],
        $_SERVER['HTTP_SEC_FETCH_MODE'],
        $_SERVER['HTTP_ACCEPT_LANGUAGE']
    );
    $_SERVER['REQUEST_METHOD']  = 'POST';
    $_SERVER['HTTP_USER_AGENT'] = PF_BROWSER;

    $GLOBALS['__pf_test_posts']   = [];
    $GLOBALS['__pf_test_filters'] = [];
    $GLOBALS['__pf_test_options'] = ['pixelflow_debug_log_key' => 'botfilter'];

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
// The signature list.
// ---------------------------------------------------------------------

foreach (['guzzle', 'httpx', 'aiohttp'] as $signature) {
    pf_run_bot_case(
        "Signature {$signature} suppresses the event and names itself in the log",
        /** @return bool|string */
        function () use ($signature) {
            $_SERVER['HTTP_USER_AGENT'] = 'SomeClient ' . $signature . '/1.2';
            pf_call(pf_hooks(), 'post_event', [pf_payload('AddToCart')]);

            if (pf_event_was_sent()) {
                return 'the event was sent anyway';
            }
            $row = pf_last_blocked_row();
            if (($row['reason'] ?? null) !== 'bot' || ($row['detail'] ?? null) !== $signature) {
                return 'unexpected blocked row: ' . json_encode($row);
            }
            if (strpos(pf_debug_log(), $signature) === false) {
                return 'the debug log does not name the matched signature';
            }

            return true;
        },
        $failures,
        $passes
    );
}

// Meta's crawler family: the two agents seen in production are listed exactly and report
// themselves; anything else under the prefix falls through to the generic catch-all. The list is
// ordered specific-before-general to make that so.
foreach (['meta-externalads', 'meta-externalagent'] as $agent) {
    pf_run_bot_case(
        "Meta crawler {$agent} reports itself, not the prefix that also matches it",
        /** @return bool|string */
        function () use ($agent) {
            $_SERVER['HTTP_USER_AGENT'] = 'Mozilla/5.0 (compatible; ' . $agent . '/1.1)';
            pf_call(pf_hooks(), 'post_event', [pf_payload('AddToCart')]);

            if (pf_event_was_sent()) {
                return 'the event was sent anyway';
            }
            $row = pf_last_blocked_row();
            if (($row['detail'] ?? null) !== $agent) {
                return 'the prefix shadowed the exact agent: ' . json_encode($row);
            }
            if (strpos(pf_debug_log(), $agent) === false) {
                return 'the debug log does not name the matched agent';
            }

            return true;
        },
        $failures,
        $passes
    );
}

pf_run_bot_case(
    'An unrecognised Meta crawler is still suppressed, under the generic prefix',
    /** @return bool|string */
    function () {
        // The point of the catch-all: the next crawler Meta ships is filtered without a release.
        $_SERVER['HTTP_USER_AGENT'] = 'Mozilla/5.0 (compatible; meta-externalsomethingnew/2.0)';
        pf_call(pf_hooks(), 'post_event', [pf_payload('AddToCart')]);

        if (pf_event_was_sent()) {
            return 'an unrecognised Meta crawler was reported as a shopper';
        }
        $row = pf_last_blocked_row();

        return ($row['reason'] ?? null) === 'bot' && ($row['detail'] ?? null) === 'meta-external'
            ? true
            : 'unexpected blocked row: ' . json_encode($row);
    },
    $failures,
    $passes
);

pf_run_bot_case(
    'A mainstream browser user agent is not classified as automated',
    /** @return bool|string */
    function () {
        pf_call(pf_hooks(), 'post_event', [pf_payload('AddToCart')]);

        return pf_event_was_sent() && pf_last_blocked_row() === null
            ? true
            : 'the event was suppressed: ' . json_encode($GLOBALS['__pf_test_posts']);
    },
    $failures,
    $passes
);

// ---------------------------------------------------------------------
// The prefetch headers.
// ---------------------------------------------------------------------

foreach (
    [
        'Sec-Purpose: prefetch'           => ['HTTP_SEC_PURPOSE', 'prefetch'],
        'Sec-Purpose: prefetch;prerender' => ['HTTP_SEC_PURPOSE', 'prefetch;prerender'],
        'Purpose: prefetch (legacy)'      => ['HTTP_PURPOSE', 'prefetch'],
        'X-Purpose: preview (Safari)'     => ['HTTP_X_PURPOSE', 'preview'],
        'X-Purpose: prefetch (Safari)'    => ['HTTP_X_PURPOSE', 'prefetch'],
    ] as $label => $header
) {
    pf_run_bot_case(
        "{$label} suppresses the event and is reported as prefetch_header",
        /** @return bool|string */
        function () use ($header) {
            $_SERVER[$header[0]] = $header[1];
            pf_call(pf_hooks(), 'post_event', [pf_payload('AddToCart')]);

            if (pf_event_was_sent()) {
                return 'the event was sent anyway';
            }
            $row = pf_last_blocked_row();
            if (($row['reason'] ?? null) !== 'bot' || ($row['detail'] ?? null) !== 'prefetch_header') {
                return 'unexpected blocked row: ' . json_encode($row);
            }

            $log = pf_debug_log();
            if (strpos($log, 'prefetch_header') === false) {
                return 'the debug log does not name the prefetch rule';
            }
            // A test on the presence of prefetch_header alone would pass while the line still
            // claimed a user-agent match, which is the wording this change removed.
            if (strpos($log, 'USER AGENT MATCHED') !== false) {
                return 'the debug log still claims a user-agent match';
            }

            return true;
        },
        $failures,
        $passes
    );
}

pf_run_bot_case(
    'A prefetch header on a request that is not the buyer\'s does not suppress the Purchase',
    /** @return bool|string */
    function () {
        // A gateway callback or a wp-admin status change carries someone else's headers; they say
        // nothing about the shopper, exactly as for the user agent.
        $_SERVER['HTTP_SEC_PURPOSE'] = 'prefetch';
        $order = new WC_Order(201);

        pf_call(
            pf_hooks(),
            'post_event',
            [pf_payload('Purchase'), ['order' => $order, 'allow_live' => false]]
        );

        return pf_event_was_sent() && pf_last_blocked_row() === null
            ? true
            : 'the Purchase was suppressed by someone else\'s header: ' . json_encode($GLOBALS['__pf_test_posts']);
    },
    $failures,
    $passes
);

// ---------------------------------------------------------------------
// Precedence: exactly one cause is reported.
// ---------------------------------------------------------------------

pf_run_bot_case(
    'Precedence: a matched signature wins over a prefetch header',
    /** @return bool|string */
    function () {
        $_SERVER['HTTP_USER_AGENT'] = 'httpx/0.27';
        $_SERVER['HTTP_SEC_PURPOSE'] = 'prefetch';
        pf_call(pf_hooks(), 'post_event', [pf_payload('AddToCart')]);

        return (pf_last_blocked_row()['detail'] ?? null) === 'httpx'
            ? true
            : 'got ' . json_encode(pf_last_blocked_row());
    },
    $failures,
    $passes
);

pf_run_bot_case(
    'Precedence: a matched signature wins over a caller-supplied rule',
    /** @return bool|string */
    function () {
        $_SERVER['HTTP_USER_AGENT'] = 'httpx/0.27';
        pf_call(
            pf_hooks(),
            'post_event',
            [pf_payload('AddToCart'), ['bot_rule' => 'no_cookies_in_wp_plugin']]
        );

        return (pf_last_blocked_row()['detail'] ?? null) === 'httpx'
            ? true
            : 'got ' . json_encode(pf_last_blocked_row());
    },
    $failures,
    $passes
);

pf_run_bot_case(
    'Precedence: a prefetch header wins over a caller-supplied rule',
    /** @return bool|string */
    function () {
        $_SERVER['HTTP_SEC_PURPOSE'] = 'prefetch';
        pf_call(
            pf_hooks(),
            'post_event',
            [pf_payload('AddToCart'), ['bot_rule' => 'no_cookies_in_wp_plugin']]
        );

        return (pf_last_blocked_row()['detail'] ?? null) === 'prefetch_header'
            ? true
            : 'got ' . json_encode(pf_last_blocked_row());
    },
    $failures,
    $passes
);

// ---------------------------------------------------------------------
// The blocked-Purchase branch logs through a separate statement.
// ---------------------------------------------------------------------

pf_run_bot_case(
    'A Purchase blocked as a bot names the rule in the debug log',
    /** @return bool|string */
    function () {
        $_SERVER['HTTP_USER_AGENT'] = 'guzzle/7.8';
        $order = new WC_Order(202);

        pf_call(pf_hooks(), 'post_event', [pf_payload('Purchase'), ['order' => $order]]);

        if (pf_event_was_sent()) {
            return 'the Purchase was sent anyway';
        }
        if (strpos(pf_debug_log(), 'guzzle') === false) {
            return 'the blocked-Purchase log line does not name the rule that fired';
        }

        return true;
    },
    $failures,
    $passes
);

// ---------------------------------------------------------------------
// The cookieless add-to-cart GET.
//
// The hook's only job is to set bot_rule from the request shape, so the shape detector and the
// reporting path are asserted separately: is_cookieless_add_to_cart_request() decides, and
// post_event() carries the decision to the log and the beacon.
// ---------------------------------------------------------------------

/** @return bool */
function pf_is_cookieless_add(): bool
{
    return (bool) pf_call(pf_hooks(), 'is_cookieless_add_to_cart_request');
}

pf_run_bot_case(
    'An add-to-cart GET with neither cookie and no browser headers is classified as automated',
    /** @return bool|string */
    function () {
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_GET['add-to-cart']       = '4242';

        return pf_is_cookieless_add() ? true : 'the rule did not fire';
    },
    $failures,
    $passes
);

// Both cookies are written by JavaScript, so a mainstream ad blocker leaves a real shopper with
// neither — and server-side events are the only signal left for them, which is the whole point of
// the plugin. The headers have to agree before anything is withheld.
foreach (
    [
        'Sec-Fetch-Mode: navigate' => ['HTTP_SEC_FETCH_MODE', 'navigate'],
        'Accept-Language'          => ['HTTP_ACCEPT_LANGUAGE', 'en-GB,en;q=0.9'],
    ] as $label => $header
) {
    pf_run_bot_case(
        "An ad-blocked shopper is spared: no cookies but {$label} is present",
        /** @return bool|string */
        function () use ($header) {
            $_SERVER['REQUEST_METHOD'] = 'GET';
            $_GET['add-to-cart']       = '4242';
            $_SERVER[$header[0]]       = $header[1];

            return pf_is_cookieless_add()
                ? 'a browser navigation was classified as automation'
                : true;
        },
        $failures,
        $passes
    );
}

pf_run_bot_case(
    'Sec-Fetch-Mode that is not a navigation does not vouch for the request',
    /** @return bool|string */
    function () {
        // A script-initiated fetch is not a person following a link.
        $_SERVER['REQUEST_METHOD']        = 'GET';
        $_GET['add-to-cart']              = '4242';
        $_SERVER['HTTP_SEC_FETCH_MODE']   = 'cors';

        return pf_is_cookieless_add() ? true : 'the rule stopped firing for a non-navigation';
    },
    $failures,
    $passes
);

foreach (['_pf_uid' => '1757000000.123', '_fbp' => 'fb.1.1757000000.98765'] as $cookie => $value) {
    pf_run_bot_case(
        "An add-to-cart GET carrying {$cookie} is left alone",
        /** @return bool|string */
        function () use ($cookie, $value) {
            $_SERVER['REQUEST_METHOD'] = 'GET';
            $_GET['add-to-cart']       = '4242';
            $_COOKIE[$cookie]          = $value;

            return pf_is_cookieless_add() ? 'the rule fired for a returning shopper' : true;
        },
        $failures,
        $passes
    );
}

pf_run_bot_case(
    'A classic AJAX add is outside the rule even with no cookies at all',
    /** @return bool|string */
    function () {
        // ?wc-ajax=add_to_cart posts product_id; it never sets $_GET['add-to-cart'].
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_GET['wc-ajax']           = 'add_to_cart';

        return pf_is_cookieless_add() ? 'the rule widened to the AJAX endpoint' : true;
    },
    $failures,
    $passes
);

pf_run_bot_case(
    'A Store API add is outside the rule even with no cookies at all',
    /** @return bool|string */
    function () {
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_SERVER['REQUEST_URI']    = '/wp-json/wc/store/v1/cart/add-item';

        return pf_is_cookieless_add() ? 'the rule widened to the Store API' : true;
    },
    $failures,
    $passes
);

pf_run_bot_case(
    'A cookieless add-to-cart skip is logged and beaconed under its own rule',
    /** @return bool|string */
    function () {
        pf_call(
            pf_hooks(),
            'post_event',
            [pf_payload('AddToCart'), ['bot_rule' => 'no_cookies_in_wp_plugin']]
        );

        if (pf_event_was_sent()) {
            return 'the event was sent anyway';
        }
        $row = pf_last_blocked_row();
        if (($row['reason'] ?? null) !== 'bot' || ($row['detail'] ?? null) !== 'no_cookies_in_wp_plugin') {
            return 'unexpected blocked row: ' . json_encode($row);
        }
        if (strpos(pf_debug_log(), 'no_cookies_in_wp_plugin') === false) {
            return 'the debug log does not name the rule';
        }

        return true;
    },
    $failures,
    $passes
);

pf_run_bot_case(
    'A cookieless add-to-cart from a known client is reported under the signature, not the rule',
    /** @return bool|string */
    function () {
        $_SERVER['HTTP_USER_AGENT'] = 'python-httpx/0.27';
        pf_call(
            pf_hooks(),
            'post_event',
            [pf_payload('AddToCart'), ['bot_rule' => 'no_cookies_in_wp_plugin']]
        );

        return (pf_last_blocked_row()['detail'] ?? null) === 'httpx'
            ? true
            : 'the rule bypassed the precedence: ' . json_encode(pf_last_blocked_row());
    },
    $failures,
    $passes
);

pf_run_bot_case(
    'The quantity-update hook carries the cookieless rule as well',
    /** @return bool|string */
    function () {
        // For a product already in the cart WooCommerce calls set_quantity() before
        // do_action('woocommerce_add_to_cart'), so this hook wins the shared add_to_cart:<key>
        // dedupe. If only pf_add_to_cart_hook() set the rule, the outcome would depend on cart
        // state rather than on the shape of the request.
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_GET['add-to-cart']       = '4242';

        $GLOBALS['__pf_test_products'][4242] = new WC_Product();

        pf_hooks()->pf_cart_item_quantity_update_hook('item-key', 2, 1, new PF_Test_Cart());

        if (pf_event_was_sent()) {
            return 'the AddToCart was sent with no rule attached';
        }
        $row = pf_last_blocked_row();
        if (($row['reason'] ?? null) !== 'bot' || ($row['detail'] ?? null) !== 'no_cookies_in_wp_plugin') {
            return 'unexpected blocked row: ' . json_encode($row);
        }

        return true;
    },
    $failures,
    $passes
);

pf_run_bot_case(
    'The quantity-update hook still reports a normal shopper\'s increase',
    /** @return bool|string */
    function () {
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_GET['add-to-cart']       = '4242';
        $_COOKIE['_pf_uid']        = '1757000000.123';

        $GLOBALS['__pf_test_products'][4242] = new WC_Product();

        pf_hooks()->pf_cart_item_quantity_update_hook('item-key', 2, 1, new PF_Test_Cart());

        return pf_event_was_sent() && pf_last_blocked_row() === null
            ? true
            : 'a returning shopper was suppressed: ' . json_encode($GLOBALS['__pf_test_posts']);
    },
    $failures,
    $passes
);

// ---------------------------------------------------------------------
// The cookieless rule against the consent state.
//
// The rule infers automation from an absence of cookies — but a shopper who has not answered the
// banner has no _pf_uid and no _fbp either, because both are marketing cookies. Ranking the rule
// below the consent checks is what keeps that shopper's event held and replayed on a grant
// instead of dropped as automation.
// ---------------------------------------------------------------------

pf_run_bot_case(
    'A cookieless add-to-cart with the decision still pending is held, not dropped as a bot',
    /** @return bool|string */
    function () {
        $_COOKIE['_pf_no_consent_decision'] = 'true';
        pf_call(
            pf_hooks(),
            'post_event',
            [pf_payload('AddToCart'), ['bot_rule' => 'no_cookies_in_wp_plugin']]
        );

        $row = pf_last_blocked_row();
        if (($row['reason'] ?? null) === 'bot') {
            return 'an undecided shopper was classified as automation and can never be replayed';
        }

        return ($row['reason'] ?? null) === 'no_decision'
            ? true
            : 'unexpected blocked row: ' . json_encode($row);
    },
    $failures,
    $passes
);

pf_run_bot_case(
    'A cookieless add-to-cart after a decline is reported as denied, not as a bot',
    /** @return bool|string */
    function () {
        $_COOKIE['_pf_consent'] = pf_consent_cookie('denied');
        pf_call(
            pf_hooks(),
            'post_event',
            [pf_payload('AddToCart'), ['bot_rule' => 'no_cookies_in_wp_plugin']]
        );

        $row = pf_last_blocked_row();

        return ($row['reason'] ?? null) === 'denied'
            ? true
            : 'unexpected blocked row: ' . json_encode($row);
    },
    $failures,
    $passes
);

pf_run_bot_case(
    'A real crawler carrying no cookies at all is still filtered by the rule',
    /** @return bool|string */
    function () {
        // The consent cookies are set by the browser script, so a client running no JavaScript
        // has none of them. Ranking the rule last therefore costs nothing against real crawlers.
        pf_call(
            pf_hooks(),
            'post_event',
            [pf_payload('AddToCart'), ['bot_rule' => 'no_cookies_in_wp_plugin']]
        );

        $row = pf_last_blocked_row();

        return ($row['reason'] ?? null) === 'bot' && ($row['detail'] ?? null) === 'no_cookies_in_wp_plugin'
            ? true
            : 'the rule stopped filtering crawlers: ' . json_encode($row);
    },
    $failures,
    $passes
);

pf_run_bot_case(
    'A matched signature still wins over a pending decision',
    /** @return bool|string */
    function () {
        // Unchanged: a user-agent match is positive evidence about the client, not an inference
        // from absence, so holding such an event for a decision would replay a bot's event.
        $_COOKIE['_pf_no_consent_decision'] = 'true';
        $_SERVER['HTTP_USER_AGENT']         = 'httpx/0.27';
        pf_call(pf_hooks(), 'post_event', [pf_payload('AddToCart')]);

        $row = pf_last_blocked_row();

        return ($row['reason'] ?? null) === 'bot' && ($row['detail'] ?? null) === 'httpx'
            ? true
            : 'unexpected blocked row: ' . json_encode($row);
    },
    $failures,
    $passes
);

pf_run_bot_case(
    'A prefetch header still wins over a pending decision',
    /** @return bool|string */
    function () {
        $_COOKIE['_pf_no_consent_decision'] = 'true';
        $_SERVER['HTTP_SEC_PURPOSE']        = 'prefetch';
        pf_call(pf_hooks(), 'post_event', [pf_payload('AddToCart')]);

        $row = pf_last_blocked_row();

        return ($row['reason'] ?? null) === 'bot' && ($row['detail'] ?? null) === 'prefetch_header'
            ? true
            : 'unexpected blocked row: ' . json_encode($row);
    },
    $failures,
    $passes
);

echo "\n{$passes} passed, " . count($failures) . " failed\n";

exit(count($failures) > 0 ? 1 : 0);
