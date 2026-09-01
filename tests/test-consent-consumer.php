<?php
/**
 * Consent consumer tests for ticket 11-wordpress-plugin-consent.
 *
 * Run: php tests/test-consent-consumer.php
 */

declare(strict_types=1);

require_once __DIR__ . '/bootstrap-wp-stubs.php';

if ( ! defined('PIXELFLOW_PLUGIN_BASENAME')) {
    define('PIXELFLOW_PLUGIN_BASENAME', 'pixelflow/pixelflow.php');
}

require_once dirname(__DIR__) . '/includes/consent.php';

$GLOBALS['__pf_test_consent_type']   = '';
$GLOBALS['__pf_test_marketing_consent'] = true;

function wp_get_consent_type(): string
{
    return (string) ($GLOBALS['__pf_test_consent_type'] ?? '');
}

function wp_has_consent(string $category, ?string $requested_by = null): bool
{
    if ($category === 'marketing') {
        return (bool) ($GLOBALS['__pf_test_marketing_consent'] ?? false);
    }

    return false;
}

/**
 * @param string $state granted|denied
 * @param string $source Consent source wire value
 * @param int    $timestamp Ms timestamp
 * @return string Base64 cookie value
 */
function pf_encode_consent_cookie(string $state, string $source, int $timestamp): string
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

$failures = [];
$passes   = 0;

/**
 * @param string   $label
 * @param callable $fn
 */
function pf_run_consent_case(string $label, callable $fn, array &$failures, int &$passes): void
{
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

pf_run_consent_case(
    'WP Consent API maps marketing grant to consent block',
    /** @return bool|string */
    function () {
        $GLOBALS['__pf_test_consent_type']      = 'optin';
        $GLOBALS['__pf_test_marketing_consent'] = true;
        $_COOKIE                                = [];

        $block = pixelflow_resolve_event_consent_block();
        if ($block === null) {
            return 'expected consent block';
        }
        if ($block['state'] !== 'granted' || $block['source'] !== 'api') {
            return 'expected granted/api from WP Consent API';
        }

        return true;
    },
    $failures,
    $passes
);

pf_run_consent_case(
    'WP Consent API maps marketing deny to denied consent block',
    /** @return bool|string */
    function () {
        $GLOBALS['__pf_test_consent_type']      = 'optin';
        $GLOBALS['__pf_test_marketing_consent'] = false;
        $_COOKIE                                = [];

        $block = pixelflow_resolve_event_consent_block();
        if ($block === null) {
            return 'expected consent block';
        }
        if ($block['state'] !== 'denied' || $block['source'] !== 'api') {
            return 'expected denied/api from WP Consent API';
        }

        return true;
    },
    $failures,
    $passes
);

pf_run_consent_case(
    'Cookie fallback parses _pf_consent when WP Consent API inactive',
    /** @return bool|string */
    function () {
        $GLOBALS['__pf_test_consent_type'] = '';
        $_COOKIE[PIXELFLOW_CONSENT_COOKIE_NAME] = pf_encode_consent_cookie('granted', 'cache', 1756370000000);

        $block = pixelflow_resolve_event_consent_block();
        if ($block === null) {
            return 'expected consent block from cookie';
        }
        if ($block['state'] !== 'granted' || $block['source'] !== 'cache' || $block['timestamp'] !== 1756370000000) {
            return 'unexpected cookie-derived consent block';
        }

        return true;
    },
    $failures,
    $passes
);

pf_run_consent_case(
    'No consent information leaves payload without consent block',
    /** @return bool|string */
    function () {
        $GLOBALS['__pf_test_consent_type'] = '';
        $_COOKIE                           = [];

        $payload = ['siteId' => 'site', 'eventData' => ['eventName' => 'AddToCart']];
        pixelflow_append_consent_to_payload($payload);

        if (isset($payload['eventData']['consent'])) {
            return 'consent block should be absent';
        }

        return true;
    },
    $failures,
    $passes
);

pf_run_consent_case(
    'Cookie with visitor identifier is rejected',
    /** @return bool|string */
    function () {
        $GLOBALS['__pf_test_consent_type'] = '';
        $_COOKIE[PIXELFLOW_CONSENT_COOKIE_NAME] = base64_encode(
            wp_json_encode(
                [
                    's'         => 'granted',
                    't'         => 1756370000000,
                    'src'       => 'cache',
                    'v'         => 1,
                    'visitorId' => 'pfv_test',
                ]
            )
        );

        $block = pixelflow_resolve_event_consent_block();
        if ($block !== null) {
            return 'tampered cookie should not produce a consent block';
        }

        return true;
    },
    $failures,
    $passes
);

pf_run_consent_case(
    'Order meta override supplies consent when WP Consent API inactive',
    /** @return bool|string */
    function () {
        $GLOBALS['__pf_test_consent_type'] = '';
        $_COOKIE                           = [];

        $raw = pf_encode_consent_cookie('denied', 'gcm', 1756370001000);
        $block = pixelflow_resolve_event_consent_block($raw);

        if ($block === null) {
            return 'expected override consent block';
        }
        if ($block['state'] !== 'denied' || $block['source'] !== 'gcm') {
            return 'unexpected override consent block';
        }

        return true;
    },
    $failures,
    $passes
);

pf_run_consent_case(
    'append_consent_to_payload attaches block under eventData.consent',
    /** @return bool|string */
    function () {
        $GLOBALS['__pf_test_consent_type']      = 'optin';
        $GLOBALS['__pf_test_marketing_consent'] = true;
        $_COOKIE                                = [];

        $payload = ['siteId' => 'site', 'eventData' => ['eventName' => 'Purchase']];
        pixelflow_append_consent_to_payload($payload);

        if ( ! isset($payload['eventData']['consent']['state'])) {
            return 'eventData.consent.state missing';
        }

        return true;
    },
    $failures,
    $passes
);

echo "\n{$passes} passed, " . count($failures) . " failed\n";

exit(count($failures) > 0 ? 1 : 0);
