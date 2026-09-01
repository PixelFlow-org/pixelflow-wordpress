<?php
/**
 * Tests for the session-less de-duplication fallback in
 * PixelFlow_WooCommerce_Cart_Hooks::should_send_event().
 *
 * Covers the "Repeated events are suppressed without a commerce session"
 * requirement of the event-noise-filtering capability.
 *
 * Background: WooCommerce's non-AJAX `?add-to-cart=<id>` URLs re-add a
 * product on any GET of that URL, so a prefetcher, crawler or reload
 * produces a second event. Those requests carry no Woo session, and the
 * session-less branch of should_send_event() used to return true
 * unconditionally — leaving only a per-request guard, which cannot see a
 * repeat that arrives as a separate request.
 *
 * should_send_event() is private, and in this harness both the "send" and
 * "suppress" outcomes make pf_add_to_cart_hook() return without an
 * observable difference, so there is no black-box seam to assert through.
 * The tests therefore call it via reflection, treating it as the unit
 * under test.
 *
 * Run with:
 *
 *   php tests/test-session-less-dedupe.php
 *
 * Exit code 0 = all assertions passed.
 * Exit code 1 = at least one assertion failed.
 */

declare(strict_types=1);

require_once __DIR__ . '/bootstrap-wp-stubs.php';
require_once dirname(__DIR__) . '/includes/helpers.php';
require_once dirname(__DIR__) . '/includes/woo/hooks/class-woocommerce-hooks.php';

$failures = [];
$passes   = 0;

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

function pf_new_hooks(): PixelFlow_WooCommerce_Cart_Hooks
{
    // A fresh instance per simulated request: $sent_in_request is the
    // per-request guard, so reusing an instance would mask the
    // cross-request behavior these tests target.
    return new PixelFlow_WooCommerce_Cart_Hooks(
        'https://example.test/api',
        'test-api-key',
        'site-external-id',
        []
    );
}

/**
 * Invokes the private should_send_event() on a fresh instance, i.e. as a
 * separate request would.
 */
function pf_should_send(string $key, int $ttl): bool
{
    $method = new ReflectionMethod(PixelFlow_WooCommerce_Cart_Hooks::class, 'should_send_event');
    $method->setAccessible(true);

    return (bool)$method->invoke(pf_new_hooks(), $key, $ttl);
}

/**
 * Resets everything a case depends on: transients, clock and client identity.
 */
function pf_reset_request_state(string $ip = '203.0.113.10', string $ua = 'Mozilla/5.0 (X11; Linux x86_64) Chrome/148.0.0.0'): void
{
    pf_test_reset_transients();
    pf_test_set_wc_session(false);
    $_SERVER['REMOTE_ADDR']     = $ip;
    $_SERVER['HTTP_USER_AGENT'] = $ua;
}

// ---------------------------------------------------------------------
// Scenario: Session-less repeat within the window
// ---------------------------------------------------------------------
pf_run_case('session-less repeat inside the window is suppressed', function () {
    pf_reset_request_state();

    $first = pf_should_send('add_to_cart:abc123', 5);
    pf_test_advance_clock(1);
    $second = pf_should_send('add_to_cart:abc123', 5);

    if ($first !== true) {
        return 'first event should have been sent, got ' . var_export($first, true);
    }
    if ($second !== false) {
        return 'second event 1s later should have been suppressed, got ' . var_export($second, true);
    }

    return true;
}, $failures, $passes);

// ---------------------------------------------------------------------
// Scenario: Session-less repeat after the window
// ---------------------------------------------------------------------
pf_run_case('session-less repeat after the window is sent', function () {
    pf_reset_request_state();

    $first = pf_should_send('add_to_cart:abc123', 5);
    pf_test_advance_clock(6);
    $second = pf_should_send('add_to_cart:abc123', 5);

    if ($first !== true || $second !== true) {
        return sprintf(
            'both events should have been sent, got first=%s second=%s',
            var_export($first, true),
            var_export($second, true)
        );
    }

    return true;
}, $failures, $passes);

// ---------------------------------------------------------------------
// Scenario: Different clients are not conflated
// ---------------------------------------------------------------------
pf_run_case('same event from a different client IP is still sent', function () {
    pf_reset_request_state('203.0.113.10');

    $first = pf_should_send('add_to_cart:abc123', 5);

    $_SERVER['REMOTE_ADDR'] = '198.51.100.20';
    $second = pf_should_send('add_to_cart:abc123', 5);

    if ($first !== true || $second !== true) {
        return sprintf(
            'distinct clients must not be conflated, got first=%s second=%s',
            var_export($first, true),
            var_export($second, true)
        );
    }

    return true;
}, $failures, $passes);

pf_run_case('same event from a different user agent is still sent', function () {
    pf_reset_request_state();

    $first = pf_should_send('add_to_cart:abc123', 5);

    $_SERVER['HTTP_USER_AGENT'] = 'Mozilla/5.0 (Macintosh) Safari/605.1.15';
    $second = pf_should_send('add_to_cart:abc123', 5);

    if ($first !== true || $second !== true) {
        return sprintf(
            'distinct user agents must not be conflated, got first=%s second=%s',
            var_export($first, true),
            var_export($second, true)
        );
    }

    return true;
}, $failures, $passes);

