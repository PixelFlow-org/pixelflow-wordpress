<?php
/**
 * WooCommerce Checkout Hooks
 *
 * @package PixelFlow
 */

// Prevent direct access
if ( ! defined('ABSPATH')) {
    exit;
}

/**
 * WooCommerce Checkout Hooks class
 */
class PixelFlow_WooCommerce_Checkout_Hooks
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
        // Add info-chk-itm-ctnr-pf class to the checkout form tag
        // (Add this to the overall main/parent container containing all the checkout items (mandatory))
        if ($this->is_class_enabled('woo_class_checkout_form')) {
            add_action('woocommerce_before_checkout_form', array($this, 'add_checkout_form_class_start'), 0);
            add_action('woocommerce_after_checkout_form', array($this, 'add_checkout_form_class_end'), PHP_INT_MAX);
        }

        // Add info-chk-itm-pf class to each checkout item row 
        // (Add this the container of each individual item)
        if ($this->is_class_enabled('woo_class_checkout_item')) {
            add_filter('woocommerce_cart_item_class', array($this, 'add_checkout_item_class'), 10, 3);
        }

        // Add info-itm-prc-pf class to checkout item price 
        // (Add this to the Item price:)
        if ($this->is_class_enabled('woo_class_checkout_item_price')) {
            add_action(
                'woocommerce_review_order_before_cart_contents',
                array($this, 'add_checkout_item_price_classes_start'),
                0
            );
            add_action(
                'woocommerce_review_order_after_cart_contents',
                array($this, 'add_checkout_item_price_classes_end'),
                PHP_INT_MAX
            );
        }

        // Add info-itm-name-pf class to product name 
        // (Add this to the Item name:)
        if ($this->is_class_enabled('woo_class_checkout_item_name')) {
            add_filter('woocommerce_cart_item_name', array($this, 'add_checkout_product_name_class'), 10, 3);
        }

        // Add info-itm-qnty-pf class to product quantity 
        // (Add this to the Item quantity:)
        if ($this->is_class_enabled('woo_class_checkout_item_quantity')) {
            add_filter(
                'woocommerce_checkout_cart_item_quantity',
                array($this, 'add_checkout_product_quantity_class'),
                10,
                3
            );
        }

        // Add info-totl-amt-pf class to the order total amount 
        // (Add this to total amount:)
        if ($this->is_class_enabled('woo_class_checkout_total')) {
            add_filter(
                'woocommerce_cart_totals_order_total_html',
                array($this, 'add_checkout_total_amount_class'),
                10,
                1
            );
        }

        // Add action-btn-plc-ord-018-pf class to the place order button 
        // (Add this to the place order button)
        if ($this->is_class_enabled('woo_class_checkout_place_order')) {
            add_filter('woocommerce_order_button_html', array($this, 'add_place_order_button_class'), 10, 1);
        }
    }

    // Add info-chk-itm-ctnr-pf class to the checkout form tag 
    // (Add this to the overall main/parent container containing all the checkout items (mandatory))
    public function add_checkout_form_class_start()
    {
        if (is_checkout()) {
            ob_start(array($this, 'add_checkout_form_class_buffer'));
        }
    }

    public function add_checkout_form_class_buffer($content)
    {
        $className = 'info-chk-itm-ctnr-pf';

        // Modify only the main checkout <form> tag
        // replace checkout woocommerce-checkout with checkout woocommerce-checkout info-chk-itm-ctnr-pf
        return str_ireplace(
            'checkout woocommerce-checkout',
            'checkout woocommerce-checkout ' . esc_attr($className),
            $content
        );
    }

    public function add_checkout_form_class_end()
    {
        if (is_checkout()) {
            ob_end_flush();
        }
    }

    // Add info-chk-itm-pf class to each checkout item row 
    // (Add this the container of each individual item)
    public function add_checkout_item_class($classes, $cart_item, $cart_item_key)
    {
        $className = 'info-chk-itm-pf';

        if (is_checkout() && strpos($classes, $className) === false) {
            $classes .= ' ' . $className;
        }

        return trim($classes);
    }

    // Add info-itm-prc-pf class to checkout item price 
    // (Add this to the Item price:)
    public function add_checkout_item_price_classes_start()
    {
        if (is_checkout()) {
            ob_start(array($this, 'add_checkout_item_price_classes_buffer'));
        }
    }

    public function add_checkout_item_price_classes_buffer($content)
    {
        $className = 'info-itm-prc-pf';

        // Product price cell
        $content = preg_replace_callback(
            '/<td\s+class="([^"]*\bproduct-total\b[^"]*)"([^>]*)>/i',
            function ($matches) use ($className) {
                $classes = $matches[1];
                if (strpos($classes, $className) === false) {
                    $classes .= ' ' . $className;
                }

                return '<td class="' . esc_attr(trim($classes)) . '"' . $matches[2] . '>';
            },
            $content
        );

        return $content;
    }

    public function add_checkout_item_price_classes_end()
    {
        if (is_checkout()) {
            ob_end_flush();
        }
    }

    // Add info-itm-name-pf class to product name 
    // (Add this to the Item name:)
    public function add_checkout_product_name_class($name, $cart_item, $cart_item_key)
    {
        $className = 'info-itm-name-pf';

        // Ensure we only affect the checkout page (optional)
        if ( ! is_checkout()) {
            return $name;
        }

        // Prevent double-wrapping
        if (strpos($name, $className) !== false) {
            return $name;
        }

        return '<span class="' . esc_attr($className) . '">' . $name . '</span>';
    }

    // Add info-itm-qnty-pf class to product quantity 
    // (Add this to the Item quantity:)
    public function add_checkout_product_quantity_class($quantity_html, $cart_item, $cart_item_key)
    {
        $className = 'info-itm-qnty-pf';

        // Apply only on the checkout page
        if ( ! is_checkout()) {
            return $quantity_html;
        }

        // Append the class to the existing <strong class="product-quantity">
        $quantity_html = preg_replace(
            '/class="([^"]*\bproduct-quantity\b[^"]*)"/i',
            'class="$1 ' . esc_attr($className) . '"',
            $quantity_html,
            1
        );

        return $quantity_html;
    }

    // Add info-totl-amt-pf class to the order total amount 
    // (Add this to total amount:)
    public function add_checkout_total_amount_class($value)
    {
        $className = 'info-totl-amt-pf';

        // Apply only on checkout page
        if ( ! is_checkout()) {
            return $value;
        }

        // If <strong> has no class → add it, else append
        if (strpos($value, 'class=') === false) {
            $value = str_replace('<strong', '<strong class="' . esc_attr($className) . '"', $value);
        } else {
            $value = preg_replace(
                '/class="([^"]*)"/i',
                'class="$1 ' . esc_attr($className) . '"',
                $value,
                1
            );
        }

        return $value;
    }

    // Add action-btn-plc-ord-018-pf class to the place order button 
    // (Add this to the place order button)
    public function add_place_order_button_class($button)
    {
        $className = 'action-btn-plc-ord-018-pf';

        // Only on checkout page
        if ( ! is_checkout()) {
            return $button;
        }

        // Add the class if it's not already present
        $button = preg_replace(
            '/class="([^"]*)"/i',
            'class="$1 ' . esc_attr($className) . '"',
            $button,
            1
        );

        return $button;
    }
}

