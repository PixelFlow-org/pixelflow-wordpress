<?php
/**
 * Line filtering: excluded and free products leave the payload entirely.
 *
 * A product a store has chosen not to track must not appear in `contents`, must
 * not be counted in `num_items`, and must not inflate `value` — while the rest of
 * the cart or order still produces its event.
 *
 * Run: php tests/test-woo-line-filtering.php
 */

declare(strict_types=1);

require_once __DIR__ . '/bootstrap-wp-stubs.php';

$_SERVER['REMOTE_ADDR'] = '203.0.113.10';

/** Minimal stand-in for WC_Product: the builders read a price and a SKU. */
class WC_Product
{
    /** @var float */
    private $price;

    /** @var string */
    private $sku;

    public function __construct(float $price, string $sku = '')
    {
        $this->price = $price;
        $this->sku   = $sku;
    }

    public function get_price()
    {
        return (string) $this->price;
    }

    public function get_sku()
    {
        return $this->sku;
    }

    public function get_display_price(): float
    {
        return $this->price;
    }
}

/** Minimal stand-in for a WooCommerce order line. */
class WC_Order_Item_Product
{
    /** @var array<string, mixed> */
    private $data;

    public function __construct(array $data)
    {
        $this->data = $data;
    }

    public function get_quantity()
    {
        return $this->data['qty'];
    }

    public function get_product_id()
    {
        return $this->data['product_id'];
    }

    public function get_variation_id()
    {
        return 0;
    }

    public function get_name()
    {
        return $this->data['name'];
    }

    public function get_total()
    {
        return $this->data['total'];
    }

    public function get_subtotal()
    {
        return $this->data['total'];
    }

    public function get_product()
    {
        return $this->data['product'];
    }
}

/** Minimal stand-in for WC_Order. */
class WC_Order
{
    /** @var array<int, WC_Order_Item_Product> */
    private $items;

    /** @var float */
    private $total;

    /** @var float */
    private $shipping;

    /** @var float */
    private $tax;

    public function __construct(array $items, float $total, float $shipping = 0.0, float $tax = 0.0)
    {
        $this->items    = $items;
        $this->total    = $total;
        $this->shipping = $shipping;
        $this->tax      = $tax;
    }

    public function get_items($type = 'line_item'): array
    {
        return $this->items;
    }

    public function get_currency()
    {
        return 'USD';
    }

    public function get_total()
    {
        return $this->total;
    }

    public function get_shipping_total()
    {
        return $this->shipping;
    }

    public function get_total_tax()
    {
        return $this->tax;
    }

    public function get_id()
    {
        return 1;
    }

    public function get_meta($key, $single = true)
    {
        return '';
    }
}

/** Minimal stand-in for WC_Cart. */
class PF_Test_Cart
{
    /** @var array<int, array<string, mixed>> */
    private $items;

    /** @var float */
    private $total;

    public function __construct(array $items, float $total)
    {
        $this->items = $items;
        $this->total = $total;
    }

    public function get_cart(): array
    {
        return $this->items;
    }

    public function get_cart_contents_total(): float
    {
        return $this->total;
    }
}

function wc_get_price_decimals(): int
{
    return 2;
}

function wc_format_decimal($value, $decimals = 2)
{
    return number_format((float) $value, (int) $decimals, '.', '');
}

function wc_get_price_to_display($product): float
{
    return $product instanceof WC_Product ? $product->get_display_price() : 0.0;
}

function get_woocommerce_currency(): string
{
    return 'USD';
}

require_once dirname(__DIR__) . '/includes/helpers.php';
require_once dirname(__DIR__) . '/includes/consent.php';
require_once dirname(__DIR__) . '/includes/blocked-events.php';
require_once dirname(__DIR__) . '/includes/held-events.php';
require_once dirname(__DIR__) . '/includes/woo/hooks/class-woocommerce-hooks.php';

/**
 * Calls one of the private builders on a freshly configured hooks instance.
 *
 * @param array  $options Plugin options under test
 * @param string $method  Builder to call
 * @param mixed  $subject Cart or order to build from
 * @return array additionalData
 */
function pf_build(array $options, string $method, $subject): array
{
    $hooks      = new PixelFlow_WooCommerce_Cart_Hooks('https://example.test/api', 'k', 'site', $options);
    $reflection = new ReflectionMethod(PixelFlow_WooCommerce_Cart_Hooks::class, $method);
    $reflection->setAccessible(true);

    return $reflection->invoke($hooks, $subject);
}

/**
 * @param array  $options Plugin options under test
 * @param mixed  $subject Cart or order to test
 * @param string $method  Gate to call
 * @return bool
 */
function pf_gate(array $options, string $method, $subject): bool
{
    $hooks      = new PixelFlow_WooCommerce_Cart_Hooks('https://example.test/api', 'k', 'site', $options);
    $reflection = new ReflectionMethod(PixelFlow_WooCommerce_Cart_Hooks::class, $method);
    $reflection->setAccessible(true);

    return (bool) $reflection->invoke($hooks, $subject);
}

