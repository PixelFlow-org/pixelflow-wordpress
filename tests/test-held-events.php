<?php
/**
 * Held Woo event recipes, session cap, and grant/deny/abandon disposition.
 *
 * Run: php tests/test-held-events.php
 */

declare(strict_types=1);

require_once __DIR__ . '/bootstrap-wp-stubs.php';

if ( ! defined('PIXELFLOW_PLUGIN_BASENAME')) {
    define('PIXELFLOW_PLUGIN_BASENAME', 'pixelflow/pixelflow.php');
}

if ( ! function_exists('is_ssl')) {
    function is_ssl(): bool
    {
        return false;
    }
}

if ( ! defined('COOKIEPATH')) {
    define('COOKIEPATH', '/');
}

/**
 * In-memory stand-in for WC()->session used by the hold queue.
 */
class PixelFlow_Test_Woo_Session
{
    /** @var array<string, mixed> */
    private array $data = [];

    /**
     * @param string $key Session key
     * @return mixed
     */
    public function get(string $key)
    {
        return $this->data[$key] ?? null;
    }

    /**
     * @param string $key   Session key
     * @param mixed  $value Stored value
     * @return void
     */
    public function set(string $key, $value): void
    {
        $this->data[$key] = $value;
    }
}

$GLOBALS['__pf_test_wc'] = (object) [
    'session' => new PixelFlow_Test_Woo_Session(),
];

if ( ! function_exists('WC')) {
    function WC()
    {
        return $GLOBALS['__pf_test_wc'];
    }
}

$GLOBALS['__pf_test_consent_type']      = '';
$GLOBALS['__pf_test_marketing_consent'] = true;

if ( ! function_exists('wp_get_consent_type')) {
    function wp_get_consent_type(): string
    {
        return (string) ($GLOBALS['__pf_test_consent_type'] ?? '');
    }
}

if ( ! function_exists('wp_has_consent')) {
    function wp_has_consent(string $category, ?string $requested_by = null): bool
    {
        if ($category === 'marketing') {
            return (bool) ($GLOBALS['__pf_test_marketing_consent'] ?? false);
        }

        return false;
    }
}

require_once dirname(__DIR__) . '/includes/helpers.php';
require_once dirname(__DIR__) . '/includes/consent.php';
require_once dirname(__DIR__) . '/includes/blocked-events.php';
require_once dirname(__DIR__) . '/includes/held-events.php';

/**
 * @param string $state     granted|denied
 * @param string $source    Consent source wire value
 * @param int    $timestamp Ms timestamp
 * @return string Base64 cookie value
 */
function pf_encode_held_consent_cookie(string $state, string $source, int $timestamp): string
{
    return base64_encode(
        wp_json_encode(
            [
                's'   => $state,
                't'   => $timestamp,
                'src' => $source,
                'v'   => 1,
            ]
        )
    );
}

/**
 * @return array<string, mixed>
 */
function pf_held_add_to_cart_payload(): array
{
    return [
        'siteId'    => 'site_1',
        'eventData' => [
            'event_id'       => 'atc-1',
            'eventName'      => 'AddToCart',
            'eventTime'      => 1757000000,
            'actionSource'   => 'website',
            'siteURL'        => 'https://shop.example',
            'additionalData' => [
                'currency'    => 'EUR',
                'value'       => 24.5,
                'contentType' => 'product',
                'contents'    => [
                    [
                        'id'         => '99',
                        'quantity'   => 2,
                        'item_price' => 12.25,
                    ],
                ],
            ],
            'customerData'   => [
                'em'                  => str_repeat('a', 64),
                'client_ip_address'   => '203.0.113.10',
                'client_user_agent'   => 'Mozilla/5.0',
            ],
        ],
    ];
}

$failures = [];
$passes   = 0;

/**
 * @param string   $label
 * @param callable $fn
 */
