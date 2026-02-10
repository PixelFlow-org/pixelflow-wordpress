<?php
/**
 * WooCommerce Integration
 *
 * @package PixelFlow
 */

// Prevent direct access
if ( ! defined('ABSPATH')) {
    exit;
}

/**
 * WooCommerce Integration class
 */
class PixelFlow_WooCommerce_Integration
{

    /**
     * Single instance of the class
     */
    private static $instance = null;

    /**
     * Get single instance
     */
    public static function get_instance()
    {
        if (null === self::$instance) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    /**
     * Constructor
     */
    private function __construct()
    {
        // Only initialize hooks if WooCommerce is active and integration is enabled
        if ( ! $this->is_woocommerce_active() || ! $this->is_integration_enabled()) {
            return;
        }

        $this->load_hooks();
    }

    /**
     * Load hook classes
     */
    private function load_hooks()
    {
        $api_url = "https://api.pixelflow.so";
        $params = get_option('pixelflow_script_params', array());
        $site_external_id = isset($params['siteExternalId']) ? $params['siteExternalId'] : '';
        $api_key          = isset($params['apiKey']) ? $params['apiKey'] : '';
        require_once PIXELFLOW_PLUGIN_PATH . 'includes/helpers.php';
        require_once PIXELFLOW_PLUGIN_PATH . 'includes/woo/hooks/class-woocommerce-hooks.php';
        new PixelFlow_WooCommerce_Cart_Hooks($api_url, $api_key, $site_external_id);
    }

    /**
     * Check if WooCommerce is active
     */
    public static function is_woocommerce_active()
    {
        return class_exists('WooCommerce');
    }

    /**
     * Check if integration is enabled
     */
    private function is_integration_enabled()
    {
        $pixelflow_general_options = get_option('pixelflow_general_options');

        // WooCommerce integration requires both PixelFlow and WooCommerce integration to be enabled
        return isset($pixelflow_general_options['enabled']) && $pixelflow_general_options['enabled']
               && isset($pixelflow_general_options['woo_enabled']) && $pixelflow_general_options['woo_enabled'];
    }
}