pf_run_case('a different event key from the same client is still sent', function () {
    pf_reset_request_state();

    $first  = pf_should_send('add_to_cart:abc123', 5);
    $second = pf_should_send('add_to_cart:def456', 5);

    if ($first !== true || $second !== true) {
        return sprintf(
            'distinct event keys must not be conflated, got first=%s second=%s',
            var_export($first, true),
            var_export($second, true)
        );
    }

    return true;
}, $failures, $passes);

// ---------------------------------------------------------------------
// Scenario: Zero-length window disables suppression
//
// Matches the session branch, where ($now - $last_ts) < 0 is never true.
// Also guards against the WordPress behavior where a non-positive
// expiration means "never expires", which would make a ttl=0 record
// permanent.
// ---------------------------------------------------------------------
pf_run_case('ttl=0 always sends and writes no permanent record', function () {
    pf_reset_request_state();

    $first  = pf_should_send('add_to_cart:abc123', 0);
    $second = pf_should_send('add_to_cart:abc123', 0);
    $third  = pf_should_send('add_to_cart:abc123', 0);

    if ($first !== true || $second !== true || $third !== true) {
        return sprintf(
            'ttl=0 must never suppress, got %s / %s / %s',
            var_export($first, true),
            var_export($second, true),
            var_export($third, true)
        );
    }

    foreach (pf_test_all_transients() as $k => $entry) {
        if ($entry['expires_at'] === 0) {
            return "ttl=0 wrote a non-expiring transient: {$k}";
        }
    }

    return true;
}, $failures, $passes);

// ---------------------------------------------------------------------
// Scenario: Session-based path is unchanged
// ---------------------------------------------------------------------
pf_run_case('with a Woo session present no fallback record is written', function () {
    pf_reset_request_state();
    pf_test_set_wc_session(true);

    $sent = pf_should_send('add_to_cart:abc123', 5);

    if ($sent !== true) {
        return 'first event should have been sent, got ' . var_export($sent, true);
    }

    foreach (array_keys(pf_test_all_transients()) as $k) {
        if (strpos($k, 'pf_dedupe_') === 0) {
            return "session path must not write the transient fallback, found: {$k}";
        }
    }

    return true;
}, $failures, $passes);

// ---------------------------------------------------------------------
// Scenario: Suppression state does not accumulate indefinitely
// ---------------------------------------------------------------------
pf_run_case('every fallback record carries a bounded expiry', function () {
    pf_reset_request_state();

    pf_should_send('add_to_cart:abc123', 5);

    $records = pf_test_all_transients();
    if (empty($records)) {
        return 'expected a fallback record to be written for a session-less request';
    }

    foreach ($records as $k => $entry) {
        if ($entry['expires_at'] <= 0) {
            return "record {$k} has no expiry";
        }
        if ($entry['expires_at'] > pf_test_now() + 60) {
            return sprintf('record %s expires too far out (%ds)', $k, $entry['expires_at'] - pf_test_now());
        }
    }

    return true;
}, $failures, $passes);

// ---------------------------------------------------------------------
// The stored key must not carry the raw IP or user agent.
// ---------------------------------------------------------------------
pf_run_case('fallback record stores no raw client identifiers', function () {
    pf_reset_request_state('203.0.113.77', 'Mozilla/5.0 SecretAgent/1.0');

    pf_should_send('add_to_cart:abc123', 5);

    foreach (pf_test_all_transients() as $k => $entry) {
        $blob = $k . '|' . var_export($entry['value'], true);
        if (strpos($blob, '203.0.113.77') !== false) {
            return "raw client IP is persisted in {$k}";
        }
        if (strpos($blob, 'SecretAgent') !== false) {
            return "raw user agent is persisted in {$k}";
        }
    }

    return true;
}, $failures, $passes);

// ---------------------------------------------------------------------
// The per-request guard must keep working within a single request.
// ---------------------------------------------------------------------
pf_run_case('per-request guard still suppresses a repeat on one instance', function () {
    pf_reset_request_state();

    $hooks  = pf_new_hooks();
    $method = new ReflectionMethod(PixelFlow_WooCommerce_Cart_Hooks::class, 'should_send_event');
    $method->setAccessible(true);

    $first  = $method->invoke($hooks, 'add_to_cart:abc123', 5);
    $second = $method->invoke($hooks, 'add_to_cart:abc123', 5);

    if ($first !== true || $second !== false) {
        return sprintf(
            'expected true then false within one request, got %s then %s',
            var_export($first, true),
            var_export($second, true)
        );
    }

    return true;
}, $failures, $passes);

echo "\n" . count($failures) . ' failing / ' . $passes . ' passing (of ' . ($passes + count($failures)) . " cases)\n";

if ( ! empty($failures)) {
    echo "\nFailures:\n";
    foreach ($failures as $f) {
        echo " - {$f}\n";
    }
    exit(1);
}

exit(0);
