<?php
/**
 * Blocked-events reason mapping and anonymous payload shape.
 *
 * Run: php tests/test-blocked-events.php
 */

declare(strict_types=1);

require_once __DIR__ . '/bootstrap-wp-stubs.php';

if ( ! defined('PIXELFLOW_PLUGIN_BASENAME')) {
    define('PIXELFLOW_PLUGIN_BASENAME', 'pixelflow/pixelflow.php');
}

require_once dirname(__DIR__) . '/includes/helpers.php';
require_once dirname(__DIR__) . '/includes/consent.php';
require_once dirname(__DIR__) . '/includes/blocked-events.php';

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
function pf_encode_consent_cookie_for_blocked(string $state, string $source, int $timestamp): string
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
function pf_run_blocked_case(string $label, callable $fn, array &$failures, int &$passes): void
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

pf_run_blocked_case(
    'Hold cookie maps to no_decision without consentSource',
    /** @return bool|string */
    function () {
        $_COOKIE[PIXELFLOW_NO_CONSENT_DECISION_COOKIE_NAME] = 'true';
        $row = pixelflow_resolve_blocked_event_reason();
        if ($row === null || ($row['reason'] ?? '') !== 'no_decision') {
            return 'expected no_decision';
        }
        if (isset($row['consentSource'])) {
            return 'hold with no consent cookie must omit consentSource';
        }

        return true;
    },
    $failures,
    $passes
);

pf_run_blocked_case(
    'Denied cookie maps to denied with cookie source',
    /** @return bool|string */
    function () {
        $_COOKIE[PIXELFLOW_CONSENT_COOKIE_NAME] = pf_encode_consent_cookie_for_blocked('denied', 'cookieyes', 1756370000000);
        $row = pixelflow_resolve_blocked_event_reason();
        if ($row === null || ($row['reason'] ?? '') !== 'denied') {
            return 'expected denied';
        }
        if (($row['consentSource'] ?? '') !== 'cookieyes') {
            return 'expected consentSource cookieyes, got ' . (string) ($row['consentSource'] ?? 'null');
        }

        return true;
    },
    $failures,
    $passes
);

pf_run_blocked_case(
    'Order-meta hold maps to no_decision with no live cookies',
    /** @return bool|string */
    function () {
        $row = pixelflow_resolve_blocked_event_reason(null, 'true');
        if ($row === null || ($row['reason'] ?? '') !== 'no_decision') {
            return 'expected no_decision from persisted hold';
        }

        return true;
    },
    $failures,
    $passes
);

pf_run_blocked_case(
    'Order-meta denied maps to denied with persisted source',
    /** @return bool|string */
    function () {
        $raw = pf_encode_consent_cookie_for_blocked('denied', 'gcm', 1756370001000);
        $row = pixelflow_resolve_blocked_event_reason($raw, null);
        if ($row === null || ($row['reason'] ?? '') !== 'denied') {
            return 'expected denied from persisted cookie';
        }
        if (($row['consentSource'] ?? '') !== 'gcm') {
            return 'expected consentSource gcm';
        }

        return true;
    },
    $failures,
    $passes
);

pf_run_blocked_case(
    'Bot pattern maps to bot and detail is the pattern not the UA',
    /** @return bool|string */
    function () {
        $ua  = 'Mozilla/5.0 HeadlessChrome/120.0';
        $row = pixelflow_resolve_blocked_event_reason(null, null, pixelflow_get_bot_detail_pattern($ua));
        if ($row === null || ($row['reason'] ?? '') !== 'bot') {
            return 'expected bot';
        }
        if (($row['detail'] ?? '') !== 'headless') {
            return 'expected detail headless, got ' . (string) ($row['detail'] ?? 'null');
        }
        if (isset($row['consentSource'])) {
            return 'bot rows must omit consentSource';
        }

        return true;
    },
    $failures,
    $passes
);

pf_run_blocked_case(
    'Bot wins over hold',
    /** @return bool|string */
    function () {
        $_COOKIE[PIXELFLOW_NO_CONSENT_DECISION_COOKIE_NAME] = 'true';
        $row = pixelflow_resolve_blocked_event_reason(null, null, 'headless');
        if ($row === null || ($row['reason'] ?? '') !== 'bot') {
            return 'bot must beat no_decision';
        }

        return true;
    },
    $failures,
    $passes
);

