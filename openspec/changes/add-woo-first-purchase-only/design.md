## Context

See proposal.md for motivation and `specs/woo-first-purchase-only/spec.md` for the behaviour.

- `pf_purchase_hook()` (`includes/woo/hooks/class-woocommerce-hooks.php`) runs on
  `woocommerce_order_status_processing` and `_completed` in every request context (front end,
  wp-admin, WP-CLI, background jobs), and on `woocommerce_thankyou` on the storefront. One
  order therefore reaches it up to three times. Its guards, in order: Purchase disabled, no
  reportable lines, `pixelflow_should_send_purchase`, `_pf_purchase_sent`,
  `_pf_purchase_blocked_reported`, the in-request guard, then the consent sync and
  `claim_purchase_delivery()`.
- A Purchase held for the buyer's consent is not sent by the consent decision itself.
  `record_consent_decision_on_open_orders()` only writes the decision onto the order; a later
  call of `pf_purchase_hook()` sends it.
- Settings are one option array, `pixelflow_general_options`, saved through
  `sanitize_general_options()` in `pixelflow.php`, which keeps only the keys it knows and
  forces every listed checkbox to 0/1. The React panel saves on each toggle, merging its
  defaults (`useSettings.ts`) with the saved values.
- Frisbii Billing 1.3.10 (source read from wordpress.org, `includes/WC_Reepay_Renewals.php`):
  - when an order contains a subscription, it copies a non-zero total (or, when the total is
    already 0, a non-zero subtotal) into `_real_total` and sets the total to 0 (lines 194-198,
    519-526);
  - a renewal is created by `create_child_order` with `parent` set to the original order
    (1276-1278), and its status is applied through `set_status()` + `save()` (1933-1934), so
    the WooCommerce status hooks fire;
  - renewal product lines come from the invoice with no product id (~1405-1420);
  - a checkout with several subscription products is split into several orders of the same
    customer (`get_division_of_products_into_orders`, 613-700), listed on the main order in
    `_reepay_another_orders` (430).

## Goals / Non-Goals

**Goals:**
- A bounded number of queries per order, run once (when its decision is recorded) while the
  setting is on: the anchor lookup, the main lookup (one query in legacy storage, two in HPOS),
  and, only on sites that hook the amount filter, the filter walk and the promotion query.
  None while the setting is off.
- A decision per order that is taken once and does not change afterwards.
- No change to the outbound payload or to any other event.

**Non-Goals:**
- Detecting renewals per subscription plugin, or limiting the rule to the same product or the
  same subscription (both considered and rejected, see Decisions).
- Treating orders split from one Frisbii checkout specially: observed not to be needed (see
  Open Questions).
- Removing Purchase events already sent for past renewals.

## Decisions

### Rule by customer, any order (as in the PRD)
A previous paid order of the same customer suppresses Purchase, whatever it contained.
- Rejected: **per-subscription chain** (parent order for Frisbii, subscription relation for
  WooCommerce Subscriptions). It would keep real repeat purchases, but needs an adapter per
  subscription plugin, and nobody asked for it.
- Rejected: **"same product" mode**. Frisbii renewal lines carry no product id, so it cannot
  work for the store that asked for the feature.
- Accepted cost: a store that also sells one-off products loses repeat purchases in Meta while
  the setting is on. The tooltip says so.

### "Another order", not "an earlier order"
The other order may be created before or after the one being sent. Requiring "earlier" would
send a late Purchase for an old never-sent order whose status staff change after the
customer's later order was already sent. The PRD's "earlier WooCommerce orders" in its
Solution section is read as "other".

