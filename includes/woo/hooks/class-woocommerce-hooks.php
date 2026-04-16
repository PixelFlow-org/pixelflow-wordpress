<?php
/**
 * WooCommerce Hooks
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
    private string $api_url;
    private string $api_key;
    private string $site_external_id;
    private $options;
    private array $sent_in_request = [];
    private bool  $coupon_changed  = false;

    private const DEFAULT_TIMEOUT = 5;
    private int $timeout;

    /**
     * Constructor
     */
    public function __construct(string $api_url, string $api_key, string $site_external_id, $options)
    {
        $this->api_url          = rtrim($api_url, '/');
        $this->api_key          = $api_key;
        $this->site_external_id = $site_external_id;
        $this->options          = $options;
        $this->timeout          = $this->get_timeout();


        $this->init_hooks();
    }

    private function get_timeout(): int
    {
        $timeout = (int)apply_filters(
            'pixelflow_request_timeout',
            self::DEFAULT_TIMEOUT,
            $this->site_external_id,
        );

        return $timeout > 0 ? $timeout : self::DEFAULT_TIMEOUT;
    }


    /**
     * Initialize hooks
     */
    private function init_hooks()
    {
        // Skip all hook registration if this is a cache-warmer / internal request
        if (pixelflow_is_cache_warmer_request()) {
            return;
        }

        if (is_admin()) {
            return;
        }

        add_action('woocommerce_add_to_cart', [$this, 'pf_add_to_cart_hook'], 10, 6);
        add_action('woocommerce_after_cart_item_quantity_update', [$this, 'pf_cart_item_quantity_update_hook'], 10, 4);

        add_action('woocommerce_before_checkout_form', [$this, 'pf_initiate_checkout_hook'], 10);
        add_action('wp', [$this, 'pf_initiate_checkout_fallback'], 20);
        add_action('woocommerce_cart_emptied', [$this, 'pf_reset_checkout_guard'], 10);
        add_action('woocommerce_applied_coupon',         [$this, 'pf_coupon_changed_flag'], 10);
        add_action('woocommerce_removed_coupon',         [$this, 'pf_coupon_changed_flag'], 10);
        add_action('woocommerce_after_calculate_totals', [$this, 'pf_after_calculate_totals_hook'], 20);

        // Persist tracking cookies to order meta while the browser request is available
        add_action('woocommerce_new_order', [$this, 'pf_save_tracking_cookies_to_order'], 10, 1);

        // Fire Purchase on order status change (works even for async payment gateways)
        add_action('woocommerce_order_status_processing', [$this, 'pf_purchase_hook'], 10, 1);
        add_action('woocommerce_order_status_completed', [$this, 'pf_purchase_hook'], 10, 1);

        // Keep thankyou as a fallback for gateways that go straight to "on-hold" or "pending"
        add_action('woocommerce_thankyou', [$this, 'pf_purchase_hook'], 10, 1);
    }

    /**
     * Classic add-to-cart handler
     *
     * @param string $cart_item_key
     * @param int $product_id
     * @param int $quantity
     * @param int $variation_id
     * @param array $variation
     * @param array $cart_item_data
     */
    public function pf_add_to_cart_hook(
        string $cart_item_key,
        int $product_id,
        int $quantity,
        int $variation_id,
        array $variation,
        array $cart_item_data
    ): void {
        if (pixelflow_is_blocked_ajax_action()) {
            return;
        }

        // One event per actual cart line add
        $dedupe_key = 'add_to_cart:' . $cart_item_key;

        if ( ! $this->should_send_event($dedupe_key, 5)) {
            return;
        }

        if ( ! function_exists('wc_get_product')) {
            return;
        }

        $wc_product_id = $variation_id > 0 ? $variation_id : $product_id;
        $product       = wc_get_product($wc_product_id);

        if ( ! $product) {
            return;
        }

        // Master disable: skip AddToCart for all products (overrides freebies setting)
        if ( ! empty($this->options['woo_disable_add_to_cart']) && (int)$this->options['woo_disable_add_to_cart'] === 1) {
            return;
        }

        // When option is set to 1: do not send AddToCart for free products (price 0)
        if ( ! empty($this->options['woo_disable_add_to_cart_freebies']) && (int)$this->options['woo_disable_add_to_cart_freebies'] === 1) {
            if ((float)wc_get_price_to_display($product) <= 0) {
                return;
            }
        }

        $event_time = time();
        $utm        = pixelflow_get_utm_params_from_cookie();

        $payload = [
            'siteId'    => (string)$this->site_external_id,
            'eventData' => [
                'event_id'       => uniqid('', true),
                'eventName'      => 'AddToCart',
                'eventTime'      => $event_time,
                'actionSource'   => 'website',
                'siteURL'        => pixelflow_get_site_url(),
                'additionalData' => $this->build_additional_data($product, $quantity),
            ],
        ];
        if ( ! empty($utm)) {
            $payload['eventData']['utm_params'] = $utm;
        }
        pixelflow_append_cookie_params($payload);

        $customer = $this->build_customer_data_from_current_user();
        if ( ! empty($customer)) {
            $payload['eventData']['customerData'] = $customer;
        }

        $this->post_event($payload);
    }

    /**
     * Fires when an existing cart item's quantity is updated (e.g. via WC Store API update-item).
     * Sends AddToCart only when the quantity has increased (delta > 0).
     *
     * @param string  $cart_item_key
     * @param int     $new_quantity
     * @param int     $old_quantity
     * @param WC_Cart $cart
     */
    public function pf_cart_item_quantity_update_hook(
        string $cart_item_key,
        int $new_quantity,
        int $old_quantity,
        $cart
    ): void {
        $added = $new_quantity - $old_quantity;
        if ($added <= 0) {
            return; // quantity decreased or unchanged — not an AddToCart
        }

        if (pixelflow_is_blocked_ajax_action()) {
            return;
        }

        // Use the same dedupe key as pf_add_to_cart_hook with TTL=0:
        // - TTL=0 means no session-based blocking, so rapid successive clicks each fire their own event
        // - The in-request guard (sent_in_request) still prevents double-firing when classic
        //   WooCommerce fires both woocommerce_add_to_cart AND this hook within the same request
        $dedupe_key = 'add_to_cart:' . $cart_item_key;
        if ( ! $this->should_send_event($dedupe_key, 0)) {
            return;
        }

        if ( ! function_exists('wc_get_product')) {
            return;
        }

        $cart_item = $cart->get_cart_item($cart_item_key);
        if ( ! $cart_item) {
            return;
        }

        $variation_id  = (int)($cart_item['variation_id'] ?? 0);
        $product_id    = (int)($cart_item['product_id'] ?? 0);
        $wc_product_id = $variation_id > 0 ? $variation_id : $product_id;
        $product       = wc_get_product($wc_product_id);

        if ( ! $product) {
            return;
        }

        // Master disable: skip AddToCart for all products (overrides freebies setting)
        if ( ! empty($this->options['woo_disable_add_to_cart']) && (int)$this->options['woo_disable_add_to_cart'] === 1) {
            return;
        }

        if ( ! empty($this->options['woo_disable_add_to_cart_freebies']) && (int)$this->options['woo_disable_add_to_cart_freebies'] === 1) {
            if ((float)wc_get_price_to_display($product) <= 0) {
                return;
            }
        }

        $utm = pixelflow_get_utm_params_from_cookie();

        $payload = [
            'siteId'    => (string)$this->site_external_id,
            'eventData' => [
                'event_id'       => uniqid('', true),
                'eventName'      => 'AddToCart',
                'eventTime'      => time(),
                'actionSource'   => 'website',
                'siteURL'        => pixelflow_get_site_url(),
                'additionalData' => $this->build_additional_data($product, $added),
            ],
        ];
        if ( ! empty($utm)) {
            $payload['eventData']['utm_params'] = $utm;
        }
        pixelflow_append_cookie_params($payload);

        $customer = $this->build_customer_data_from_current_user();
        if ( ! empty($customer)) {
            $payload['eventData']['customerData'] = $customer;
        }

        $this->post_event($payload);
    }

    /**
     * Existing (AddToCart) additional data builder
     */
    private function build_additional_data(WC_Product $product, int $quantity): array
    {
        $price = (float)wc_get_price_to_display($product);

        $name = (string)$product->get_name();
        if ($product instanceof WC_Product_Variation) {
            $parent = wc_get_product((int)$product->get_parent_id());
            if ($parent) {
                $name  = (string)$parent->get_name();
                $attrs = wc_get_formatted_variation($product, true, false, true);
                if (is_string($attrs) && $attrs !== '') {
                    $name .= ' — ' . $attrs;
                }
            }
        }

        $id = (int)$product->get_id();

        $currency = function_exists('get_woocommerce_currency') ? (string)get_woocommerce_currency() : 'USD';
        $qty      = (int)max(1, $quantity);

        return [
            'content_ids' => ['product_' . $id],
            'contentName' => $name,
            'currency'    => $currency,
            'value'       => $price * $qty,
            'contentType' => 'product',
            'contents'    => [
                [
                    'id'         => 'product_' . $id,
                    'quantity'   => $qty,
                    'item_price' => $price,
                ],
            ],
        ];
    }

    /**
     * InitiateCheckout (server-side best approximation):
     * fires when checkout page is entered, guarded to avoid multiple sends.
     */
    public function pf_initiate_checkout_hook(): void
    {
        $this->maybe_send_initiate_checkout('before_checkout_form');
    }

    /**
     * Fallback: runs on checkout page load even if template hooks differ.
     */
    public function pf_initiate_checkout_fallback(): void
    {
        if ( ! function_exists('is_checkout') || ! is_checkout()) {
            return;
        }

        if (function_exists('is_order_received_page') && is_order_received_page()) {
            return;
        }

        $this->maybe_send_initiate_checkout('wp_fallback');
    }

    private function get_initiate_checkout_guard_key(): string
    {
        if ( ! function_exists('WC') || ! WC()->cart) {
            return '';
        }

        $cart        = WC()->cart;
        $fingerprint = md5(json_encode([
            'hash'    => $cart->get_cart_hash(),
            'coupons' => $cart->get_applied_coupons(),
            'total'   => $cart->get_cart_contents_total(),
        ]));

        return 'pf_initiate_checkout_' . $fingerprint;
    }

    /**
     * Whether the cart has at least one item with price > 0.
     */
    private function cart_has_paid_items($cart): bool
    {
        foreach ($cart->get_cart() as $cart_item) {
            $pid           = isset($cart_item['product_id']) ? (int)$cart_item['product_id'] : 0;
            $vid           = isset($cart_item['variation_id']) ? (int)$cart_item['variation_id'] : 0;
            $wc_product_id = $vid > 0 ? $vid : $pid;
            if ($wc_product_id <= 0) {
                continue;
            }
            $product = wc_get_product($wc_product_id);
            if ($product && (float)wc_get_price_to_display($product) > 0) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether the order has at least one line item with price > 0.
     */
    private function order_has_paid_items(WC_Order $order): bool
    {
        foreach ($order->get_items('line_item') as $item) {
            if ( ! ($item instanceof WC_Order_Item_Product)) {
                continue;
            }
            $product = $item->get_product();
            if ($product && (float)wc_get_price_to_display($product) > 0) {
                return true;
            }
        }

        return false;
    }

    private function maybe_send_initiate_checkout(string $source): void
    {
        if ( ! function_exists('WC') || ! WC()->cart) {
            return;
        }

        $cart = WC()->cart;

        if ($cart->is_empty()) {
            return;
        }

        // Master disable: skip InitiateCheckout entirely (overrides freebies setting)
        if ( ! empty($this->options['woo_disable_initiate_checkout']) && (int)$this->options['woo_disable_initiate_checkout'] === 1) {
            return;
        }

        // When option is set to 1: do not send InitiateCheckout if cart has only free products
        if ( ! empty($this->options['woo_disable_initiate_checkout_freebies']) && (int)$this->options['woo_disable_initiate_checkout_freebies'] === 1) {
            if ( ! $this->cart_has_paid_items($cart)) {
                return;
            }
        }

        if ( ! WC()->session) {
            return;
        }
        $additionalData = $this->build_checkout_additional_data_from_cart($cart);
        $utm_params     = pixelflow_get_utm_params_from_cookie();

        $guard_key = $this->get_initiate_checkout_guard_key();

        if ($guard_key !== '') {
            $already = WC()->session->get($guard_key);
            if ($already) {
                return;
            }
        }

        // Extra dedupe (fast TTL) to avoid same-load double send when multiple hooks fire
        if ( ! $this->should_send_event('initiate_checkout:' . $source, 10)) {
            return;
        }

        if ($guard_key !== '') {
            WC()->session->set($guard_key, 1);
            // Remember the active guard key so pf_reset_checkout_guard() can clear it
            // even after the cart is emptied (where get_cart_hash() would differ).
            WC()->session->set('pf_last_checkout_guard_key', $guard_key);
        }

        $event_time = time();

        $payload = [
            'siteId'    => (string)$this->site_external_id,
            'eventData' => [
                'event_id'       => uniqid('', true),
                'eventName'      => 'InitiateCheckout',
                'eventTime'      => $event_time,
                'actionSource'   => 'website',
                'siteURL'        => pixelflow_get_site_url(),
                'additionalData' => $additionalData,
            ],
        ];
        if ( ! empty($utm_params)) {
            $payload['eventData']['utm_params'] = $utm_params;
        }
        pixelflow_append_cookie_params($payload);

        $customer = $this->build_customer_data_from_current_user();
        if ( ! empty($customer)) {
            $payload['eventData']['customerData'] = $customer;
        }

        $this->post_event($payload);
    }

    public function pf_coupon_changed_flag(): void
    {
        $this->coupon_changed = true;
    }

    public function pf_after_calculate_totals_hook(): void
    {
        if ( ! $this->coupon_changed) {
            return;
        }
        $this->coupon_changed = false;
        $this->maybe_send_initiate_checkout('coupon_changed');
    }

    public function pf_reset_checkout_guard(): void
    {
        if ( ! function_exists('WC') || ! WC()->session) {
            return;
        }

        $guard_key = WC()->session->get('pf_last_checkout_guard_key');
        if ( ! empty($guard_key) && is_string($guard_key)) {
            WC()->session->set($guard_key, 0);
            WC()->session->set('pf_last_checkout_guard_key', '');
        }
    }

    /**
     * Save tracking cookies to order meta at order creation time.
     * At this point the browser IS making the request, so $_COOKIE is available.
     * By the time the order status changes (via async webhook), cookies are gone.
     */
    public function pf_save_tracking_cookies_to_order($order_id): void
    {
        $order_id = (int)$order_id;
        if ($order_id <= 0) {
            return;
        }

        $order = wc_get_order($order_id);
        if ( ! $order) {
            return;
        }

        $cookie_keys = ['_fbp', 'pf_fbc', '_fbc', 'pf_clkid', 'pf_loc', '_pf_utm'];

        foreach ($cookie_keys as $key) {
            if (isset($_COOKIE[$key]) && is_string($_COOKIE[$key]) && $_COOKIE[$key] !== '') {
                $order->update_meta_data('_pf_cookie_' . $key, sanitize_text_field(wp_unslash($_COOKIE[$key])));
            }
        }

        // Also save client IP and UA while we have them from the real browser request
        $ip = pixelflow_get_client_ip_address();
        $ua = pixelflow_get_client_user_agent();

        if ($ip !== '' && ! pixelflow_is_private_ip($ip)) {
            $order->update_meta_data('_pf_client_ip', $ip);
        }
        if ($ua !== '') {
            $order->update_meta_data('_pf_client_ua', $ua);
        }

        $order->save();
    }

    /**
     * Purchase: fires on order status change to processing/completed, or on thank-you page as fallback.
     */
    public function pf_purchase_hook($order_id): void
    {
        $order_id = (int)$order_id;

        if ($order_id <= 0) {
            return;
        }

        if ( ! function_exists('wc_get_order')) {
            return;
        }

        $order = wc_get_order($order_id);

        if ( ! $order) {
            return;
        }

        // Master disable: skip Purchase entirely (overrides freebies setting)
        if ( ! empty($this->options['woo_disable_purchase']) && (int)$this->options['woo_disable_purchase'] === 1) {
            return;
        }

        // When option is set to 1: do not send Purchase if order has only free products
        if ( ! empty($this->options['woo_disable_purchase_freebies']) && (int)$this->options['woo_disable_purchase_freebies'] === 1) {
            if ( ! $this->order_has_paid_items($order)) {
                return;
            }
        }

        // Avoid duplicate sends (works across thankyou page refresh AND status change hooks)
        $meta_key = '_pf_purchase_sent';
        $sent     = $order->get_meta($meta_key, true);

        if ( ! empty($sent)) {
            return;
        }

        $order->update_meta_data($meta_key, '1');
        $order->save();

        // Reset checkout guard after successful purchase
        $this->pf_reset_checkout_guard();

        $event_time = time();

        $additional = $this->build_purchase_additional_data_from_order($order);
        $customer   = $this->build_customer_data_from_order($order);
        $utm        = $this->get_utm_params_for_order($order);

        $payload = [
            'siteId'    => (string)$this->site_external_id,
            'eventData' => [
                'event_id'       => uniqid('', true),
                'eventName'      => 'Purchase',
                'eventTime'      => $event_time,
                'actionSource'   => 'website',
                'siteURL'        => $order->get_checkout_order_received_url() ?: pixelflow_get_site_url(),
                'customerData'   => $customer,
                'additionalData' => $additional,
            ],
        ];
        if ( ! empty($utm)) {
            $payload['eventData']['utm_params'] = $utm;
        }
        $this->append_cookie_params_for_order($payload, $order);

        $this->post_event($payload);
    }

    /**
     * Get UTM params from order meta (saved at order creation) or fall back to cookies.
     */
    private function get_utm_params_for_order(WC_Order $order): array
    {
        $saved_utm = $order->get_meta('_pf_cookie__pf_utm', true);
        if ( ! empty($saved_utm) && is_string($saved_utm)) {
            parse_str($saved_utm, $parsed);
            if (is_array($parsed) && ! empty($parsed)) {
                $allowed = ['utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content', 'utm_id'];
                $out = [];
                foreach ($allowed as $key) {
                    if (isset($parsed[$key]) && is_scalar($parsed[$key])) {
                        $out[$key] = sanitize_text_field((string)$parsed[$key]);
                    }
                }
                if ( ! empty($out)) {
                    return $out;
                }
            }
        }

        return pixelflow_get_utm_params_from_cookie();
    }

    /**
     * Append cookie params from order meta (saved at creation) or fall back to live cookies.
     */
    private function append_cookie_params_for_order(array &$payload, WC_Order $order): void
    {
        if ( ! isset($payload['eventData']) || ! is_array($payload['eventData'])) {
            return;
        }

        $map = [
            'clkId' => 'pf_clkid',
            'fbc'   => 'pf_fbc',
            'fbp'   => '_fbp',
        ];

        foreach ($map as $param => $cookie_name) {
            // Try order meta first (saved at woocommerce_new_order)
            $val = $order->get_meta('_pf_cookie_' . $cookie_name, true);
            if (empty($val) && isset($_COOKIE[$cookie_name])) {
                $val = sanitize_text_field(wp_unslash($_COOKIE[$cookie_name]));
            }
            if ( ! empty($val) && is_string($val)) {
                $payload['eventData'][$param] = $val;
            }
        }

        // Fallback for _fbc
        if ( ! isset($payload['eventData']['fbc'])) {
            $fbc = $order->get_meta('_pf_cookie__fbc', true);
            if (empty($fbc) && isset($_COOKIE['_fbc'])) {
                $fbc = sanitize_text_field(wp_unslash($_COOKIE['_fbc']));
            }
            if ( ! empty($fbc) && is_string($fbc)) {
                $payload['eventData']['fbc'] = $fbc;
            }
        }
    }


    /**
     * Dedupe helper:
     * - per-request static guard
     * - plus (when possible) Woo session TTL guard to survive multiple hooks in one page load
     */
    private function should_send_event(string $key, int $ttl_seconds): bool
    {
        if (isset($this->sent_in_request[$key]) && $this->sent_in_request[$key] === 1) {
            return false;
        }

        $this->sent_in_request[$key] = 1;

        if ( ! function_exists('WC') || ! WC()->session) {
            return true;
        }

        $session_key = 'pf_dedupe_' . md5($key);
        $last_ts_raw = WC()->session->get($session_key);

        $now     = time();
        $last_ts = is_numeric($last_ts_raw) ? (int)$last_ts_raw : 0;

        if ($last_ts > 0 && ($now - $last_ts) < $ttl_seconds) {
            return false;
        }

        WC()->session->set($session_key, $now);

        return true;
    }


    /**
     * Build hashed customer data for the currently logged-in WP user.
     * Returns an empty array for guests (no user_id).
     * Used to enrich AddToCart and InitiateCheckout events with PII for logged-in users,
     * improving Facebook match rate before the order is placed.
     *
     * Pulls WP user fields + WooCommerce billing meta (billing_phone, billing_city, etc.)
     * when available. client_ip_address and client_user_agent are added by post_event().
     */
    private function build_customer_data_from_current_user(): array
    {
        $user_id = get_current_user_id();
        if ($user_id <= 0) {
            return [];
        }

        $user = get_userdata($user_id);
        if ( ! $user) {
            return [];
        }

        // WooCommerce stores billing data in user meta
        $phone   = (string)get_user_meta($user_id, 'billing_phone',     true);
        $city    = (string)get_user_meta($user_id, 'billing_city',       true);
        $state   = (string)get_user_meta($user_id, 'billing_state',      true);
        $zip     = (string)get_user_meta($user_id, 'billing_postcode',   true);
        $country = (string)get_user_meta($user_id, 'billing_country',    true);

        // Use billing first/last name when available, fall back to WP display name fields
        $fn = (string)get_user_meta($user_id, 'billing_first_name', true);
        $ln = (string)get_user_meta($user_id, 'billing_last_name',  true);
        if ($fn === '') {
            $fn = (string)$user->first_name;
        }
        if ($ln === '') {
            $ln = (string)$user->last_name;
        }

        $out = [
            'em'          => pixelflow_sha256_if_not_empty(pixelflow_normalize_email((string)$user->user_email)),
            'fn'          => pixelflow_sha256_if_not_empty(pixelflow_normalize_name($fn)),
            'ln'          => pixelflow_sha256_if_not_empty(pixelflow_normalize_name($ln)),
            'ph'          => pixelflow_sha256_if_not_empty(pixelflow_normalize_phone($phone)),
            'ct'          => pixelflow_sha256_if_not_empty(pixelflow_normalize_city($city)),
            'st'          => pixelflow_sha256_if_not_empty(pixelflow_normalize_state($state)),
            'zp'          => pixelflow_sha256_if_not_empty(pixelflow_normalize_zip($zip)),
            'country'     => pixelflow_sha256_if_not_empty(pixelflow_normalize_country($country)),
            'external_id' => pixelflow_sha256_if_not_empty(pixelflow_normalize_external_id((string)$user_id)),
        ];

        return array_filter($out, fn($v) => $v !== '');
    }

    private function build_customer_data_from_order(WC_Order $order): array
    {
        $email   = (string)$order->get_billing_email();
        $phone   = (string)$order->get_billing_phone();
        $fn      = (string)$order->get_billing_first_name();
        $ln      = (string)$order->get_billing_last_name();
        $city    = (string)$order->get_billing_city();
        $state   = (string)$order->get_billing_state();
        $zip     = (string)$order->get_billing_postcode();
        $country = (string)$order->get_billing_country(); // ISO alpha-2 in Woo, usually

        $customer_id  = (int)$order->get_customer_id();
        $external_raw = $customer_id > 0 ? (string)$customer_id : ($email !== '' ? $email : (string)$order->get_id());

        $out = [];

        $out['ln'] = pixelflow_sha256_if_not_empty(pixelflow_normalize_name($ln));
        $out['fn'] = pixelflow_sha256_if_not_empty(pixelflow_normalize_name($fn));
        $out['em'] = pixelflow_sha256_if_not_empty(pixelflow_normalize_email($email));
        $out['ph'] = pixelflow_sha256_if_not_empty(pixelflow_normalize_phone($phone));

        $out['st']      = pixelflow_sha256_if_not_empty(pixelflow_normalize_state($state));
        $out['zp']      = pixelflow_sha256_if_not_empty(pixelflow_normalize_zip($zip));
        $out['ct']      = pixelflow_sha256_if_not_empty(pixelflow_normalize_city($city));
        $out['country'] = pixelflow_sha256_if_not_empty(pixelflow_normalize_country($country));

        $out['external_id'] = pixelflow_sha256_if_not_empty(pixelflow_normalize_external_id($external_raw));

        // Get IP and UA: prefer values saved to order meta at creation time (real browser request),
        // fall back to current request values
        $ip = (string)$order->get_meta('_pf_client_ip', true);
        if ($ip === '') {
        $ip = pixelflow_get_client_ip_address();
        }

        $ua = (string)$order->get_meta('_pf_client_ua', true);
        if ($ua === '') {
        $ua = pixelflow_get_client_user_agent();
        }

        if ($ip !== '' && ! pixelflow_is_private_ip($ip)) {
            $out['client_ip_address'] = $ip;
        }

        if ($ua !== '') {
            $out['client_user_agent'] = $ua;
        }

        // Restore location data from saved pf_loc cookie if not already set
        $saved_loc = (string)$order->get_meta('_pf_cookie_pf_loc', true);
        if ($saved_loc !== '' && empty($out['st'])) {
            $decoded = json_decode($saved_loc, true);
            if (is_array($decoded)) {
                if (isset($decoded['st']) && $decoded['st'] !== '') {
                    $out['st'] = sanitize_text_field($decoded['st']);
                }
                if (isset($decoded['zp']) && $decoded['zp'] !== '') {
                    $out['zp'] = sanitize_text_field($decoded['zp']);
                }
                if (isset($decoded['ct']) && $decoded['ct'] !== '') {
                    $out['ct'] = sanitize_text_field($decoded['ct']);
                }
                if (isset($decoded['country']) && $decoded['country'] !== '') {
                    $out['country'] = sanitize_text_field($decoded['country']);
                }
            }
        }

        foreach ($out as $k => $v) {
            if ($v === '' || $v === null) {
                unset($out[$k]);
            }
        }

        return $out;
    }


    /**
     * InitiateCheckout additionalData requirements:
     * content_ids, contents, currency, num_items, value
     */
    private function build_checkout_additional_data_from_cart($cart): array
    {
        $currency = function_exists('get_woocommerce_currency') ? (string)get_woocommerce_currency() : 'USD';
        $decimals = wc_get_price_decimals();

        $content_ids = [];
        $contents    = [];
        $num_items   = 0;

        foreach ($cart->get_cart() as $cart_item) {
            $qty = isset($cart_item['quantity']) ? (int)$cart_item['quantity'] : 0;
            if ($qty <= 0) {
                continue;
            }

            $pid = isset($cart_item['product_id']) ? (int)$cart_item['product_id'] : 0;
            $vid = isset($cart_item['variation_id']) ? (int)$cart_item['variation_id'] : 0;

            $tracked_id = $vid > 0 ? $vid : $pid;

            if ($tracked_id <= 0) {
                continue;
            }

            // Unit price (after discounts), excluding tax
            $line_total = isset($cart_item['line_total']) ? (float)$cart_item['line_total'] : 0.0;
            $price      = $line_total / $qty;

            // Fallbacks (if some theme/plugin messed with line_total)
            if ($price <= 0) {
                $line_subtotal = isset($cart_item['line_subtotal']) ? (float)$cart_item['line_subtotal'] : 0.0;
                $price         = $line_subtotal / $qty;
            }

            if ($price <= 0 && isset($cart_item['data']) && $cart_item['data'] instanceof WC_Product) {
                $product = $cart_item['data'];

                $raw   = $product->get_price(); // string or ''
                $price = $raw !== '' ? (float)wc_format_decimal($raw, $decimals) : 0.0;
            }

            $price = (float)wc_format_decimal($price, $decimals);

            $content_ids[] = 'product_' . $tracked_id;
            $contents[]    = [
                'id'         => 'product_' . $tracked_id,
                'quantity'   => $qty,
                'item_price' => $price,
            ];

            $num_items += $qty;
        }

        $value = (float)wc_format_decimal($cart->get_cart_contents_total(), $decimals);
        $this->apply_rounding_correction($contents, $value, $decimals);

        return [
            'content_ids' => array_values(array_unique($content_ids)),
            'contentType' => 'product',
            'contents'    => $contents,
            'currency'    => $currency,
            'num_items'   => (int)$num_items,
            'value'       => $value,
        ];
    }

    /**
     * Applies a minimal safe rounding correction to one item so that
     * sum(item_price * quantity) gets as close to value as possible.
     */
    private function apply_rounding_correction(array &$contents, float $value, int $decimals): void
    {
        if (empty($contents)) {
            return;
        }

        $computed = 0.0;
        foreach ($contents as $item) {
            $computed += (float) $item['item_price'] * (int) $item['quantity'];
        }

        $computed = round($computed, $decimals);
        $delta = round($value - $computed, $decimals);

        if ($delta == 0.0) {
            return;
        }

        $step = 1 / (10 ** $decimals);
        $maxGap = count($contents) * $step;

        if (abs($delta) > $maxGap) {
            return;
        }

        $bestIdx = -1;
        $bestCorrected = 0.0;
        $bestResidual = null;
        $bestQty1 = false;

        foreach ($contents as $idx => $item) {
            $qty = (int) ($item['quantity'] ?? 0);
            $price = (float) ($item['item_price'] ?? 0);

            if ($qty <= 0 || $price <= 0.0) {
                continue;
            }

            $corrected = round($price + $delta / $qty, $decimals);

            if ($corrected <= 0.0 || $corrected == $price) {
                continue;
            }

            $newComputed = round($computed - ($price * $qty) + ($corrected * $qty), $decimals);
            $residual = abs(round($value - $newComputed, $decimals));
            $isQty1 = ($qty === 1);

            if (
                $bestIdx === -1
                || $residual < $bestResidual
                || ($residual == $bestResidual && $isQty1 && !$bestQty1)
            ) {
                $bestIdx = $idx;
                $bestCorrected = $corrected;
                $bestResidual = $residual;
                $bestQty1 = $isQty1;

                if ($residual == 0.0) {
                    break;
                }
            }
        }

        if ($bestIdx === -1) {
            return;
        }

        $contents[$bestIdx]['item_price'] = $bestCorrected;
    }

    /**
     * Purchase additionalData requirements:
     * content_ids, contentType, contents, currency, num_items, value
     *
     * Handles order-level discounts (bundles, subscription trials, coupons) by distributing
     * the gap between sum(item totals) and actual products value proportionally across items,
     * so sum(item_price × qty) always equals value minus shipping and tax.
     * For normal orders with no gap the ratio is 1.0 and prices are unchanged.
     */
    private function build_purchase_additional_data_from_order(WC_Order $order): array
    {
        $currency  = (string)$order->get_currency();
        $num_items = 0;

        // ── Pass 1: collect raw line totals ──────────────────────────────────
        $rows            = [];
        $product_names   = [];
        $sum_item_totals = 0.0;

        foreach ($order->get_items('line_item') as $item) {
            if ( ! ($item instanceof WC_Order_Item_Product)) {
                continue;
            }

            $qty = (int)$item->get_quantity();
            if ($qty <= 0) {
                continue;
            }

            $pid        = (int)$item->get_product_id();
            $vid        = (int)$item->get_variation_id();
            $tracked_id = $vid > 0 ? $vid : $pid;

            if ($tracked_id <= 0) {
                continue;
            }

            $product_names[] = (string)$item->get_name();

            // Line total after item-level discounts, excl. tax
            $line_total = (float)$item->get_total();

            // Fallback: subtotal exists but total is 0 (e.g. 100% item-level coupon)
            if ($line_total <= 0) {
                $line_subtotal = (float)$item->get_subtotal();
                if ($line_subtotal > 0) {
                    $line_total = $line_subtotal;
                }
            }

            $rows[]           = [
                'tracked_id' => $tracked_id,
                'qty'        => $qty,
                'line_total' => $line_total,
            ];
            $sum_item_totals += $line_total;
            $num_items       += $qty;
        }

        // ── Pass 2: calculate discount ratio ─────────────────────────────────
        // What the customer actually paid for products only (excl. shipping + tax)
        $order_products_value = max(
            0.0,
            (float)$order->get_total() - (float)$order->get_shipping_total() - (float)$order->get_total_tax()
        );

        // Only redistribute when there is a gap (order-level discount, bundle, subscription trial).
        // When sum_item_totals == order_products_value the ratio is 1.0 — prices unchanged.
        $discount_ratio = ($sum_item_totals > 0 && $order_products_value < $sum_item_totals)
            ? $order_products_value / $sum_item_totals
            : 1.0;

        // ── Pass 3: build contents with adjusted prices ───────────────────────
        $content_ids = [];
        $contents    = [];

        $decimals = wc_get_price_decimals();

        foreach ($rows as $row) {
            $adjusted_total = $row['line_total'] * $discount_ratio;
            $price          = $row['qty'] > 0
                ? (float)wc_format_decimal($adjusted_total / $row['qty'], $decimals)
                : 0.0;

            $content_ids[] = 'product_' . $row['tracked_id'];
            $contents[]    = [
                'id'         => 'product_' . $row['tracked_id'],
                'quantity'   => $row['qty'],
                'item_price' => $price,
            ];
        }

        $this->apply_rounding_correction($contents, $order_products_value, $decimals);

        return [
            'content_ids' => array_values(array_unique($content_ids)),
            'contentName' => implode(', ', array_unique($product_names)),
            'contentType' => 'product',
            'contents'    => $contents,
            'currency'    => $currency,
            'num_items'   => (int)$num_items,
            'value'       => $order_products_value,
        ];
    }


    /**
     * Write a debug log entry when woo_debug_enabled option is set.
     * Logs: hook name, full payload sent (including customerData, UA, IP), response, selected cookies and server vars.
     *
     * @param string     $hook_name  WooCommerce hook that triggered the event.
     * @param array      $payload    Full payload passed to wp_remote_post.
     * @param mixed|null $response   Return value of wp_remote_post (WP_Error or array; non-blocking so body is empty).
     */
    private function debug_log(string $hook_name, array $payload, $response = null): void
    {
        if (empty($this->options['woo_debug_enabled']) || (int) $this->options['woo_debug_enabled'] !== 1) {
            return;
        }

        $log_file = pixelflow_get_debug_log_path();
        if (empty($log_file)) {
            return;
        }

        if (is_wp_error($response)) {
            $response_summary = ['wp_error' => $response->get_error_message()];
        } elseif (is_array($response)) {
            $response_summary = [
                'code'    => wp_remote_retrieve_response_code($response),
                'message' => wp_remote_retrieve_response_message($response),
            ];
        } else {
            $response_summary = $response;
        }

        $cookie_keys = ['_pf_utm', 'pf_clkid', 'pf_fbc', '_fbp', '_fbc', 'pf_loc'];
        $cookies     = array_intersect_key($_COOKIE, array_flip($cookie_keys));

        $server_keys = ['REQUEST_URI', 'HTTP_ORIGIN', 'HTTP_REFERER', 'SERVER_NAME', 'SERVER_ADDR', 'QUERY_STRING', 'REQUEST_TIME'];
        $server      = array_intersect_key($_SERVER, array_flip($server_keys));

        $raw_ip     = pixelflow_get_client_ip_address();
        $client_ip  = $raw_ip !== '' ? substr($raw_ip, 0, -4) . '****' : '';

        // Mask client_ip_address inside the payload copy before logging
        if (isset($payload['eventData']['customerData']['client_ip_address'])) {
            $ip = (string) $payload['eventData']['customerData']['client_ip_address'];
            $payload['eventData']['customerData']['client_ip_address'] = $ip !== '' ? substr($ip, 0, -4) . '****' : '';
        }

        $entry = [
            'time'      => gmdate('Y-m-d H:i:s'),
            'version'   => PIXELFLOW_VERSION,
            'hook'      => $hook_name,
            'client_ip' => $client_ip,
            'payload'   => $payload,
            'response'  => $response_summary,
            'cookies'   => $cookies,
            'server'    => $server,
        ];

        pixelflow_write_debug_log_entry($log_file, wp_json_encode($entry, JSON_PRETTY_PRINT) . "\n---\n");
    }

    private function post_event(array $payload): void
    {
        $url = $this->api_url . '/event';
        $event_skipped_message = __('EVENT SENDING SKIPPED BECAUSE USER AGENT MATCHED BOT SIGNATURE', 'pixelflow');

        // Resolve UA early so the bot check always has a value
        $ua = pixelflow_get_client_user_agent();

        // Bot check before any further processing
        $is_bot = pixelflow_if_is_bot($ua);

        // Also skip if the resolved IP is private (server-to-server / cache warmer)
        $resolved_ip = pixelflow_get_client_ip_address();
        $is_private_ip = pixelflow_is_private_ip($resolved_ip);

        // add loc from cookies
        $cookie_pf_loc = filter_input(INPUT_COOKIE, 'pf_loc', FILTER_UNSAFE_RAW);

        if (is_string($cookie_pf_loc) && $cookie_pf_loc !== '') {
            if ( ! isset($payload['eventData']['customerData']) || ! is_array($payload['eventData']['customerData'])) {
                $payload['eventData']['customerData'] = [];
            }
            $cd = &$payload['eventData']['customerData'];

            $raw = wp_unslash($cookie_pf_loc);

            $decoded = json_decode($raw, true);
            if (is_array($decoded)) {
                foreach (['st', 'zp', 'ct', 'country'] as $loc_key) {
                    if ( ! isset($cd[$loc_key]) && ! empty($decoded[$loc_key])) {
                        $cd[$loc_key] = sanitize_text_field($decoded[$loc_key]);
                    }
                }
            }
        }

        // add ua and ip to customerData
        if ( ! isset($payload['eventData']['customerData']) || ! is_array($payload['eventData']['customerData'])) {
            $payload['eventData']['customerData'] = [];
        }
        $cd = &$payload['eventData']['customerData'];
        if ( ! isset($cd['client_user_agent']) && $ua !== '') {
                $cd['client_user_agent'] = $ua;
            }
        if ( ! isset($cd['client_ip_address']) && $resolved_ip !== '' && ! $is_private_ip) {
            $cd['client_ip_address'] = $resolved_ip;
        }

        $args = [
            'method'      => 'POST',
            'timeout'     => $this->timeout,
            'blocking'    => false,
            'sslverify'   => true,
            'redirection' => 0,
            'headers'     => [
                'Content-Type' => 'application/json',
                'api-key'      => $this->api_key,
            ],
            'body'        => wp_json_encode($payload),
            'data_format' => 'body',
        ];

        // Make connect stage fast, so it truly doesn’t slow the user down much
        add_filter('http_request_args', [$this, 'tune_connect_timeout_for_pixelflow'], 10, 2);

        if ( ! $is_bot && ! $is_private_ip) {
            $response = wp_remote_post($url, $args);
        } else {
            $skip_reason = $is_bot ? 'BOT_UA' : 'PRIVATE_IP';
            $response = $event_skipped_message . ' (' . $skip_reason . ')';
        }

        remove_filter('http_request_args', [$this, 'tune_connect_timeout_for_pixelflow'], 10);

        $event_name = isset($payload['eventData']['eventName']) ? (string) $payload['eventData']['eventName'] : 'unknown';
        if ($is_bot || $is_private_ip) {
            $event_name .= " " . $event_skipped_message;
        }
        $this->debug_log($event_name, $payload, $response);
    }

    public function tune_connect_timeout_for_pixelflow(array $args, string $url): array
    {
        if ($url === $this->api_url . '/event') {
            $args['connect_timeout'] = 1;
        }

        return $args;
    }


}

