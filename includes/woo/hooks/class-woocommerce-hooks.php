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
    private $blocking = false;
    private $timeout = 5;

    /**
     * Constructor
     */
    public function __construct(string $api_url, string $api_key, string $site_external_id)
    {
        $this->api_url          = rtrim($api_url, '/');
        $this->api_key          = $api_key;
        $this->site_external_id = $site_external_id;

        $this->init_hooks();
    }


    /**
     * Initialize hooks
     */
    private function init_hooks()
    {
        if (is_admin()) {
            return;
        }
        // Real add-to-cart hook (works for classic + AJAX add to cart)
        add_action('woocommerce_add_to_cart', [$this, 'pf_add_to_cart_hook'], 10, 6);

        // InitiateCheckout: when user enters checkout flow (best server-side signal)
        add_action('woocommerce_before_checkout_form', [$this, 'pf_initiate_checkout_hook'], 10);

        // InitiateCheckout: fallback for setups where before_checkout_form is skipped / different rendering
        add_action('wp', [$this, 'pf_initiate_checkout_fallback'], 20);

        // Reset checkout guard when cart is emptied
        add_action('woocommerce_cart_emptied', [$this, 'pf_reset_checkout_guard'], 10);

        // Purchase: when user lands on thank you page
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

        $this->send_add_to_cart_event($product_id, $variation_id, $quantity);
    }

    private function send_add_to_cart_event(int $product_id, int $variation_id, int $quantity): void
    {
        if ( ! function_exists('wc_get_product')) {
            return;
        }

        $wc_product_id = $variation_id > 0 ? $variation_id : $product_id;
        $product       = wc_get_product($wc_product_id);

        if ( ! $product) {
            return;
        }

        $event_time = time();

        $payload = [
            'siteId'    => (string)$this->site_external_id,
            'eventData' => [
                'event_id'       => uniqid('', true),
                'eventName'      => 'AddToCart',
                'eventTime'      => $event_time,
                'actionSource'   => 'website',
                'siteURL'        => home_url('/'),
                'additionalData' => $this->build_additional_data($product, $quantity),
                'utm_params'     => $this->get_utm_params_from_cookie(),
            ],
        ];
        $this->append_cookie_params($payload);

        $this->post_event($payload);
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

    private function maybe_send_initiate_checkout(string $source): void
    {
        if ( ! function_exists('WC') || ! WC()->cart) {
            return;
        }

        $cart = WC()->cart;

        if ($cart->is_empty()) {
            return;
        }

        if ( ! WC()->session) {
            return;
        }

        $guard_key = 'pf_initiate_checkout_sent';
        $already   = WC()->session->get($guard_key);

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
                'additionalData' => $this->build_checkout_additional_data_from_cart($cart),
                'utm_params'     => $this->get_utm_params_from_cookie(),
            ],
        ];
        $this->append_cookie_params($payload);

        $this->post_event($payload);
    }

    public function pf_reset_checkout_guard(): void
    {
        if (function_exists('WC') && WC()->session) {
            WC()->session->set('pf_initiate_checkout_sent', 0);
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
                'utm_params'     => $this->get_utm_params_from_cookie(),
            ],
        ];
        $this->append_cookie_params($payload);

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
            'content_ids'  => ['product_' . $id],
            'contentName'  => $name,
            'content_name' => $name,
            'currency'     => $currency,
            'value'        => $price * $qty,
            'contentType'  => 'product',
            'content_type' => 'product',
            'numItems'     => $qty,
            'num_items'    => $qty,
        ];
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

        $ip = $this->get_client_ip_address();
        $ua = $this->get_client_user_agent();

        $out = [];

        $out['ln'] = $this->sha256_if_not_empty($this->normalize_name($ln));
        $out['fn'] = $this->sha256_if_not_empty($this->normalize_name($fn));
        $out['em'] = $this->sha256_if_not_empty($this->normalize_email($email));
        $out['ph'] = $this->sha256_if_not_empty($this->normalize_phone($phone));

        $out['st']      = $this->sha256_if_not_empty($this->normalize_state($state));
        $out['zp']      = $this->sha256_if_not_empty($this->normalize_zip($zip));
        $out['ct']      = $this->sha256_if_not_empty($this->normalize_city($city));
        $out['country'] = $this->sha256_if_not_empty($this->normalize_country($country));

        $out['external_id'] = $this->sha256_if_not_empty($this->normalize_external_id($external_raw));

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

    private function sha256_if_not_empty(string $value): string
    {
        if ($value === '') {
            return '';
        }

        return hash('sha256', $value);
    }

    private function normalize_email(string $email): string
    {
        $email = trim($email);
        $email = mb_strtolower($email, 'UTF-8');

        return $email;
    }

    private function normalize_phone(string $phone): string
    {
        $phone = trim($phone);

        // remove everything except digits
        $phone = preg_replace('/\D+/', '', $phone);
        if ( ! is_string($phone)) {
            return '';
        }

        // remove leading zeros (Meta requirement mentions leading zeros)
        $phone = ltrim($phone, '0');

        return $phone;
    }

    private function normalize_name(string $name): string
    {
        $name = trim($name);
        $name = mb_strtolower($name, 'UTF-8');

        // keep letters only (unicode), remove punctuation/spaces
        $name = preg_replace('/[^\p{L}]+/u', '', $name);
        if ( ! is_string($name)) {
            return '';
        }

        return $name;
    }

    private function normalize_city(string $city): string
    {
        $city = trim($city);
        $city = mb_strtolower($city, 'UTF-8');

        // Meta: lowercase, no punctuation, no spaces. Keep unicode letters/numbers.
        $city = preg_replace('/[^\p{L}\p{N}]+/u', '', $city);
        if ( ! is_string($city)) {
            return '';
        }

        return $city;
    }

    private function normalize_state(string $state): string
    {
        $state = trim($state);
        $state = mb_strtolower($state, 'UTF-8');

        // Meta: for US use 2-char abbreviation; we keep only letters/numbers.
        $state = preg_replace('/[^\p{L}\p{N}]+/u', '', $state);
        if ( ! is_string($state)) {
            return '';
        }

        return $state;
    }

    private function normalize_zip(string $zip): string
    {
        $zip = trim($zip);
        $zip = mb_strtolower($zip, 'UTF-8');

        // Meta: no spaces, no dash (we remove all non-alnum)
        $zip = preg_replace('/[^\p{L}\p{N}]+/u', '', $zip);
        if ( ! is_string($zip)) {
            return '';
        }

        return $zip;
    }

    private function normalize_country(string $country): string
    {
        $country = trim($country);
        $country = mb_strtolower($country, 'UTF-8');

        // Woo stores ISO alpha-2 already; keep only letters
        $country = preg_replace('/[^\p{L}]+/u', '', $country);
        if ( ! is_string($country)) {
            return '';
        }

        return $country;
    }

    private function normalize_external_id(string $external_id): string
    {
        $external_id = trim($external_id);
        $external_id = mb_strtolower($external_id, 'UTF-8');

        return $external_id;
    }

    private function get_client_user_agent(): string
    {
        if ( ! isset($_SERVER['HTTP_USER_AGENT'])) {
            return '';
        }

        $ua = $_SERVER['HTTP_USER_AGENT'];

        if ( ! is_string($ua)) {
            return '';
        }

        return trim($ua);
    }

    private function get_client_ip_address(): string
    {
        // Prefer CF if available, then XFF, then REMOTE_ADDR
        $candidates = [];

        if (isset($_SERVER['HTTP_CF_CONNECTING_IP']) && is_string($_SERVER['HTTP_CF_CONNECTING_IP'])) {
            $candidates[] = $_SERVER['HTTP_CF_CONNECTING_IP'];
        }

        if (isset($_SERVER['HTTP_X_FORWARDED_FOR']) && is_string($_SERVER['HTTP_X_FORWARDED_FOR'])) {
            $parts = explode(',', $_SERVER['HTTP_X_FORWARDED_FOR']);
            if (isset($parts[0]) && is_string($parts[0])) {
                $candidates[] = trim($parts[0]);
            }
        }

        if (isset($_SERVER['REMOTE_ADDR']) && is_string($_SERVER['REMOTE_ADDR'])) {
            $candidates[] = $_SERVER['REMOTE_ADDR'];
        }

        foreach ($candidates as $ip) {
            if ($ip !== '' && filter_var($ip, FILTER_VALIDATE_IP)) {
                return $ip;
            }
        }

        return '';
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

            $content_ids[] = 'product_' . $tracked_id;
            $contents[]    = [
                'id'       => 'product_' . $tracked_id,
                'quantity' => $qty,
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
     * content_ids, content_type, contents, currency, num_items, value
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

            $content_ids[] = 'product_' . $tracked_id;
            $contents[]    = [
                'id'       => 'product_' . $tracked_id,
                'quantity' => $qty,
            ];

            $num_items += $qty;
        }

        $value = (float)$order->get_total();

        return [
            'content_ids'  => array_values(array_unique($content_ids)),
            'content_type' => 'product',
            'contents'     => $contents,
            'currency'     => $currency,
            'num_items'    => (int)$num_items,
            'value'        => $value,
        ];
    }

    private function get_utm_params_from_cookie(): array
    {
        if ( ! isset($_COOKIE['_pf_utm']) || ! is_string($_COOKIE['_pf_utm'])) {
            return [];
        }

        $raw = wp_unslash($_COOKIE['_pf_utm']);

        if ($raw === '') {
            return [];
        }

        parse_str($raw, $parsed);

        if ( ! is_array($parsed) || empty($parsed)) {
            return [];
        }

        $allowed = [
            'utm_source',
            'utm_medium',
            'utm_campaign',
            'utm_term',
            'utm_content',
            'utm_id',
        ];

        $out = [];

        foreach ($allowed as $key) {
            if (isset($parsed[$key]) && is_scalar($parsed[$key])) {
                $out[$key] = (string)$parsed[$key];
            }
        }

        return $out;
    }


    private function post_event(array $payload): void
    {
        $url = $this->api_url . '/event';

        $args = [
            'method'      => 'POST',
            'timeout'     => $this->timeout,
            'blocking'    => $this->blocking,
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

        wp_remote_post($url, $args);

        remove_filter('http_request_args', [$this, 'tune_connect_timeout_for_pixelflow'], 10);
    }

    public function tune_connect_timeout_for_pixelflow(array $args, string $url): array
    {
        if ($url === $this->api_url . '/event') {
            $args['connect_timeout'] = 1;
        }

        return $args;
    }

    private function append_cookie_params(
        array &$payload,
        array $map = [
            'clkId'    => 'pf_clkid',
            'fbc'      => 'pf_fbc',
            'fbp'      => '_fbp',
            'fbpValue' => '_fbp',
        ]
    ): void {
        if ( ! isset($payload['eventData']) || ! is_array($payload['eventData'])) {
            return;
        }

        foreach ($map as $param => $cookieName) {
            if ( ! isset($_COOKIE[$cookieName])) {
                continue;
            }

            $val = $_COOKIE[$cookieName];

            if ( ! is_string($val) || $val === '') {
                continue;
            }

            $payload['eventData'][$param] = wp_unslash($val);
        }
    }
}