pf_run_blocked_case(
    'Granted consent does not beacon',
    /** @return bool|string */
    function () {
        $_COOKIE[PIXELFLOW_CONSENT_COOKIE_NAME] = pf_encode_consent_cookie_for_blocked('granted', 'cookieyes', 1756370000000);
        if (pixelflow_resolve_blocked_event_reason() !== null) {
            return 'granted must not produce a blocked row';
        }

        return true;
    },
    $failures,
    $passes
);

pf_run_blocked_case(
    'No cookies does not beacon',
    /** @return bool|string */
    function () {
        if (pixelflow_resolve_blocked_event_reason() !== null) {
            return 'absence of cookies must not beacon';
        }

        return true;
    },
    $failures,
    $passes
);

pf_run_blocked_case(
    'Cookie-less Purchase with no snapshot does not beacon',
    /** @return bool|string */
    function () {
        if (pixelflow_resolve_blocked_event_reason(null, null) !== null) {
            return 'admin/cron with no snapshot must not beacon';
        }

        return true;
    },
    $failures,
    $passes
);

pf_run_blocked_case(
    'Payload is anonymous and strips illegal fields',
    /** @return bool|string */
    function () {
        $payload = pixelflow_build_blocked_events_payload(
            'site_1',
            'AddToCart',
            [
                'reason'        => 'denied',
                'consentSource' => 'cookieyes',
                'detail'        => 'headless',
                'visitor_id'    => 'should-not-appear',
            ]
        );
        if ($payload === null) {
            return 'expected a payload';
        }
        if (($payload['siteId'] ?? '') !== 'site_1') {
            return 'siteId mismatch';
        }
        $entry = $payload['blocked'][0] ?? [];
        if (($entry['eventType'] ?? '') !== 'AddToCart' || ($entry['reason'] ?? '') !== 'denied') {
            return 'eventType/reason mismatch';
        }
        if (($entry['consentSource'] ?? '') !== 'cookieyes') {
            return 'consentSource should stay on denied';
        }
        if (isset($entry['detail'])) {
            return 'detail must be stripped on denied';
        }
        if (isset($entry['visitor_id']) || array_key_exists('visitor_id', $payload)) {
            return 'visitor_id must not appear';
        }

        return true;
    },
    $failures,
    $passes
);

pf_run_blocked_case(
    'Payload strips consentSource on bot and detail on deny already covered; bot keeps detail',
    /** @return bool|string */
    function () {
        $payload = pixelflow_build_blocked_events_payload(
            'site_1',
            'Purchase',
            [
                'reason'        => 'bot',
                'detail'        => 'headless',
                'consentSource' => 'cookieyes',
            ]
        );
        if ($payload === null) {
            return 'expected a payload';
        }
        $entry = $payload['blocked'][0] ?? [];
        if (($entry['detail'] ?? '') !== 'headless') {
            return 'bot must keep detail';
        }
        if (isset($entry['consentSource'])) {
            return 'bot must strip consentSource';
        }

        return true;
    },
    $failures,
    $passes
);

pf_run_blocked_case(
    'Payload drops consent_mode_disabled as consentSource',
    /** @return bool|string */
    function () {
        $payload = pixelflow_build_blocked_events_payload(
            'site_1',
            'AddToCart',
            [
                'reason'        => 'denied',
                'consentSource' => 'consent_mode_disabled',
            ]
        );
        if ($payload === null) {
            return 'expected a payload';
        }
        $entry = $payload['blocked'][0] ?? [];
        if (isset($entry['consentSource'])) {
            return 'consent_mode_disabled must be omitted';
        }

        return true;
    },
    $failures,
    $passes
);

pf_run_blocked_case(
    'Empty site id or event type yields no payload',
    /** @return bool|string */
    function () {
        if (pixelflow_build_blocked_events_payload('', 'AddToCart', [ 'reason' => 'bot' ]) !== null) {
            return 'empty siteId must be null';
        }
        if (pixelflow_build_blocked_events_payload('site_1', '', [ 'reason' => 'bot' ]) !== null) {
            return 'empty eventType must be null';
        }

        return true;
    },
    $failures,
    $passes
);

echo "\n{$passes} passed, " . count($failures) . " failed\n";

exit(count($failures) > 0 ? 1 : 0);
