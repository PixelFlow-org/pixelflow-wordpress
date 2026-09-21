<?php
/**
 * Identity on the wire: what external_id each event type actually carries.
 *
 * Asserts on the built payload rather than on the resolver, because the field is written in
 * post_event() and an unresolved identity must leave no key behind at all — not a null, not an
 * empty string.
 *
 * Run: php tests/test-external-id-events.php
 */

declare(strict_types=1);

require_once __DIR__ . '/bootstrap-wp-stubs.php';

$_SERVER['REMOTE_ADDR'] = '203.0.113.10';

if ( ! defined('WP_CONTENT_DIR')) {
    define('WP_CONTENT_DIR', sys_get_temp_dir() . '/pf-external-id-test');
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

/** Minimal stand-in for WC_Order: only meta and the billing fields identity cares about. */
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

    /** @var int */
    public $customer_id = 0;

    public function get_customer_id()
    {
        return $this->customer_id;
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
}

require_once dirname(__DIR__) . '/includes/helpers.php';
require_once dirname(__DIR__) . '/includes/consent.php';
require_once dirname(__DIR__) . '/includes/blocked-events.php';
require_once dirname(__DIR__) . '/includes/held-events.php';
require_once dirname(__DIR__) . '/includes/woo/hooks/class-woocommerce-hooks.php';

const PF_SITE    = 'wp_0123456789abcdef0123456789abcdef';
const PF_VISITOR = '1757000000.123';

/**
 * @param string $visitor_id
 * @return string
 */
function pf_attribution_raw(string $visitor_id): string
{
    return rawurlencode((string) wp_json_encode(['visitor_id' => $visitor_id]));
}

/**
 * Calls a private method on the hooks class.
 *
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

/**
 * Builds the payload shape post_event() receives.
 *
 * @param string $event_name
 * @param array  $customer_data
 * @return array
 */
function pf_payload(string $event_name, array $customer_data = []): array
{
    return [
        'siteId'    => PF_SITE,
        'eventData' => [
            'eventName'    => $event_name,
            'eventTime'    => 1757000000,
            'customerData' => $customer_data,
        ],
    ];
}

/**
 * The customerData of the last event POSTed to /event, or null when nothing was sent.
 *
 * @return array|null
 */
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
function pf_run_event_case(string $label, callable $fn, array &$failures, int &$passes): void
{
    $_COOKIE                      = [];
    $GLOBALS['__pf_test_options'] = [];
    $GLOBALS['__pf_test_posts']   = [];
    $GLOBALS['__pf_test_filters'] = [];

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
// The three event types, through the one resolution path.
// ---------------------------------------------------------------------

pf_run_event_case(
    'AddToCart: a guest with a visitor cookie carries the derived identifier',
    /** @return bool|string */
    function () {
        $_COOKIE['_pf_uid'] = PF_VISITOR;
        pf_call(pf_hooks(), 'post_event', [pf_payload('AddToCart'), ['product_id' => 42]]);

        $customer = pf_last_customer_data();
        $expected = hash('sha256', PF_SITE . '_' . PF_VISITOR);

        return ($customer['external_id'] ?? null) === $expected
            ? true
            : 'got ' . json_encode($customer);
    },
    $failures,
    $passes
);

pf_run_event_case(
    'InitiateCheckout: resolves from live cookies even though it passes no context',
    /** @return bool|string */
    function () {
        $_COOKIE['_pf_uid'] = PF_VISITOR;
        pf_call(pf_hooks(), 'post_event', [pf_payload('InitiateCheckout')]);

        $customer = pf_last_customer_data();
        $expected = hash('sha256', PF_SITE . '_' . PF_VISITOR);

        return ($customer['external_id'] ?? null) === $expected
            ? true
            : 'got ' . json_encode($customer);
    },
    $failures,
    $passes
);

pf_run_event_case(
    'Purchase outside the shopper\'s request uses the visitor id stored on the order',
    /** @return bool|string */
    function () {
        $order = new WC_Order(101);
        $order->meta['_pf_cookie__pf_uid'] = 'order-visitor';

        pf_call(
            pf_hooks(),
            'post_event',
            [pf_payload('Purchase'), ['order' => $order, 'allow_live' => false]]
        );

        $customer = pf_last_customer_data();
        $expected = hash('sha256', PF_SITE . '_order-visitor');

        return ($customer['external_id'] ?? null) === $expected
            ? true
            : 'got ' . json_encode($customer);
    },
    $failures,
    $passes
);

// ---------------------------------------------------------------------
// Negative cases: the key is absent, never present and null.
// ---------------------------------------------------------------------

pf_run_event_case(
    'A logged-in shopper with no visitor id sends no external_id key at all',
    /** @return bool|string */
    function () {
        // The WordPress user id is not a fallback: the builders no longer derive identity, so a
        // logged-in shopper without a visitor cookie is simply unidentified.
        pf_call(pf_hooks(), 'post_event', [pf_payload('AddToCart', ['em' => 'hashed-email'])]);

        $customer = pf_last_customer_data();
        if ($customer === null) {
            return 'no event was sent at all';
        }
        if (array_key_exists('external_id', $customer)) {
            return 'the key is present: ' . json_encode($customer);
        }
        if (($customer['em'] ?? null) !== 'hashed-email') {
            return 'the rest of customerData was lost: ' . json_encode($customer);
        }

        return true;
    },
    $failures,
    $passes
);

pf_run_event_case(
    'A request carrying only _fbp sends no external_id key',
    /** @return bool|string */
    function () {
        $_COOKIE['_fbp'] = 'fb.1.1757000000.9876543210';

        // The cookie is not identity, but it still travels in its own field, which is where
        // Meta matches on it.
        $payload = pf_payload('AddToCart');
        pixelflow_append_cookie_params($payload);
        if (($payload['eventData']['fbp'] ?? null) !== 'fb.1.1757000000.9876543210') {
            return '_fbp did not reach the fbp field: ' . json_encode($payload['eventData']);
        }

        pf_call(pf_hooks(), 'post_event', [$payload]);

        $customer = pf_last_customer_data();

        return $customer !== null && ! array_key_exists('external_id', $customer)
            ? true
            : 'got ' . json_encode($customer);
    },
    $failures,
    $passes
);

// ---------------------------------------------------------------------
// The staff cases: someone else's request must not lend the buyer its identity.
// ---------------------------------------------------------------------

pf_run_event_case(
    'Staff _pf_uid is not borrowed for an order with no stored visitor id',
    /** @return bool|string */
    function () {
        $_COOKIE['_pf_uid'] = 'staff-visitor';
        $order = new WC_Order(102);

        pf_call(
            pf_hooks(),
            'post_event',
            [pf_payload('Purchase'), ['order' => $order, 'allow_live' => false]]
        );

        $customer = pf_last_customer_data();

        return $customer !== null && ! array_key_exists('external_id', $customer)
            ? true
            : 'the staff identity was borrowed: ' . json_encode($customer);
    },
    $failures,
    $passes
);

pf_run_event_case(
    'Staff _pf_attribution is not borrowed for an order with no stored attribution',
    /** @return bool|string */
    function () {
        $_COOKIE['_pf_attribution'] = pf_attribution_raw('staff-visitor');
        $order = new WC_Order(103);

        pf_call(
            pf_hooks(),
            'post_event',
            [pf_payload('Purchase'), ['order' => $order, 'allow_live' => false]]
        );

        $customer = pf_last_customer_data();

        return $customer !== null && ! array_key_exists('external_id', $customer)
            ? true
            : 'the staff attribution was borrowed: ' . json_encode($customer);
    },
    $failures,
    $passes
);

// ---------------------------------------------------------------------
// append_attribution_for_order(): the same two leaks, at their own call site.
// ---------------------------------------------------------------------

pf_run_event_case(
    'append_attribution_for_order ignores a live _pf_attribution on a non-buyer request',
    /** @return bool|string */
    function () {
        $_COOKIE['_pf_attribution'] = pf_attribution_raw('staff-visitor');
        $order   = new WC_Order(104);
        $payload = pf_payload('Purchase');

        pf_call(pf_hooks(), 'append_attribution_for_order', [&$payload, $order, false]);

        return ! isset($payload['eventData']['attribution'])
            ? true
            : 'attribution leaked: ' . json_encode($payload['eventData']['attribution']);
    },
    $failures,
    $passes
);

pf_run_event_case(
    'append_attribution_for_order ignores a bare live _pf_uid on a non-buyer request',
    /** @return bool|string */
    function () {
        $_COOKIE['_pf_uid'] = 'staff-visitor';
        $order   = new WC_Order(105);
        $payload = pf_payload('Purchase');

        pf_call(pf_hooks(), 'append_attribution_for_order', [&$payload, $order, false]);

        return ! isset($payload['eventData']['attribution'])
            ? true
            : 'attribution leaked: ' . json_encode($payload['eventData']['attribution']);
    },
    $failures,
    $passes
);

pf_run_event_case(
    'append_attribution_for_order still reads live cookies on the buyer\'s own request',
    /** @return bool|string */
    function () {
        $_COOKIE['_pf_attribution'] = pf_attribution_raw(PF_VISITOR);
        $order   = new WC_Order(106);
        $payload = pf_payload('Purchase');

        pf_call(pf_hooks(), 'append_attribution_for_order', [&$payload, $order, true]);

        return ($payload['eventData']['attribution']['visitor_id'] ?? null) === PF_VISITOR
            ? true
            : 'the buyer\'s own attribution was dropped: ' . json_encode($payload['eventData']);
    },
    $failures,
    $passes
);

// ---------------------------------------------------------------------
// The pixelflow_external_id filter: replace, suppress, supply.
// ---------------------------------------------------------------------

pf_run_event_case(
    'A callback replaces the derived identifier',
    /** @return bool|string */
    function () {
        $_COOKIE['_pf_uid'] = PF_VISITOR;
        $GLOBALS['__pf_test_filters']['pixelflow_external_id'] = 'replaced-value';

        pf_call(pf_hooks(), 'post_event', [pf_payload('AddToCart')]);

        return (pf_last_customer_data()['external_id'] ?? null) === 'replaced-value'
            ? true
            : 'got ' . json_encode(pf_last_customer_data());
    },
    $failures,
    $passes
);

pf_run_event_case(
    'A callback returning an empty value suppresses the field',
    /** @return bool|string */
    function () {
        $_COOKIE['_pf_uid'] = PF_VISITOR;
        $GLOBALS['__pf_test_filters']['pixelflow_external_id'] = '';

        pf_call(pf_hooks(), 'post_event', [pf_payload('AddToCart')]);

        $customer = pf_last_customer_data();

        return $customer !== null && ! array_key_exists('external_id', $customer)
            ? true
            : 'the field survived suppression: ' . json_encode($customer);
    },
    $failures,
    $passes
);

pf_run_event_case(
    'A callback supplies an identifier where the plugin resolved none',
    /** @return bool|string */
    function () {
        // The supply case is the documented way back to the previous behaviour, and it only
        // works because the filter runs even when the resolver returned null.
        $GLOBALS['__pf_test_filters']['pixelflow_external_id'] = 'supplied-value';

        pf_call(pf_hooks(), 'post_event', [pf_payload('AddToCart')]);

        return (pf_last_customer_data()['external_id'] ?? null) === 'supplied-value'
            ? true
            : 'got ' . json_encode(pf_last_customer_data());
    },
    $failures,
    $passes
);

// ---------------------------------------------------------------------
// Account and order identifiers are not used as browser identity.
//
// These drive build_customer_data_from_order(), which is where the deleted derivation lived:
// it used to hash the customer id, else the billing email, else the order id into external_id.
// ---------------------------------------------------------------------

pf_run_event_case(
    'Guest checkout with an email and no browser identity omits external_id but keeps em',
    /** @return bool|string */
    function () {
        $order = new WC_Order(301);
        $hooks = pf_hooks();

        $customer = pf_call($hooks, 'build_customer_data_from_order', [$order, false]);
        if (array_key_exists('external_id', $customer)) {
            return 'the builder still derives an identifier: ' . json_encode($customer);
        }
        $expected_em = hash('sha256', 'buyer@example.test');
        if (($customer['em'] ?? null) !== $expected_em) {
            return 'the hashed email was lost: ' . json_encode($customer);
        }

        $payload = pf_payload('Purchase', $customer);
        pf_call($hooks, 'post_event', [$payload, ['order' => $order, 'allow_live' => false]]);

        $sent = pf_last_customer_data();
        if ($sent === null) {
            return 'no Purchase was sent';
        }
        if (array_key_exists('external_id', $sent)) {
            return 'the identifier came back on the wire: ' . json_encode($sent);
        }
        if (($sent['em'] ?? null) !== $expected_em) {
            return 'em did not survive to the wire: ' . json_encode($sent);
        }

        return true;
    },
    $failures,
    $passes
);

pf_run_event_case(
    'Logged-in checkout with no visitor id hashes neither the user id nor the order id',
    /** @return bool|string */
    function () {
        $order              = new WC_Order(302);
        $order->customer_id = 77;
        $hooks              = pf_hooks();

        $customer = pf_call($hooks, 'build_customer_data_from_order', [$order, false]);
        // Asserted on the builder, not only on the wire: post_event() strips an unresolved
        // external_id on its way out, so a wire-only check would pass even if the builder
        // still derived one.
        if (array_key_exists('external_id', $customer)) {
            // The old code hashed the customer id, then the email, then the order id.
            return 'the builder still derives an identifier: ' . json_encode($customer);
        }

        $payload = pf_payload('Purchase', $customer);
        pf_call($hooks, 'post_event', [$payload, ['order' => $order, 'allow_live' => false]]);

        $sent = pf_last_customer_data();
        if ($sent === null) {
            return 'no Purchase was sent';
        }
        if (array_key_exists('external_id', $sent)) {
            return 'an account or order identifier reached the wire: ' . json_encode($sent);
        }
        foreach (['77', '302'] as $forbidden) {
            if (strpos((string) json_encode($sent), hash('sha256', $forbidden)) !== false) {
                return "the hash of {$forbidden} appears in customerData";
            }
        }

        return true;
    },
    $failures,
    $passes
);

// ---------------------------------------------------------------------
// Held replays keep the identity captured at hold time.
// ---------------------------------------------------------------------

/**
 * Runs post_event() with the replay flag set, the way flush_held_events() does.
 *
 * @param PixelFlow_WooCommerce_Cart_Hooks $hooks
 * @param array                            $payload
 * @return void
 */
function pf_replay(PixelFlow_WooCommerce_Cart_Hooks $hooks, array $payload): void
{
    $flag = new ReflectionProperty(PixelFlow_WooCommerce_Cart_Hooks::class, 'flushing_held');
    $flag->setAccessible(true);
    $flag->setValue($hooks, true);

    try {
        pf_call($hooks, 'post_event', [$payload, ['product_id' => 42, 'variation_id' => 0]]);
    } finally {
        $flag->setValue($hooks, false);
    }
}

pf_run_event_case(
    'A held event flushed on someone else\'s request keeps the identity it was held with',
    /** @return bool|string */
    function () {
        // The recipe restored this value; the flushing request carries a different visitor.
        $held = hash('sha256', PF_SITE . '_' . PF_VISITOR);
        $_COOKIE['_pf_uid'] = 'someone-else';

        pf_replay(pf_hooks(), pf_payload('AddToCart', ['external_id' => $held]));

        return (pf_last_customer_data()['external_id'] ?? null) === $held
            ? true
            : 'the flushing request overwrote the captured identity: ' . json_encode(pf_last_customer_data());
    },
    $failures,
    $passes
);

pf_run_event_case(
    'An event held with no identity does not acquire one on the way out',
    /** @return bool|string */
    function () {
        $_COOKIE['_pf_uid'] = 'flusher-visitor';

        pf_replay(pf_hooks(), pf_payload('AddToCart'));

        $customer = pf_last_customer_data();

        return $customer !== null && ! array_key_exists('external_id', $customer)
            ? true
            : 'the replay picked up an identity: ' . json_encode($customer);
    },
    $failures,
    $passes
);

echo "\n{$passes} passed, " . count($failures) . " failed\n";

exit(count($failures) > 0 ? 1 : 0);
