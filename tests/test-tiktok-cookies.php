<?php
/**
 * TikTok ids from live cookies: `ttp` from `_ttp`, `ttclid` from the `_pf_click_ids` query string.
 *
 * These cases call the cookie helper directly. The hooks that call it, the held-event replay and
 * the debug log are covered through their real paths in test-automation-dedupe-release.php and
 * test-held-replay-context.php; the order-meta cases live in test-purchase-customer-data.php.
 *
 * Run: php tests/test-tiktok-cookies.php
 */

declare(strict_types=1);

require_once __DIR__ . '/bootstrap-wp-stubs.php';
require_once dirname(__DIR__) . '/includes/helpers.php';

$failures = [];
$passes   = 0;

/**
 * @param string   $label
 * @param callable $fn
 */
function pf_run_tiktok_case(string $label, callable $fn, array &$failures, int &$passes): void
{
    $_COOKIE = [];

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

/**
 * The eventData an AddToCart gets from the current request's cookies.
 *
 * @return array<string, mixed>
 */
function pf_tiktok_event_data(): array
{
    $payload = ['eventData' => ['eventName' => 'AddToCart']];
    pixelflow_append_cookie_params($payload);

    return $payload['eventData'];
}

pf_run_tiktok_case(
    'Live cookies copy ttp and ttclid only',
    /** @return bool|string */
    function () {
        $_COOKIE['_ttp']          = 'tiktok-browser-1';
        $_COOKIE['_pf_click_ids'] = 'ttclid=E_C_P_abc&gclid=other';

        $event = pf_tiktok_event_data();
        if (($event['ttp'] ?? null) !== 'tiktok-browser-1') {
            return 'ttp did not reach the payload: ' . wp_json_encode($event);
        }
        if (($event['ttclid'] ?? null) !== 'E_C_P_abc') {
            return 'ttclid did not reach the payload: ' . wp_json_encode($event);
        }
        if (array_key_exists('gclid', $event)) {
            return 'gclid was forwarded from the click-id bag';
        }

        return true;
    },
    $failures,
    $passes
);

pf_run_tiktok_case(
    'A missing TikTok cookie omits the field',
    /** @return bool|string */
    function () {
        $event = pf_tiktok_event_data();
        if (array_key_exists('ttp', $event) || array_key_exists('ttclid', $event)) {
            return 'an absent cookie still emitted a TikTok field: ' . wp_json_encode($event);
        }

        return true;
    },
    $failures,
    $passes
);

pf_run_tiktok_case(
    'Only _ttp present sends ttp and no ttclid',
    /** @return bool|string */
    function () {
        $_COOKIE['_ttp'] = 'tiktok-browser-1';

        $event = pf_tiktok_event_data();
        if (($event['ttp'] ?? null) !== 'tiktok-browser-1') {
            return 'ttp did not reach the payload: ' . wp_json_encode($event);
        }
        if (array_key_exists('ttclid', $event)) {
            return 'ttclid was emitted without a click-id cookie: ' . wp_json_encode($event);
        }

        return true;
    },
    $failures,
    $passes
);

pf_run_tiktok_case(
    'A click-id cookie without ttclid sends no ttclid',
    /** @return bool|string */
    function () {
        $_COOKIE['_pf_click_ids'] = 'gclid=other&fbclid=another';

        $event = pf_tiktok_event_data();
        if (array_key_exists('ttclid', $event)) {
            return 'ttclid was emitted from a bag without one: ' . wp_json_encode($event);
        }

        return true;
    },
    $failures,
    $passes
);

pf_run_tiktok_case(
    'A ttclid that is not a single value sends no ttclid',
    /** @return bool|string */
    function () {
        $_COOKIE['_pf_click_ids'] = 'ttclid[]=x';

        $event = pf_tiktok_event_data();
        if (array_key_exists('ttclid', $event)) {
            return 'an array ttclid reached the payload: ' . wp_json_encode($event);
        }

        return true;
    },
    $failures,
    $passes
);

echo "\n{$passes} passed, " . count($failures) . " failed\n";

exit(count($failures) > 0 ? 1 : 0);