/**
 * @param array $lines [price, sku, qty, total, name]
 * @return WC_Order
 */
function pf_order(array $lines, float $total, float $shipping = 0.0, float $tax = 0.0): WC_Order
{
    $items = [];
    foreach ($lines as $i => $line) {
        $items[] = new WC_Order_Item_Product([
            'qty'        => $line['qty'],
            'product_id' => 100 + $i,
            'name'       => $line['name'],
            'total'      => $line['total'],
            'product'    => new WC_Product($line['price'], $line['sku']),
        ]);
    }

    return new WC_Order($items, $total, $shipping, $tax);
}

/**
 * @param array $lines [price, sku, qty, total]
 * @return PF_Test_Cart
 */
function pf_cart(array $lines, float $total): PF_Test_Cart
{
    $items = [];
    foreach ($lines as $i => $line) {
        $items[] = [
            'quantity'     => $line['qty'],
            'product_id'   => 200 + $i,
            'variation_id' => 0,
            'line_total'   => $line['total'],
            'data'         => new WC_Product($line['price'], $line['sku']),
        ];
    }

    return new PF_Test_Cart($items, $total);
}

$failures = [];
$passes   = 0;

/**
 * @param string   $label
 * @param callable $fn
 */
function pf_run_filter_case(string $label, callable $fn, array &$failures, int &$passes): void
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

pf_run_filter_case(
    'Mixed order with one excluded product reports two lines and a value of 125',
    /** @return bool|string */
    function () {
        $order = pf_order([
            ['price' => 100.0, 'sku' => 'A', 'qty' => 1, 'total' => 100.0, 'name' => 'A'],
            ['price' => 50.0,  'sku' => 'B', 'qty' => 1, 'total' => 50.0,  'name' => 'B'],
            ['price' => 25.0,  'sku' => 'C', 'qty' => 1, 'total' => 25.0,  'name' => 'C'],
        ], 175.0);

        $data = pf_build(['woo_excluded_skus' => ['B']], 'build_purchase_additional_data_from_order', $order);

        if (count($data['contents']) !== 2) {
            return 'expected two reported products, got ' . count($data['contents']);
        }
        if ((int) $data['num_items'] !== 2) {
            return 'expected num_items 2, got ' . $data['num_items'];
        }
        if (abs((float) $data['value'] - 125.0) > 0.001) {
            return 'expected value 125, got ' . $data['value'];
        }
        if (strpos((string) $data['contentName'], 'B') !== false) {
            return 'the excluded product must not appear in contentName';
        }

        return true;
    },
    $failures,
    $passes
);

pf_run_filter_case(
    'Nothing excluded leaves the payload exactly as it is today',
    /** @return bool|string */
    function () {
        $order = pf_order([
            ['price' => 100.0, 'sku' => 'A', 'qty' => 1, 'total' => 100.0, 'name' => 'A'],
            ['price' => 50.0,  'sku' => 'B', 'qty' => 2, 'total' => 100.0, 'name' => 'B'],
        ], 200.0);

        $data = pf_build([], 'build_purchase_additional_data_from_order', $order);

        if (count($data['contents']) !== 2 || (int) $data['num_items'] !== 3) {
            return 'every line must contribute when the store configured no exclusions';
        }
        if (abs((float) $data['value'] - 200.0) > 0.001) {
            return 'expected value 200, got ' . $data['value'];
        }

        return true;
    },
    $failures,
    $passes
);

pf_run_filter_case(
    'Free product is dropped when the purchase freebie setting is on',
    /** @return bool|string */
    function () {
        $order = pf_order([
            ['price' => 40.0, 'sku' => 'A', 'qty' => 1, 'total' => 40.0, 'name' => 'A'],
            ['price' => 0.0,  'sku' => 'F', 'qty' => 1, 'total' => 0.0,  'name' => 'Freebie'],
        ], 40.0);

        $data = pf_build(['woo_disable_purchase_freebies' => 1], 'build_purchase_additional_data_from_order', $order);

        if (count($data['contents']) !== 1 || (int) $data['num_items'] !== 1) {
            return 'the free product must not be reported while the setting is on';
        }
        if (abs((float) $data['value'] - 40.0) > 0.001) {
            return 'expected value 40, got ' . $data['value'];
        }

        return true;
    },
    $failures,
    $passes
);

pf_run_filter_case(
    'Free product is reported when the purchase freebie setting is off',
    /** @return bool|string */
    function () {
        $order = pf_order([
            ['price' => 40.0, 'sku' => 'A', 'qty' => 1, 'total' => 40.0, 'name' => 'A'],
            ['price' => 0.0,  'sku' => 'F', 'qty' => 1, 'total' => 0.0,  'name' => 'Freebie'],
        ], 40.0);

        $data = pf_build([], 'build_purchase_additional_data_from_order', $order);

        if (count($data['contents']) !== 2 || (int) $data['num_items'] !== 2) {
            return 'both products must be reported while the setting is off';
        }

        return true;
    },
    $failures,
    $passes
);

