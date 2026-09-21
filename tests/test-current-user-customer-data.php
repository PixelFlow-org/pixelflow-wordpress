<?php
/**
 * The logged-in customer-data builder derives no identifier of its own.
 *
 * Identity comes from the visitor id and nowhere else; an account is a match key, not an
 * identity. The check is on the builder rather than only on the wire, exactly as the sibling
 * order builder is checked: post_event() resolves external_id and overwrites or strips the field
 * on its way out, so a wire-only assertion would pass even if this builder derived one again.
 *
 * Run: php tests/test-current-user-customer-data.php
 */

declare(strict_types=1);

require_once __DIR__ . '/bootstrap-wp-stubs.php';

$_SERVER['REMOTE_ADDR'] = '203.0.113.10';

if ( ! defined('WP_CONTENT_DIR')) {
    define('WP_CONTENT_DIR', sys_get_temp_dir() . '/pf-current-user-customer-data-test');
}
if ( ! is_dir(WP_CONTENT_DIR)) {
    mkdir(WP_CONTENT_DIR, 0777, true);
}
if ( ! defined('PIXELFLOW_VERSION')) {
    define('PIXELFLOW_VERSION', '0.0.0-test');
}

$GLOBALS['__pf_test_options']   = [];
$GLOBALS['__pf_test_posts']     = [];
$GLOBALS['__pf_test_user_id']   = 0;
$GLOBALS['__pf_test_user_meta'] = [];

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

class WC_Order
{
}

class WC_Order_Item_Product
{
}

class WC_Product
{
}

function get_current_user_id()
{
    return (int) $GLOBALS['__pf_test_user_id'];
}

function get_userdata($user_id)
{
    if ((int) $user_id !== (int) $GLOBALS['__pf_test_user_id'] || (int) $user_id <= 0) {
        return false;
    }

    return (object) [
        'user_email' => 'shopper@example.test',
        'first_name' => 'Pixel',
        'last_name'  => 'Flow',
    ];
}

function get_user_meta($user_id, $key = '', $single = false)
{
    return $GLOBALS['__pf_test_user_meta'][$key] ?? '';
}

require_once dirname(__DIR__) . '/includes/helpers.php';
require_once dirname(__DIR__) . '/includes/consent.php';
require_once dirname(__DIR__) . '/includes/blocked-events.php';
require_once dirname(__DIR__) . '/includes/held-events.php';
require_once dirname(__DIR__) . '/includes/woo/hooks/class-woocommerce-hooks.php';

const PF_SITE    = 'wp_0123456789abcdef0123456789abcdef';
const PF_VISITOR = '1757000000.123';

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
    return new PixelFlow_WooCommerce_Cart_Hooks('https://example.test/api', 'k', PF_SITE, []);
}

/** customerData of the last event that reached /event, or null. */
function pf_last_customer_data(): ?array
{
    foreach (array_reverse($GLOBALS['__pf_test_posts']) as $post) {
        if (substr((string) $post['url'], -6) === '/event') {
            return $post['payload']['eventData']['customerData'] ?? [];
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
function pf_run_user_case(string $label, callable $fn, array &$failures, int &$passes): void
{
    $_COOKIE = [];
    $_GET    = [];
    $_SERVER['REQUEST_METHOD']  = 'GET';
    $_SERVER['HTTP_USER_AGENT'] = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) Chrome/140.0 Safari/537.36';

    $GLOBALS['__pf_test_posts']     = [];
    $GLOBALS['__pf_test_filters']   = [];
    $GLOBALS['__pf_test_options']   = [];
    $GLOBALS['__pf_test_user_id']   = 77;
    $GLOBALS['__pf_test_user_meta'] = [];

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

pf_run_user_case(
    'The logged-in builder returns match keys and no identifier',
    /** @return bool|string */
    function () {
        $customer = pf_call(pf_hooks(), 'build_customer_data_from_current_user');

        if (array_key_exists('external_id', $customer)) {
            // The old code hashed the account id or the account email here.
            return 'the builder derives an identifier: ' . json_encode($customer);
        }
        if (($customer['em'] ?? null) !== hash('sha256', 'shopper@example.test')) {
            return 'the hashed email was lost: ' . json_encode($customer);
        }
        if (strpos((string) json_encode($customer), hash('sha256', '77')) !== false) {
            return 'the account id was hashed into the payload: ' . json_encode($customer);
        }

        return true;
    },
    $failures,
    $passes
);

pf_run_user_case(
    'A logged-in shopper is identified by the visitor id, not by the account',
    /** @return bool|string */
    function () {
        $_COOKIE['_pf_uid'] = PF_VISITOR;

        $hooks    = pf_hooks();
        $customer = pf_call($hooks, 'build_customer_data_from_current_user');

        $payload = [
            'siteId'    => PF_SITE,
            'eventData' => [
                'event_id'     => 'atc-1',
                'eventName'    => 'AddToCart',
                'eventTime'    => 1757000000,
                'customerData' => $customer,
            ],
        ];
        pf_call($hooks, 'post_event', [$payload, ['product_id' => 17, 'variation_id' => 0]]);

        $sent = pf_last_customer_data();
        if ($sent === null) {
            return 'no event was sent';
        }

        return ($sent['external_id'] ?? null) === hash('sha256', PF_SITE . '_' . PF_VISITOR)
            ? true
            : 'the identity on the wire is not the visitor id\'s: ' . json_encode($sent);
    },
    $failures,
    $passes
);

pf_run_user_case(
    'A logged-in shopper with no visitor id goes out without an identifier',
    /** @return bool|string */
    function () {
        $hooks    = pf_hooks();
        $customer = pf_call($hooks, 'build_customer_data_from_current_user');

        $payload = [
            'siteId'    => PF_SITE,
            'eventData' => [
                'event_id'     => 'atc-2',
                'eventName'    => 'AddToCart',
                'eventTime'    => 1757000000,
                'customerData' => $customer,
            ],
        ];
        pf_call($hooks, 'post_event', [$payload, ['product_id' => 17, 'variation_id' => 0]]);

        $sent = pf_last_customer_data();
        if ($sent === null) {
            return 'no event was sent';
        }

        return array_key_exists('external_id', $sent)
            ? 'an account-derived identifier reached the wire: ' . json_encode($sent)
            : true;
    },
    $failures,
    $passes
);

echo "\n{$passes} passed, " . count($failures) . " failed\n";

exit(count($failures) > 0 ? 1 : 0);
