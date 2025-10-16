<?php
/**
 * WooCommerce Purchase Tracking Hooks
 *
 * @package PixelFlow
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

/**
 * WooCommerce Purchase Tracking Hooks class
 */
class PixelFlow_WooCommerce_Purchase_Hooks {
    
    /**
     * Class options
     */
    private $options;
    
    /**
     * Constructor
     */
    public function __construct($options = array()) {
        $this->options = $options;
        
        // Track purchase on order received page
        add_action('woocommerce_before_thankyou', array($this, 'track_purchase'), 10, 1);
    }
    
    /**
     * Track purchase event on order received page
     * 
     * @param int $order_id Order ID
     */
    public function track_purchase($order_id) {
        if (!$order_id) return;

        $order = wc_get_order($order_id);
        if (!$order) return;

        $billing = array(
            'first_name' => $order->get_billing_first_name(),
            'last_name'  => $order->get_billing_last_name(),
            'email'      => $order->get_billing_email(),
            'phone'      => $order->get_billing_phone(),
            'city'       => $order->get_billing_city(),
            'state'      => $order->get_billing_state(),
            'postcode'   => $order->get_billing_postcode(),
            'country'    => $order->get_billing_country(),
        );

        $products = array();
        $num_items = 0;

        foreach ($order->get_items() as $item) {
            $product = $item->get_product();
            if (!$product) continue;
            $qty = $item->get_quantity();
            $num_items += (int)$qty;
            $products[] = array(
                'name'  => $item->get_name(),
                'qty'   => $qty,
                'price' => wc_get_price_to_display($product),
                'id'    => $product->get_id(),
                'sku'   => $product->get_sku(),
            );
        }

        $data = array(
            'orderId'     => $order_id,
            'currency'    => $order->get_currency(),
            'value'       => (float)$order->get_total(),
            'numItems'    => $num_items,
            'contentType' => 'product',
            'billing'     => $billing,
            'products'    => $products,
        );

        $json = wp_json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        echo <<<HTML
<script>

setInterval(() => {
  console.log('window.pixelFlow =');
}, 100);
//
//
// (function waitForPixelFlow() {
//   try {
//     if (
//       typeof window.pixelFlow !== 'undefined' &&
//       window.pixelFlow &&
//       window.pixelFlow.utils &&
//       typeof window.pixelFlow.trackEvent === 'function'
//     ) {
//       console.log('PixelFlow loaded, running Purchase event');
//       // runPixelFlowPurchase();
//     }
//   } catch (e) {
//       console.log('PixelFlow check error', e);
//   }
//
//   console.log('PixelFlow not loaded yet, retrying...');
//   setTimeout(waitForPixelFlow, 200);
// })();
/*
function runPixelFlowPurchase() {
    console.log('runPixelFlowPurchase');
    const data = $json;
    const key = 'pixel_purchase_sent_' + data.orderId;
    try {
        if (localStorage.getItem(key)) {
            console.log('PixelFlow: purchase already sent for order', data.orderId);
            return;
        }

        const u = pixelFlow.utils;
        const hashed = {
            fn: u.hash(u.normalize(data.billing.first_name || '')),
            ln: u.hash(u.normalize(data.billing.last_name  || '')),
            em: u.hash(u.normalize(data.billing.email      || '')),
            ph: u.hash(u.normalize(data.billing.phone      || '')),
            ct: u.hash(u.normalize(data.billing.city       || '')),
            st: u.hash(u.normalize(data.billing.state      || '')),
            zp: u.hash(u.normalize(data.billing.postcode   || '')),
            country: u.hash(u.normalize(data.billing.country || '')),
            external_id: u.hash(String(data.orderId))
        };

        const payload = {
            currency: data.currency,
            contentType: data.contentType,
            numItems: data.numItems,
            value: data.value
        };

        pixelFlow.trackEvent('Purchase', payload, hashed);
        localStorage.setItem(key, '1');
        console.log('PixelFlow Purchase event sent for order', data.orderId);
    } catch (e) {
        console.error('PixelFlow tracking error', e);
    }
}*/
</script>
HTML;
    }
}

