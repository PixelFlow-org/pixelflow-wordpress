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

require_once __DIR__ . '/trait-held-woo-events.php';

/**
 * WooCommerce Cart Hooks class
 */
class PixelFlow_WooCommerce_Cart_Hooks
{
    use PixelFlow_Held_Woo_Events_Trait;

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

    /** Floor for how long a delivery claim is honoured before it counts as abandoned. */
    private const CLAIM_TTL_FLOOR = 300;

    /** How long a blocked purchase may still be resolved by a grant before it is reported. */
    private const BLOCKED_REPORT_DELAY = 1800;

    /** Cron hook that sends a blocked purchase's row once its window has closed. */
    private const BLOCKED_REPORT_HOOK = 'pixelflow_report_blocked_purchase';

    /** How many of the buyer's most recent orders a consent decision is carried onto. */
    private const CONSENT_SYNC_ORDER_SCAN = 5;
    private int $timeout;
    private bool $flushing_held = false;
    private bool $consent_synced_this_request = false;

    /** Reason the last post_event() refused the send, or null when it did not refuse. */
    private ?string $last_block_reason = null;

    /** @var self|null */
    private static $instance = null;

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
        self::$instance         = $this;

        $this->init_hooks();
    }

    /**
     * Live hooks instance for AJAX flush after a same-page grant or deny.
     *
     * @return self|null
     */
    public static function instance(): ?self
    {
        return self::$instance;
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
        // Registered before the guards below: the scheduler runs in neither a
        // front-end nor an admin context, and a report that never fires would
        // silently undercount the backend.
        add_action(self::BLOCKED_REPORT_HOOK, [$this, 'report_blocked_purchase'], 10, 1);

        // Registered before the guards too: an order reaches a purchasing status in
        // whatever request moved it — a staff change in wp-admin, automation over
        // WP-CLI — and the Purchase has no other path to fall back on. The payload is
        // built from the order, so the kind of request only decides whether the
        // requester's own identity may be attributed to the buyer (it may not).
        add_action('woocommerce_order_status_processing', [$this, 'pf_purchase_hook'], 10, 1);
        add_action('woocommerce_order_status_completed', [$this, 'pf_purchase_hook'], 10, 1);

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

        // Keep thankyou as a fallback for gateways that go straight to "on-hold" or "pending"
        add_action('woocommerce_thankyou', [$this, 'pf_purchase_hook'], 10, 1);

        // Every storefront request the buyer makes, `wc-ajax` routes included: those
        // are dispatched on `template_redirect`, which runs after `wp`, and the one the
        // storefront script fires on a consent change is the only request a buyer who
        // changes their mind without reloading the page ever makes.
        add_action('wp', [$this, 'record_consent_decision_on_open_orders'], 25);
        add_action('wp', [$this, 'resolve_held_events_on_page_view'], 30);
        add_action('wc_ajax_pixelflow_resolve_held_events', [$this, 'ajax_resolve_held_events']);
        add_action('wc_ajax_pixelflow_held_state', [$this, 'ajax_held_state']);
    }

    /**
     * Classic add-to-cart handler
     *
     * @param string $cart_item_key
     * @param int $product_id
     * @param int $quantity
     * @param int|null $variation_id
     * @param array|null $variation
     * @param array|null $cart_item_data
     */
    public function pf_add_to_cart_hook(
        string $cart_item_key,
        int $product_id,
        int $quantity,
        ?int $variation_id = 0,
        ?array $variation = [],
        ?array $cart_item_data = []
    ): void {
        if (pixelflow_is_blocked_ajax_action()) {
            return;
        }

        // Some third-party integrations (e.g. CheckoutWC Order Bumps) call
        // woocommerce_add_to_cart with null instead of an int here.
        $variation_id = (int)$variation_id;

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

        $excluded_skus = $this->get_excluded_skus();
        if ( ! empty($excluded_skus) && in_array($product->get_sku(), $excluded_skus, true)) {
            return;
        }

        $should_send = apply_filters('pixelflow_should_send_add_to_cart', true, $product, $quantity, $cart_item_key);
        if ($should_send === false) {
            return;
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
        pixelflow_append_attribution_from_cookie($payload);

        $customer = $this->build_customer_data_from_current_user();
        if ( ! empty($customer)) {
            $payload['eventData']['customerData'] = $customer;
        }

        $context = [
            'product_id'   => (int) $product_id,
            'variation_id' => (int) $variation_id,
        ];

        // WooCommerce adds to the cart on any request carrying an add-to-cart parameter, so
        // crawlers and prefetchers that follow such a link produce cart activity with no shopper
        // behind it. A real shopper with browsing history keeps at least one of these cookies; an
        // anonymous crawler has neither. The rule is scoped by the request itself, so AJAX, Store
        // API and POST-form adds fall outside it by construction — none of them puts add-to-cart
        // in the query string.
        //
        // The skip goes through post_event() rather than returning early, so the precedence in
        // pixelflow_resolve_bot_detail() still applies: a crawler that also matches a signature is
        // reported under that signature, and the logging and beaconing stay in one place.
        if ($this->is_cookieless_add_to_cart_request()) {
            $context['bot_rule'] = 'no_cookies_in_wp_plugin';
        }

        $outcome = $this->post_event($payload, $context);

        // The two hooks share the dedupe key, so both release it: whichever one a request that
        // settled nothing lands on must leave the window to the shopper's own click.
        if ( ! $this->send_settled_the_event($outcome)) {
            $this->release_dedupe($dedupe_key);
        }
    }

    /**
     * Reports whether this request carries `add-to-cart` in its query string and was made by
     * something that is not a browser: no visitor cookie, no Facebook browser cookie, and no
     * sign in the headers that a browser navigated here.
     *
     * The method is deliberately not tested. WC_Form_Handler::add_to_cart_action() runs on
     * `wp_loaded` and reads $_REQUEST, so a HEAD or a POST to any URL carrying the parameter in
     * its query string adds to the cart just as a GET does. Reading $_GET — which PHP fills from
     * the query string whatever the method — covers all three, while a genuine add-to-cart form
     * stays outside the rule by construction: it posts the parameter in the request body, which
     * never reaches $_GET.
     *
     * Both cookies require the absence test, and both are written by JavaScript — which a
     * mainstream ad blocker prevents. An ad-blocked shopper therefore carries neither, and
     * server-side events are the only signal that survives for them: exactly what this plugin
     * exists to recover. Cookie absence alone cannot tell that shopper from a crawler, so the
     * headers have to agree before the event is withheld.
     *
     * @return bool
     */
    private function is_cookieless_add_to_cart_request(): bool
    {
        if ( ! isset($_GET['add-to-cart'])) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only request shape check, no state change
            return false;
        }

        if ( ! empty($_COOKIE['_pf_uid']) || ! empty($_COOKIE['_fbp'])) {
            return false;
        }

        return ! $this->request_looks_like_a_browser_navigation();
    }

    /**
     * Reports whether the request headers carry positive evidence that a browser navigated here.
     *
     * Either signal is enough, deliberately. `Sec-Fetch-Mode` is the stronger of the two but is
     * not universal — older browsers never send it — and `Accept-Language` is sent by every
     * mainstream browser and by none of the HTTP client libraries by default. Requiring both
     * would put old browsers back in the same bucket as crawlers.
     *
     * This is positive evidence rather than an inference from absence, which is why it is allowed
     * to overrule the cookie test rather than merely add to it.
     *
     * @return bool
     */
    private function request_looks_like_a_browser_navigation(): bool
    {
        if (isset($_SERVER['HTTP_SEC_FETCH_MODE']) && is_string($_SERVER['HTTP_SEC_FETCH_MODE'])) {
            $mode = strtolower(sanitize_text_field(wp_unslash($_SERVER['HTTP_SEC_FETCH_MODE'])));
            if ($mode === 'navigate') {
                return true;
            }
        }

        if (isset($_SERVER['HTTP_ACCEPT_LANGUAGE']) && is_string($_SERVER['HTTP_ACCEPT_LANGUAGE'])) {
            if (trim(sanitize_text_field(wp_unslash($_SERVER['HTTP_ACCEPT_LANGUAGE']))) !== '') {
                return true;
            }
        }

        return false;
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

        $excluded_skus = $this->get_excluded_skus();
        if ( ! empty($excluded_skus) && in_array($product->get_sku(), $excluded_skus, true)) {
            return;
        }

        $should_send = apply_filters('pixelflow_should_send_add_to_cart', true, $product, $added, $cart_item_key);
        if ($should_send === false) {
            return;
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
        pixelflow_append_attribution_from_cookie($payload);

        $customer = $this->build_customer_data_from_current_user();
        if ( ! empty($customer)) {
            $payload['eventData']['customerData'] = $customer;
        }

        $context = [
            'product_id'   => $product_id,
            'variation_id' => $variation_id,
        ];

        // The same cookieless add-to-cart rule as pf_add_to_cart_hook(). This hook is the other
        // AddToCart producer, and for a product already in the cart WooCommerce fires it first —
        // set_quantity() runs before do_action('woocommerce_add_to_cart') — so it wins the shared
        // add_to_cart:<key> dedupe. Without this the rule would depend on cart state rather than
        // on the shape of the request.
        if ($this->is_cookieless_add_to_cart_request()) {
            $context['bot_rule'] = 'no_cookies_in_wp_plugin';
        }

        $outcome = $this->post_event($payload, $context);

        // The two hooks share the dedupe key, so both release it: whichever one a request that
        // settled nothing lands on must leave the window to the shopper's own click.
        if ( ! $this->send_settled_the_event($outcome)) {
            $this->release_dedupe($dedupe_key);
        }
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

        $data = [
            'contentName' => $name,
            'currency'    => $currency,
            'value'       => $price * $qty,
            'contentType' => 'product',
        ];

        if (($this->options['woo_product_id_format'] ?? 'product_id') !== 'off') {
            $formatted_id    = $this->format_product_id($id, $product);
            $data['contents'] = [
                [
                    'id'         => $formatted_id,
                    'quantity'   => $qty,
                    'item_price' => $price,
                ],
            ];
        }

        return $data;
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
     * Whether a cart or order line contributes to an event's contents, count and value.
     *
     * The freebie option is a parameter rather than a constant, so the three
     * per-event settings keep their separate meanings.
     *
     * @param WC_Product|null $product        Product behind the line, when resolvable
     * @param string          $freebie_option Option key deciding free products for this event
     * @param array           $excluded_skus  SKUs the store has chosen not to track
     * @return bool
     */
    private function line_is_reported($product, string $freebie_option, array $excluded_skus): bool
    {
        // Nothing to test against: keep the line rather than silently dropping it.
        if ( ! ($product instanceof WC_Product)) {
            return true;
        }

        if ($excluded_skus !== [] && in_array((string) $product->get_sku(), $excluded_skus, true)) {
            return false;
        }

        if ( ! empty($this->options[$freebie_option]) && (int) $this->options[$freebie_option] === 1
            && (float) wc_get_price_to_display($product) <= 0) {
            return false;
        }

        return true;
    }

    /**
     * Whether the cart still has a line to report once the filter has run.
     *
     * @param object $cart WooCommerce cart
     * @return bool
     */
    private function cart_has_reported_lines($cart): bool
    {
        $excluded_skus = $this->get_excluded_skus();

        foreach ($cart->get_cart() as $cart_item) {
            $product = isset($cart_item['data']) && $cart_item['data'] instanceof WC_Product
                ? $cart_item['data']
                : null;
            if ($this->line_is_reported($product, 'woo_disable_initiate_checkout_freebies', $excluded_skus)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether the order still has a line to report once the filter has run.
     *
     * @param WC_Order $order Order being reported
     * @return bool
     */
    private function order_has_reported_lines(WC_Order $order): bool
    {
        $excluded_skus = $this->get_excluded_skus();

        foreach ($order->get_items('line_item') as $item) {
            if ( ! ($item instanceof WC_Order_Item_Product)) {
                continue;
            }
            if ($this->line_is_reported($item->get_product(), 'woo_disable_purchase_freebies', $excluded_skus)) {
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

        if ( ! WC()->session) {
            return;
        }

        // Excluded and free lines are filtered out of the payload, so the event is
        // skipped only when the filter leaves nothing to report.
        if ( ! $this->cart_has_reported_lines($cart)) {
            return;
        }

        $should_send = apply_filters('pixelflow_should_send_initiate_checkout', true, $cart);
        if ($should_send === false) {
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
        if ( ! $this->should_send_event('initiate_checkout', 10)) {
            return;
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
        pixelflow_append_attribution_from_cookie($payload);

        $customer = $this->build_customer_data_from_current_user();
        if ( ! empty($customer)) {
            $payload['eventData']['customerData'] = $customer;
        }

        $outcome = $this->post_event($payload);

        // The guard is closed only once the outcome is known, and only when it settled the event
        // for this shopper. It is keyed on the cart fingerprint and nothing reopens it, so a
        // prefetch, a missing credential or a failed request that closed it would silence this
        // cart's InitiateCheckout for good. Within one request the in-request guard above already
        // keeps this to a single event.
        if ( ! $this->send_settled_the_event($outcome)) {
            $this->release_dedupe('initiate_checkout');

            return;
        }

        if ($guard_key !== '') {
            WC()->session->set($guard_key, 1);
            // Remember the active guard key so pf_reset_checkout_guard() can clear it
            // even after the cart is emptied (where get_cart_hash() would differ).
            WC()->session->set('pf_last_checkout_guard_key', $guard_key);
        }
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

        $cookie_keys = ['_fbp', '_fbc', 'pf_loc', '_pf_utm', '_pf_attribution', '_pf_consent', '_pf_no_consent_decision', '_pf_consent_source', '_pf_uid'];

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

        // Written once and never rewritten, so a later visitor cannot claim the
        // order by arriving with a different session.
        $session = pixelflow_woo_session();
        if ($session !== null && method_exists($session, 'get_customer_id')
            && (string) $order->get_meta('_pf_session_customer_id', true) === '') {
            $session_customer_id = (string) $session->get_customer_id();
            if ($session_customer_id !== '') {
                $order->update_meta_data('_pf_session_customer_id', $session_customer_id);
            }
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

        // Excluded and free lines are filtered out of the payload, so the event is
        // skipped only when the filter leaves nothing to report.
        if ( ! $this->order_has_reported_lines($order)) {
            return;
        }

        $should_send = apply_filters('pixelflow_should_send_purchase', true, $order);
        if ($should_send === false) {
            return;
        }

        $guard_key = 'purchase_' . $order_id;
        if ( ! empty($order->get_meta('_pf_purchase_sent', true))) {
            return;
        }
        // A reported order is closed: the backend has already counted one unit
        // for it, and a second one would be the inflation this guards against.
        if ( ! empty($order->get_meta('_pf_purchase_blocked_reported', true))) {
            return;
        }
        if (isset($this->sent_in_request[$guard_key])) {
            return;
        }
        $this->sent_in_request[$guard_key] = 1;

        $owns_order = pixelflow_request_owns_order($order);
        if ($owns_order) {
            $this->sync_live_consent_onto_order($order);
        }

        if ( ! $this->claim_purchase_delivery($order_id)) {
            return;
        }

        $event_time = time();

        $additional = $this->build_purchase_additional_data_from_order($order);
        $customer   = $this->build_customer_data_from_order($order, $owns_order);
        $utm        = $this->get_utm_params_for_order($order, $owns_order);

        $payload = [
            'siteId'    => (string)$this->site_external_id,
            'eventData' => [
                'event_id'       => $this->purchase_event_id($order_id, 'Purchase'),
                'eventName'      => 'Purchase',
                'eventTime'      => $event_time,
                'actionSource'   => 'website',
                'siteURL'        => $order->get_checkout_order_received_url() ?: pixelflow_get_site_url(),
                'additionalData' => $additional,
            ],
        ];
        // An order with no billing details, reported by a request that is not the buyer's, leaves
        // nothing to identify anyone — and an empty array serialises as [], not {}. The other two
        // producers already set the key only when they have something to put in it.
        if ( ! empty($customer)) {
            $payload['eventData']['customerData'] = $customer;
        }
        if ( ! empty($utm)) {
            $payload['eventData']['utm_params'] = $utm;
        }
        $this->append_cookie_params_for_order($payload, $order, $owns_order);
        $this->append_attribution_for_order($payload, $order, $owns_order);

        $consent_raw = $order->get_meta('_pf_cookie__pf_consent', true);
        $consent_override = is_string($consent_raw) && $consent_raw !== '' ? $consent_raw : null;
        $no_decision_raw = $order->get_meta('_pf_cookie__pf_no_consent_decision', true);
        $no_decision_override = is_string($no_decision_raw) && $no_decision_raw !== '' ? $no_decision_raw : null;
        $source_raw = $order->get_meta('_pf_cookie__pf_consent_source', true);
        $source_override = is_string($source_raw) && $source_raw !== '' ? $source_raw : null;

        $outcome = $this->post_event($payload, [
            'consent'     => $consent_override,
            'no_decision' => $no_decision_override,
            'source'      => $source_override,
            'allow_live'  => $owns_order,
            'ua'          => (string) $order->get_meta('_pf_client_ua', true),
            'order'       => $order,
        ]);

        $this->release_purchase_delivery_claim($order_id);

        if ($outcome === 'sent') {
            $order->update_meta_data('_pf_purchase_sent', '1');
            // A hold that a grant has now resolved: the scheduled row would
            // double-count an order that is being delivered for real.
            $this->cancel_blocked_purchase_report($order);
            $order->save();
            $this->pf_reset_checkout_guard();
        }
    }

    /**
     * Deterministic event id, so a duplicate that outran the claim is recognisable
     * in the backend's data and collapses on its own if the API ever deduplicates.
     *
     * @param int    $order_id   Order the event describes
     * @param string $event_name Catalog event name, so a second event type cannot collide
     * @return string
     */
    private function purchase_event_id(int $order_id, string $event_name): string
    {
        return 'pf-order-' . $order_id . '-' . strtolower($event_name);
    }

    /**
     * How long a delivery claim is honoured before another hook may take it over.
     *
     * The floor is two orders of magnitude over a default request; the multiple of
     * the timeout keeps that margin when a site raises `pixelflow_request_timeout`.
     *
     * @return int Seconds
     */
    private function get_claim_ttl(): int
    {
        return max(self::CLAIM_TTL_FLOOR, $this->timeout * 10);
    }

    /**
     * Claims delivery of this order's purchase before the POST.
     *
     * `add_option()` is atomic because `option_name` carries a unique index, so of
     * two concurrent hooks exactly one is told it inserted the row.
     *
     * @param int $order_id Order being delivered
     * @return bool True when this request owns the send
     */
    private function claim_purchase_delivery(int $order_id): bool
    {
        $key = 'pixelflow_purchase_claim_' . $order_id;
        if (add_option($key, (string) time(), '', 'no')) {
            return true;
        }

        $taken_at = (int) get_option($key, 0);
        if ($taken_at > 0 && (time() - $taken_at) < $this->get_claim_ttl()) {
            return false;
        }

        // Abandoned by a request that died before recording an outcome. Deleting it
        // here is also the only cleanup these rows get.
        delete_option($key);

        return (bool) add_option($key, (string) time(), '', 'no');
    }

    /**
     * Releases the delivery claim once the outcome has been recorded.
     *
     * @param int $order_id Order being delivered
     * @return void
     */
    private function release_purchase_delivery_claim(int $order_id): void
    {
        delete_option('pixelflow_purchase_claim_' . $order_id);
    }

    /**
     * Records a skipped purchase and defers its blocked row, or sends one that is due.
     *
     * Nothing is beaconed at the moment of the skip: a shopper who declines at
     * checkout and accepts on the thank-you page would otherwise cost the backend
     * two units for one order.
     *
     * @param WC_Order $order   Order whose purchase was skipped
     * @param array    $blocked Reason row from pixelflow_resolve_blocked_event_reason()
     * @return string What happened, for the debug log
     */
    private function defer_blocked_purchase_report(WC_Order $order, array $blocked): string
    {
        if ( ! empty($order->get_meta('_pf_purchase_blocked_reported', true))) {
            return 'ALREADY REPORTED';
        }

        $order_id = (int) $order->get_id();
        $stored   = $order->get_meta('_pf_purchase_blocked', true);
        $marker   = is_string($stored) && $stored !== '' ? json_decode($stored, true) : null;

        if ( ! is_array($marker) || ! isset($marker['due'])) {
            $marker = $blocked;
            $marker['due'] = time() + self::BLOCKED_REPORT_DELAY;
            $order->update_meta_data('_pf_purchase_blocked', (string) wp_json_encode($marker));
            $order->save();

            if ( ! wp_next_scheduled(self::BLOCKED_REPORT_HOOK, [$order_id])) {
                wp_schedule_single_event((int) $marker['due'], self::BLOCKED_REPORT_HOOK, [$order_id]);
            }

            return 'DEFERRED UNTIL ' . gmdate('Y-m-d H:i:s', (int) $marker['due'])
                . ' UTC UNLESS THE BUYER GRANTS FIRST';
        }

        // Overdue: either the scheduler never ran, or it ran and the order was
        // still blocked. Either way this hook can close the order itself.
        if ((int) $marker['due'] <= time()) {
            $reported = $this->send_blocked_purchase_report($order, $marker);

            return ($reported ? 'REPORTED NOW' : 'STILL PENDING, COULD NOT BE REPORTED')
                . ', OVERDUE SINCE ' . gmdate('Y-m-d H:i:s', (int) $marker['due']) . ' UTC';
        }

        return 'ALREADY DEFERRED UNTIL ' . gmdate('Y-m-d H:i:s', (int) $marker['due']) . ' UTC';
    }

    /**
     * Sends the deferred blocked row for an order and closes it to further traffic.
     *
     * The order is closed only by a row that actually left the site. A send that did not happen —
     * a missing credential, a failed request — leaves the marker and the order untouched and
     * re-arms the retry, because `_pf_purchase_blocked_reported` is permanent: an order closed by
     * a report nobody received can never be reported again, not even once the site is working.
     *
     * @param WC_Order $order  Order whose purchase stayed blocked
     * @param array    $marker Stored reason row
     * @return bool True when the row was reported and the order closed
     */
    private function send_blocked_purchase_report(WC_Order $order, array $marker): bool
    {
        $row = $marker;
        unset($row['due']);
        if (($row['reason'] ?? '') === '') {
            return false;
        }

        $order_id = (int) $order->get_id();

        if ( ! $this->post_blocked_event('Purchase', $row)) {
            // Marking a row reported is what closes the order to all further reporting, so it may
            // only ever follow a row that left the site. The marker and the order stay as they
            // are, and one retry — never more than one — is re-armed to carry the report once the
            // site can send again.
            if ( ! wp_next_scheduled(self::BLOCKED_REPORT_HOOK, [$order_id])) {
                wp_schedule_single_event(time() + self::BLOCKED_REPORT_DELAY, self::BLOCKED_REPORT_HOOK, [$order_id]);
            }

            return false;
        }

        $order->update_meta_data('_pf_purchase_blocked_reported', '1');
        $order->delete_meta_data('_pf_purchase_blocked');
        $order->save();

        wp_clear_scheduled_hook(self::BLOCKED_REPORT_HOOK, [$order_id]);

        return true;
    }

    /**
     * Drops a pending blocked report because the purchase is being delivered instead.
     *
     * @param WC_Order $order Order whose purchase was just sent
     * @return void
     */
    private function cancel_blocked_purchase_report(WC_Order $order): void
    {
        if ((string) $order->get_meta('_pf_purchase_blocked', true) === '') {
            return;
        }

        $order->delete_meta_data('_pf_purchase_blocked');
        wp_clear_scheduled_hook(self::BLOCKED_REPORT_HOOK, [(int) $order->get_id()]);
    }

    /**
     * Scheduler callback: reports a purchase that stayed blocked for its whole window.
     *
     * @param int $order_id Order to report
     * @return void
     */
    public function report_blocked_purchase($order_id): void
    {
        $order_id = (int) $order_id;
        if ($order_id <= 0 || ! function_exists('wc_get_order')) {
            return;
        }

        $order = wc_get_order($order_id);
        if ( ! $order) {
            return;
        }

        if ( ! empty($order->get_meta('_pf_purchase_sent', true))
            || ! empty($order->get_meta('_pf_purchase_blocked_reported', true))) {
            return;
        }

        $stored = $order->get_meta('_pf_purchase_blocked', true);
        $marker = is_string($stored) && $stored !== '' ? json_decode($stored, true) : null;
        if ( ! is_array($marker)) {
            return;
        }

        $this->send_blocked_purchase_report($order, $marker);
    }

    /**
     * Carries the decision on this request onto the buyer's undelivered orders.
     *
     * The order-received page is the only storefront request that runs a purchase
     * hook, so a buyer who reconsiders anywhere else — the order-pay page for an
     * unpaid order, my-account, a product page — used to leave the decision in the
     * browser, and a later status change was gated on the one taken at checkout.
     *
     * @return void
     */
    public function record_consent_decision_on_open_orders(): void
    {
        if ($this->consent_synced_this_request) {
            return;
        }
        $this->consent_synced_this_request = true;

        $decision = pixelflow_live_consent_decision();
        $state    = $decision === null ? '' : (string) ($decision['state'] ?? '');
        if ($state !== 'granted' && $state !== 'denied') {
            return;
        }

        foreach ($this->orders_awaiting_this_buyers_decision() as $order) {
            $this->sync_live_consent_onto_order($order);
        }
    }

    /**
     * The buyer's orders whose purchase a decision made now can still change.
     *
     * Candidates come from the signals the request carries — the order it is paying
     * for, the customer it is logged in as, the visitor id it was issued — and each
     * one is put to the same ownership predicate the purchase hook uses, so a request
     * that merely knows an order id changes nothing.
     *
     * @return array<int, WC_Order>
     */
    private function orders_awaiting_this_buyers_decision(): array
    {
        if ( ! function_exists('wc_get_orders') || ! function_exists('wc_get_order')) {
            return [];
        }

        $ids = [];

        $session = pixelflow_woo_session();
        if ($session !== null) {
            $awaiting = (int) $session->get('order_awaiting_payment');
            if ($awaiting > 0) {
                $ids[] = $awaiting;
            }
        }

        $user_id = function_exists('get_current_user_id') ? (int) get_current_user_id() : 0;
        if ($user_id > 0) {
            $ids = array_merge($ids, (array) wc_get_orders([
                'customer_id' => $user_id,
                'limit'       => self::CONSENT_SYNC_ORDER_SCAN,
                'orderby'     => 'date',
                'order'       => 'DESC',
                'return'      => 'ids',
            ]));
        }

        // Both signals a guest order can be tied to the browser by, asked as one
        // query. The visitor id is stamped only on an order placed under a granted
        // consent, so the session id carries the case that matters most here: an
        // order placed under a decline, whose buyer is now granting.
        $meta_clauses = [];

        $uid = isset($_COOKIE['_pf_uid']) && is_string($_COOKIE['_pf_uid'])
            ? sanitize_text_field(wp_unslash($_COOKIE['_pf_uid']))
            : '';
        if ($uid !== '') {
            $meta_clauses[] = [
                'key'   => '_pf_cookie__pf_uid',
                'value' => $uid,
            ];
        }

        $session_customer_id = $session !== null && method_exists($session, 'get_customer_id')
            ? (string) $session->get_customer_id()
            : '';
        if ($session_customer_id !== '') {
            $meta_clauses[] = [
                'key'   => '_pf_session_customer_id',
                'value' => $session_customer_id,
            ];
        }

        if ($meta_clauses !== []) {
            $ids = array_merge($ids, (array) wc_get_orders([
                'limit'      => self::CONSENT_SYNC_ORDER_SCAN,
                'orderby'    => 'date',
                'order'      => 'DESC',
                'return'     => 'ids',
                'meta_query' => array_merge( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- these are the only links between a guest order and the browser
                    ['relation' => 'OR'],
                    $meta_clauses
                ),
            ]));
        }

        $orders = [];
        foreach (array_unique(array_map('intval', $ids)) as $order_id) {
            if ($order_id <= 0) {
                continue;
            }

            $order = wc_get_order($order_id);
            if ( ! $order instanceof WC_Order) {
                continue;
            }

            // An order the backend has already counted, either way, is closed: a
            // decision made afterwards has nothing left to change about it.
            if ( ! empty($order->get_meta('_pf_purchase_sent', true))
                || ! empty($order->get_meta('_pf_purchase_blocked_reported', true))) {
                continue;
            }

            if ( ! pixelflow_request_owns_order($order)) {
                continue;
            }

            $orders[] = $order;
        }

        return $orders;
    }

    /**
     * Writes the buyer's own decision onto the order so a later background hook sees it.
     *
     * Called only for a request that owns the order, and it moves in both directions:
     * a grant releases a held purchase, a withdrawal stops one that has not been sent.
     * Identity already recorded for the order is never replaced — a later request may
     * fill a missing identifier, never overwrite the attribution the buyer arrived with.
     *
     * @param WC_Order $order Order the current request belongs to
     * @return void
     */
    private function sync_live_consent_onto_order(WC_Order $order): void
    {
        $consent = pixelflow_live_consent_decision();
        $state   = $consent === null ? '' : (string) ($consent['state'] ?? '');
        if ($state !== 'granted' && $state !== 'denied') {
            return;
        }

        $changed = false;

        $snapshot = $this->consent_snapshot_to_record($consent);
        if ($snapshot !== '' && (string) $order->get_meta('_pf_cookie__pf_consent', true) !== $snapshot) {
            $order->update_meta_data('_pf_cookie__pf_consent', $snapshot);
            $changed = true;
        }

        if ((string) $order->get_meta('_pf_cookie__pf_no_consent_decision', true) !== '') {
            $order->delete_meta_data('_pf_cookie__pf_no_consent_decision');
            $changed = true;
        }

        $overwritable = ['_pf_consent_source'];
        $fill_only    = ['_pf_uid', '_pf_attribution'];

        foreach (array_merge($overwritable, $fill_only) as $key) {
            if ( ! isset($_COOKIE[$key]) || ! is_string($_COOKIE[$key]) || $_COOKIE[$key] === '') {
                continue;
            }
            $stored = (string) $order->get_meta('_pf_cookie_' . $key, true);
            if (in_array($key, $fill_only, true) && $stored !== '') {
                continue;
            }
            $value = sanitize_text_field(wp_unslash($_COOKIE[$key]));
            if ($value === $stored) {
                continue;
            }
            $order->update_meta_data('_pf_cookie_' . $key, $value);
            $changed = true;
        }

        // This runs on every storefront request the buyer makes, so an order that
        // already carries the decision is left alone rather than rewritten.
        if ($changed) {
            $order->save();
        }
    }

    /**
     * The value to persist for the decision this request resolved.
     *
     * The raw cookie is kept whenever it says the same thing, so an order records
     * exactly what the buyer's browser holds. It is re-encoded only when the two
     * disagree — the CMP has answered and the tracking script has yet to mirror it —
     * and then the CMP's own source is preserved, because that is what the blocked
     * row is reported under.
     *
     * @param array{state: string, source: string, timestamp: int} $decision Resolved decision
     * @return string Cookie-format snapshot, or '' when none can be built
     */
    private function consent_snapshot_to_record(array $decision): string
    {
        $raw    = isset($_COOKIE['_pf_consent']) && is_string($_COOKIE['_pf_consent'])
            ? wp_unslash($_COOKIE['_pf_consent']) // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- decoded and field-sanitized in pixelflow_decode_consent_cookie()
            : '';
        $cookie = $raw !== '' ? pixelflow_decode_consent_cookie($raw) : null;

        if ($cookie !== null && ($cookie['state'] ?? '') === ($decision['state'] ?? '')) {
            return $raw;
        }

        if ($cookie !== null && isset($cookie['source'])) {
            $decision['source'] = $cookie['source'];
        } else {
            $source = pixelflow_get_consent_source_from_cookie();
            if ($source !== null) {
                $decision['source'] = $source;
            }
        }

        return pixelflow_encode_consent_cookie($decision);
    }

    /**
     * Get UTM params from order meta (saved at order creation) or fall back to cookies.
     *
     * @param WC_Order $order
     * @param bool     $request_is_buyer False when the request is not the buyer's, so
     *                                   the live cookies belong to someone else
     * @return array
     */
    private function get_utm_params_for_order(WC_Order $order, bool $request_is_buyer = true): array
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

        if ( ! $request_is_buyer) {
            return [];
        }

        return pixelflow_get_utm_params_from_cookie();
    }

    /**
     * Append cookie params from order meta (saved at creation) or fall back to live cookies.
     *
     * @param array    $payload          Event payload passed by reference
     * @param WC_Order $order
     * @param bool     $request_is_buyer False when the request is not the buyer's, so the
     *                                   live cookies identify someone other than the buyer
     * @return void
     */
    private function append_cookie_params_for_order(array &$payload, WC_Order $order, bool $request_is_buyer = true): void
    {
        if ( ! isset($payload['eventData']) || ! is_array($payload['eventData'])) {
            return;
        }

        $map = [
            'fbc' => '_fbc',
            'fbp' => '_fbp',
        ];

        foreach ($map as $param => $cookie_name) {
            // Try order meta first (saved at woocommerce_new_order)
            $val = $order->get_meta('_pf_cookie_' . $cookie_name, true);
            if (empty($val) && $request_is_buyer && isset($_COOKIE[$cookie_name])) {
                $val = sanitize_text_field(wp_unslash($_COOKIE[$cookie_name]));
            }
            if ( ! empty($val) && is_string($val)) {
                $payload['eventData'][$param] = $val;
            }
        }
    }


    /**
     * Appends the attribution block to a Purchase payload.
     * Reads from order meta first (saved at woocommerce_new_order when cookies were available),
     * then falls back to the live $_COOKIE (e.g. thank-you page same request).
     * Delegates parsing and sanitization to pixelflow_get_attribution_from_cookie().
     *
     * @param array    $payload Event payload passed by reference
     * @param WC_Order $order
     * @param bool     $request_is_buyer False when the request is not the buyer's, so the
     *                                   live cookies identify someone other than the buyer
     * @return void
     */
    private function append_attribution_for_order( array &$payload, WC_Order $order, bool $request_is_buyer = true ): void {
        if ( ! isset( $payload['eventData'] ) || ! is_array( $payload['eventData'] ) ) {
            return;
        }

        $raw = $order->get_meta( '_pf_cookie__pf_attribution', true );

        if ( empty( $raw ) && $request_is_buyer && isset( $_COOKIE['_pf_attribution'] ) && is_string( $_COOKIE['_pf_attribution'] ) ) {
            $raw = wp_unslash( $_COOKIE['_pf_attribution'] ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- JSON; field-level sanitization happens inside pixelflow_get_attribution_from_cookie()
        }

        $uid = $order->get_meta( '_pf_cookie__pf_uid', true );
        if ( empty( $uid ) && $request_is_buyer && isset( $_COOKIE['_pf_uid'] ) && is_string( $_COOKIE['_pf_uid'] ) ) {
            $uid = sanitize_text_field( wp_unslash( $_COOKIE['_pf_uid'] ) );
        }

        // An absent override is not permission to read the request's cookies: passing null for
        // $raw means "no override given, read $_COOKIE" inside the helper, which is how a staff
        // member's own _pf_attribution reached an order with nothing stored of its own.
        $attribution = pixelflow_get_attribution_from_cookie(
            is_string( $raw ) && $raw !== '' ? $raw : null,
            is_string( $uid ) && $uid !== '' ? $uid : null,
            $request_is_buyer
        );
        if ( $attribution === null ) {
            return;
        }

        $payload['eventData']['attribution'] = $attribution;
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
     * Whether an outcome settles the event for this shopper, so the dedupe window and the
     * per-cart guard may close on it.
     *
     * Settled means one of three things, and nothing else: the event was delivered; it was parked
     * for a decision the shopper has not made yet, to be replayed on a grant; or it was withheld
     * by the decision the shopper did make. Only those say something about this shopper, and the
     * guard is what then stops a recipe being queued and a beacon sent on every page view.
     *
     * Everything else leaves the state open for the shopper's next request to use: an automated
     * request must not spend the window the real click needs — a `bot` row is never held or
     * replayed — and a send that did not happen for a reason unrelated to the shopper (a missing
     * credential, a transport failure, a private client IP) has settled nothing at all. The
     * per-cart guard is the case that matters most: it is keyed on the cart fingerprint, so
     * closing it on a send that never left the site silences that cart permanently.
     *
     * @param string $outcome What post_event() returned
     * @return bool
     */
    private function send_settled_the_event(string $outcome): bool
    {
        if ($outcome === 'sent' || $outcome === 'held') {
            return true;
        }

        if ($outcome !== 'blocked') {
            return false;
        }

        // Reading the recorded reason is safe exactly here: post_event() returns `blocked` from
        // hold_or_block_event(), before the nested held-event flush that could record another
        // one, so the reason still belongs to this call.
        return $this->last_block_reason !== null && $this->last_block_reason !== 'bot';
    }

    /**
     * Drops the session dedupe timestamp for a key, leaving the in-request guard committed.
     *
     * @param string $key Dedupe key passed to should_send_event()
     * @return void
     */
    private function release_dedupe(string $key): void
    {
        if ( ! function_exists('WC') || ! WC()->session) {
            return;
        }

        WC()->session->set('pf_dedupe_' . md5($key), 0);
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
        ];

        return array_filter($out, fn($v) => $v !== '');
    }

    /**
     * @param WC_Order $order
     * @param bool     $request_is_buyer False when the request is not the buyer's, so its
     *                                   IP and user agent must not stand in for the order's
     * @return array
     */
    private function build_customer_data_from_order(WC_Order $order, bool $request_is_buyer = true): array
    {
        $email   = (string)$order->get_billing_email();
        $phone   = (string)$order->get_billing_phone();
        $fn      = (string)$order->get_billing_first_name();
        $ln      = (string)$order->get_billing_last_name();
        $city    = (string)$order->get_billing_city();
        $state   = (string)$order->get_billing_state();
        $zip     = (string)$order->get_billing_postcode();
        $country = (string)$order->get_billing_country(); // ISO alpha-2 in Woo, usually

        $out = [];

        $out['ln'] = pixelflow_sha256_if_not_empty(pixelflow_normalize_name($ln));
        $out['fn'] = pixelflow_sha256_if_not_empty(pixelflow_normalize_name($fn));
        $out['em'] = pixelflow_sha256_if_not_empty(pixelflow_normalize_email($email));
        $out['ph'] = pixelflow_sha256_if_not_empty(pixelflow_normalize_phone($phone));

        $out['st']      = pixelflow_sha256_if_not_empty(pixelflow_normalize_state($state));
        $out['zp']      = pixelflow_sha256_if_not_empty(pixelflow_normalize_zip($zip));
        $out['ct']      = pixelflow_sha256_if_not_empty(pixelflow_normalize_city($city));
        $out['country'] = pixelflow_sha256_if_not_empty(pixelflow_normalize_country($country));

        // Get IP and UA: prefer values saved to order meta at creation time (real browser
        // request), fall back to the current request only when this request is the buyer's.
        // A staff member's address must never be attributed to the buyer, so an order with
        // none of its own goes out without them.
        $ip = (string)$order->get_meta('_pf_client_ip', true);
        if ($ip === '' && $request_is_buyer) {
            $ip = pixelflow_get_client_ip_address();
        }

        $ua = (string)$order->get_meta('_pf_client_ua', true);
        if ($ua === '' && $request_is_buyer) {
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
        $currency   = function_exists('get_woocommerce_currency') ? (string)get_woocommerce_currency() : 'USD';
        $decimals   = wc_get_price_decimals();
        $id_format  = $this->options['woo_product_id_format'] ?? 'product_id';

        $contents      = [];
        $num_items     = 0;
        $excluded_skus = $this->get_excluded_skus();
        $kept_total    = 0.0;
        $all_total     = 0.0;
        $omitted_any   = false;

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

            $product = isset($cart_item['data']) && $cart_item['data'] instanceof WC_Product
                ? $cart_item['data']
                : null;

            $all_total += $line_total;
            if ( ! $this->line_is_reported($product, 'woo_disable_initiate_checkout_freebies', $excluded_skus)) {
                $omitted_any = true;
                continue;
            }
            $kept_total += $line_total;

            if ($price <= 0 && $product !== null) {
                $raw   = $product->get_price(); // string or ''
                $price = $raw !== '' ? (float)wc_format_decimal($raw, $decimals) : 0.0;
            }

            $price = (float)wc_format_decimal($price, $decimals);

            if ($id_format !== 'off') {
                $contents[] = [
                    'id'         => $this->format_product_id($tracked_id, $product),
                    'quantity'   => $qty,
                    'item_price' => $price,
                ];
            }

            $num_items += $qty;
        }

        $cart_total = (float)$cart->get_cart_contents_total();

        // With nothing filtered out the cart total is reported unchanged. Otherwise
        // the same proportion the cart applies to its lines is applied to the kept
        // ones, so the omitted product keeps its own share of any cart-level discount.
        if ($omitted_any) {
            $ratio      = ($all_total > 0 && $cart_total < $all_total) ? $cart_total / $all_total : 1.0;
            $cart_total = $kept_total * $ratio;
        }

        $value = (float)wc_format_decimal($cart_total, $decimals);

        $data = [
            'contentType' => 'product',
            'currency'    => $currency,
            'num_items'   => (int)$num_items,
            'value'       => $value,
        ];

        if ($id_format !== 'off') {
            $this->apply_rounding_correction($contents, $value, $decimals);
            $data['contents'] = $contents;
        }

        return $data;
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
        $id_format = $this->options['woo_product_id_format'] ?? 'product_id';

        // ── Pass 1: collect raw line totals ──────────────────────────────────
        $rows            = [];
        $product_names   = [];
        $sum_item_totals = 0.0;
        $all_line_totals = 0.0;
        $omitted_any     = false;
        $excluded_skus   = $this->get_excluded_skus();

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

            // Line total after item-level discounts, excl. tax
            $line_total = (float)$item->get_total();

            // Fallback: subtotal exists but total is 0 (e.g. 100% item-level coupon)
            if ($line_total <= 0) {
                $line_subtotal = (float)$item->get_subtotal();
                if ($line_subtotal > 0) {
                    $line_total = $line_subtotal;
                }
            }

            $all_line_totals += $line_total;

            if ( ! $this->line_is_reported($item->get_product(), 'woo_disable_purchase_freebies', $excluded_skus)) {
                $omitted_any = true;
                continue;
            }

            $product_names[] = (string)$item->get_name();

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

        // With nothing filtered out this is what the shopper paid for products, as
        // before. Otherwise the order-level discount is spread over every line first
        // and only the kept share is reported, so the omitted product carries its own
        // share of the discount out of the payload with it.
        if ($omitted_any) {
            $order_ratio          = ($all_line_totals > 0 && $order_products_value < $all_line_totals)
                ? $order_products_value / $all_line_totals
                : 1.0;
            $order_products_value = $sum_item_totals * $order_ratio;
        }

        // Only redistribute when there is a gap (order-level discount, bundle, subscription trial).
        // When sum_item_totals == order_products_value the ratio is 1.0 — prices unchanged.
        $discount_ratio = ($sum_item_totals > 0 && $order_products_value < $sum_item_totals)
            ? $order_products_value / $sum_item_totals
            : 1.0;

        $decimals = wc_get_price_decimals();

        $data = [
            'contentName' => implode(', ', array_unique($product_names)),
            'contentType' => 'product',
            'currency'    => $currency,
            'num_items'   => (int)$num_items,
            'value'       => $order_products_value,
        ];

        if ($id_format !== 'off') {
            // ── Pass 3: build contents with adjusted prices ───────────────────
            $contents = [];

            foreach ($rows as $row) {
                $adjusted_total = $row['line_total'] * $discount_ratio;
                $price          = $row['qty'] > 0
                    ? (float)wc_format_decimal($adjusted_total / $row['qty'], $decimals)
                    : 0.0;

                $contents[] = [
                    'id'         => $this->format_product_id($row['tracked_id']),
                    'quantity'   => $row['qty'],
                    'item_price' => $price,
                ];
            }

            $this->apply_rounding_correction($contents, $order_products_value, $decimals);
            $data['contents'] = $contents;
        }

        return $data;
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

        $cookie_keys = ['_pf_utm', '_fbp', '_fbc', 'pf_loc'];
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

    /**
     * Whether the site has both credentials the API requires.
     *
     * Checked at the two outbound requests rather than at hook registration: everything the
     * hooks merely record — the attribution snapshot, the consent decision, the held queue —
     * has to keep working while a key is being rotated or pasted back in.
     *
     * @return bool
     */
    private function has_api_credentials(): bool
    {
        return trim($this->api_key) !== '' && trim($this->site_external_id) !== '';
    }

    /** Debug-log line for a send the credential gate stopped. */
    private function no_credentials_message(): string
    {
        return __('EVENT SENDING SKIPPED BECAUSE THE SITE ID OR API KEY IS MISSING', 'pixelflow');
    }

    /**
     * POSTs one anonymous blocked-events row when a Woo send is skipped for bot, hold, or deny.
     *
     * @param string $event_name Catalog event name
     * @param array  $row        Reason row from pixelflow_resolve_blocked_event_reason()
     * @return bool True when the row reached the API, so a caller may close what it recorded
     */
    private function post_blocked_event(string $event_name, array $row): bool
    {
        if ( ! $this->has_api_credentials()) {
            $this->debug_log(
                'BLOCKED_EVENTS ' . $event_name,
                ['blocked' => [$row]],
                $this->no_credentials_message()
            );

            return false;
        }

        $blocked_payload = pixelflow_build_blocked_events_payload($this->site_external_id, $event_name, $row);
        if ($blocked_payload === null) {
            // Unknown reason, or no site id: nothing reaches the API, and without a
            // line here the row would disappear with no trace at all.
            $this->debug_log(
                'BLOCKED_EVENTS ' . $event_name,
                ['blocked' => [$row]],
                'BLOCKED EVENT NOT REPORTED BECAUSE THE PAYLOAD COULD NOT BE BUILT'
            );

            return false;
        }

        add_filter('http_request_args', [$this, 'tune_connect_timeout_for_pixelflow'], 10, 2);
        $response = pixelflow_post_blocked_events($this->api_url, $this->api_key, $blocked_payload);
        remove_filter('http_request_args', [$this, 'tune_connect_timeout_for_pixelflow'], 10);

        $this->debug_log('BLOCKED_EVENTS ' . $event_name, $blocked_payload, $response);

        // The POST is non-blocking, so there is no status to read; a refused connection, an
        // unresolvable host or a TLS failure still arrives here as a WP_Error, and a null means
        // the helper itself declined to send.
        return $response !== null && ! is_wp_error($response);
    }

    /**
     * Posts a server-side event to the PixelFlow API, or a blocked-events beacon when skipped.
     *
     * @param array $payload Event payload
     * @param array{consent?: ?string, no_decision?: ?string, source?: ?string, product_id?: int, variation_id?: int, allow_live?: bool, ua?: string, bot_rule?: string, order?: WC_Order} $context
     * @return string sent|held|blocked|skipped|failed
     */
    private function post_event(array $payload, array $context = []): string
    {
        $consent_cookie_raw = isset($context['consent']) && is_string($context['consent']) ? $context['consent'] : null;
        $no_decision_raw    = isset($context['no_decision']) && is_string($context['no_decision']) ? $context['no_decision'] : null;
        $source_cookie_raw  = isset($context['source']) && is_string($context['source']) ? $context['source'] : null;
        $product_id         = isset($context['product_id']) ? (int) $context['product_id'] : 0;
        $variation_id       = isset($context['variation_id']) ? (int) $context['variation_id'] : 0;
        $allow_live         = ! isset($context['allow_live']) || (bool) $context['allow_live'];
        $order              = isset($context['order']) && $context['order'] instanceof WC_Order ? $context['order'] : null;
        $bot_rule           = isset($context['bot_rule']) && is_string($context['bot_rule']) ? $context['bot_rule'] : null;

        // Identity is resolved here rather than in the customer-data builders: this is the one
        // point every event type passes through, and the only place $context exists — which the
        // pixelflow_external_id filter is contracted to receive. Resolving before
        // hold_or_block_event() below means a held event captures the identity of the shopper who
        // actually triggered it.
        //
        // A replay does not re-resolve. flush_held_events() may run on a later request that is not
        // the shopper's, so the recipe's captured value stands; only the filter runs again.
        if ($this->flushing_held) {
            $external_id = isset($payload['eventData']['customerData']['external_id'])
                && is_string($payload['eventData']['customerData']['external_id'])
                ? $payload['eventData']['customerData']['external_id']
                : null;
        } else {
            $attribution_raw = null;
            $uid_override    = null;

            if ($order instanceof WC_Order) {
                $stored_attribution = $order->get_meta('_pf_cookie__pf_attribution', true);
                $attribution_raw    = is_string($stored_attribution) && $stored_attribution !== ''
                    ? $stored_attribution
                    : null;

                $stored_uid   = $order->get_meta('_pf_cookie__pf_uid', true);
                $uid_override = is_string($stored_uid) && $stored_uid !== '' ? $stored_uid : null;
            }

            $external_id = pixelflow_resolve_external_id(
                (string) $this->site_external_id,
                $attribution_raw,
                $uid_override,
                $allow_live
            );
        }

        /**
         * Filters the derived external_id before it is sent.
         *
         * Runs on every event, including when nothing resolved, so a site can supply an
         * identifier where the plugin found none. Returning an empty value omits the field.
         *
         * @param string|null $external_id Derived identifier, or null when none resolved
         * @param array       $context     The event context; its keys are a public contract
         *                                 and are not uniform across event types
         */
        $external_id = apply_filters('pixelflow_external_id', $external_id, $context);

        if (isset($payload['eventData']) && is_array($payload['eventData'])) {
            if (is_string($external_id) && $external_id !== '') {
                if ( ! isset($payload['eventData']['customerData'])
                    || ! is_array($payload['eventData']['customerData'])) {
                    $payload['eventData']['customerData'] = [];
                }
                $payload['eventData']['customerData']['external_id'] = $external_id;
            } elseif (isset($payload['eventData']['customerData']['external_id'])) {
                // Never serialise as null: an unresolved or suppressed identity leaves no key.
                unset($payload['eventData']['customerData']['external_id']);
            }
        }

        pixelflow_append_consent_to_payload($payload, $consent_cookie_raw, $source_cookie_raw, $allow_live);

        $event_name = isset($payload['eventData']['eventName']) ? (string) $payload['eventData']['eventName'] : 'unknown';
        // The agent of whoever the event is about: this request's when it is the buyer's,
        // otherwise the one the order saved at creation. A staff member or an automation
        // client must not decide whether the buyer looks like a bot.
        $ua         = $allow_live
            ? pixelflow_get_client_user_agent()
            : (isset($context['ua']) && is_string($context['ua']) ? $context['ua'] : '');
        // The prefetch header is read live only when the request is the buyer's, on the same gate
        // as the user agent above: on a gateway callback or a wp-admin status change the headers
        // belong to someone other than the shopper and say nothing about their event.
        $is_prefetch = $allow_live && pixelflow_request_is_speculative_prefetch();
        // The caller's rule goes in separately, as the last-ranked cause: it is an inference from
        // an absence of cookies, and a pending or declined consent decision explains that same
        // absence without any automation being involved.
        $blocked     = pixelflow_resolve_blocked_event_reason(
            $consent_cookie_raw,
            $no_decision_raw,
            pixelflow_resolve_bot_detail($ua, $is_prefetch, $event_name),
            $source_cookie_raw,
            $allow_live,
            $bot_rule
        );

        // Recorded for the producers: dedupe and guard state is committed before the send, so
        // they need to know what refused it. See last_send_was_refused_as_automated().
        $this->last_block_reason = isset($blocked['reason']) && is_string($blocked['reason'])
            ? $blocked['reason']
            : null;

        $gate = $this->hold_or_block_event($payload, $event_name, $blocked, $product_id, $variation_id, $order);
        if ($gate !== null) {
            return $gate;
        }

        if ( ! $this->flushing_held) {
            $this->resolve_held_events();
        }

        return $this->dispatch_event_post($payload, $event_name, $ua, $allow_live);
    }

    /**
     * Queues a storefront hold, or beacons, when the consent/bot gate refuses the send.
     *
     * @param array      $payload      Event payload
     * @param string     $event_name   Catalog event name
     * @param array|null $blocked      Reason row, or null to send
     * @param int        $product_id   Parent product id when queueing AddToCart
     * @param int        $variation_id Variation id when queueing AddToCart
     * @param WC_Order|null $order      Order behind a Purchase, whose blocked row is deferred
     * @return string|null held|blocked, or null to continue the send
     */
    private function hold_or_block_event(array $payload, string $event_name, ?array $blocked, int $product_id, int $variation_id, ?WC_Order $order = null): ?string
    {
        if ($blocked === null) {
            return null;
        }

        if ($order !== null && $event_name === 'Purchase') {
            $disposition = $this->defer_blocked_purchase_report($order, $blocked);
            $detail      = isset($blocked['detail']) && is_string($blocked['detail']) ? $blocked['detail'] : '';
            $this->debug_log(
                $event_name,
                $payload,
                'EVENT SENDING SKIPPED (' . ($blocked['reason'] ?? 'unknown')
                    . ($detail !== '' ? '; ' . $detail : '')
                    . '); BLOCKED ROW ' . $disposition
            );

            return 'blocked';
        }

        if (pixelflow_should_queue_held_event($blocked, $event_name)) {
            $recipe = pixelflow_held_event_recipe_from_payload($payload, $product_id, $variation_id);
            if ($recipe !== null && pixelflow_enqueue_held_woo_event($recipe)) {
                $this->debug_log($event_name, $payload, 'EVENT SENDING HELD UNTIL CONSENT IS GRANTED OR THE VISIT ENDS');

                return 'held';
            }
        }

        $this->post_blocked_event($event_name, $blocked);
        if (($blocked['reason'] ?? '') === 'bot') {
            // Three rules reach this branch — the signature list, the prefetch header and the
            // cookieless add-to-cart rule — so the line names whichever fired rather than
            // asserting a user-agent match that may not have happened.
            $detail  = isset($blocked['detail']) && is_string($blocked['detail']) ? $blocked['detail'] : '';
            $message = __('EVENT SENDING SKIPPED BECAUSE THE REQUEST WAS CLASSIFIED AS AUTOMATED', 'pixelflow');
            if ($detail !== '') {
                $message .= ': ' . $detail;
            }
            $this->debug_log($event_name . ' ' . $message, $payload, $message);

            return 'blocked';
        }

        $this->debug_log($event_name, $payload, 'EVENT SENDING SKIPPED BECAUSE CONSENT IS PENDING OR DENIED');

        return 'blocked';
    }

    /**
     * POSTs /event unless the client IP is private.
     *
     * @param array  $payload           Event payload
     * @param string $event_name        Catalog event name
     * @param string $ua                Client user agent
     * @param bool   $request_is_buyer  False when the request is not the buyer's
     * @return string sent|skipped|failed
     */
    private function dispatch_event_post(array $payload, string $event_name, string $ua, bool $request_is_buyer = true): string
    {
        if ( ! $this->has_api_credentials()) {
            $this->debug_log($event_name, $payload, $this->no_credentials_message());

            return 'skipped';
        }

        if ($request_is_buyer) {
            $resolved_ip   = pixelflow_get_client_ip_address();
            $is_private_ip = pixelflow_is_private_ip($resolved_ip);
            $this->append_request_customer_fields($payload, $ua, $resolved_ip, $is_private_ip);
        } else {
            // Nothing about this request belongs to the buyer: it contributes no fields,
            // and its address decides nothing. An admin or WP-CLI request has a private
            // address or none at all, and gating on it would drop the event for good.
            $is_private_ip = false;
        }

        $private_skip_message = __('EVENT SENDING SKIPPED BECAUSE CLIENT IP IS PRIVATE', 'pixelflow');
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

        add_filter('http_request_args', [$this, 'tune_connect_timeout_for_pixelflow'], 10, 2);
        if ( ! $is_private_ip) {
            $response = wp_remote_post($this->api_url . '/event', $args);
            // The request is non-blocking, so there is no status to read, but the
            // connection is still established synchronously: a refused connection,
            // an unresolvable host or a TLS failure arrives here as a WP_Error.
            $outcome  = is_wp_error($response) ? 'failed' : 'sent';
        } else {
            $response = $private_skip_message . ' (PRIVATE_IP)';
            $outcome  = 'skipped';
            $event_name .= ' ' . $private_skip_message;
        }
        remove_filter('http_request_args', [$this, 'tune_connect_timeout_for_pixelflow'], 10);

        $this->debug_log($event_name, $payload, $response);

        return $outcome;
    }

    /**
     * Adds location, UA, and public IP onto customerData for this request.
     *
     * @param array  $payload       Event payload
     * @param string $ua            Client user agent
     * @param string $resolved_ip   Client IP
     * @param bool   $is_private_ip Whether the IP must stay off the payload
     * @return void
     */
    private function append_request_customer_fields(array &$payload, string $ua, string $resolved_ip, bool $is_private_ip): void
    {
        if ( ! isset($payload['eventData']['customerData']) || ! is_array($payload['eventData']['customerData'])) {
            $payload['eventData']['customerData'] = [];
        }
        $cd = &$payload['eventData']['customerData'];

        $cookie_pf_loc = filter_input(INPUT_COOKIE, 'pf_loc', FILTER_UNSAFE_RAW);
        if (is_string($cookie_pf_loc) && $cookie_pf_loc !== '') {
            $decoded = json_decode(wp_unslash($cookie_pf_loc), true);
            if (is_array($decoded)) {
                foreach (['st', 'zp', 'ct', 'country'] as $loc_key) {
                    if ( ! isset($cd[$loc_key]) && ! empty($decoded[$loc_key])) {
                        $cd[$loc_key] = sanitize_text_field($decoded[$loc_key]);
                    }
                }
            }
        }

        if ( ! isset($cd['client_user_agent']) && $ua !== '') {
            $cd['client_user_agent'] = $ua;
        }
        if ( ! isset($cd['client_ip_address']) && $resolved_ip !== '' && ! $is_private_ip) {
            $cd['client_ip_address'] = $resolved_ip;
        }
    }

    public function tune_connect_timeout_for_pixelflow(array $args, string $url): array
    {
        if ($url === $this->api_url . '/event' || $url === $this->api_url . '/blocked-events') {
            $args['connect_timeout'] = 1;
        }

        return $args;
    }

    private function get_excluded_skus(): array
    {
        $raw = $this->options['woo_excluded_skus'] ?? [];
        if ( ! is_array($raw)) {
            $raw = array_filter(array_map('trim', explode(',', (string)$raw)));
        }
        return array_values($raw);
    }

    private function format_product_id(int $id, ?WC_Product $product = null): string
    {
        $format = $this->options['woo_product_id_format'] ?? 'product_id';
        switch ($format) {
            case 'legacy':
                return 'product_' . $id;
            case 'prefixed':
                return 'wc_post_id_' . $id;
            case 'sku':
                if ($product === null) {
                    $product = wc_get_product($id);
                }
                $sku = $product ? (string)$product->get_sku() : '';
                return $sku !== '' ? $sku : (string)$id;
            case 'product_id':
            default:
                return (string)$id;
        }
    }

}