pf_run_filter_case(
    'Each event type keeps its own freebie setting',
    /** @return bool|string */
    function () {
        $lines = [
            ['price' => 40.0, 'sku' => 'A', 'qty' => 1, 'total' => 40.0, 'name' => 'A'],
            ['price' => 0.0,  'sku' => 'F', 'qty' => 1, 'total' => 0.0,  'name' => 'Freebie'],
        ];
        $options = [
            'woo_disable_initiate_checkout_freebies' => 1,
            'woo_disable_purchase_freebies'          => 0,
        ];

        $checkout = pf_build($options, 'build_checkout_additional_data_from_cart', pf_cart($lines, 40.0));
        $purchase = pf_build($options, 'build_purchase_additional_data_from_order', pf_order($lines, 40.0));

        if (count($checkout['contents']) !== 1) {
            return 'the checkout event must drop the free product';
        }
        if (count($purchase['contents']) !== 2) {
            return 'the purchase event must keep it while its own setting is off';
        }

        return true;
    },
    $failures,
    $passes
);

pf_run_filter_case(
    'Checkout value covers the reported products only',
    /** @return bool|string */
    function () {
        $cart = pf_cart([
            ['price' => 100.0, 'sku' => 'A', 'qty' => 1, 'total' => 100.0],
            ['price' => 50.0,  'sku' => 'B', 'qty' => 1, 'total' => 50.0],
        ], 150.0);

        $data = pf_build(['woo_excluded_skus' => ['B']], 'build_checkout_additional_data_from_cart', $cart);

        if (abs((float) $data['value'] - 100.0) > 0.001) {
            return 'expected value 100, got ' . $data['value'];
        }
        if ((int) $data['num_items'] !== 1) {
            return 'expected num_items 1, got ' . $data['num_items'];
        }

        return true;
    },
    $failures,
    $passes
);

pf_run_filter_case(
    'Order-level discount is shared with the excluded product, not shifted onto the rest',
    /** @return bool|string */
    function () {
        // 175 of line totals, 10% off the order: the shopper paid 157.50 in total
        // and 112.50 for the two tracked products.
        $order = pf_order([
            ['price' => 100.0, 'sku' => 'A', 'qty' => 1, 'total' => 100.0, 'name' => 'A'],
            ['price' => 50.0,  'sku' => 'B', 'qty' => 1, 'total' => 50.0,  'name' => 'B'],
            ['price' => 25.0,  'sku' => 'C', 'qty' => 1, 'total' => 25.0,  'name' => 'C'],
        ], 157.5);

        $data = pf_build(['woo_excluded_skus' => ['B']], 'build_purchase_additional_data_from_order', $order);

        if (abs((float) $data['value'] - 112.5) > 0.001) {
            return 'expected value 112.50, got ' . $data['value'];
        }

        return true;
    },
    $failures,
    $passes
);

pf_run_filter_case(
    'An order whose every line is excluded reports nothing',
    /** @return bool|string */
    function () {
        $order = pf_order([
            ['price' => 100.0, 'sku' => 'A', 'qty' => 1, 'total' => 100.0, 'name' => 'A'],
        ], 100.0);

        if (pf_gate(['woo_excluded_skus' => ['A']], 'order_has_reported_lines', $order)) {
            return 'an order with nothing left to report must not send';
        }

        return true;
    },
    $failures,
    $passes
);

pf_run_filter_case(
    'A cart whose every line is free reports nothing while the setting is on',
    /** @return bool|string */
    function () {
        $cart = pf_cart([
            ['price' => 0.0, 'sku' => 'F1', 'qty' => 1, 'total' => 0.0],
            ['price' => 0.0, 'sku' => 'F2', 'qty' => 1, 'total' => 0.0],
        ], 0.0);

        if (pf_gate(['woo_disable_initiate_checkout_freebies' => 1], 'cart_has_reported_lines', $cart)) {
            return 'a cart with nothing left to report must not send';
        }

        return true;
    },
    $failures,
    $passes
);

pf_run_filter_case(
    'A mixed cart still sends when only some lines are excluded',
    /** @return bool|string */
    function () {
        $cart = pf_cart([
            ['price' => 100.0, 'sku' => 'A', 'qty' => 1, 'total' => 100.0],
            ['price' => 50.0,  'sku' => 'B', 'qty' => 1, 'total' => 50.0],
        ], 150.0);

        if ( ! pf_gate(['woo_excluded_skus' => ['B']], 'cart_has_reported_lines', $cart)) {
            return 'a cart that still has a tracked product must send its event';
        }

        return true;
    },
    $failures,
    $passes
);

echo "\n{$passes} passed, " . count($failures) . " failed\n";

exit(count($failures) > 0 ? 1 : 0);
