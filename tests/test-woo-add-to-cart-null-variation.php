<?php
/**
 * Repro test for T-001-woo-add-to-cart-null-variation.
 *
 * Reproduces the reported PHP fatal TypeError in
 * PixelFlow_WooCommerce_Cart_Hooks::pf_add_to_cart_hook() when a 3rd-party
 * integration (e.g. CheckoutWC Order Bumps) fires `woocommerce_add_to_cart`
 * with `null` in place of the variation-related args — specifically the
 * gap the analysis identified: a null 4th arg ($variation_id), which the
 * current working-tree code does NOT protect against (it still declares
 * `int $variation_id = 0`, which rejects an explicitly-passed null).
 *
 * No PHPUnit/composer is set up in this repo, so this is a plain, minimal
 * `php`-runnable script. Run with:
 *
 *   php tests/test-woo-add-to-cart-null-variation.php
 *
 * Exit code 0 = all assertions passed (bug is fixed).
 * Exit code 1 = at least one assertion failed (bug reproduced / regression).
 *
 * Acceptance criteria under test (verbatim IDs from analysis.md):
 *   AC-1, AC-2, AC-3, AC-4, AC-5
 */

declare(strict_types=1);

require_once __DIR__ . '/bootstrap-wp-stubs.php';
require_once dirname(__DIR__) . '/includes/helpers.php';
require_once dirname(__DIR__) . '/includes/consent.php';
require_once dirname(__DIR__) . '/includes/blocked-events.php';
require_once dirname(__DIR__) . '/includes/held-events.php';
require_once dirname(__DIR__) . '/includes/woo/hooks/class-woocommerce-hooks.php';

// Public (non-private) IP so pixelflow_is_cache_warmer_request() resolves
// to false and PixelFlow_WooCommerce_Cart_Hooks::init_hooks() actually
// registers the woocommerce_add_to_cart action (needed to exercise this
// through do_action(), per AC-1's literal wording).
$_SERVER['REMOTE_ADDR'] = '203.0.113.10';

$failures = [];
$passes   = 0;

/**
 * @param string $label
 * @param callable $fn Should return true (pass) or a string (failure message).
 */
function pf_run_case(string $label, callable $fn, array &$failures, int &$passes): void
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

/**
 * Wraps a callable so any PHP warning/notice/deprecation raised inside it
 * is captured and reported instead of silently passing (AC-3: "no
 * notices/warnings emitted").
 */
function pf_capture_php_diagnostics(callable $fn)
{
    $diagnostics = [];
    set_error_handler(function (int $errno, string $errstr) use (&$diagnostics) {
        $diagnostics[] = $errstr;
        return true; // don't fall through to PHP's default handler
    });

    try {
        $fn();
    } finally {
        restore_error_handler();
    }

    return $diagnostics;
}

function pf_new_hooks(): PixelFlow_WooCommerce_Cart_Hooks
{
    // Fresh instance per test case so the in-request dedupe guard
    // (should_send_event()) never masks a case with leftover state.
    return new PixelFlow_WooCommerce_Cart_Hooks(
        'https://example.test/api',
        'test-api-key',
        'site-external-id',
        []
    );
}

// ---------------------------------------------------------------------
// AC-1 / AC-2 / AC-3: do_action('woocommerce_add_to_cart', $key, $pid, $qty, null, null, null)
// simulating CheckoutWC Order Bumps — must not TypeError/fatal, must treat
// null $variation_id as 0 (falls back to $product_id), and null
// $variation/$cart_item_data as [] with no notices/warnings.
// ---------------------------------------------------------------------
pf_run_case('AC-1/AC-2/AC-3: null variation_id/variation/cart_item_data via do_action', function () {
    $hooks = pf_new_hooks();
    unset($GLOBALS['__pf_test_last_wc_get_product_id']);

    $diagnostics = pf_capture_php_diagnostics(function () use ($hooks) {
        // Mirrors CheckoutWC Order Bumps' reported call shape exactly.
        do_action('woocommerce_add_to_cart', 'ci_key_1', 42, 1, null, null, null);
    });

    if (($GLOBALS['__pf_test_last_wc_get_product_id'] ?? null) !== 42) {
        return sprintf(
            'AC-2 violated: expected wc_get_product() to be called with product_id 42 '
            . '(null $variation_id must fall back to $product_id), got %s',
            var_export($GLOBALS['__pf_test_last_wc_get_product_id'] ?? null, true)
        );
    }

    if ( ! empty($diagnostics)) {
        return 'AC-3 violated: PHP notice/warning emitted: ' . implode(' | ', $diagnostics);
    }

    return true;
}, $failures, $passes);

