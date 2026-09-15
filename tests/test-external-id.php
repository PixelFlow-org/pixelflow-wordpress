<?php
/**
 * Identity resolution: the external_id the plugin derives for an event.
 *
 * The primary formula is sha256(site_external_id . '_' . visitor_id), byte-identical to the
 * PixelFlow browser script's. The fixtures below are literal so a refactor cannot silently
 * diverge from the script: a changed separator, an added normalisation step or a different
 * ordering all fail here rather than in production.
 *
 * Run: php tests/test-external-id.php
 */

declare(strict_types=1);

require_once __DIR__ . '/bootstrap-wp-stubs.php';

if ( ! defined('PIXELFLOW_PLUGIN_BASENAME')) {
    define('PIXELFLOW_PLUGIN_BASENAME', 'pixelflow/pixelflow.php');
}

require_once dirname(__DIR__) . '/includes/helpers.php';

const PF_SITE       = 'wp_0123456789abcdef0123456789abcdef';
const PF_OTHER_SITE = 'wp_ffffffffffffffffffffffffffffffff';
const PF_VISITOR    = '1757000000.123';

/**
 * Builds a raw _pf_attribution value the way the browser script writes it.
 *
 * @param string $visitor_id
 * @return string
 */
function pf_attribution_raw(string $visitor_id): string
{
    return rawurlencode((string) wp_json_encode(['visitor_id' => $visitor_id]));
}

$failures = [];
$passes   = 0;

/**
 * @param string   $label
 * @param callable $fn
 */
function pf_run_identity_case(string $label, callable $fn, array &$failures, int &$passes): void
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

// ---------------------------------------------------------------------
// The formula itself, pinned to literal fixtures.
// ---------------------------------------------------------------------

pf_run_identity_case(
    'Primary formula is sha256(site . "_" . visitor), pinned to a literal fixture',
    /** @return bool|string */
    function () {
        $got = pixelflow_resolve_external_id(PF_SITE, pf_attribution_raw(PF_VISITOR));
        $expected = '1a2f975206c5c3b6bb7dcc4d941f014ccf297eb87a8da66c284227068cd68d44';

        return $got === $expected ? true : "expected {$expected}, got " . var_export($got, true);
    },
    $failures,
    $passes
);

pf_run_identity_case(
    'The same visitor id on two sites produces two different identifiers',
    /** @return bool|string */
    function () {
        $one = pixelflow_resolve_external_id(PF_SITE, pf_attribution_raw(PF_VISITOR));
        $two = pixelflow_resolve_external_id(PF_OTHER_SITE, pf_attribution_raw(PF_VISITOR));

        if ($one === $two) {
            return 'both sites hashed to ' . var_export($one, true);
        }
        if ($two !== '5a1442640b3153d8908a0666fb95bf221ec445b6f71d3612fffb775c3c82e1d7') {
            return 'second site hashed to an unexpected value: ' . var_export($two, true);
        }

        return true;
    },
    $failures,
    $passes
);

pf_run_identity_case(
    'Uppercase survives: the hash input is not case-folded',
    /** @return bool|string */
    function () {
        $got      = pixelflow_resolve_external_id('WP_AbCdEf', pf_attribution_raw('VisitorID.42'));
        $expected = '232955c6c1a258de1ea9d02e936288fd333099804b37602b1034b9e764d2c5b8';
        $lowered  = 'b2ca6b5a59d90fc87d354475e75160d2c02524130e75f57acef567010064808c';

        if ($got === $lowered) {
            return 'the input was lowercased before hashing';
        }

        return $got === $expected ? true : "expected {$expected}, got " . var_export($got, true);
    },
    $failures,
    $passes
);

pf_run_identity_case(
    'A visitor id longer than 64 characters hashes the truncated value',
    /** @return bool|string */
    function () {
        // The identity path reuses pixelflow_resolve_attribution_visitor_id(), which caps the
        // visitor id at 64 characters. Ids the current script generates are far shorter, so the
        // cap never binds today; this pins the boundary so a future longer id fails here rather
        // than splitting one shopper across the plugin and the script.
        $long     = str_repeat('a', 70);
        $got      = pixelflow_resolve_external_id(PF_SITE, pf_attribution_raw($long));
        $expected = '4234ea663cbb9cc7c25e0173ddfcdd8059a71b6d94d0b8735397a9714ed734e4';
        $full     = '88cb8b32196d7ec04c8d2d0171b278b710a7ece6eee620fc129fc49937820927';

        if ($got === $full) {
            return 'the full 70-character id was hashed; the 64-character cap no longer applies';
        }

        return $got === $expected ? true : "expected {$expected}, got " . var_export($got, true);
    },
    $failures,
    $passes
);