function pf_run_held_case(string $label, callable $fn, array &$failures, int &$passes): void
{
    $_COOKIE                                = [];
    $GLOBALS['__pf_test_consent_type']      = '';
    $GLOBALS['__pf_test_marketing_consent'] = true;
    $GLOBALS['__pf_test_wc']->session       = new PixelFlow_Test_Woo_Session();

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

pf_run_held_case(
    'AddToCart recipe keeps value, qty, product ids, hashed PII, and original time',
    /** @return bool|string */
    function () {
        $recipe = pixelflow_held_event_recipe_from_payload(pf_held_add_to_cart_payload(), 99, 0);
        if ($recipe === null) {
            return 'expected a recipe';
        }
        if (($recipe['eventName'] ?? '') !== 'AddToCart' || ($recipe['event_id'] ?? '') !== 'atc-1') {
            return 'event identity mismatch';
        }
        if ((int) ($recipe['eventTime'] ?? 0) !== 1757000000) {
            return 'original eventTime must be kept';
        }
        if ((int) ($recipe['product_id'] ?? 0) !== 99 || (int) ($recipe['qty'] ?? 0) !== 2) {
            return 'product_id/qty mismatch';
        }
        if ((float) ($recipe['value'] ?? 0) !== 24.5 || ($recipe['currency'] ?? '') !== 'EUR') {
            return 'value/currency mismatch';
        }
        if (($recipe['customerData']['em'] ?? '') !== str_repeat('a', 64)) {
            return 'hashed email must be stored';
        }
        if (isset($recipe['customerData']['client_ip_address']) || isset($recipe['customerData']['client_user_agent'])) {
            return 'IP and UA must not be stored';
        }

        return true;
    },
    $failures,
    $passes
);

pf_run_held_case(
    'Purchase is never queued',
    /** @return bool|string */
    function () {
        $payload = pf_held_add_to_cart_payload();
        $payload['eventData']['eventName'] = 'Purchase';
        if (pixelflow_held_event_recipe_from_payload($payload, 1, 0) !== null) {
            return 'Purchase must not produce a recipe';
        }
        $blocked = [ 'reason' => 'no_decision' ];
        if (pixelflow_should_queue_held_event($blocked, 'Purchase')) {
            return 'Purchase must not queue';
        }
        if ( ! pixelflow_should_queue_held_event($blocked, 'AddToCart')) {
            return 'AddToCart hold must queue';
        }

        return true;
    },
    $failures,
    $passes
);

pf_run_held_case(
    'Denied and bot do not queue',
    /** @return bool|string */
    function () {
        if (pixelflow_should_queue_held_event([ 'reason' => 'denied' ], 'AddToCart')) {
            return 'denied must not queue';
        }
        if (pixelflow_should_queue_held_event([ 'reason' => 'bot' ], 'AddToCart')) {
            return 'bot must not queue';
        }

        return true;
    },
    $failures,
    $passes
);

pf_run_held_case(
    'Queue caps at 20 and drops the oldest',
    /** @return bool|string */
    function () {
        for ($i = 1; $i <= 21; $i++) {
            $payload = pf_held_add_to_cart_payload();
            $payload['eventData']['event_id'] = 'atc-' . $i;
            $recipe = pixelflow_held_event_recipe_from_payload($payload, $i, 0);
            if ($recipe === null) {
                return 'recipe failed at ' . $i;
            }
            pixelflow_enqueue_held_woo_event($recipe);
        }
        $queue = pixelflow_get_held_woo_events();
        if (count($queue) !== 20) {
            return 'expected 20 recipes, got ' . count($queue);
        }
        if (($queue[0]['event_id'] ?? '') !== 'atc-2' || ($queue[19]['event_id'] ?? '') !== 'atc-21') {
            return 'oldest should drop; first=' . (string) ($queue[0]['event_id'] ?? '') . ' last=' . (string) ($queue[19]['event_id'] ?? '');
        }
        $cookie = $_COOKIE[PIXELFLOW_HELD_WOO_EVENTS_COOKIE_NAME] ?? '';
        $names  = json_decode((string) $cookie, true);
        if ( ! is_array($names) || count($names) !== 20) {
            return 'cookie should list 20 event names';
        }

        return true;
    },
    $failures,
    $passes
);

pf_run_held_case(
    'Disposition: hold keeps, grant sends, deny denies, leftover abandons',
    /** @return bool|string */
    function () {
        $_COOKIE[PIXELFLOW_NO_CONSENT_DECISION_COOKIE_NAME] = 'true';
        if (pixelflow_held_events_disposition() !== 'keep') {
            return 'live hold must keep';
        }
        unset($_COOKIE[PIXELFLOW_NO_CONSENT_DECISION_COOKIE_NAME]);
        $_COOKIE[PIXELFLOW_CONSENT_COOKIE_NAME] = pf_encode_held_consent_cookie('granted', 'cookieyes', 1756370000000);
        if (pixelflow_held_events_disposition() !== 'send') {
            return 'grant must send';
        }
        $_COOKIE[PIXELFLOW_CONSENT_COOKIE_NAME] = pf_encode_held_consent_cookie('denied', 'cookieyes', 1756370000000);
        if (pixelflow_held_events_disposition() !== 'deny') {
            return 'deny must deny';
        }
        unset($_COOKIE[PIXELFLOW_CONSENT_COOKIE_NAME]);
        if (pixelflow_held_events_disposition() !== 'abandon') {
            return 'no hold and no decision must abandon';
        }

        return true;
    },
    $failures,
    $passes
);

pf_run_held_case(
    'Persisted hold plus live grant flushes rather than keeping',
    /** @return bool|string */
    function () {
        $_COOKIE[PIXELFLOW_CONSENT_COOKIE_NAME] = pf_encode_held_consent_cookie('granted', 'cookieyes', 1756370000000);
        if (pixelflow_held_events_disposition(null, 'true') !== 'send') {
            return 'live grant must flush even when order-meta hold is true';
        }

        return true;
    },
    $failures,
    $passes
);

pf_run_held_case(
    'Enqueue returns false when the Woo session is missing',
    /** @return bool|string */
    function () {
        $GLOBALS['__pf_test_wc']->session = null;
        $recipe = pixelflow_held_event_recipe_from_payload(pf_held_add_to_cart_payload(), 99, 0);
        if ($recipe === null) {
            return 'expected a recipe';
        }
        if (pixelflow_enqueue_held_woo_event($recipe) !== false) {
            return 'missing session must return false';
        }

        return true;
    },
    $failures,
    $passes
);

pf_run_held_case(
    'Flush overlay keeps the stored value over a live rebuild',
    /** @return bool|string */
    function () {
        $additional = pixelflow_apply_held_recipe_to_additional_data(
            [ 'value' => 99.0, 'currency' => 'USD', 'contentType' => 'product' ],
            [ 'value' => 24.5, 'currency' => 'EUR' ]
        );
        if ((float) $additional['value'] !== 24.5 || $additional['currency'] !== 'EUR') {
            return 'stored value/currency must win';
        }

        return true;
    },
    $failures,
    $passes
);

pf_run_held_case(
    'Clear empties the session and the cookie',
    /** @return bool|string */
    function () {
        $recipe = pixelflow_held_event_recipe_from_payload(pf_held_add_to_cart_payload(), 99, 0);
        pixelflow_enqueue_held_woo_event($recipe);
        pixelflow_clear_held_woo_events();
        if (pixelflow_get_held_woo_events() !== []) {
            return 'session queue should be empty';
        }
        if (isset($_COOKIE[PIXELFLOW_HELD_WOO_EVENTS_COOKIE_NAME])) {
            return 'held cookie should be cleared';
        }

        return true;
    },
    $failures,
    $passes
);

echo "\n{$passes} passed, " . count($failures) . " failed\n";

exit(count($failures) > 0 ? 1 : 0);
