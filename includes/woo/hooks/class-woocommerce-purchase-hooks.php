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
    private $general_options;

    /**
     * Constructor
     */
    public function __construct($options = array(), $general_options = array()) {
        $this->options = $options;
        $this->general_options = $general_options;

        if(!is_admin()) {
            // Track purchase on order received page
            add_action('woocommerce_before_thankyou', array($this, 'track_purchase'), 10, 1);
        }
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

        $countries = WC()->countries->get_countries();
        $country_code = $order->get_billing_country();
        $country_name = $countries[$country_code] ? $countries[$country_code] : $country_code;


        $billing = array(
            'first_name' => $order->get_billing_first_name(),
            'last_name'  => $order->get_billing_last_name(),
            'email'      => $order->get_billing_email(),
            'phone'      => $order->get_billing_phone(),
            'city'       => $order->get_billing_city(),
            'state'      => $order->get_billing_state(),
            'postcode'   => $order->get_billing_postcode(),
            'country'    => $country_name,
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

        // Prevent duplicate purchase events using localStorage
        $disableRepeatedOrders = "if (localStorage.getItem(key)) {console.log('PixelFlow: purchase already sent for order', data.orderId);return;}";
        // Enabled if Debug is enabled
        $debugEnabled = $this->general_options["debug_enabled"];
        // You can also use the filter 'pixelflow_debug_purchase_event' to programmatically always enable or disable this
        $shouldAlwaysSendOrder = apply_filters('pixelflow_debug_purchase_event', $debugEnabled);
        if($shouldAlwaysSendOrder) {
            $disableRepeatedOrders = '';
        }

        $purchaseTrackingCode = <<<HTML
<script>
(function waitForPixelFlow(maxWait) {
    if (window.pixelFlow && pixelFlow.utils && pixelFlow.trackEvent) {
        runPixelFlowPurchase();
    } else if (maxWait > 0) {
        setTimeout(function() { waitForPixelFlow(maxWait - 200); }, 200);
    } else {
        console.warn('PixelFlow not loaded after 10s, skipping event');
    }
})(10000);

function runPixelFlowPurchase() {
    const data = $json;
    console.log(data);
    const key = 'pixel_purchase_sent_' + data.orderId;
    try {
        
        $disableRepeatedOrders

        const u = pixelFlow.utils;
        
        
        const payload = {
            currency: data.currency,
            contentType: data.contentType,
            numItems: data.numItems,
            value: data.value
        };
        const customerData = {
            fn: data.billing.first_name,
            ln: data.billing.last_name,
            em: data.billing.email,
            ph: data.billing.phone,
            ct: data.billing.city,
            st: data.billing.state,
            zp: data.billing.postcode,
            country: data.billing.country
        };
        
        pixelFlow.trackEvent('Purchase', payload, u.normalizeCustomerData(data));
        localStorage.setItem(key, '1');
        console.log('PixelFlow Purchase event sent for order', data.orderId);
    } catch (e) {
        console.error('PixelFlow tracking error', e);
    }
}
</script>
HTML;

        if ($debugEnabled) {
            $show_tracking = true;
        } else {
            $show_tracking = !(
                current_user_can('administrator') ||
                current_user_can('editor') ||
                current_user_can('shop_manager')
            );
        }

        $show_tracking = apply_filters('pixelflow_purchase_tracking', $show_tracking);

        if ($show_tracking) {
            echo $purchaseTrackingCode;
        }
    }
}

