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

$GLOBALS['__pf_test_filters'] = [];

function add_filter($hook, $callback, $priority = 10, $accepted_args = 1)
{
    $GLOBALS['__pf_test_filters'][$hook][] = [
        'callback'      => $callback,
        'accepted_args' => $accepted_args,
    ];
}

function remove_filter($hook, $callback, $priority = 10)
{
    if (empty($GLOBALS['__pf_test_filters'][$hook])) {
        return;
    }

    foreach ($GLOBALS['__pf_test_filters'][$hook] as $i => $registration) {
        if ($registration['callback'] === $callback) {
            unset($GLOBALS['__pf_test_filters'][$hook][$i]);
        }
    }
}

function pf_test_reset_filters(): void
{
    $GLOBALS['__pf_test_filters'] = [];
}

/**
 * apply_filters(): runs callbacks registered through add_filter() in
 * registration order, each receiving the previous return value. With no
 * callback hooked to the tag this returns $value unchanged, matching
 * WordPress. A real implementation is needed because
 * pixelflow_if_is_bot() resolves its pattern list through the
 * `pixelflow_useragent_bot_patterns` filter, which is part of the
 * documented contract under test.
 */
function apply_filters($tag, $value, ...$args)
{
    if (empty($GLOBALS['__pf_test_filters'][$tag])) {
        return $value;
    }

    foreach ($GLOBALS['__pf_test_filters'][$tag] as $registration) {
        $call_args = array_slice(array_merge([$value], $args), 0, $registration['accepted_args']);
        $value = call_user_func_array($registration['callback'], $call_args);
    }

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

// ---------------------------------------------------------------------
// Transient stubs (in-memory) with a simulated clock.
//
// The session-less de-duplication fallback in should_send_event() relies
// on set_transient()/get_transient() surviving between requests. A test
// cannot sleep for the TTL, so expiry is evaluated against a settable
// clock: pf_test_advance_clock() moves time forward without waiting.
//
// Each entry is ['value' => mixed, 'expires_at' => int|0], where 0 means
// "no expiry", matching WordPress's treatment of a non-positive $expiration.
// ---------------------------------------------------------------------
$GLOBALS['__pf_test_transients'] = [];
$GLOBALS['__pf_test_clock']      = 1000000000;

function pf_test_now(): int
{
    return $GLOBALS['__pf_test_clock'];
}

function pf_test_advance_clock(int $seconds): void
{
    $GLOBALS['__pf_test_clock'] += $seconds;
}

function pf_test_reset_transients(): void
{
    $GLOBALS['__pf_test_transients'] = [];
}

/**
 * @return array<string, array{value: mixed, expires_at: int}>
 */
function pf_test_all_transients(): array
{
    return $GLOBALS['__pf_test_transients'];
}

function set_transient($key, $value, $expiration = 0)
{
    $GLOBALS['__pf_test_transients'][$key] = [
        'value'      => $value,
        'expires_at' => $expiration > 0 ? pf_test_now() + (int)$expiration : 0,
    ];

    return true;
}

function get_transient($key)
{
    if ( ! isset($GLOBALS['__pf_test_transients'][$key])) {
        return false;
    }

    $entry = $GLOBALS['__pf_test_transients'][$key];

    // Expired entries read as absent, exactly as WordPress does.
    if ($entry['expires_at'] > 0 && pf_test_now() >= $entry['expires_at']) {
        unset($GLOBALS['__pf_test_transients'][$key]);
        return false;
    }

    return $entry['value'];
}

function delete_transient($key)
{
    if ( ! isset($GLOBALS['__pf_test_transients'][$key])) {
        return false;
    }

    unset($GLOBALS['__pf_test_transients'][$key]);

    return true;
}

// ---------------------------------------------------------------------
// WooCommerce session stub.
//
// should_send_event() branches on `function_exists('WC') || ! WC()->session`.
// WC() is declared here unconditionally, so the session-present and
// session-absent paths are selected by pf_test_set_wc_session() rather than
// by whether the function exists — a test cannot undeclare a function.
//
// The client IP and user agent need no stub: pixelflow_get_client_ip_address()
// and pixelflow_get_client_user_agent() read $_SERVER directly, so a test
// controls them by assigning $_SERVER['REMOTE_ADDR'] and
// $_SERVER['HTTP_USER_AGENT'].
// ---------------------------------------------------------------------

class PF_Test_WC_Session
{
    /** @var array<string, mixed> */
    private array $data = [];

    public function get($key, $default = null)
    {
        return $this->data[$key] ?? $default;
    }

    public function set($key, $value): void
    {
        $this->data[$key] = $value;
    }
}

class PF_Test_WC
{
    /** @var PF_Test_WC_Session|null */
    public $session = null;
}

$GLOBALS['__pf_test_wc'] = new PF_Test_WC();

function WC()
{
    return $GLOBALS['__pf_test_wc'];
}

/**
 * @param bool $enabled true = a Woo session is available, false = none
 *                      (the bot / prefetch case).
 */
function pf_test_set_wc_session(bool $enabled): void
{
    $GLOBALS['__pf_test_wc']->session = $enabled ? new PF_Test_WC_Session() : null;
}

// Default to the session-less case, which is what the pre-existing tests
// ran under back when WC() was not declared at all.
pf_test_set_wc_session(false);
