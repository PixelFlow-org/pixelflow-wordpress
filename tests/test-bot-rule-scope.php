<?php
/**
 * How far the automation rules reach: which request shapes the cookieless add-to-cart rule
 * covers, and which events a matched signature may suppress.
 *
 * WooCommerce's own add-to-cart handler runs on `wp_loaded` and reads $_REQUEST with no method
 * check, so HEAD and POST-with-query-string add to the cart too and have to fall inside the rule;
 * a genuine POST form carries its parameter in the body and stays outside it by construction.
 *
 * A Purchase is the other half: an order in the database is evidence a human paid, so the three
 * generic HTTP client libraries — legitimate for a headless store or a mobile app — do not
 * suppress it, while every other signature and the prefetch rule still do.
 *
 * Run: php tests/test-bot-rule-scope.php
 */

declare(strict_types=1);

require_once __DIR__ . '/bootstrap-wp-stubs.php';

$_SERVER['REMOTE_ADDR'] = '203.0.113.10';

if ( ! defined('WP_CONTENT_DIR')) {
    define('WP_CONTENT_DIR', sys_get_temp_dir() . '/pf-bot-rule-scope-test');
}
if ( ! is_dir(WP_CONTENT_DIR)) {
    mkdir(WP_CONTENT_DIR, 0777, true);
}
if ( ! defined('PIXELFLOW_VERSION')) {
    define('PIXELFLOW_VERSION', '0.0.0-test');
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
}

/** Minimal stand-in for WC_Order: only meta and the id are read here. */
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

function home_url($path = '')
{
    return 'https://example.test' . $path;
}

function wp_parse_url($url, $component = -1)
{
    return $component === -1 ? parse_url($url) : parse_url($url, $component);
}

require_once dirname(__DIR__) . '/includes/helpers.php';
require_once dirname(__DIR__) . '/includes/consent.php';
require_once dirname(__DIR__) . '/includes/blocked-events.php';
require_once dirname(__DIR__) . '/includes/held-events.php';
require_once dirname(__DIR__) . '/includes/woo/hooks/class-woocommerce-hooks.php';

const PF_SITE   = 'wp_0123456789abcdef0123456789abcdef';
const PF_SCRAPY = 'Scrapy/2.11 (+https://scrapy.org)';

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

/**
 * Runs a Purchase for an order created by a client with this user agent, as a request that is
 * not the buyer's — the shape a headless store or a mobile app produces, where the agent comes
 * from the order rather than from the live request.
 *
 * @param string $ua Agent saved at order creation
 * @return WC_Order The order, so the caller can read the deferred blocked marker
 */
function pf_purchase_from_client(string $ua): WC_Order
{
    $order = new WC_Order(301);
    $order->meta['_pf_client_ua'] = $ua;

    pf_call(
        pf_hooks(),
        'post_event',
        [pf_payload('Purchase'), ['order' => $order, 'allow_live' => false, 'ua' => $ua]]
    );

    return $order;
}

/**
 * The reason a blocked Purchase was deferred under, or null when the order was not blocked.
 * A blocked Purchase is never beaconed on the spot — a grant may still resolve it.
 *
 * @param WC_Order $order Order the Purchase ran for
 * @return array|null
 */
function pf_deferred_block(WC_Order $order): ?array
{
    $stored = (string) $order->get_meta('_pf_purchase_blocked', true);
    if ($stored === '') {
        return null;
    }
    $marker = json_decode($stored, true);

    return is_array($marker) ? $marker : null;
}

$failures = [];
$passes   = 0;

/**
 * @param string   $label
 * @param callable $fn
 */