// ---------------------------------------------------------------------
// Resolution order: attribution payload, then order meta, then live cookie.
// ---------------------------------------------------------------------

pf_run_identity_case(
    'The attribution payload is the first source',
    /** @return bool|string */
    function () {
        $_COOKIE['_pf_uid'] = 'cookie-visitor';
        $got = pixelflow_resolve_external_id(PF_SITE, pf_attribution_raw(PF_VISITOR), 'order-visitor');

        return $got === hash('sha256', PF_SITE . '_' . PF_VISITOR)
            ? true
            : 'the attribution payload did not win: ' . var_export($got, true);
    },
    $failures,
    $passes
);

pf_run_identity_case(
    'The order-meta visitor id is used when no attribution is stored',
    /** @return bool|string */
    function () {
        $got = pixelflow_resolve_external_id(PF_SITE, null, 'order-visitor');

        return $got === hash('sha256', PF_SITE . '_order-visitor')
            ? true
            : 'unexpected: ' . var_export($got, true);
    },
    $failures,
    $passes
);

pf_run_identity_case(
    'The live _pf_uid cookie is used when the request is the buyer\'s',
    /** @return bool|string */
    function () {
        $_COOKIE['_pf_uid'] = 'cookie-visitor';
        $got = pixelflow_resolve_external_id(PF_SITE);

        return $got === hash('sha256', PF_SITE . '_cookie-visitor')
            ? true
            : 'unexpected: ' . var_export($got, true);
    },
    $failures,
    $passes
);

// ---------------------------------------------------------------------
// Nothing else may stand in for a visitor id.
// ---------------------------------------------------------------------

pf_run_identity_case(
    'Nothing identifies the request: the resolver returns null',
    /** @return bool|string */
    function () {
        $got = pixelflow_resolve_external_id(PF_SITE);

        return $got === null ? true : 'expected null, got ' . var_export($got, true);
    },
    $failures,
    $passes
);

pf_run_identity_case(
    'A request carrying only _fbp resolves to null',
    /** @return bool|string */
    function () {
        $_COOKIE['_fbp'] = 'fb.1.1757000000.9876543210';
        $got = pixelflow_resolve_external_id(PF_SITE);

        return $got === null ? true : 'the Facebook cookie was used as identity: ' . var_export($got, true);
    },
    $failures,
    $passes
);

pf_run_identity_case(
    'An unconfigured site external id resolves to null',
    /** @return bool|string */
    function () {
        $got = pixelflow_resolve_external_id('', pf_attribution_raw(PF_VISITOR));

        return $got === null ? true : 'expected null, got ' . var_export($got, true);
    },
    $failures,
    $passes
);

// ---------------------------------------------------------------------
// The buyer gate: no live cookie may reach the identity on someone else's request.
// ---------------------------------------------------------------------

pf_run_identity_case(
    'A staff member\'s live _pf_uid is ignored when the request is not the buyer\'s',
    /** @return bool|string */
    function () {
        $_COOKIE['_pf_uid'] = 'staff-visitor';
        $got = pixelflow_resolve_external_id(PF_SITE, null, null, false);

        return $got === null ? true : 'the staff cookie reached the identity: ' . var_export($got, true);
    },
    $failures,
    $passes
);

pf_run_identity_case(
    'A staff member\'s live _pf_attribution is ignored when the request is not the buyer\'s',
    /** @return bool|string */
    function () {
        // The order has nothing stored of its own, which is the path that leaked: an absent
        // override used to read as permission to fall back to $_COOKIE.
        $_COOKIE['_pf_attribution'] = pf_attribution_raw('staff-visitor');
        $got = pixelflow_resolve_external_id(PF_SITE, null, null, false);

        return $got === null ? true : 'the staff attribution reached the identity: ' . var_export($got, true);
    },
    $failures,
    $passes
);

pf_run_identity_case(
    'The order\'s own stored values are still used on a request that is not the buyer\'s',
    /** @return bool|string */
    function () {
        $_COOKIE['_pf_uid'] = 'staff-visitor';
        $got = pixelflow_resolve_external_id(PF_SITE, null, 'order-visitor', false);

        return $got === hash('sha256', PF_SITE . '_order-visitor')
            ? true
            : 'unexpected: ' . var_export($got, true);
    },
    $failures,
    $passes
);

echo "\n{$passes} passed, " . count($failures) . " failed\n";

exit(count($failures) > 0 ? 1 : 0);
