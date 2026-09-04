<?php
/**
 * Consent send-gate: skip Woo events while holding or denied.
 *
 * Run: php tests/test-consent-send-gate.php
 */

declare(strict_types=1);

require_once __DIR__ . '/bootstrap-wp-stubs.php';

if ( ! defined('PIXELFLOW_PLUGIN_BASENAME')) {
    define('PIXELFLOW_PLUGIN_BASENAME', 'pixelflow/pixelflow.php');
}

require_once dirname(__DIR__) . '/includes/consent.php';

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

/**
 * @param string $state granted|denied
 * @param string $source Consent source wire value
 * @param int    $timestamp Ms timestamp
 * @return string Base64 cookie value
 */
function pf_encode_consent_cookie_for_gate(string $state, string $source, int $timestamp): string
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
function pf_run_send_gate_case(string $label, callable $fn, array &$failures, int &$passes): void
{
    $_COOKIE                                = [];
    $GLOBALS['__pf_test_consent_type']      = '';
    $GLOBALS['__pf_test_marketing_consent'] = true;

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

pf_run_send_gate_case(
    'Hold cookie true skips the send',
    /** @return bool|string */
    function () {
        $_COOKIE[PIXELFLOW_NO_CONSENT_DECISION_COOKIE_NAME] = 'true';
        if (pixelflow_should_send_event_for_consent()) {
            return 'expected skip while _pf_no_consent_decision=true';
        }

        return true;
    },
    $failures,
    $passes
);

pf_run_send_gate_case(
    'Malformed hold cookie is ignored and the event sends',
    /** @return bool|string */
    function () {
        $_COOKIE[PIXELFLOW_NO_CONSENT_DECISION_COOKIE_NAME] = 'yes';
        if ( ! pixelflow_should_send_event_for_consent()) {
            return 'only the literal true value is a hold';
        }

        return true;
    },
    $failures,
    $passes
);

pf_run_send_gate_case(
    'Denied consent cookie skips the send',
    /** @return bool|string */
    function () {
        $_COOKIE[PIXELFLOW_CONSENT_COOKIE_NAME] = pf_encode_consent_cookie_for_gate('denied', 'cookieyes', 1756370000000);
        if (pixelflow_should_send_event_for_consent()) {
            return 'expected skip when _pf_consent is denied';
        }

        return true;
    },
    $failures,
    $passes
);

pf_run_send_gate_case(
    'Granted consent cookie allows the send',
    /** @return bool|string */
    function () {
        $_COOKIE[PIXELFLOW_CONSENT_COOKIE_NAME] = pf_encode_consent_cookie_for_gate('granted', 'cookieyes', 1756370000000);
        if ( ! pixelflow_should_send_event_for_consent()) {
            return 'expected send when _pf_consent is granted';
        }

        return true;
    },
    $failures,
    $passes
);

pf_run_send_gate_case(
    'No cookies allows the send (no-banner / script not loaded)',
    /** @return bool|string */
    function () {
        if ( ! pixelflow_should_send_event_for_consent()) {
            return 'absence of cookies must still send';
        }

        return true;
    },
    $failures,
    $passes
);

pf_run_send_gate_case(
    'Order-meta hold skips Purchase even with no live cookies',
    /** @return bool|string */
    function () {
        if (pixelflow_should_send_event_for_consent(null, 'true')) {
            return 'expected skip from persisted _pf_no_consent_decision';
        }

        return true;
    },
    $failures,
    $passes
);

pf_run_send_gate_case(
    'Order-meta denied consent skips Purchase even with no live cookies',
    /** @return bool|string */
    function () {
        $raw = pf_encode_consent_cookie_for_gate('denied', 'gcm', 1756370001000);
        if (pixelflow_should_send_event_for_consent($raw, null)) {
            return 'expected skip from persisted denied _pf_consent';
        }

        return true;
    },
    $failures,
    $passes
);

pf_run_send_gate_case(
    'Live grant wins over a persisted order-meta hold',
    /** @return bool|string */
    function () {
        $_COOKIE[PIXELFLOW_CONSENT_COOKIE_NAME] = pf_encode_consent_cookie_for_gate('granted', 'cookieyes', 1756370000000);
        if ( ! pixelflow_should_send_event_for_consent(null, 'true')) {
            return 'thank-you grant must send even when the order saved a hold';
        }

        return true;
    },
    $failures,
    $passes
);

pf_run_send_gate_case(
    'Cookie-less Purchase with no snapshot still sends',
    /** @return bool|string */
    function () {
        if ( ! pixelflow_should_send_event_for_consent(null, null)) {
            return 'admin/cron with no snapshot must still send';
        }

        return true;
    },
    $failures,
    $passes
);

echo "\n{$passes} passed, " . count($failures) . " failed\n";

exit(count($failures) > 0 ? 1 : 0);
