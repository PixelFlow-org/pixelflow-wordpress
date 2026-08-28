<?php
/**
 * Minimal WordPress/WooCommerce function stubs for standalone `php` execution
 * of the PixelFlow_WooCommerce_Cart_Hooks repro test (T-001).
 *
 * This project has no PHPUnit/composer setup, so this is a lightweight,
 * dependency-free stand-in for wp-load.php: it defines just enough of the
 * WordPress hook system and the handful of core functions that
 * includes/helpers.php and includes/woo/hooks/class-woocommerce-hooks.php
 * touch on the code paths exercised by this test.
 *
 * Not a general-purpose WP test harness — only extend it if a future test
 * in this directory needs another stub.
 */

if ( ! defined('ABSPATH')) {
    define('ABSPATH', __DIR__ . '/');
}

// ---------------------------------------------------------------------
// Minimal action hook system (mirrors wp-includes/class-wp-hook.php just
// enough to reproduce the same call-boundary behavior: do_action() slices
// the passed args down to the callback's registered $accepted_args and
// invokes it with call_user_func_array(), which is exactly where PHP's
// argument-type enforcement fires for a strictly-typed callback param).
// ---------------------------------------------------------------------
$GLOBALS['__pf_test_actions'] = [];

function add_action($hook, $callback, $priority = 10, $accepted_args = 1)
{
    $GLOBALS['__pf_test_actions'][$hook][] = [
        'callback'      => $callback,
        'accepted_args' => $accepted_args,
    ];
}

function do_action($hook, ...$args)
{
    if (empty($GLOBALS['__pf_test_actions'][$hook])) {
        return;
    }
    foreach ($GLOBALS['__pf_test_actions'][$hook] as $registration) {
        $call_args = array_slice($args, 0, $registration['accepted_args']);
        call_user_func_array($registration['callback'], $call_args);
    }
}

function add_filter($hook, $callback, $priority = 10, $accepted_args = 1)
{
    // Not exercised by the code paths under test (early-returns happen
    // before any add_filter() call in pf_add_to_cart_hook), but stub it
    // so nothing fatals if that changes.
}

function remove_filter($hook, $callback, $priority = 10)
{
}

/**
 * Simplified apply_filters(): no filters are registered in this harness,
 * so it always returns the provided default value — matching WordPress's
 * own behavior when no callback is hooked to the given tag.
 */
function apply_filters($tag, $value, ...$args)
{
    return $value;
}

function is_admin(): bool
{
    return false;
}

function sanitize_text_field($str)
{
    return is_string($str) ? trim($str) : $str;
}

function wp_unslash($value)
{
    return $value;
}

function wp_json_encode($data, $options = 0, $depth = 512)
{
    return json_encode($data, $options, $depth);
}

/**
 * wc_get_product() stub: records the id it was called with (so the test
 * can assert product-id resolution / AC-2) and returns null, which makes
 * pf_add_to_cart_hook() return early at its "if (!$product) return;"
 * guard — deliberately, so this harness does not need to stub the rest of
 * WooCommerce (WC_Product, wc_get_price_to_display, wp_remote_post, etc.)
 * to prove the TypeError / null-handling behavior this test targets.
 */
function wc_get_product($id)
{
    $GLOBALS['__pf_test_last_wc_get_product_id'] = $id;
    return null;
}
