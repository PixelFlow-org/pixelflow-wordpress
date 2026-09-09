<?php
/**
 * A held event is flushed once per grant, not once per route that notices it.
 *
 * `wc-ajax` is dispatched on `template_redirect`, which runs after `wp`. The
 * page-view flush hooked on `wp` therefore fires on the plugin's own AJAX routes
 * too, and used to turn the read-only state route into a second dispatcher.
 *
 * Run: php tests/test-held-flush-once.php
 */

declare(strict_types=1);

require_once __DIR__ . '/bootstrap-wp-stubs.php';

$_SERVER['REMOTE_ADDR'] = '203.0.113.10';

if ( ! defined('PIXELFLOW_PLUGIN_BASENAME')) {
    define('PIXELFLOW_PLUGIN_BASENAME', 'pixelflow/pixelflow.php');
}
if ( ! defined('PIXELFLOW_VERSION')) {
    define('PIXELFLOW_VERSION', '0.0.0-test');
}
if ( ! defined('COOKIEPATH')) {
    define('COOKIEPATH', '/');
}

function is_ssl(): bool
{
    return false;
}

function __($text, $domain = 'default')
{
    return $text;
}

function sanitize_key($key)
{
    return strtolower(preg_replace('/[^a-zA-Z0-9_\-]/', '', (string) $key));
}

function wp_parse_url($url, $component = -1)
{
    return parse_url((string) $url, (int) $component);
}

function home_url($path = '')
{
    return 'https://shop.test' . $path;
}

function is_wp_error($thing): bool
{
    return $thing instanceof WP_Error;
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

/** Records the events that would have gone to the API. */
function wp_remote_post($url, $args = [])
{
    $payload = json_decode((string) ($args['body'] ?? '{}'), true);
    $GLOBALS['__pf_test_sent'][] = [
        'url'      => $url,
        'event'    => $payload['eventData']['eventName'] ?? '',
        'event_id' => $payload['eventData']['event_id'] ?? '',
    ];

    return ['response' => ['code' => 200]];
}

/**
 * In-memory WooCommerce session that records when it was persisted, so a test can
 * tell an in-memory clear from one that reached storage.
 */
class PixelFlow_Test_Woo_Session
{
    /** @var array<string, mixed> */
    private $data = [];

    /** @var array<string, mixed> */
    public $persisted = [];

    /** @var int */
    public $saves = 0;

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
        $this->saves++;
        $this->persisted = $this->data;
    }
}

$GLOBALS['__pf_test_wc'] = (object) ['session' => new PixelFlow_Test_Woo_Session()];

function WC()
{
    return $GLOBALS['__pf_test_wc'];
}

require_once dirname(__DIR__) . '/includes/helpers.php';
require_once dirname(__DIR__) . '/includes/consent.php';
require_once dirname(__DIR__) . '/includes/blocked-events.php';
require_once dirname(__DIR__) . '/includes/held-events.php';
require_once dirname(__DIR__) . '/includes/woo/hooks/class-woocommerce-hooks.php';

/**
 * @param string $state granted|denied
 * @return string Base64 consent cookie value
 */
function pf_consent_cookie(string $state): string
{
    return base64_encode(
        wp_json_encode(['s' => $state, 't' => 1757000000000, 'src' => 'complianz', 'v' => 1])
    );
}

/**
 * Queues one AddToCart recipe in the session.
 *
 * @param string $event_id Identifier the recipe replays
 * @return void
 */
function pf_queue_recipe(string $event_id): void
{
    $payload = [
        'siteId'    => 'site_1',
        'eventData' => [
            'event_id'       => $event_id,
            'eventName'      => 'AddToCart',
            'eventTime'      => 1757000000,
            'additionalData' => [
                'currency'    => 'EUR',
                'value'       => 10.0,
                'contentType' => 'product',
                'contents'    => [['id' => '17', 'quantity' => 1, 'item_price' => 10.0]],
            ],
        ],
    ];

    pixelflow_enqueue_held_woo_event(pixelflow_held_event_recipe_from_payload($payload, 17, 0));
}

function pf_hooks(): PixelFlow_WooCommerce_Cart_Hooks
{
    return new PixelFlow_WooCommerce_Cart_Hooks('https://example.test/api', 'k', 'site', []);
}

$failures = [];
$passes   = 0;

