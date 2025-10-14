<?php
/**
 * WooCommerce Cart Hooks
 *
 * @package PixelFlow
 */

// Prevent direct access
if ( ! defined('ABSPATH')) {
    exit;
}

/**
 * WooCommerce Cart Hooks class
 */
class PixelFlow_WooCommerce_Cart_Hooks
{

    /**
     * Plugin options
     */
    private $options;

    /**
     * Constructor
     */
    public function __construct($options = array())
    {
        $this->options = $options;
        $this->init_hooks();
    }

    /**
     * Check if a specific class is enabled
     */
    private function is_class_enabled($class_key)
    {
        // If not set, default to enabled
        if ( ! isset($this->options[$class_key])) {
            return true;
        }

        // Explicitly check if it's enabled (1 or true)
        return ! empty($this->options[$class_key]);
    }

    /**
     * Initialize hooks
     */
    private function init_hooks()
    {
        // Add info-pdct-ctnr-pf class to the cart table 
        // (Add this to the overall main/parent container containing all the cart items)
        if ($this->is_class_enabled('woo_class_cart_table')) {
            add_action('woocommerce_before_cart_table', array($this, 'start_cart_table_buffer'), 0);
            add_action('woocommerce_after_cart_table', array($this, 'end_cart_table_buffer'), PHP_INT_MAX);
        }

        // Add info-pdct-ctnr-pf class to each cart item row 
        // (Add this the container of each individual item)
        if ($this->is_class_enabled('woo_class_cart_item')) {
            add_filter('woocommerce_cart_item_class', array($this, 'add_cart_item_class'), 10, 3);
        }

        // Add info-pdct-price-pf class to cart item price 
        // (Add this to the Item price:)
        if ($this->is_class_enabled('woo_class_cart_price')) {
            add_filter('woocommerce_cart_item_price', array($this, 'add_cart_item_price_class'), 10, 3);
        }

        // Add action-btn-buy-004-pf class to the proceed to checkout button 
        // (Add this to the proceed to checkout button)
        if ($this->is_class_enabled('woo_class_cart_checkout_button')) {
            add_action('woocommerce_proceed_to_checkout', array($this, 'start_proceed_to_checkout_buffer'), 1);
            add_action('woocommerce_after_cart', array($this, 'end_proceed_to_checkout_buffer'), PHP_INT_MAX);
        }
    }

    // Add info-pdct-ctnr-pf class to the cart table 
    // (Add this to the overall main/parent container containing all the cart items)
    public function start_cart_table_buffer()
    {
        if (is_cart()) {
            ob_start(array($this, 'add_cart_table_class'));
        }
    }

    public function add_cart_table_class($content)
    {
        $className = 'info-pdct-ctnr-pf';

        // Replace shop_table shop_table_responsive with shop_table shop_table_responsive info-pdct-ctnr-pf
        $content = str_replace(
            'shop_table shop_table_responsive',
            'shop_table shop_table_responsive ' . esc_attr($className) . ' ',
            $content
        );

        return $content;
    }

    public function end_cart_table_buffer()
    {
        if (is_cart()) {
            ob_end_flush();
        }
    }

    // Add info-pdct-ctnr-pf class to each cart item row 
    // (Add this the container of each individual item)
    public function add_cart_item_class($classes, $cart_item, $cart_item_key)
    {
        if ( ! is_cart()) {
            return $classes;
        }

        $className = 'info-pdct-ctnr-pf';

        if (strpos($classes, $className) === false) {
            $classes .= ' ' . $className;
        }

        return trim($classes);
    }

    // Add info-pdct-price-pf class to cart item price 
    // (Add this to the Item price:)
    public function add_cart_item_price_class($price, $cart_item, $cart_item_key)
    {
        if ( ! is_cart() || empty($price)) {
            return $price;
        }

        $className = 'info-pdct-price-pf';

        // Add class to existing price span elements
        $price = preg_replace_callback(
            '/<span\s+class="([^"]*amount[^"]*)"/i',
            function ($matches) use ($className) {
                $classes = $matches[1];
                if (strpos($classes, $className) === false) {
                    $classes .= ' ' . $className;
                }

                return '<span class="' . esc_attr(trim($classes)) . '"';
            },
            $price,
            1
        );

        return $price;
    }

    // Add action-btn-buy-004-pf class to the proceed to checkout button 
    // (Add this to the proceed to checkout button)
    public function start_proceed_to_checkout_buffer()
    {
        if (is_cart()) {
            ob_start(array($this, 'add_proceed_to_checkout_button_class'));
        }
    }

    public function add_proceed_to_checkout_button_class($content)
    {
        $className = 'action-btn-buy-004-pf';

        // Add class to the checkout button
        $content = preg_replace(
            '/class="([^"]*checkout-button[^"]*)"/i',
            'class="$1 ' . esc_attr($className) . '"',
            $content
        );

        return $content;
    }

    public function end_proceed_to_checkout_buffer()
    {
        if (is_cart()) {
            ob_end_flush();
        }
    }


}

