<?php
/**
 * Attribution cookie parser: partial blocks, uid fallback, extra keys stripped.
 *
 * Run: php tests/test-attribution-cookie.php
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
function pf_run_attribution_case(string $label, callable $fn, array &$failures, int &$passes): void
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
 * @param array<string, mixed> $data Attribution JSON
 * @return string
 */
function pf_encode_attribution(array $data): string
{
    return rawurlencode((string) wp_json_encode($data));
}

pf_run_attribution_case(
    'Full attribution block is forwarded with only whitelisted keys',
    /** @return bool|string */
    function () {
        $raw = pf_encode_attribution(
            [
                'visitor_id'  => 'pfv_abc',
                'session_id'  => 'pfs_123',
                'first_touch' => ['timestamp' => 1700000000, 'url' => 'https://shop.example/'],
                'last_touch'  => ['timestamp' => 1700000100],
                'extra'       => 'drop-me',
            ]
        );

        $block = pixelflow_get_attribution_from_cookie($raw);
        if ($block === null) {
            return 'expected attribution block';
        }
        if (($block['visitor_id'] ?? '') !== 'pfv_abc' || ($block['session_id'] ?? '') !== 'pfs_123') {
            return 'expected visitor_id and session_id, got ' . wp_json_encode($block);
        }
        if ( ! isset($block['first_touch']['timestamp'], $block['last_touch']['timestamp'])) {
            return 'expected touch snapshots';
        }
        if (isset($block['extra'])) {
            return 'extra keys must be stripped';
        }

        return true;
    },
    $failures,
    $passes
);

pf_run_attribution_case(
    'Uid-only fallback returns visitor_id and does not invent session_id',
    /** @return bool|string */
    function () {
        $_COOKIE['_pf_uid'] = 'pfv_from_uid';

        $block = pixelflow_get_attribution_from_cookie();
        if ($block === null || ($block['visitor_id'] ?? '') !== 'pfv_from_uid') {
            return 'expected visitor_id from _pf_uid, got ' . wp_json_encode($block);
        }
        if (isset($block['session_id'])) {
            return 'session_id must not be invented';
        }

        return true;
    },
    $failures,
    $passes
);

pf_run_attribution_case(
    'Order-meta uid override supplies visitor_id when cookies are absent',
    /** @return bool|string */
    function () {
        $block = pixelflow_get_attribution_from_cookie(null, 'pfv_order_meta');
        if ($block === null || ($block['visitor_id'] ?? '') !== 'pfv_order_meta') {
            return 'expected visitor_id from uid override, got ' . wp_json_encode($block);
        }

        return true;
    },
    $failures,
    $passes
);

pf_run_attribution_case(
    'Partial attribution without session or touches is accepted',
    /** @return bool|string */
    function () {
        $raw   = pf_encode_attribution(['visitor_id' => 'pfv_partial', 'noise' => 1]);
        $block = pixelflow_get_attribution_from_cookie($raw);
        if ($block === null || ($block['visitor_id'] ?? '') !== 'pfv_partial') {
            return 'expected visitor_id-only block, got ' . wp_json_encode($block);
        }
        if (isset($block['session_id']) || isset($block['noise'])) {
            return 'partial block must not invent session_id or keep extra keys';
        }

        return true;
    },
    $failures,
    $passes
);

pf_run_attribution_case(
    'Invalid attribution JSON still falls back to uid',
    /** @return bool|string */
    function () {
        $block = pixelflow_get_attribution_from_cookie('not-json', 'pfv_fallback');
        if ($block === null || ($block['visitor_id'] ?? '') !== 'pfv_fallback') {
            return 'expected uid fallback after bad JSON, got ' . wp_json_encode($block);
        }

        return true;
    },
    $failures,
    $passes
);

pf_run_attribution_case(
    'No attribution and no uid returns null',
    /** @return bool|string */
    function () {
        if (pixelflow_get_attribution_from_cookie() !== null) {
            return 'expected null when nothing is available';
        }

        return true;
    },
    $failures,
    $passes
);

echo "\n{$passes} passed, " . count($failures) . " failed\n";

exit(count($failures) > 0 ? 1 : 0);
