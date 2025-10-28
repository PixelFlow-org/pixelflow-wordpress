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
        if(!is_admin()) {
            // Wrap class and 'proceed to checkout' button with the element with classes:
            // For each product: info-chk-itm-pf
            // All products in: info-chk-itm-ctnr-pf
            // There's no common wrapper around the cart table and the proceed to checkout button
            // So we need to add a wrapper around the cart table and another around the proceed to checkout button
            if ($this->is_class_enabled('woo_class_cart_products_container')) {
                add_action('woocommerce_before_cart', [$this, 'start_cart_wrapper'], 0);
                add_action('woocommerce_after_cart', [$this, 'end_cart_wrapper'], PHP_INT_MAX);
            }

            // Add info-chk-itm-pf class to each cart item row
            // (Add this the container of each individual item)
            if ($this->is_class_enabled('woo_class_cart_item')) {
                add_filter('woocommerce_cart_item_class', array($this, 'add_cart_item_class'), 10, 3);
            }

            // Add info-itm-prc-pf class to cart item price
            // (Add this to the Item price:)
            if ($this->is_class_enabled('woo_class_cart_price')) {
                add_filter('woocommerce_cart_item_price', array($this, 'add_cart_item_price_class'), 10, 3);
            }

            // Add action-btn-buy-004-pf class to the proceed to checkout button
            // (Add this to the proceed to checkout button)
            if ($this->is_class_enabled('woo_class_cart_checkout_button')) {
                add_action('woocommerce_proceed_to_checkout', array($this, 'pf_proceed_btn_buffer_start'), 5);
                add_action('woocommerce_proceed_to_checkout', array($this, 'pf_proceed_btn_buffer_end'), 99);
            }

            if ($this->is_class_enabled('woo_class_cart_product_name')) {
                add_filter('woocommerce_cart_item_name', array($this, 'add_cart_item_name_class'), 10, 3);
            }
        }
    }

    // Add info-chk-itm-pf class to the cart table 
    // (Add this to the overall main/parent container containing all the cart items)
    public function start_cart_wrapper()
    {
        $className = 'info-chk-itm-ctnr-pf';
        echo "<div class='" . esc_attr($className) . "'>";
    }


    public function end_cart_wrapper()
    {
        echo '</div>';
    }

    // Add info-chk-itm-pf class to each cart item row 
    // (Add this the container of each individual item)
    public function add_cart_item_class($classes, $cart_item, $cart_item_key)
    {
        if ( ! is_cart()) {
            return $classes;
        }

        $className = 'info-chk-itm-pf';

        if (strpos($classes, $className) === false) {
            $classes .= ' ' . $className;
        }

        return trim($classes);
    }

    // Add info-itm-prc-pf class to cart item price 
    // (Add this to the Item price:)
    public function add_cart_item_price_class($price, $cart_item, $cart_item_key)
    {
        if ( ! is_cart() || empty($price)) {
            return $price;
        }

        $className = 'info-itm-prc-pf';

        // Add class to existing price span elements
        $priceUpdated = preg_replace_callback(
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
        if($priceUpdated) {
            return $priceUpdated;
        }

        return $price;
    }


    public function pf_proceed_btn_buffer_start() {
        if (!is_cart()) {
            return;
        }
        ob_start();
    }

    public function pf_proceed_btn_buffer_end() {
        if (!is_cart()) {
            if (ob_get_level() > 0) {
                ob_end_clean();
            }
            return;
        }
        $html = ob_get_clean();

        $extra_class  = 'action-btn-buy-004-pf';

        if (stripos($html, $extra_class) === false) {
            // Only replace in class attribute context
            $htmlUpdated = preg_replace(
                '/class="([^"]*checkout-button[^"]*)"/i',
                'class="$1 ' . esc_attr($extra_class) . '"',
                $html,
                1
            );
            if($htmlUpdated) {
                $html = $htmlUpdated;
            }
        }

        echo $html;
    }

    public function add_cart_item_name_class($product_name, $cart_item, $cart_item_key)
    {
        $className = 'info-itm-name-pf';
        // Avoid double-wrapping if WooCommerce calls the filter twice
        if (strpos($product_name, $className) !== false) {
            return $product_name;
        }

        $product      = $cart_item['data'];
        $name         = $product->get_name();
        $product_link = $product->is_visible() ? $product->get_permalink() : '';

        if ($product_link) {
            $wrapped_name = sprintf('<a href="%s" class="%s">%s</a>',
                esc_url($product_link),
                esc_attr($className),
                esc_html($name)
            );
        } else {
            $wrapped_name = sprintf('<span class="%s">%s</span>',
                esc_attr($className),
                esc_html($name)
            );
        }

        return $wrapped_name;
    }
}