Known gap, accepted: the thank-you hook sends Purchase for an order in any status (it is the
fallback for gateways that stop at on-hold or pending), while on-hold and pending orders do not
count as previous purchases. A customer who pays one order by bank transfer and places a second
before the transfer arrives gets two Purchases, as without the setting. Neither counting
already-sent unpaid orders (contradicts the PRD's "on-hold … do not qualify") nor deferring
the decision until payment (changes when Purchase fires) was chosen.

### The conditions are in the query, not in a loop
First, one meta lookup: does any order carry `_pf_purchase_first_only = skipped:<current id>`?
If so, other orders were suppressed because of this one, and it is recorded `first` without
the lookup below. This is the "anchor" rule. Without it, an order with no recorded decision
(held for consent while the setting was off) could be suppressed by the very orders that were
suppressed because of it: X ← Y ← Z leaves X `skipped:Z`, Y `skipped:X`, Z `skipped:Y` and no
Purchase at all. Rejected: excluding only candidates recorded `skipped:<current id>` (closes
X ↔ Y but not longer chains); ignoring every `skipped:*` candidate (breaks renewals inside a
day window, where earlier suppressed renewals must still count).

Otherwise `wc_get_orders()` selects the qualifying order directly, `limit => 1`, newest first,
with:
- `customer => [user_id, email]`: WooCommerce ORs ids and emails in both order storages
  (`OrdersTableQuery::generate_customer_query()`,
  `WC_Order_Data_Store_CPT::get_orders_generate_customer_meta_query()`);
- `status => [processing, completed, refunded]`, `type => shop_order`;
- `exclude => [current id]`;
- `date_created => '>=' . (time() - N * DAY_IN_SECONDS)` when lookback is `days`;
- with ignore-free on, "paid": total > 0, or total = 0 and `_real_total` > 0. In legacy
  storage the total is the `_order_total` meta, so both arms go into one OR group, nested as
  a sub-array of the meta clauses (numeric compare). It must not be the top-level relation:
  WooCommerce appends the `customer` clause to the top level of the WP_Query `meta_query`
  (`class-wc-order-data-store-cpt.php:1010-1014`), so a top-level OR would match any
  customer's paid order. In HPOS the total is the `total_amount` column, reached
  through `field_query` (`OrdersTableQuery.php:307, 793`), which is ANDed with `meta_query`
  and cannot be ORed with it, so HPOS runs two queries: `total_amount > 0`, then
  `total_amount = 0` with `_real_total > 0`. The storage is read from
  `OrderUtil::custom_orders_table_usage_is_enabled()`.

Meta clauses reach the two storages differently. HPOS takes `meta_query` from the
wc_get_orders() arguments. Legacy storage drops it: `WC_Data_Store_WP::get_wp_query_args()`
skips that key (`class-wc-data-store-wp.php:284`), so a `meta_query` passed there is silently
ignored and the query matches without it. Observed on the test site: an anchor lookup for an
impossible `skipped:` value returned an order; the first live run on legacy storage recorded
every order `first`. In legacy storage the clauses therefore travel in a query var of the
plugin's own, `pixelflow_meta_query`, and a callback on
`woocommerce_order_data_store_cpt_get_orders_query` (WooCommerce's documented hook for custom
query vars), added for that one call and removed after it, appends them to the WP_Query
`meta_query`, where they are ANDed with the customer clause. Verified on the test site before
it was chosen. Rejected: a hand-written `$wpdb` query for legacy storage (a second query
shape outside WooCommerce's API).

The `pixelflow_order_amount_paid` filter is PHP and cannot run inside the query. It is applied
to the order the query returns; only when it returns 0 for that order (with ignore-free on)
does the lookup continue to the next candidates, 20 orders in total including the first, and
treat the customer as having no paid order if none of them pass (fail open). In HPOS the walk
takes up to 20 from each of the two query shapes, merges them newest first and examines the
first 20. Sites without the filter never loop.

When something is hooked to the filter (`has_filter('pixelflow_order_amount_paid')`),
"Ignore previous free orders" is on, and no order counted above, one more query fetches up to 20 of the customer's paid-status orders with
total 0 and no positive `_real_total` — the meta missing, or 0 or less, i.e. exactly the
orders the default amount calls free; a nested group "`_real_total` NOT EXISTS OR <= 0"
(numeric), ANDed with total = 0 (`_order_total` meta in legacy storage, `field_query` on
`total_amount` in HPOS) — (same customer, status, window and exclude clauses), newest
first, and asks the filter about each (a separate limit of 20 from the demotion walk, so the
filter runs at most 40 times for one order); the first it values above 0 counts. This lets a plugin
that keeps the amount in its own meta promote such orders, as the PRD's "can override the
amount for another plugin" intends, while the PRD's "returning 0 makes a paid order count as
free" is the demotion path above. Sites without a hooked filter never run this query.

The email is trimmed and lowercased before the query, and dropped when `is_email()` rejects
it: legacy storage turns an invalid email into a `WP_Error` for the whole customer clause
(`class-wc-order-data-store-cpt.php:1010-1012`), which would lose the user-id match too, while
HPOS only adds `1=0` for it. An order left with neither is recorded `first` and sent; it cannot
match any other order, so the record only stops it being looked up again. The comparison in
the database follows its collation, which is case-insensitive in standard WordPress installs.
- Rejected: walking up to 20 candidates in PHP for every order. Simpler, one code path, but a
  customer with more than 20 free or suppressed orders newer than the paid one would send an
  extra Purchase, which the spec does not allow.
- Rejected: walking the whole history. Exact, but unbounded per check.
- Rejected: `wc_customer_bought_product()`. It has no date window, does not exclude the
  current order (which is already `processing` when the hook runs) and ignores `refunded`.
- Rejected: WooCommerce Analytics' returning-customer flag
  (`Admin\API\Reports\Orders\Stats\DataStore::is_returning_customer()`). It is an internal
  class and depends on lookup tables filled by a background sync.
- Cost: two query shapes, one per storage, plus the promotion query. The PHP tests stub
  `wc_get_orders()` with both legacy behaviours above (meta_query dropped, customer clause at
  the top level) and cannot check the SQL itself, so the live suite runs the scenarios on both
  storages; the filter paths are driven there by hooking the filter inside the same `wp eval`
  request that moves the order to processing, so no helper plugin is deployed.

### The decision is recorded for both outcomes
`_pf_purchase_first_only` = `first` or `skipped:<matching order id>`, written the first time
the check runs for an order. Every later pass follows it, even if the setting is turned off.
- Why both outcomes: an order recorded `first` whose Purchase is held for consent is sent by
  a later pass. Re-checking it then could find a second order of the same customer that was
  itself suppressed because of it, and neither would ever send.
- Rejected: re-evaluating on every pass, as the PRD reads. It costs up to three lookups per
  order, and a decision can flip after the fact (the matching order is cancelled, the merchant
  changes the window), producing a late Purchase.
- `_pf_purchase_sent` stays untouched on a skip, as the PRD requires, because the blocked
  report and the consent paths read it as "delivered".

### Place in `pf_purchase_hook()`
The recorded-decision guard and the check go after the existing guards (so a disabled,
excluded or already-closed order is never looked up) and before the consent sync and
`claim_purchase_delivery()` (so a suppressed order never takes a delivery claim, never posts,
and never schedules a blocked report).

### Amount paid
`pixelflow_order_amount_paid` filter (default amount, order), accepted when `is_numeric()`
(so `"15"` works; ≤ 0 means free), ignored otherwise → total if > 0 → `_real_total` if > 0 → 0.
Rejected: accepting only int/float (a filter returning WooCommerce's string amounts would be
silently ignored); casting anything to float (a filter returning `null` by mistake would turn
paid orders free). `_real_total` is read without a Frisbii check: other plugins do not write that
key, and the filter covers any plugin that stores the amount elsewhere.

### Settings plumbing
- `sanitize_general_options()`: `woo_purchase_first_only` joins the checkbox list (a
  missing key means off, which is its default). `woo_purchase_first_only_ignore_free` does
  not: the list forces a missing key to 0, which would silently turn off a control whose
  default is on, so a missing key keeps the stored value, or 1 when nothing is stored. `woo_purchase_first_only_lookback`
  is limited to `all` / `days`; a missing or unknown value, like a missing N, keeps the stored
  lookback and N (`all` / 60 when nothing is stored), the same rule as for ignore-free and
  unlike `woo_product_id_format`, which falls back to its default.
  `woo_purchase_first_only_days` must be an integer ≥ 1 when lookback is `days`; otherwise lookback and days silently revert to the stored values while
  the rest of the save goes through. With lookback `all`, an invalid N is replaced by the
  stored N (60 when none). No upper bound: the cutoff is computed as an integer and, when it
  is 0 or less (or N * DAY_IN_SECONDS overflows to a float), the date clause is left out, which
  is the same as lookback "all". Passing a float string to `date_created` is avoided because
  `parse_date_for_wp_query()` (`class-wc-data-store-wp.php:356-368`) splits its argument on
  `.`. A digit string larger than `PHP_INT_MAX` is accepted and stored as `PHP_INT_MAX`.
- The server check is a safety net only: `ajax_save_settings` always
  answers success, and the panel saves the whole option set on every toggle, so an invalid N
  must never enter the panel's state.
- "Whole number" means digits 0-9 only after trimming whitespace, checked the same way in the
  panel (a regular expression on the draft string) and on the server (`ctype_digit()` on the
  trimmed string), so `7.0` and `1e3` are rejected by both.
- Validation lives in the panel: the day input keeps its own draft value and commits it to the
  settings state (and saves) on blur or Enter only when it is a whole number ≥ 1; otherwise it
  shows "Enter a whole number of days, 1 or more" and commits nothing. Rejected: a
  `wp_send_json_error` from the shared handler (blocks every other toggle while the panel holds
  a bad N, and changes a handler all settings use); a success response with a warning field
  (new response shape and UI handling for one field).
- PHP reads a missing key with the spec's default (`0`, `all`, `60`, `1`); the React defaults
  match, so the first save writes the same values.
- UI: under the Purchase free-products toggle, a switch, then a lookback dropdown (the
  existing `Dropdown` component) with a number input shown for `days`, saved on blur or
  Enter, and the ignore-free switch. The whole group is disabled while Purchase is disabled,
  and the controls under the switch are disabled while the switch is off.

### UI copy (approved)
- Switch: "Only the customer's first purchase". Tooltip: "When enabled, Purchase is sent only
  if this customer (same account or billing email) has no other paid order in the lookback
  window (or no other order at all, when "Ignore previous free orders" is off). Subscription renewals are such orders, so they stop sending — and so do repeat
  purchases of any product, including orders that contain only excluded or free products. Set
  the window longer than your longest billing period, or yearly renewals will send again.
  Orders already processed keep their decision when you change these settings."
- Lookback: "Count previous orders from", options "All time" / "The last N days". Day-field
  error: "Enter a whole number of days, 1 or more".
- "Ignore previous free orders". Tooltip: "When enabled, an earlier order with nothing paid
  (such as a free trial) does not count, so the first paid charge still sends Purchase."

### Debug log
Like every other entry, these are written only when `woo_debug_enabled` is 1; tests and live
specs that assert on them enable it. A suppression writes one entry through the existing
`debug_log()` with hook
`FIRST_PURCHASE_SKIP` and payload `{order_id, matched_order_id}`. An order with no identity
writes `FIRST_PURCHASE_NO_CUSTOMER`. The live suite's `helpers/debug-log.ts` reads these
entries.

## Risks / Trade-offs

- [A wrong skip drops a real first purchase from Meta] → fail open when there is no identity
  or no paid candidate within the cap; fail closed only on a positive match; the decision is
  logged with the matching order id.
- [Mixed stores lose repeat purchases] → off by default; the tooltip and changelog state that
  every later order of a customer stops sending.
- [Day window shorter than the subscription period] → an annual renewal with a 60-day window
  sends every year. The tooltip says the window must be longer than the billing period.
- [Frisbii split checkout] → observed on the test site: the main order sends one Purchase with
  the whole cart's value and the split orders are skipped (see Open Questions).
- [Email case on a non-standard collation] → the lowercased email is compared by the database;
  standard WordPress collations are case-insensitive, a binary one would miss a stored
  `Buyer@Example.test` and the order would send (fail open). Accepted: a PHP comparison would
  need the candidate loop rejected for the lookup.
- [Concurrent requests] → the decision is written after the lookup without a claim, so two
  orders of one customer processed in the same instant can each see the other undecided and
  both be suppressed. Accepted as unlikely; the lookup and the write are a few milliseconds
  apart.
- [An order whose Purchase the buyer declined still counts] → a customer who declined
  consent on the first order is not sent again on the second. This follows the PRD ("orders
  that never sent because of consent, still count").
- [Guest typo or a new account with a different email] → seen as a new customer; this is the
  limit of the order data.
- [Legacy order storage] → the billing email is matched in `wp_postmeta.meta_value`, which is
  not indexed; the query is still bounded by status, type and limit. Stores on HPOS use the
  `billing_email` and `customer_id` indexes of `wc_orders`.
- [Orders that never sent Purchase still count] → an order made only of excluded SKUs or of
  free products with free-product Purchase off is a paid order and suppresses later ones, so
  a store that excludes products from tracking can lose a customer's first tracked purchase.
  Accepted: the PRD compares orders, not sent events, and checking lines would take the lookup
  out of the query; the tooltip says so.
- [Settings changes do not apply retroactively] → orders already recorded keep their decision;
  documented in the tooltip.

## Migration Plan

No migration. New keys read their defaults until the settings page is saved. Rollback:
turning the setting off restores the previous behaviour for every order without a recorded
skip; the `_pf_purchase_first_only` meta is inert once the code is gone.

## Open Questions

None left open. Both earlier questions were answered on the test site with Frisbii Pay 1.8.18,
Frisbii Billing 1.3.10 and a Frisbii test account (2026-10-07, HPOS, setting on):

- **Frisbii renewal.** The signup order (one subscription) had its total zeroed after payment
  and the amount in `_real_total`; it was recorded `first` and sent Purchase. The renewal,
  triggered by moving the subscription's next period start, was a new order with the signup as
  its parent, one line with product id 0 named after the plan, the full amount in its total
  and no `_real_total`; it was recorded `skipped:<signup id>` and sent nothing.
- **Frisbii split checkout.** A cart with two subscriptions became the main order (one
  subscription left, total zeroed, `_real_total` set, `_reepay_another_orders` naming the
  split order), the split order 16 s later, and a child of the main order for the first
  invoice. The main order sent one Purchase carrying the whole cart's value; the split order
  and the first-invoice child were both recorded skipped. No value was lost and nothing was
  sent twice, so no exclusion for `_reepay_another_orders` is needed.
- **Test-account setup worth knowing.** The Frisbii account's webhooks were limited to
  `customer_payment_method_added` and `invoice_authorized`, so renewals never reached the site
  until `subscription_renewal` and the invoice events were added. The gateway builds its
  webhook URL from the site URL, which on the test site is `http://`; the https URL was
  registered through the API instead. `change_next_period_start` reads its time in the
  subscription's own timezone.

Before the Frisbii account existed, the free "Subscriptions for WooCommerce" 2.1.0 stood in for
it (approved fallback), observed on the test site on 2026-10-06 (HPOS): its renewal is a new
order with no parent, the same user id and billing email as the signup, the subscription's
product id on its line, the full amount in the total and `wps_sfw_renewal_order = yes`. The
signup was recorded `first` and sent Purchase; the renewal was recorded `skipped:<signup id>`
and sent nothing. That plugin offers only card processors for subscriptions; the test site
adds cash on delivery through its `wps_sfw_supported_payment_gateway_for_woocommerce` filter
in a test-only mu-plugin.
