<?php
/**
 * AddToCart value excludes tax, like InitiateCheckout and Purchase.
 *
 * A store that displays prices including tax must not report AddToCart at the
 * displayed price while the checkout and the order report the same product net
 * of tax: the funnel would disagree with itself by the tax rate.
 *
 * Run: php tests/test-add-to-cart-value.php
 */

declare(strict_types=1);

require_once __DIR__ . '/bootstrap-wp-stubs.php';

$_SERVER['REMOTE_ADDR'] = '203.0.113.10';

/** Minimal stand-in for WC_Product, enough for build_additional_data(). */
class WC_Product
{
    public function get_id()
    {
        return 4242;
    }

    public function get_name()
    {
        return 'PF Test Product';
    }

    public function get_sku()
    {
        return 'PF-TEST';
    }
}

// The shop displays prices including 20% tax: 100 net is shown as 120.
function wc_get_price_to_display($product, $args = [])
{
    return 120.0;
}

function wc_get_price_excluding_tax($product, $args = [])
{
    return 100.0;
}

function get_woocommerce_currency()
{
    return 'USD';
}

require_once dirname(__DIR__) . '/includes/helpers.php';
require_once dirname(__DIR__) . '/includes/consent.php';
require_once dirname(__DIR__) . '/includes/blocked-events.php';
require_once dirname(__DIR__) . '/includes/held-events.php';
require_once dirname(__DIR__) . '/includes/woo/hooks/class-woocommerce-hooks.php';

/**
 * @return array additionalData for an AddToCart of $quantity units
 */
function pf_add_to_cart_data(int $quantity): array
{
    $hooks      = new PixelFlow_WooCommerce_Cart_Hooks('https://example.test/api', 'k', 'site', []);
    $reflection = new ReflectionMethod(PixelFlow_WooCommerce_Cart_Hooks::class, 'build_additional_data');
    $reflection->setAccessible(true);

    return $reflection->invoke($hooks, new WC_Product(), $quantity);
}

$failures = [];
$passes   = 0;

/**
 * @param string   $label
 * @param callable $fn
 */
function pf_run_value_case(string $label, callable $fn, array &$failures, int &$passes): void
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

pf_run_value_case(
    'One unit is reported at its price excluding tax',
    /** @return bool|string */
    function () {
        $data = pf_add_to_cart_data(1);

        if (abs((float) $data['value'] - 100.0) > 0.001) {
            return 'expected value 100, got ' . $data['value'];
        }
        if (abs((float) $data['contents'][0]['item_price'] - 100.0) > 0.001) {
            return 'expected item_price 100, got ' . $data['contents'][0]['item_price'];
        }

        return true;
    },
    $failures,
    $passes
);

pf_run_value_case(
    'Several units are reported at quantity times the price excluding tax',
    /** @return bool|string */
    function () {
        $data = pf_add_to_cart_data(3);

        if (abs((float) $data['value'] - 300.0) > 0.001) {
            return 'expected value 300, got ' . $data['value'];
        }
        if (abs((float) $data['contents'][0]['item_price'] - 100.0) > 0.001) {
            return 'expected item_price 100, got ' . $data['contents'][0]['item_price'];
        }

        return true;
    },
    $failures,
    $passes
);

echo "\n{$passes} passed, " . count($failures) . " failed\n";

exit(count($failures) > 0 ? 1 : 0);
