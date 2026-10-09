<?php
/**
 * "Only the customer's first purchase": the previous-order check behind Purchase.
 *
 * @package PixelFlow
 */

// Prevent direct access
if ( ! defined('ABSPATH')) {
    exit;
}

/**
 * Decides, once per order, whether a Purchase is the customer's first.
 *
 * @mixin PixelFlow_WooCommerce_Cart_Hooks
 */
trait PixelFlow_First_Purchase_Trait
{
    /** Order meta holding the decision: `first`, or `skipped:<id of the order that counted>`. */
    private static string $first_purchase_meta = '_pf_purchase_first_only';

    /** At most this many orders are put to the amount filter, per direction. */
    private static int $first_purchase_filter_scan = 20;

    /** Statuses of an order that counts as a previous purchase. */
    private static array $first_purchase_statuses = ['processing', 'completed', 'refunded'];

    /**
     * Whether this order's Purchase is withheld because the customer bought before.
     *
     * A recorded decision is followed as it stands, even with the setting off. Without one,
     * the setting decides whether the check runs; its outcome is recorded either way so a
     * later status change, the thank-you page or a consent grant follows the same decision.
     *
     * @param WC_Order $order Order whose Purchase is about to be sent
     * @return bool True when the Purchase must not be sent
     */
    private function first_purchase_withholds(WC_Order $order): bool
    {
        $recorded = (string) $order->get_meta(self::$first_purchase_meta, true);
        if ($recorded === 'first') {
            return false;
        }
        if (strpos($recorded, 'skipped:') === 0) {
            return true;
        }

        $settings = pixelflow_first_purchase_settings(is_array($this->options) ? $this->options : []);
        if ( ! $settings['enabled']) {
            return false;
        }

        $matched_id = $this->find_previous_purchase($order, $settings);
        $order->update_meta_data(self::$first_purchase_meta, $matched_id === null ? 'first' : 'skipped:' . $matched_id);
        $order->save();

        if ($matched_id === null) {
            return false;
        }

        $this->debug_log('FIRST_PURCHASE_SKIP', [
            'order_id'         => $order->get_id(),
            'matched_order_id' => $matched_id,
        ]);

        return true;
    }

    /**
     * Id of another order of the same customer that counts as a previous purchase.
     *
     * @param WC_Order $order    Order being sent
     * @param array    $settings From pixelflow_first_purchase_settings()
     * @return int|null Null when the order is the customer's first
     */
    private function find_previous_purchase(WC_Order $order, array $settings): ?int
    {
        $customer = $this->first_purchase_customer($order);
        if ($customer === []) {
            $this->debug_log('FIRST_PURCHASE_NO_CUSTOMER', ['order_id' => $order->get_id()]);

            return null;
        }

        // Other orders were withheld because of this one: it is the purchase they deferred to.
        if ($this->first_purchase_is_anchor($order)) {
            return null;
        }

        $args = [
            'customer' => $customer,
            'status'   => self::$first_purchase_statuses,
            'type'     => 'shop_order',
            'orderby'  => 'date',
            'order'    => 'DESC',
        ];
        $cutoff = $this->first_purchase_cutoff($settings);
        if ($cutoff !== null) {
            $args['date_created'] = '>=' . $cutoff;
        }

        // The order being sent is left out after the query rather than by an `exclude` argument
        // (a NOT IN clause), so every query asks for one more row than it needs.
        $current = $order->get_id();

        if ( ! $settings['ignore_free']) {
            $found = $this->first_purchase_without($current, wc_get_orders($args + ['limit' => 2]), 1);

            return $found ? (int) $found[0]->get_id() : null;
        }

        $filtered = has_filter('pixelflow_order_amount_paid');
        $limit    = $filtered ? self::$first_purchase_filter_scan : 1;

        foreach ($this->first_purchase_paid_candidates($args, $limit, $current) as $candidate) {
            if (pixelflow_order_amount_paid($candidate) > 0) {
                return (int) $candidate->get_id();
            }
        }

        if ( ! $filtered) {
            return null;
        }

        // Orders the default amount calls free, in case the site's filter knows better.
        foreach ($this->first_purchase_free_candidates($args, $current) as $candidate) {
            if (pixelflow_order_amount_paid($candidate) > 0) {
                return (int) $candidate->get_id();
            }
        }

        return null;
    }

