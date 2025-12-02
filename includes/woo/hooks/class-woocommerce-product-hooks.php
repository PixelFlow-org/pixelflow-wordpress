<?php
/**
 * WooCommerce Product Hooks
 *
 * @package PixelFlow
 */

// Prevent direct access
if ( ! defined('ABSPATH')) {
    exit;
}

/**
 * WooCommerce Product Hooks class
 */
class PixelFlow_WooCommerce_Product_Hooks
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
        if ( ! is_admin()) {
            // Add info-chk-itm-pf class to product container
            // (Add this to the product container)
            if ($this->is_class_enabled('woo_class_product_container')) {
                add_filter('woocommerce_post_class', array($this, 'add_product_container_class'), 10, 2);
            }

            // Add info-itm-name-pf class to product name in loop
            // (Add this to the product name)
            if ($this->is_class_enabled('woo_class_product_name')) {
                add_filter('woocommerce_product_loop_title_classes', array($this, 'add_product_name_class_loop'));

                // Add info-itm-name-pf class to product name on single product page
                // (Add this to the product name)
                add_action(
                    'woocommerce_single_product_summary',
                    array($this, 'add_product_name_class_single'),
                    5
                );
            }

            // Add info-itm-prc-pf class to product price
            // (Add this to the Item price:)
            if ($this->is_class_enabled('woo_class_product_price')) {
                add_filter('woocommerce_get_price_html', array($this, 'add_product_price_class'), 10, 2);
            }

            // Add info-itm-qnty-pf class to quantity input
            // (Add this to the Item quantity:)
            if ($this->is_class_enabled('woo_class_product_quantity')) {
                add_filter('woocommerce_quantity_input_args', array($this, 'add_product_quantity_class'), 10, 2);
            }

            // Add action-btn-cart-005-pf class to add to cart buttons in loop
            // (Add this to the add to cart button)
            if ($this->is_class_enabled('woo_class_product_add_to_cart')) {
                add_filter('woocommerce_loop_add_to_cart_args', array($this, 'add_add_to_cart_button_class'), 10, 2);

                // Add action-btn-cart-005-pf class to single product add to cart button
                // (Add this to the add to cart button)
                add_action('woocommerce_before_add_to_cart_button', array($this, 'add_single_add_to_cart_button_class'), 10);
            }
        }
    }

    // Add info-chk-itm-pf class to product container 
    // (Add this to the product container)
    public function add_product_container_class($classes, $product)
    {
        $classes[] = 'info-chk-itm-pf';

        return $classes;
    }

    // Add info-itm-name-pf class to product name in loop 
    // (Add this to the product name)
    public function add_product_name_class_loop($classes)
    {
        return $classes . ' info-itm-name-pf';
    }

    // Add info-itm-prc-pf class to product price 
    // (Add this to the Item price:)
    public function add_product_price_class($price, $product)
    {
        $className = 'info-itm-prc-pf';

        if (empty($price) || ! is_a($product, 'WC_Product')) {
            return $price;
        }

        // === 1️⃣ VARIABLE PRODUCTS ===
        if ($product->is_type('variable')) {
            if (strpos($price, 'woocommerce-variation-price') !== false) {
                // Discounted variation
                $priceUpdated = preg_replace_callback(
                    '/(<div[^>]*class="[^"]*woocommerce-variation-price[^"]*"[^>]*>.*?<ins[^>]*>\s*<span\s+class="([^"]*woocommerce-Price-amount[^"]*)"([^>]*)>)/is',
                    function ($matches) use ($className) {
                        $before  = $matches[1];
                        $classes = $matches[2];
                        if (strpos($classes, $className) === false) {
                            $classes .= ' ' . $className;
                        }

                        return str_replace(
                            '<span class="' . $matches[2] . '"',
                            '<span class="' . esc_attr(trim($classes)) . '"',
                            $before
                        );
                    },
                    $price,
                    1
                );
                if ($priceUpdated) {
                    $price = $priceUpdated;
                }

                // Non-discounted variation fallback
                if (strpos($price, '<ins') === false) {
                    $priceUpdated = preg_replace_callback(
                        '/(<div[^>]*class="[^"]*woocommerce-variation-price[^"]*"[^>]*>.*?<span\s+class="([^"]*woocommerce-Price-amount[^"]*)"([^>]*)>)/is',
                        function ($matches) use ($className) {
                            $before  = $matches[1];
                            $classes = $matches[2];
                            if (strpos($classes, $className) === false) {
                                $classes .= ' ' . $className;
                            }

                            return str_replace(
                                '<span class="' . $matches[2] . '"',
                                '<span class="' . esc_attr(trim($classes)) . '"',
                                $before
                            );
                        },
                        $price,
                        1
                    );
                    if ($priceUpdated) {
                        $price = $priceUpdated;
                    }
                }
            }

            return $price;
        }

        // === 2️⃣ GROUPED PRODUCTS ===
        if ($product->is_type('grouped')) {
            // Skip grouped range price (it doesn't have .woocommerce-grouped-product-list-item)
            if (strpos($price, 'woocommerce-grouped-product-list-item') === false) {
                return $price;
            }

            // Discounted grouped child
            $priceUpdated = preg_replace_callback(
                '/<ins[^>]*>\s*<span\s+class="([^"]*woocommerce-Price-amount[^"]*)"([^>]*)>/i',
                function ($matches) use ($className) {
                    $classes = $matches[1];
                    if (strpos($classes, $className) === false) {
                        $classes .= ' ' . $className;
                    }

                    return '<ins><span class="' . esc_attr(trim($classes)) . '"' . $matches[2] . '>';
                },
                $price,
                1
            );
            if ($priceUpdated) {
                $price = $priceUpdated;
            }

            // Non-discounted grouped child
            if (strpos($price, '<ins') === false) {
                $priceUpdated = preg_replace_callback(
                    '/<span\s+class="([^"]*woocommerce-Price-amount[^"]*)"([^>]*)>/i',
                    function ($matches) use ($className) {
                        $classes = $matches[1];
                        if (strpos($classes, $className) === false) {
                            $classes .= ' ' . $className;
                        }

                        return '<span class="' . esc_attr(trim($classes)) . '"' . $matches[2] . '>';
                    },
                    $price,
                    1
                );
                if ($priceUpdated) {
                    $price = $priceUpdated;
                }
            }

            return $price;
        }

        // === 3️⃣ SIMPLE PRODUCTS ===
        // Discounted simple
        $priceUpdated = preg_replace_callback(
            '/<ins[^>]*>\s*<span\s+class="([^"]*woocommerce-Price-amount[^"]*)"([^>]*)>/i',
            function ($matches) use ($className) {
                $classes = $matches[1];
                if (strpos($classes, $className) === false) {
                    $classes .= ' ' . $className;
                }

                return '<ins><span class="' . esc_attr(trim($classes)) . '"' . $matches[2] . '>';
            },
            $price,
            1
        );
        if ($priceUpdated) {
            $price = $priceUpdated;
        }

        // Non-discounted simple
        if (strpos($price, '<ins') === false) {
            $priceUpdated = preg_replace_callback(
                '/<span\s+class="([^"]*woocommerce-Price-amount[^"]*)"([^>]*)>/i',
                function ($matches) use ($className) {
                    $classes = $matches[1];
                    if (strpos($classes, $className) === false) {
                        $classes .= ' ' . $className;
                    }

                    return '<span class="' . esc_attr(trim($classes)) . '"' . $matches[2] . '>';
                },
                $price,
                1
            );
            if ($priceUpdated) {
                $price = $priceUpdated;
            }
        }

        return $price;
    }

    // Add info-itm-name-pf class to product name on single product page
    // (Add this to the product name)
    public function add_product_name_class_single()
    {
        $className = 'info-itm-name-pf';

        $script_key = 'pixelflow-product-name-class';

        $script = "(function() {
            var elementToAdd = document.querySelectorAll('.product_title');
            elementToAdd.forEach(function(button) {
                if (!button.classList.contains('" . esc_js($className) . "')) {
                    button.classList.add('" . esc_js($className) . "');
                }
            });
        })();";

        wp_register_script($script_key, '', array(), PIXELFLOW_VERSION, array('in_footer' => true));
        wp_add_inline_script($script_key, $script, 'before');
        wp_enqueue_script($script_key);
    }

    // Add info-itm-qnty-pf class to quantity input 
    // (Add this to the Item quantity:)
    public function add_product_quantity_class($args, $product)
    {
        $className = 'info-itm-qnty-pf';

        if ( ! isset($args['classes']) || ! is_array($args['classes'])) {
            $args['classes'] = array();
        }

        // Avoid duplication
        if ( ! in_array($className, $args['classes'], true)) {
            $args['classes'][] = $className;
        }

        return $args;
    }

    // Add action-btn-cart-005-pf class to add to cart buttons in loop 
    // (Add this to the add to cart button)
    public function add_add_to_cart_button_class($args, $product)
    {
        $className = 'action-btn-cart-005-pf';

        // Skip variable and grouped product types
        if ($product->is_type('variable') || $product->is_type('grouped')) {
            return $args;
        }

        if (empty($args['class'])) {
            $args['class'] = $className;
        } elseif (strpos($args['class'], $className) === false) {
            $args['class'] .= ' ' . $className;
        }

        return $args;
    }

    // Add action-btn-cart-005-pf class to single product add to cart button 
    // (Add this to the add to cart button)
    public function add_single_add_to_cart_button_class()
    {
        $className = 'action-btn-cart-005-pf';

        $script_key = 'pixelflow-single-button-class';

        $script = "(function() {
            var elementToAdd = document.querySelectorAll('.single_add_to_cart_button');
            elementToAdd.forEach(function(button) {
                if (!button.classList.contains('" . esc_js($className) . "')) {
                    button.classList.add('" . esc_js($className) . "');
                }
            });
        })();";

        wp_register_script($script_key, '', array(), PIXELFLOW_VERSION, array('in_footer' => true));
        wp_add_inline_script($script_key, $script, 'before');
        wp_enqueue_script($script_key);
    }
}

