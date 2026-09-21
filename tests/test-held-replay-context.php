<?php
/**
 * What a held event carries when the real flush replays it.
 *
 * The identity and the product context are asserted through flush_held_events() itself rather
 * than by calling post_event() with a hand-built context: the pass-through from the stored recipe
 * is the part that can break, and a test that supplies the context itself would pin nothing.
 *
 * Run: php tests/test-held-replay-context.php
 */

declare(strict_types=1);

require_once __DIR__ . '/bootstrap-wp-stubs.php';

$_SERVER['REMOTE_ADDR'] = '203.0.113.10';

if ( ! defined('WP_CONTENT_DIR')) {
    define('WP_CONTENT_DIR', sys_get_temp_dir() . '/pf-held-replay-context-test');
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

class WC_Product
{
}

class WC_Order
{
}

class WC_Order_Item_Product
{
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

const PF_SITE    = 'wp_0123456789abcdef0123456789abcdef';
const PF_VISITOR = '1757000000.123';

function pf_hooks(): PixelFlow_WooCommerce_Cart_Hooks
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

/**
 * Queues one AddToCart recipe the way hold_or_block_event() does, carrying the identity the
 * shopper had when the event was held.
 *
 * @param int    $product_id   Parent product id stored on the recipe
 * @param int    $variation_id Variation id stored on the recipe
 * @param string $external_id  Identity captured at hold time
 * @return void
 */
function pf_queue_recipe(int $product_id, int $variation_id, string $external_id): void
{
    $payload = [
        'siteId'    => PF_SITE,
        'eventData' => [
            'event_id'       => 'atc-1',
            'eventName'      => 'AddToCart',
            'eventTime'      => 1757000000,
            'customerData'   => ['external_id' => $external_id],
            'additionalData' => [
                'currency'    => 'EUR',
                'value'       => 10.0,
                'contentType' => 'product',
                'contents'    => [['id' => (string) $product_id, 'quantity' => 1, 'item_price' => 10.0]],
            ],
        ],
    ];

    pixelflow_enqueue_held_woo_event(
        pixelflow_held_event_recipe_from_payload($payload, $product_id, $variation_id)
    );
}

/** The last event that reached /event, or null. */
function pf_last_event(): ?array
{
    foreach (array_reverse($GLOBALS['__pf_test_posts']) as $post) {
        if (substr((string) $post['url'], -6) === '/event') {
            return $post['payload'];
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
function pf_run_replay_case(string $label, callable $fn, array &$failures, int &$passes): void
{
    $_COOKIE = [];
    $_GET    = [];
    $_SERVER['REQUEST_METHOD']  = 'GET';
    $_SERVER['HTTP_USER_AGENT'] = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) Chrome/140.0 Safari/537.36';

    $GLOBALS['__pf_test_posts']             = [];
    $GLOBALS['__pf_test_filters']           = [];
    $GLOBALS['__pf_test_filter_callbacks']  = [];
    $GLOBALS['__pf_test_options']           = [];
    $GLOBALS['__pf_test_wc']                = (object) ['session' => new PixelFlow_Test_Woo_Session()];

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

pf_run_replay_case(
    'The real flush hands the recipe\'s product context to the external_id filter',
    /** @return bool|string */
    function () {
        // A site's pixelflow_external_id callback must see the same $context on the replay as on
        // the live AddToCart that was held, which is only true if flush_held_events() passes the
        // ids it stored on the recipe.
        $seen = [];
        $GLOBALS['__pf_test_filter_callbacks']['pixelflow_external_id'] =
            function ($external_id, $context = []) use (&$seen) {
                $seen[] = $context;

                return $external_id;
            };

        pf_queue_recipe(17, 91, hash('sha256', PF_SITE . '_' . PF_VISITOR));
        $_COOKIE[PIXELFLOW_CONSENT_COOKIE_NAME] = pf_consent_cookie('granted');

        pf_hooks()->resolve_held_events_on_page_view();

        if (count($seen) !== 1) {
            return 'expected one replay through the filter, got ' . count($seen);
        }
        if (($seen[0]['product_id'] ?? null) !== 17 || ($seen[0]['variation_id'] ?? null) !== 91) {
            return 'the recipe\'s product context did not reach post_event(): ' . json_encode($seen[0]);
        }

        return true;
    },
    $failures,
    $passes
);

pf_run_replay_case(
    'The real flush keeps the identity captured at hold time',
    /** @return bool|string */
    function () {
        // The flush may run on a request made by someone else entirely — a later page view, a
        // different visitor id — and the recipe's value has to stand.
        $held = hash('sha256', PF_SITE . '_' . PF_VISITOR);
        pf_queue_recipe(17, 0, $held);

        $_COOKIE[PIXELFLOW_CONSENT_COOKIE_NAME] = pf_consent_cookie('granted');
        $_COOKIE['_pf_uid'] = 'someone-else';

        pf_hooks()->resolve_held_events_on_page_view();

        $event = pf_last_event();
        if ($event === null) {
            return 'the queue was not flushed';
        }

        return ($event['eventData']['customerData']['external_id'] ?? null) === $held
            ? true
            : 'the flushing request overwrote the captured identity: '
                . json_encode($event['eventData']['customerData'] ?? null);
    },
    $failures,
    $passes
);

pf_run_replay_case(
    'A replayed recipe with no product context reports zeroes rather than the last event\'s ids',
    /** @return bool|string */
    function () {
        // InitiateCheckout is held with no product behind it; the context must still be the
        // recipe's own, so a callback cannot be handed another event's ids.
        $seen = [];
        $GLOBALS['__pf_test_filter_callbacks']['pixelflow_external_id'] =
            function ($external_id, $context = []) use (&$seen) {
                $seen[] = $context;

                return $external_id;
            };

        pf_queue_recipe(0, 0, hash('sha256', PF_SITE . '_' . PF_VISITOR));
        $_COOKIE[PIXELFLOW_CONSENT_COOKIE_NAME] = pf_consent_cookie('granted');

        pf_hooks()->resolve_held_events_on_page_view();

        if (count($seen) !== 1) {
            return 'expected one replay through the filter, got ' . count($seen);
        }

        return ($seen[0]['product_id'] ?? null) === 0 && ($seen[0]['variation_id'] ?? null) === 0
            ? true
            : 'unexpected context: ' . json_encode($seen[0]);
    },
    $failures,
    $passes
);

echo "\n{$passes} passed, " . count($failures) . " failed\n";

exit(count($failures) > 0 ? 1 : 0);
