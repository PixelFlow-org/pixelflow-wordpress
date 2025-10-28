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

            // Add info-itm-qnty-pf class to cart item quantity
            // (Add this to the Item quantity:)
            if ($this->is_class_enabled('woo_class_cart_quantity')) {
                add_filter('woocommerce_cart_item_quantity', array($this, 'add_cart_item_quantity_class'), 10, 3);
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

            // Gutenberg/Block cart support
            add_action('wp_footer', array($this, 'pf_add_gutenberg_cart_classes'));
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

    // Add info-itm-qnty-pf class to cart item quantity
    // (Add this to the Item quantity:)
    public function add_cart_item_quantity_class($product_quantity, $cart_item_key, $cart_item)
    {
        if ( ! is_cart() || empty($product_quantity)) {
            return $product_quantity;
        }

        $className = 'info-itm-qnty-pf';

        // Check if it's already added
        if (strpos($product_quantity, $className) !== false) {
            return $product_quantity;
        }

        // Add class to quantity input element
        $quantityUpdated = preg_replace_callback(
            '/<input([^>]*class="[^"]*)/i',
            function ($matches) use ($className) {
                return '<input' . $matches[1] . ' ' . esc_attr($className);
            },
            $product_quantity,
            1
        );

        if ($quantityUpdated) {
            return $quantityUpdated;
        }

        return $product_quantity;
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

    public function pf_add_gutenberg_cart_classes() {
        if (!is_cart()) {
            return;
        }

        // Check which classes are enabled
        $enabled_classes = array();
        
        if ($this->is_class_enabled('woo_class_cart_products_container')) {
            $enabled_classes['cart'] = 'info-chk-itm-ctnr-pf';
        }
        if ($this->is_class_enabled('woo_class_cart_item')) {
            $enabled_classes['item'] = 'info-chk-itm-pf';
        }
        if ($this->is_class_enabled('woo_class_cart_price')) {
            $enabled_classes['price'] = 'info-itm-prc-pf';
        }
        if ($this->is_class_enabled('woo_class_cart_quantity')) {
            $enabled_classes['qty'] = 'info-itm-qnty-pf';
        }
        if ($this->is_class_enabled('woo_class_cart_checkout_button')) {
            $enabled_classes['btn'] = 'action-btn-buy-004-pf';
        }
        if ($this->is_class_enabled('woo_class_cart_product_name')) {
            $enabled_classes['name'] = 'info-itm-name-pf';
        }

        // If no classes are enabled, don't output anything
        if (empty($enabled_classes)) {
            return;
        }

        ?>
        <script>
            (function() {
                <?php if (isset($enabled_classes['cart'])): ?>
                const CLASS_CART = <?php echo wp_json_encode($enabled_classes['cart']); ?>;
                <?php endif; ?>
                <?php if (isset($enabled_classes['item'])): ?>
                const CLASS_ITEM = <?php echo wp_json_encode($enabled_classes['item']); ?>;
                <?php endif; ?>
                <?php if (isset($enabled_classes['price'])): ?>
                const CLASS_PRICE = <?php echo wp_json_encode($enabled_classes['price']); ?>;
                <?php endif; ?>
                <?php if (isset($enabled_classes['qty'])): ?>
                const CLASS_QTY = <?php echo wp_json_encode($enabled_classes['qty']); ?>;
                <?php endif; ?>
                <?php if (isset($enabled_classes['btn'])): ?>
                const CLASS_BTN = <?php echo wp_json_encode($enabled_classes['btn']); ?>;
                <?php endif; ?>
                <?php if (isset($enabled_classes['name'])): ?>
                const CLASS_NAME = <?php echo wp_json_encode($enabled_classes['name']); ?>;
                <?php endif; ?>

                const MAX_CHECKS = 500;
                const CHECK_DELAY_MS = 50;

                let checks = 0;
                let observer = null;
                let timerId = null;

                const hasClassicCart = () => !!document.querySelector('.woocommerce-cart-form, form.woocommerce-cart-form');
                const getBlocksCart = () => document.querySelector('.wc-block-cart');

                const applyClasses = root => {
                    if (!root) return;

                    <?php if (isset($enabled_classes['cart'])): ?>
                    const cart = root.querySelector('.wc-block-cart');
                    if (cart) cart.classList.add(CLASS_CART);
                    <?php endif; ?>

                    <?php if (isset($enabled_classes['item'])): ?>
                    root.querySelectorAll('.wc-block-cart-items .wc-block-cart-items__row').forEach(el => el.classList.add(CLASS_ITEM));
                    <?php endif; ?>

                    <?php if (isset($enabled_classes['price'])): ?>
                    root.querySelectorAll('.wc-block-cart-item__total .price .wc-block-formatted-money-amount').forEach(el => el.classList.add(CLASS_PRICE));
                    <?php endif; ?>

                    <?php if (isset($enabled_classes['qty'])): ?>
                    root.querySelectorAll('.wc-block-components-quantity-selector__input').forEach(el => el.classList.add(CLASS_QTY));
                    <?php endif; ?>

                    <?php if (isset($enabled_classes['btn'])): ?>
                    root.querySelectorAll('.wc-block-cart__submit-button, .wc-block-components-checkout-place-order-button').forEach(el => el.classList.add(CLASS_BTN));
                    <?php endif; ?>

                    <?php if (isset($enabled_classes['name'])): ?>
                    root.querySelectorAll('.wc-block-components-product-name, .wc-block-components-product-name a').forEach(el => el.classList.add(CLASS_NAME));
                    <?php endif; ?>
                };

                const startObserver = cart => {
                    if (!cart) return;
                    if (observer) observer.disconnect();
                    observer = new MutationObserver(() => applyClasses(document));
                    observer.observe(cart, { childList: true, subtree: true });
                    applyClasses(document);
                    console.log('[PF] Blocks cart ready. Classes applied and observer active.');
                };

                const stopPolling = reason => {
                    if (timerId !== null) {
                        clearInterval(timerId);
                        timerId = null;
                    }
                    if (observer) {
                        observer.disconnect();
                        observer = null;
                    }
                    if (reason) console.log('[PF] Stopped:', reason);
                };

                const pollUntilReady = () => {
                    timerId = setInterval(() => {
                        checks++;
                        const cart = getBlocksCart();
                        console.log('[PF] Polling for Blocks cart... Check #' + checks);

                        if (cart && document.querySelectorAll('.wc-block-cart-items .wc-block-cart-items__row').length > 0) {
                            stopPolling();
                            startObserver(cart);
                            return;
                        }

                        if (hasClassicCart()) {
                            stopPolling('Classic cart detected. No Blocks cart on this page.');
                            return;
                        }

                        if (checks >= MAX_CHECKS) {
                            stopPolling('No Blocks cart found after limit. Bailing safely.');
                        }
                    }, CHECK_DELAY_MS);
                };

                const init = () => {
                    if (hasClassicCart() && !getBlocksCart()) {
                        return;
                    }
                    pollUntilReady();
                };

                if (document.readyState === 'complete' || document.readyState === 'interactive') {
                    init();
                } else {
                    window.addEventListener('DOMContentLoaded', init, { once: true });
                }
            })();
        </script>
        <?php
    }
}