    /**
     * The customer values WooCommerce's `customer` query arg matches: user id, valid email.
     *
     * @param WC_Order $order Order being sent
     * @return array Empty when the order identifies no one
     */
    private function first_purchase_customer(WC_Order $order): array
    {
        $customer = [];

        $user_id = (int) $order->get_customer_id();
        if ($user_id > 0) {
            $customer[] = $user_id;
        }

        // An invalid email makes the legacy store reject the whole customer clause,
        // user id included, so it is left out rather than passed through.
        $email = strtolower(trim((string) $order->get_billing_email()));
        if ($email !== '' && is_email($email)) {
            $customer[] = $email;
        }

        return $customer;
    }

    /**
     * Whether some order was already withheld because of this one.
     *
     * @param WC_Order $order Order being sent
     * @return bool
     */
    private function first_purchase_is_anchor(WC_Order $order): bool
    {
        $found = $this->first_purchase_orders(
            ['limit' => 1, 'return' => 'ids', 'type' => 'shop_order'],
            [['key' => self::$first_purchase_meta, 'value' => 'skipped:' . $order->get_id()]]
        );

        return ! empty($found);
    }

    /**
     * Earliest creation timestamp inside the lookback, or null when every order counts.
     *
     * A window reaching back before 1970, or one too long for an integer, is the same as
     * "all", and is left out rather than handed to WooCommerce's date parser as a negative
     * or float string.
     *
     * @param array $settings From pixelflow_first_purchase_settings()
     * @return int|null
     */
    private function first_purchase_cutoff(array $settings): ?int
    {
        if ($settings['lookback'] !== 'days') {
            return null;
        }

        $days = (int) $settings['days'];
        if ($days > intdiv(PHP_INT_MAX, DAY_IN_SECONDS)) {
            return null;
        }

        $cutoff = time() - $days * DAY_IN_SECONDS;

        return $cutoff > 0 ? $cutoff : null;
    }

    /**
     * Orders paid by their total or by `_real_total`, newest first.
     *
     * @param array $args    Customer, status, type and window arguments
     * @param int   $limit   How many to return
     * @param int   $current Id of the order being sent, left out of the result
     * @return WC_Order[]
     */
    private function first_purchase_paid_candidates(array $args, int $limit, int $current): array
    {
        $args['limit'] = $limit + 1;

        if ( ! $this->first_purchase_uses_hpos()) {
            // Nested, not top-level: WooCommerce appends the customer clause to the top level
            // of meta_query, and a top-level OR would match any customer's paid order.
            return $this->first_purchase_without($current, $this->first_purchase_orders($args, [
                [
                    'relation' => 'OR',
                    ['key' => '_order_total', 'value' => 0, 'compare' => '>', 'type' => 'DECIMAL(19,4)'],
                    ['key' => '_real_total', 'value' => 0, 'compare' => '>', 'type' => 'DECIMAL(19,4)'],
                ],
            ]), $limit);
        }

        // In HPOS the total is a column, which field_query cannot OR with a meta clause.
        $by_total               = $args;
        $by_total['field_query'] = [
            ['field' => 'total', 'value' => 0, 'compare' => '>', 'type' => 'DECIMAL(19,4)'],
        ];

        $by_real_total = $this->first_purchase_orders($args, [
            ['key' => '_real_total', 'value' => 0, 'compare' => '>', 'type' => 'DECIMAL(19,4)'],
        ]);

        $orders = [];
        foreach (array_merge(wc_get_orders($by_total), $by_real_total) as $candidate) {
            if ($candidate->get_id() !== $current) {
                $orders[$candidate->get_id()] = $candidate;
            }
        }
        uasort($orders, static function ($a, $b): int {
            return $b->get_date_created() <=> $a->get_date_created() ?: $b->get_id() <=> $a->get_id();
        });

        return array_slice(array_values($orders), 0, $limit);
    }