// ---------------------------------------------------------------------
// AC-4: normal (non-null) WooCommerce-core-style invocation must keep
// working exactly as before — event flow reaches product resolution,
// variation_id (when > 0) takes precedence over product_id.
// ---------------------------------------------------------------------
pf_run_case('AC-4: standard non-null add-to-cart call still resolves the variation product', function () {
    $hooks = pf_new_hooks();
    unset($GLOBALS['__pf_test_last_wc_get_product_id']);

    do_action('woocommerce_add_to_cart', 'ci_key_2', 42, 2, 99, ['attr' => 'val'], ['foo' => 'bar']);

    if (($GLOBALS['__pf_test_last_wc_get_product_id'] ?? null) !== 99) {
        return sprintf(
            'expected wc_get_product() to be called with variation_id 99 (unchanged classic behavior), got %s',
            var_export($GLOBALS['__pf_test_last_wc_get_product_id'] ?? null, true)
        );
    }

    return true;
}, $failures, $passes);

// ---------------------------------------------------------------------
// AC-4 (dedupe still applied): a second do_action() with the SAME
// cart_item_key within the same request must not resolve the product
// again (should_send_event() dedupe guard must still block it).
// ---------------------------------------------------------------------
pf_run_case('AC-4: dedupe still applied for repeated cart_item_key in one request', function () {
    $hooks = pf_new_hooks();
    unset($GLOBALS['__pf_test_last_wc_get_product_id']);

    do_action('woocommerce_add_to_cart', 'ci_key_dedupe', 10, 1, 0, [], []);
    if (($GLOBALS['__pf_test_last_wc_get_product_id'] ?? null) !== 10) {
        return 'first call did not resolve product_id 10 as expected (setup broken)';
    }

    unset($GLOBALS['__pf_test_last_wc_get_product_id']);
    do_action('woocommerce_add_to_cart', 'ci_key_dedupe', 10, 1, 0, [], []);

    if (array_key_exists('__pf_test_last_wc_get_product_id', $GLOBALS)) {
        return 'dedupe guard did not block the second call for the same cart_item_key — wc_get_product() was called again';
    }

    return true;
}, $failures, $passes);

// ---------------------------------------------------------------------
// AC-5: the fix must stay narrowly scoped — $cart_item_key, $product_id,
// $quantity must KEEP their existing type strictness. A null $product_id
// (2nd arg) must still raise a TypeError, both before and after the fix.
// ---------------------------------------------------------------------
pf_run_case('AC-5: $product_id stays strictly typed (null still rejected)', function () {
    $hooks = pf_new_hooks();

    try {
        do_action('woocommerce_add_to_cart', 'ci_key_3', null, 1, 0, [], []);
    } catch (\TypeError $e) {
        return true; // expected — strict typing preserved
    }

    return 'expected a TypeError for null $product_id (AC-5: strictness must be preserved), none was thrown';
}, $failures, $passes);

echo "\n" . count($failures) . ' failing / ' . ($passes) . " passing (of " . ($passes + count($failures)) . " cases)\n";

if ( ! empty($failures)) {
    echo "\nFailures:\n";
    foreach ($failures as $f) {
        echo " - {$f}\n";
    }
    exit(1);
}

exit(0);