/**
 * @param string   $label
 * @param callable $fn
 */
function pf_run_flush_case(string $label, callable $fn, array &$failures, int &$passes): void
{
    $_GET                        = [];
    $_COOKIE                     = [];
    $GLOBALS['__pf_test_sent']   = [];
    $GLOBALS['__pf_test_wc']     = (object) ['session' => new PixelFlow_Test_Woo_Session()];

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

pf_run_flush_case(
    'The read-only state route does not flush the queue',
    /** @return bool|string */
    function () {
        $_COOKIE[PIXELFLOW_CONSENT_COOKIE_NAME] = pf_consent_cookie('granted');
        pf_queue_recipe('atc-1');
        $_GET['wc-ajax'] = 'pixelflow_held_state';

        pf_hooks()->resolve_held_events_on_page_view();

        if ($GLOBALS['__pf_test_sent'] !== []) {
            return 'asking whether a queue exists must not dispatch it';
        }
        if (pixelflow_get_held_woo_events() === []) {
            return 'the queue must survive for the flush route to send it';
        }

        return true;
    },
    $failures,
    $passes
);

pf_run_flush_case(
    'The flush route is left to its own nonce-checked handler',
    /** @return bool|string */
    function () {
        $_COOKIE[PIXELFLOW_CONSENT_COOKIE_NAME] = pf_consent_cookie('granted');
        pf_queue_recipe('atc-1');
        $_GET['wc-ajax'] = 'pixelflow_resolve_held_events';

        pf_hooks()->resolve_held_events_on_page_view();

        if ($GLOBALS['__pf_test_sent'] !== []) {
            return 'the page-view flush must not run before check_ajax_referer()';
        }

        return true;
    },
    $failures,
    $passes
);

pf_run_flush_case(
    'An ordinary page view still flushes the queue',
    /** @return bool|string */
    function () {
        $_COOKIE[PIXELFLOW_CONSENT_COOKIE_NAME] = pf_consent_cookie('granted');
        pf_queue_recipe('atc-1');

        pf_hooks()->resolve_held_events_on_page_view();

        if (count($GLOBALS['__pf_test_sent']) !== 1) {
            return 'expected one dispatch on a normal page view, got '
                . count($GLOBALS['__pf_test_sent']);
        }
        if (pixelflow_get_held_woo_events() !== []) {
            return 'a flushed queue must be empty afterwards';
        }

        return true;
    },
    $failures,
    $passes
);

pf_run_flush_case(
    'A state route call followed by a flush route call sends each recipe once',
    /** @return bool|string */
    function () {
        $_COOKIE[PIXELFLOW_CONSENT_COOKIE_NAME] = pf_consent_cookie('granted');
        pf_queue_recipe('atc-1');
        pf_queue_recipe('atc-2');

        // The script asks whether there is a queue...
        $_GET['wc-ajax'] = 'pixelflow_held_state';
        pf_hooks()->resolve_held_events_on_page_view();

        // ...and then flushes it.
        $_GET['wc-ajax'] = 'pixelflow_resolve_held_events';
        $hooks           = pf_hooks();
        $hooks->resolve_held_events_on_page_view();
        $hooks->resolve_held_events();

        $ids = array_column($GLOBALS['__pf_test_sent'], 'event_id');
        if (count($ids) !== 2) {
            return 'expected two dispatches for two recipes, got ' . count($ids);
        }
        if (count(array_unique($ids)) !== 2) {
            return 'the same event_id was dispatched twice: ' . implode(', ', $ids);
        }

        return true;
    },
    $failures,
    $passes
);

pf_run_flush_case(
    'Clearing the queue reaches storage before anything is dispatched',
    /** @return bool|string */
    function () {
        pf_queue_recipe('atc-1');
        $session = $GLOBALS['__pf_test_wc']->session;

        pixelflow_clear_held_woo_events();

        if ($session->saves === 0) {
            return 'the clear must be persisted, not left to the shutdown save';
        }
        $stored = $session->persisted[PIXELFLOW_HELD_WOO_EVENTS_SESSION_KEY] ?? null;
        if ($stored !== []) {
            return 'storage must hold an empty queue after the clear';
        }

        return true;
    },
    $failures,
    $passes
);

echo "\n{$passes} passed, " . count($failures) . " failed\n";

exit(count($failures) > 0 ? 1 : 0);
