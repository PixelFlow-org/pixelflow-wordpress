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
        if (is_admin()) {
            return;
        }

        add_action('woocommerce_add_to_cart', [$this, 'pf_add_to_cart_hook'], 10, 6);

        add_action('woocommerce_before_checkout_form', [$this, 'pf_initiate_checkout_hook'], 10);
        add_action('wp', [$this, 'pf_initiate_checkout_fallback'], 20);
        add_action('woocommerce_cart_emptied', [$this, 'pf_reset_checkout_guard'], 10);

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

        // When option is set to 1: do not send AddToCart for free products (price 0)
        if ( ! empty($this->options['woo_disable_add_to_cart_freebies']) && $this->options['woo_disable_add_to_cart_freebies'] === 1) {
            if ((float)wc_get_price_to_display($product) <= 0) {
                return;
            }
        }

        $event_time = time();

        $payload = [
            'siteId'    => (string)$this->site_external_id,
            'eventData' => [
                'event_id'       => uniqid('', true),
                'eventName'      => 'AddToCart',
                'eventTime'      => $event_time,
                'actionSource'   => 'website',
                // siteURL is the page URL where event happened
                'siteURL'        => isset($_SERVER['HTTP_REFERER']) ? esc_url_raw(
                    wp_unslash($_SERVER['HTTP_REFERER'])
                ) : home_url('/'),
                'additionalData' => $this->build_additional_data($product, $quantity),
                'utm_params'     => pixelflow_get_utm_params_from_cookie(),
            ],
        ];
        pixelflow_append_cookie_params($payload);

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
        return 'pf_initiate_checkout_' . WC()->cart->get_cart_hash();
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

        // When option is set to 1: do not send InitiateCheckout if cart has only free products
        if ( ! empty($this->options['woo_disable_initiate_checkout_freebies']) && $this->options['woo_disable_initiate_checkout_freebies'] === 1) {
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

        $already = WC()->session->get($guard_key);

        if ($already) {
            return;
        }

        // Extra dedupe (fast TTL) to avoid same-load double send when multiple hooks fire
        if ( ! $this->should_send_event('initiate_checkout:' . $source, 10)) {
            return;
        }

        WC()->session->set($guard_key, 1);

        $event_time = time();

        $payload = [
            'siteId'    => (string)$this->site_external_id,
            'eventData' => [
                'event_id'       => uniqid('', true),
                'eventName'      => 'InitiateCheckout',
                'eventTime'      => $event_time,
                'actionSource'   => 'website',
                'siteURL'        => home_url('/'),
                'additionalData' => $additionalData,
                'utm_params'     => $utm_params,
            ],
        ];
        pixelflow_append_cookie_params($payload);

        $this->post_event($payload);
    }

    public function pf_reset_checkout_guard(): void
    {
        if (function_exists('WC') && WC()->session) {
            $guard_key = $this->get_initiate_checkout_guard_key();
            WC()->session->set($guard_key, 0);
        }
    }

    /**
     * Purchase: fires on thank you page with order_id.
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

        // When option is set to 1: do not send Purchase if order has only free products
        if ( ! empty($this->options['woo_disable_purchase_freebies']) && $this->options['woo_disable_purchase_freebies'] === 1) {
            if ( ! $this->order_has_paid_items($order)) {
                return;
            }
        }

        // Avoid duplicate sends for page refreshes
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

        $payload = [
            'siteId'    => (string)$this->site_external_id,
            'eventData' => [
                'event_id'       => uniqid('', true),
                'eventName'      => 'Purchase',
                'eventTime'      => $event_time,
                'actionSource'   => 'website',
                'siteURL'        => home_url('/'),
                'customerData'   => $customer,
                'additionalData' => $additional,
                'utm_params'     => pixelflow_get_utm_params_from_cookie(),
            ],
        ];
        pixelflow_append_cookie_params($payload);

        $this->post_event($payload);
    }


    /**
     * Dedupe helper:
     * - per-request static guard
     * - plus (when possible) Woo session TTL guard to survive multiple hooks in one page load
     */
    private function should_send_event(string $key, int $ttl_seconds): bool
    {
        static $sent_in_request = [];

        if (isset($sent_in_request[$key]) && $sent_in_request[$key] === 1) {
            return false;
        }

        $sent_in_request[$key] = 1;

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

        if ($ip !== '') {
            $out['client_ip_address'] = $ip;
        }

        if ($ua !== '') {
            $out['client_user_agent'] = $ua;
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
                $price = $raw !== '' ? (float)wc_format_decimal($raw, wc_get_price_decimals()) : 0.0;
            }

            $price = (float)wc_format_decimal($price, wc_get_price_decimals());

            $content_ids[] = 'product_' . $tracked_id;
            $contents[]    = [
                'id'         => 'product_' . $tracked_id,
                'quantity'   => $qty,
                'item_price' => $price,
            ];

            $num_items += $qty;
        }

        $value = (float)$cart->get_total('raw');

        return [
            'content_ids' => array_values(array_unique($content_ids)),
            'contents'    => $contents,
            'currency'    => $currency,
            'num_items'   => (int)$num_items,
            'value'       => $value,
        ];
    }

    /**
     * Purchase additionalData requirements:
     * content_ids, contentType, contents, currency, num_items, value
     */
    private function build_purchase_additional_data_from_order(WC_Order $order): array
    {
        $currency = (string)$order->get_currency();

        $content_ids = [];
        $contents    = [];
        $num_items   = 0;

        foreach ($order->get_items('line_item') as $item) {
            if ( ! ($item instanceof WC_Order_Item_Product)) {
                continue;
            }

            $qty = (int)$item->get_quantity();
            if ($qty <= 0) {
                continue;
            }

            $pid = (int)$item->get_product_id();
            $vid = (int)$item->get_variation_id();

            $tracked_id = $vid > 0 ? $vid : $pid;

            if ($tracked_id <= 0) {
                continue;
            }

            // Unit price after discounts, excluding tax (recommended)
            $line_total = (float)$item->get_total();
            $price      = $line_total / $qty;

            // Fallback: if total is 0 but subtotal exists (rare, but happens)
            if ($price <= 0) {
                $line_subtotal = (float)$item->get_subtotal();
                $price         = $line_subtotal / $qty;
            }

            // Final fallback: product price
            if ($price <= 0) {
                $product = $item->get_product();
                if ($product instanceof WC_Product) {
                    $raw   = $product->get_price();
                    $price = $raw !== '' ? (float)wc_format_decimal($raw, wc_get_price_decimals()) : 0.0;
                }
            }

            $price = (float)wc_format_decimal($price, wc_get_price_decimals());

            $content_ids[] = 'product_' . $tracked_id;
            $contents[]    = [
                'id'         => 'product_' . $tracked_id,
                'quantity'   => $qty,
                'item_price' => $price
            ];

            $num_items += $qty;
        }

        $value = (float)$order->get_total();

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

        $response_summary = null;
        if (is_wp_error($response)) {
            $response_summary = ['wp_error' => $response->get_error_message()];
        } elseif (is_array($response)) {
            $response_summary = [
                'code'    => wp_remote_retrieve_response_code($response),
                'message' => wp_remote_retrieve_response_message($response),
            ];
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
                $cd['st']      = isset($decoded['st']) ? sanitize_text_field($decoded['st']) : '';
                $cd['zp']      = isset($decoded['zp']) ? sanitize_text_field($decoded['zp']) : '';
                $cd['ct']      = isset($decoded['ct']) ? sanitize_text_field($decoded['ct']) : '';
                $cd['country'] = isset($decoded['country']) ? sanitize_text_field($decoded['country']) : '';
            }
        }

        // add ua and ip
        if ( ! isset($payload['eventData']['customerData']) || ! is_array($payload['eventData']['customerData'])) {
            $payload['eventData']['customerData'] = [];
        }
        $cd = &$payload['eventData']['customerData'];
        if ( ! isset($cd['client_user_agent'])) {
            $ua = pixelflow_get_client_user_agent();
            if (is_string($ua) && $ua !== '') {
                $cd['client_user_agent'] = $ua;
            }
        }
        if ( ! isset($cd['client_ip_address'])) {
            $ip = pixelflow_get_client_ip_address();
            if (is_string($ip) && $ip !== '') {
                $cd['client_ip_address'] = $ip;
            }
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

        $response = wp_remote_post($url, $args);

        remove_filter('http_request_args', [$this, 'tune_connect_timeout_for_pixelflow'], 10);

        $event_name = isset($payload['eventData']['eventName']) ? (string) $payload['eventData']['eventName'] : 'unknown';
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

