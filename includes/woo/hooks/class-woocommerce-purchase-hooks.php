<?php
/**
 * WooCommerce Purchase Tracking Hooks
 *
 * @package PixelFlow
 */

// Prevent direct access
if ( ! defined('ABSPATH')) {
    exit;
}

/**
 * WooCommerce Purchase Tracking Hooks class
 */
class PixelFlow_WooCommerce_Purchase_Hooks
{

    /**
     * Class options
     */
    private $options;
    private $pixelflow_general_options;

    /**
     * Constructor
     */
    public function __construct($options = array(), $pixelflow_general_options = array())
    {
        $this->options         = $options;
        $this->pixelflow_general_options = $pixelflow_general_options;

        if ( ! is_admin()) {
            // Track purchase on order received page
            add_action('woocommerce_before_thankyou', array($this, 'track_purchase'), 10, 1);
        }
    }

    /**
     * Track purchase event on order received page
     *
     * @param int $order_id Order ID
     */
    public function track_purchase($order_id)
    {
        if ( ! $order_id) {
            return;
        }

        $order = wc_get_order($order_id);
        if ( ! $order) {
            return;
        }

        // This is not a form, we just check that the key parameter is present to track the order
        // on the Thank you page like /checkout/order-received/193/?key=wc_order_jKu7fu8uWsCAz
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $order_key = isset($_GET['key']) ? sanitize_text_field(wp_unslash($_GET['key'])) : '';
        if (empty($order_key)) {
            return; // Unauthorized access
        }

        if ( ! $order->key_is_valid($order_key)) {
            return; // Unauthorized access
        }

        $countries    = WC()->countries->get_countries();
        $country_code = $order->get_billing_country();
        $country_name = isset($countries[$country_code]) ? $countries[$country_code] : $country_code;


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

        $products  = array();
        $num_items = 0;

        foreach ($order->get_items() as $item) {
            $product = $item->get_product();
            if ( ! $product) {
                continue;
            }
            $qty        = $item->get_quantity();
            $num_items  += (int)$qty;
            $products[] = array(
                'name'  => $item->get_name(),
                'qty'   => $qty,
                'price' => (float)($item->get_total() / $item->get_quantity()),
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

        $json = wp_json_encode(
            $data,
            JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        );


        // Enabled if Debug is enabled
        $debugEnabled = isset($this->pixelflow_general_options["debug_enabled"]) ? $this->pixelflow_general_options["debug_enabled"] : false;
        // You can also use the filter 'pixelflow_debug_purchase_event' to programmatically always enable or disable this
        $shouldAlwaysSendOrder = apply_filters('pixelflow_debug_purchase_event', $debugEnabled);

        $purchase_tracking = "(function waitForPixelFlow(maxWait) {";
        $purchase_tracking .= "if (window.pixelFlow && pixelFlow.utils && pixelFlow.trackEvent) {";
        $purchase_tracking .= "runPixelFlowPurchase();";
        $purchase_tracking .= "} else if (maxWait > 0) {";
        $purchase_tracking .= "setTimeout(function() { waitForPixelFlow(maxWait - 200); }, 200);";
        $purchase_tracking .= "} else {";
        $purchase_tracking .= "console.warn('PixelFlow not loaded after 10s, skipping event');";
        $purchase_tracking .= "}";
        $purchase_tracking .= "})(10000);";
        $purchase_tracking .= "function runPixelFlowPurchase() {";
        $purchase_tracking .= "const data = " . $json . ";";
        $purchase_tracking .= "const key = 'pixel_purchase_sent_' + data.orderId;";
        $purchase_tracking .= "try {";
        
        // Prevent duplicate purchase events using localStorage
        if ( ! $shouldAlwaysSendOrder) {
            $purchase_tracking .= "if (localStorage.getItem(key)) {console.log('PixelFlow: purchase already sent for order', data.orderId);return;}";
        }

        $purchase_tracking .= "const u = pixelFlow.utils;";
        $purchase_tracking .= "const payload = {";
        $purchase_tracking .= "currency: data.currency,";
        $purchase_tracking .= "contentType: data.contentType,";
        $purchase_tracking .= "numItems: data.numItems,";
        $purchase_tracking .= "value: data.value";
        $purchase_tracking .= "};";
        $purchase_tracking .= "const customerData = {";
        $purchase_tracking .= "fn: data.billing.first_name,";
        $purchase_tracking .= "ln: data.billing.last_name,";
        $purchase_tracking .= "em: data.billing.email,";
        $purchase_tracking .= "ph: data.billing.phone,";
        $purchase_tracking .= "ct: data.billing.city,";
        $purchase_tracking .= "st: data.billing.state,";
        $purchase_tracking .= "zp: data.billing.postcode,";
        $purchase_tracking .= "country: data.billing.country";
        $purchase_tracking .= "};";
        $purchase_tracking .= "pixelFlow.trackEvent('Purchase', payload, u.normalizeCustomerData(customerData));";
        $purchase_tracking .= "localStorage.setItem(key, '1');";
        $purchase_tracking .= "} catch (e) {";
        $purchase_tracking .= "console.error('PixelFlow tracking error', e);";
        $purchase_tracking .= "}";
        $purchase_tracking .= "}";

        if ($debugEnabled) {
            $show_tracking = true;
        } else {
            $show_tracking = ! (
                current_user_can('administrator') ||
                current_user_can('editor') ||
                current_user_can('shop_manager')
            );
        }

        $show_tracking = apply_filters('pixelflow_purchase_tracking', $show_tracking);

        if ($show_tracking) {
            $script_key = 'pixelflow-woocommerce-purchase-tracking';
            
            // Ensure JavaScript content is safe for output
            // $json is already properly encoded with wp_json_encode() and security flags
            // JavaScript code structure is hardcoded, so it's safe
            // Validate UTF-8 encoding to prevent invalid characters
            $purchase_tracking = wp_check_invalid_utf8($purchase_tracking);
            $purchase_tracking = preg_replace('/[\x00-\x1F\x7F]/u', '', $purchase_tracking); // Remove control characters
            
            wp_register_script($script_key, '', array(), PIXELFLOW_VERSION, array('in_footer' => false));
            wp_add_inline_script($script_key, $purchase_tracking, 'before');
            wp_enqueue_script($script_key);
        }
    }
}