    /**
     * Orders the default amount calls free: total 0, `_real_total` missing or not above 0.
     *
     * @param array $args    Customer, status, type and window arguments
     * @param int   $current Id of the order being sent, left out of the result
     * @return WC_Order[]
     */
    private function first_purchase_free_candidates(array $args, int $current): array
    {
        $args['limit'] = self::$first_purchase_filter_scan + 1;

        $no_real_total = [
            'relation' => 'OR',
            ['key' => '_real_total', 'compare' => 'NOT EXISTS'],
            ['key' => '_real_total', 'value' => 0, 'compare' => '<=', 'type' => 'DECIMAL(19,4)'],
        ];

        if ($this->first_purchase_uses_hpos()) {
            $args['field_query'] = [
                ['field' => 'total', 'value' => 0, 'compare' => '=', 'type' => 'DECIMAL(19,4)'],
            ];

            $found = $this->first_purchase_orders($args, [$no_real_total]);
        } else {
            $found = $this->first_purchase_orders($args, [
                [
                    'relation' => 'AND',
                    ['key' => '_order_total', 'value' => 0, 'compare' => '=', 'type' => 'DECIMAL(19,4)'],
                    $no_real_total,
                ],
            ]);
        }

        return $this->first_purchase_without($current, $found, self::$first_purchase_filter_scan);
    }

    /**
     * Orders other than the one being sent, at most `$limit` of them, in the order given.
     *
     * @param int        $current Id of the order being sent
     * @param WC_Order[] $orders  Query result, one row longer than needed
     * @param int        $limit   How many to keep
     * @return WC_Order[]
     */
    private function first_purchase_without(int $current, array $orders, int $limit): array
    {
        $others = array_filter($orders, static function ($candidate) use ($current): bool {
            return $candidate->get_id() !== $current;
        });

        return array_slice(array_values($others), 0, $limit);
    }

    /**
     * wc_get_orders() with meta clauses, in either order storage.
     *
     * HPOS reads `meta_query` from the arguments. Legacy storage drops it
     * (WC_Data_Store_WP::get_wp_query_args() skips the key), so there the clauses travel in a
     * query var of our own and are added by WooCommerce's documented filter for custom query
     * vars, hooked for this one call. Either way they are ANDed with the customer clause.
     *
     * @param array $args         wc_get_orders() arguments
     * @param array $meta_clauses meta_query clauses
     * @return array Orders or ids, as `return` asks
     */
    private function first_purchase_orders(array $args, array $meta_clauses): array
    {
        if ($this->first_purchase_uses_hpos()) {
            $args['meta_query'] = $meta_clauses; // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- the amounts and the decision live in order meta

            return wc_get_orders($args);
        }

        $args['pixelflow_meta_query'] = $meta_clauses;
        $add_clauses = static function ($wp_query_args, $query_vars) {
            foreach ($query_vars['pixelflow_meta_query'] ?? [] as $clause) {
                $wp_query_args['meta_query'][] = $clause; // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- legacy storage keeps the amounts in post meta
            }

            return $wp_query_args;
        };

        add_filter('woocommerce_order_data_store_cpt_get_orders_query', $add_clauses, 10, 2);
        try {
            return wc_get_orders($args);
        } finally {
            remove_filter('woocommerce_order_data_store_cpt_get_orders_query', $add_clauses, 10);
        }
    }

    /**
     * Whether orders live in WooCommerce's own tables (HPOS) rather than in posts.
     *
     * @return bool
     */
    private function first_purchase_uses_hpos(): bool
    {
        return class_exists(\Automattic\WooCommerce\Utilities\OrderUtil::class)
            && \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled();
    }
}