function pf_run_scope_case(string $label, callable $fn, array &$failures, int &$passes): void
{
    $_COOKIE = [];
    $_GET    = [];
    $_POST   = [];
    unset(
        $_SERVER['HTTP_SEC_PURPOSE'],
        $_SERVER['HTTP_PURPOSE'],
        $_SERVER['HTTP_X_PURPOSE'],
        $_SERVER['HTTP_SEC_FETCH_MODE'],
        $_SERVER['HTTP_ACCEPT_LANGUAGE']
    );
    $_SERVER['REQUEST_METHOD']  = 'GET';
    $_SERVER['HTTP_USER_AGENT'] = '';

    $GLOBALS['__pf_test_posts']   = [];
    $GLOBALS['__pf_test_filters'] = [];
    $GLOBALS['__pf_test_options'] = ['pixelflow_debug_log_key' => 'botscope'];

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
// The cookieless add-to-cart rule is keyed on the query string, not on the method.
// ---------------------------------------------------------------------

/** @return bool */
function pf_is_cookieless_add(): bool
{
    return (bool) pf_call(pf_hooks(), 'is_cookieless_add_to_cart_request');
}

foreach (['GET', 'HEAD', 'POST'] as $method) {
    pf_run_scope_case(
        "A cookieless {$method} carrying add-to-cart in the query string is classified as automated",
        /** @return bool|string */
        function () use ($method) {
            // WC_Form_Handler::add_to_cart_action() is hooked on wp_loaded and reads
            // $_REQUEST['add-to-cart'], so all three add to the cart.
            $_SERVER['REQUEST_METHOD'] = $method;
            $_GET['add-to-cart']       = '4242';

            return pf_is_cookieless_add() ? true : "the rule did not fire for {$method}";
        },
        $failures,
        $passes
    );
}

pf_run_scope_case(
    'A genuine POST add-to-cart form stays outside the rule: its parameter is in the body',
    /** @return bool|string */
    function () {
        // The single-product form posts add-to-cart, so $_POST carries it and $_GET does not.
        // Keying the rule on $_REQUEST would classify every ad-blocked shopper's form submit as
        // automation; keying it on the query string cannot.
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_POST['add-to-cart']      = '4242';

        return pf_is_cookieless_add() ? 'the rule widened to POST form submissions' : true;
    },
    $failures,
    $passes
);

// ---------------------------------------------------------------------
// The signature list.
// ---------------------------------------------------------------------

pf_run_scope_case(
    'Scrapy\'s default user agent is a known signature',
    /** @return bool|string */
    function () {
        // Scrapy sends Accept-Language and keeps cookies, so the browser-navigation check spares
        // it and the agent is the only signal left.
        $_SERVER['HTTP_USER_AGENT']      = PF_SCRAPY;
        $_SERVER['HTTP_ACCEPT_LANGUAGE'] = 'en';
        $_COOKIE['_pf_uid']              = '1757000000.123';

        pf_call(pf_hooks(), 'post_event', [pf_payload('AddToCart')]);

        if (pf_event_was_sent()) {
            return 'a Scrapy crawl was reported as a shopper';
        }
        $row = pf_last_blocked_row();

        return ($row['reason'] ?? null) === 'bot' && ($row['detail'] ?? null) === 'scrapy'
            ? true
            : 'unexpected blocked row: ' . json_encode($row);
    },
    $failures,
    $passes
);

// ---------------------------------------------------------------------
// Purchase versus the generic HTTP client libraries.
// ---------------------------------------------------------------------

foreach (['guzzle/7.8', 'python-httpx/0.27', 'aiohttp/3.9'] as $client) {
    pf_run_scope_case(
        "A paid order reported by {$client} still sends its Purchase",
        /** @return bool|string */
        function () use ($client) {
            $order = pf_purchase_from_client($client);

            if ( ! pf_event_was_sent()) {
                return 'the Purchase of a real paid order was suppressed: '
                    . json_encode(pf_deferred_block($order));
            }

            return pf_deferred_block($order) === null
                ? true
                : 'a blocked row was deferred for a sent Purchase';
        },
        $failures,
        $passes
    );

    pf_run_scope_case(
        "{$client} still suppresses AddToCart",
        /** @return bool|string */
        function () use ($client) {
            $_SERVER['HTTP_USER_AGENT'] = $client;
            pf_call(pf_hooks(), 'post_event', [pf_payload('AddToCart')]);

            if (pf_event_was_sent()) {
                return 'the exemption leaked into AddToCart';
            }

            return (pf_last_blocked_row()['reason'] ?? null) === 'bot'
                ? true
                : 'unexpected blocked row: ' . json_encode(pf_last_blocked_row());
        },
        $failures,
        $passes
    );
}

foreach (['python-requests/2.31', 'Googlebot/2.1', PF_SCRAPY] as $agent) {
    pf_run_scope_case(
        "A Purchase from {$agent} is still suppressed",
        /** @return bool|string */
        function () use ($agent) {
            $order = pf_purchase_from_client($agent);

            if (pf_event_was_sent()) {
                return 'the exemption widened beyond the three client libraries';
            }

            return (pf_deferred_block($order)['reason'] ?? null) === 'bot'
                ? true
                : 'unexpected deferred row: ' . json_encode(pf_deferred_block($order));
        },
        $failures,
        $passes
    );
}

pf_run_scope_case(
    'A prefetch of the order-received page still suppresses the Purchase',
    /** @return bool|string */
    function () {
        // The prefetch rule is untouched by the exemption: it names a browser behaviour rather
        // than a client a store integrates with.
        $_SERVER['HTTP_SEC_PURPOSE'] = 'prefetch';
        $order = new WC_Order(302);

        pf_call(pf_hooks(), 'post_event', [pf_payload('Purchase'), ['order' => $order]]);

        if (pf_event_was_sent()) {
            return 'a prefetched Purchase was sent';
        }

        return (pf_deferred_block($order)['detail'] ?? null) === 'prefetch_header'
            ? true
            : 'unexpected deferred row: ' . json_encode(pf_deferred_block($order));
    },
    $failures,
    $passes
);

pf_run_scope_case(
    'An exempt library that also matches a listed signature is still suppressed',
    /** @return bool|string */
    function () {
        // A wrapper agent naming both: the exemption covers the three libraries, not any agent
        // that happens to mention one of them.
        pf_purchase_from_client('python-requests/2.31 guzzle/7.8');

        return pf_event_was_sent()
            ? 'a listed signature was shadowed by the exemption'
            : true;
    },
    $failures,
    $passes
);

pf_run_scope_case(
    'A signature the site adds itself is not exempt from suppressing a Purchase',
    /** @return bool|string */
    function () {
        // The pixelflow_useragent_bot_patterns contract is unchanged: the exemption is the
        // plugin's own named list, not "whatever the last list entry is".
        $GLOBALS['__pf_test_filters']['pixelflow_useragent_bot_patterns'] = array_merge(
            PIXELFLOW_BOT_PATTERNS,
            ['acme-importer']
        );

        pf_purchase_from_client('acme-importer/1.0');

        return pf_event_was_sent()
            ? 'a site-supplied signature was treated as exempt'
            : true;
    },
    $failures,
    $passes
);

echo "\n{$passes} passed, " . count($failures) . " failed\n";

exit(count($failures) > 0 ? 1 : 0);
