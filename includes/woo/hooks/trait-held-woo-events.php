<?php
/**
 * Hold-queue flush and payload rebuild for storefront Woo events.
 *
 * @package PixelFlow
 */

// Prevent direct access
if ( ! defined('ABSPATH')) {
    exit;
}

/**
 * Extracted from the cart hooks class so hold flush/rebuild stays out of post_event.
 *
 * @mixin PixelFlow_WooCommerce_Cart_Hooks
 */
trait PixelFlow_Held_Woo_Events_Trait
{
    /**
     * wc-ajax entry so a same-page grant or deny can flush the session queue.
     *
     * @return void
     */
    public function ajax_resolve_held_events(): void
    {
        check_ajax_referer('pixelflow_held_events', 'nonce');
        $session = pixelflow_woo_session();
        if ($session !== null && method_exists($session, 'init_session_cookie')) {
            $session->init_session_cookie();
        }
        $this->resolve_held_events();
        wp_send_json_success();
    }

    /**
     * Read-only state route: whether a queue exists, and a fresh nonce to flush it.
     *
     * It takes no nonce of its own — it is session-scoped and reveals nothing a
     * visitor cannot already infer — which is what lets it answer from a page served
     * by a full-page cache, whose baked-in nonce may long since have expired.
     *
     * @return void
     */
    public function ajax_held_state(): void
    {
        $session = pixelflow_woo_session();
        if ($session !== null && method_exists($session, 'init_session_cookie')) {
            $session->init_session_cookie();
        }

        wp_send_json_success([
            'hasQueue' => pixelflow_get_held_woo_events() !== [],
            'nonce'    => wp_create_nonce('pixelflow_held_events'),
        ]);
    }

    /**
     * Page-view entry point for the flush.
     *
     * `wc-ajax` is dispatched on `template_redirect`, which runs after `wp`, so an
     * unguarded flush here would fire on the plugin's own AJAX routes before their
     * handlers get a say. On the read-only state route that turned "does a queue
     * exist?" into a flush, and the script then flushed what it had just been told
     * about — every held event dispatched twice. On the flush route it ran before
     * `check_ajax_referer()`, which made the nonce check decorative. Both routes own
     * their own behaviour, so this one steps aside for them.
     *
     * @return void
     */
    public function resolve_held_events_on_page_view(): void
    {
        $wc_ajax = isset($_GET['wc-ajax']) && is_string($_GET['wc-ajax'])
            ? sanitize_key(wp_unslash($_GET['wc-ajax'])) // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- routing only, no state is changed on this branch
            : '';

        if ($wc_ajax === 'pixelflow_held_state' || $wc_ajax === 'pixelflow_resolve_held_events') {
            return;
        }

        $this->resolve_held_events();
    }

    /**
     * Sends, denies, or abandons queued storefront events once the hold cookie is gone.
     *
     * @return void
     */
    public function resolve_held_events(): void
    {
        $queue = pixelflow_get_held_woo_events();
        if ($queue === [] || $this->flushing_held) {
            return;
        }

        $disposition = pixelflow_held_events_disposition();
        if ($disposition === 'keep') {
            return;
        }
        if ($disposition === 'send') {
            $this->flush_held_events($queue);
            return;
        }

        $this->beacon_held_events($queue, $disposition === 'deny' ? 'denied' : 'no_decision');
        pixelflow_clear_held_woo_events();
    }

    /**
     * Rebuilds queued recipes and POSTs them as /event with grant-time cookies.
     *
     * @param array<int, array<string, mixed>> $queue Held recipes
     * @return void
     */
    private function flush_held_events(array $queue): void
    {
        $this->flushing_held = true;
        pixelflow_clear_held_woo_events();
        foreach ($queue as $recipe) {
            $payload = $this->rebuild_held_event_payload($recipe);
            if ($payload === null) {
                continue;
            }
            $this->post_event($payload);
        }
        $this->flushing_held = false;
    }

    /**
     * Reports queued event types as denied or no_decision after the visit ends without a grant.
     *
     * @param array<int, array<string, mixed>> $queue  Held recipes
     * @param string                           $reason denied|no_decision
     * @return void
     */
    private function beacon_held_events(array $queue, string $reason): void
    {
        $row = pixelflow_blocked_event_row_with_source($reason, null);
        if ($reason === 'denied') {
            $denied = pixelflow_resolve_blocked_event_reason();
            if ($denied !== null && ($denied['reason'] ?? '') === 'denied') {
                $row = $denied;
            }
        }
        foreach (pixelflow_held_event_names($queue) as $event_name) {
            $this->post_blocked_event($event_name, $row);
        }
    }

    /**
     * Rebuilds a full /event payload from a compact recipe at flush time.
     *
     * @param array $recipe Held recipe
     * @return array|null
     */
    private function rebuild_held_event_payload(array $recipe): ?array
    {
        $event_name = isset($recipe['eventName']) ? (string) $recipe['eventName'] : '';
        $event_id   = isset($recipe['event_id']) ? trim((string) $recipe['event_id']) : '';
        if ($event_id === '' || ! in_array($event_name, PIXELFLOW_HELD_WOO_EVENT_NAMES, true)) {
            return null;
        }

        $additional = pixelflow_held_recipe_additional_data($recipe);

        $payload = [
            'siteId'    => (string) $this->site_external_id,
            'eventData' => [
                'event_id'       => $event_id,
                'eventName'      => $event_name,
                'eventTime'      => isset($recipe['eventTime']) ? (int) $recipe['eventTime'] : time(),
                'actionSource'   => 'website',
                'siteURL'        => pixelflow_get_site_url(),
                'additionalData' => $additional,
            ],
        ];
        pixelflow_append_cookie_params($payload);
        pixelflow_append_attribution_from_cookie($payload);
        $this->apply_held_customer_data($payload, $recipe);

        return $payload;
    }

    /**
     * Restores hashed customer match keys stored on the recipe.
     *
     * @param array $payload Event payload
     * @param array $recipe  Held recipe
     * @return void
     */
    private function apply_held_customer_data(array &$payload, array $recipe): void
    {
        $customer = isset($recipe['customerData']) && is_array($recipe['customerData'])
            ? pixelflow_sanitize_held_customer_data($recipe['customerData'])
            : [];
        if ($customer === []) {
            return;
        }
        $payload['eventData']['customerData'] = $customer;
    }
}
