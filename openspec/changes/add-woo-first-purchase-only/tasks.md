## 1. Settings

- [x] 1.1 `pixelflow.php` `sanitize_general_options()`: add `woo_purchase_first_only` to the
  checkbox list; sanitize `woo_purchase_first_only_ignore_free` separately so a missing key
  keeps the stored value (1 when nothing is stored) instead of becoming 0; accept
  `woo_purchase_first_only_lookback` only as `all` / `days`; accept
  `woo_purchase_first_only_days` only as an integer ≥ 1 when lookback is `days`, otherwise
  silently keep the stored lookback and days while saving the rest
- [x] 1.2 A PHP helper that returns the four values with their defaults (`0`, `all`, `60`, `1`)
  when a key is missing, used by the hook
- [x] 1.3 `app/source/src/features/settings/types/settings.types.ts` and `hooks/useSettings.ts`:
  the four keys with the same defaults
- [x] 1.4 `WooCommerceSettings.tsx`: under "Enable Purchase event for free products", the
  switch "Only the customer's first purchase" with the tooltip from design.md "UI copy"; the
  lookback `Dropdown` ("Count previous orders from": All time / The last N days) with a number input for `days` that keeps a local draft and commits and
  saves it on blur or Enter only when it is a whole number ≥ 1, otherwise shows "Enter a whole
  number of days, 1 or more" and sends nothing; the "Ignore
  previous free orders" switch. The group is disabled while `woo_disable_purchase` is 1, the
  sub-controls while the switch is off
- [x] 1.5 vitest case in `app/source/src/test/` (or next to the component): defaults, the
  disabled states, an invalid day count (empty, 0, -5, 7.5, 7.0, 1e3) showing the message with
  no save call, a valid one (` 007 ` saved as 7) saved, `npm run test` green

## 2. Previous-order check

- [x] 2.1 Amount paid: `pixelflow_order_amount_paid` filter (used when `is_numeric()`, > 0 paid,
  ≤ 0 free) → total > 0 → `_real_total` > 0 → 0
- [x] 2.2 Customer identity from the order: user id if > 0, billing email trimmed and
  lowercased and kept only when `is_email()` accepts it; neither → no lookup, record `first`
- [x] 2.3 The anchor lookup first (any order with `_pf_purchase_first_only = skipped:<current
  id>` → record `first`), then the lookup through `wc_get_orders()` as in design.md:
  `customer`, statuses, `type`, `exclude`, `date_created` for `days` and, with ignore-free on,
  the "paid" condition (one `meta_query` in legacy storage; `total_amount > 0` then
  `total_amount = 0` with `_real_total > 0` in HPOS), newest first, `limit => 1`
- [x] 2.4 Apply `pixelflow_order_amount_paid` to the order found; only when it returns 0 (with
  ignore-free on) look at up to 20 candidates in total, fail open if all are marked free; when
  the filter is hooked, ignore-free is on and nothing counted, query up to 20 paid-status orders with total 0 and
  `_real_total` missing or ≤ 0, and count the first the filter values above 0
- [x] 2.5 In `pf_purchase_hook()`, after the existing guards and before the consent sync and
  `claim_purchase_delivery()`: follow a recorded `_pf_purchase_first_only` if present (even
  with the setting off); otherwise, with the setting on, run the check, record `first` or
  `skipped:<id>`, save the order, and return on a skip without posting
- [x] 2.6 Debug-log entries `FIRST_PURCHASE_SKIP` (`order_id`, `matched_order_id`) and
  `FIRST_PURCHASE_NO_CUSTOMER`

## 3. PHP tests

- [x] 3.1 New `tests/test-purchase-first-only.php` with an in-memory `wc_get_orders()` stub
  that honours `customer` (ids OR emails), `status`, `type`, `exclude`, `date_created` and
  `limit`, plus the meta clauses and the total conditions of 2.3 in a simplified form (the
  real SQL is covered by 5.4); production code keeps reading `time()`; the boundary is tested by
  creating fixtures relative to `time()` at the start of the case (60 days minus one minute
  counts, 61 days does not) and by asserting the stub received `date_created >=` within a
  few seconds of `time() - 60 * DAY_IN_SECONDS`; `woo_debug_enabled` is
  on in the cases that assert on log entries
- [x] 3.2 Cases from the spec: setting off (second order sends, no meta); upgrade with no keys;
  another customer's paid order (different user id and email) does not count;
  Purchase disabled (no lookup); same email with different case and spaces; same user id with
  empty email; invalid email `n/a` with a matching user id (suppressed); no identity (sent,
  recorded `first`, logged); renewal suppressed (no `/event`, no
  `/blocked-events`, no `_pf_purchase_sent`, `skipped:<id>` recorded, log entry); on-hold /
  pending / failed / cancelled do not count; refunded counts; a later-created order counts
  (staff complete an old pending order); an on-hold order sent from the thank-you page does
  not block a later card order and sends nothing more once paid; window
  boundary 60 vs 61 days; ignore-free on and off; `_real_total` 29 with total 0 counts;
  filter returning 0 makes a paid order free; filter returning `"0"` / `-5` (free) and
  `null` / `"n/a"` (ignored); filter returning 15 for a total-0 order without
  `_real_total` makes it count, and so does one with `_real_total` `"0"`; no filter hooked → that extra query is not run; an order of only excluded SKUs counts; 25 free orders newer than a paid one still
  block; a filter marking 21 paid orders free lets the order send
- [x] 3.3 Decision-once cases: a skipped order moved to completed after the matching order is
  cancelled stays skipped; a `first` order held for consent sends on a later pass after a
  second order was suppressed because of it; a skipped order stays skipped after the setting
  is turned off; no mutual suppression (X held while the setting was off, Y `skipped:X`, X
  then sends); no suppression through a chain (X ← Y ← Z, X then sends); a renewal recorded `skipped:<original>` still counts for the next renewal
  inside a 60-day window
- [x] 3.4 Settings sanitizer cases: invalid day counts (empty, 0, -5, 7.5, 7.0, 1e3) keep the stored
  lookback and days while another key in the same save is stored; lookback `all` with an
  empty N keeps the stored N (60 when none); a save without the lookback and N keys, or with
  lookback `weekly`, keeps the stored `days` / 90; a save with only N (or only the lookback)
  keeps both stored values; a huge N (10^12, 10^18) with lookback `days`
  sends no date clause, i.e. behaves as "all"; valid values are saved; a
  save without `woo_purchase_first_only_ignore_free` keeps it on, and stores it on when nothing
  was stored
- [x] 3.5 Run every `tests/test-*.php`; all exit 0

## 4. Test site with a subscription plugin

- [x] 4.1 Install Frisbii Billing (`reepay-subscriptions-for-woocommerce`) and Frisbii Pay
  (`reepay-checkout-gateway`) on the live test site with a Frisbii test or trial account. If
  that is not possible without a paid account, stop and report, then install another free
  WooCommerce subscription plugin instead (approved fallback) and record which one
- [x] 4.2 Observe and record (no customer data, values from test orders only): the meta of a
  checkout order and of a renewal (`parent_id`, total, `_real_total`, billing email, user id,
  statuses), and confirm they match design.md's Context
- [x] 4.3 Observe a cart with two subscription products: which orders are created, in which
  order they reach processing, and what Purchase(s) the plugin sends with the setting on;
  record the result in design.md's Open Questions and, if needed, open a follow-up change

## 5. E2E and live tests

- [x] 5.1 Admin E2E under `e2e/tests`: the controls appear under the free-products toggle,
  are disabled with Purchase disabled, and an invalid day count shows the message and is not
  saved (reload shows the previous value)
- [x] 5.2 Live spec `e2e/live/tests/purchase-first-only.spec.ts` (WooCommerce debug log on;
  AddToCart and InitiateCheckout of the returning persona still logged): with the setting on, a
  persona's first order logs Purchase and a second order with the same email logs a
  first-purchase skip and no Purchase; with the setting off both log Purchase; restore the
  settings afterwards
- [x] 5.3 Live renewal scenario with the subscription plugin from 4.1 (a real renewal, or a
  renewal order created through the plugin's own API): the renewal logs a skip, not a
  Purchase
- [x] 5.4 Run 5.2 on both order storages on the live test site (HPOS on, then legacy storage
  via the WooCommerce "Order data storage" setting, restored afterwards), including a previous
  order with total 0 and `_real_total` > 0, a paid order of another customer that must not
  count (on both storages, to catch the meta-query nesting), the two filter paths run inside
  one `wpEval()` call that hooks `pixelflow_order_amount_paid` inline and moves the order to
  processing in the same request (a total-40 order demoted to free → current order sends; a
  total-0 order without `_real_total` promoted to 15 → current order suppressed), and an
  anchor case (an order recorded
  `skipped:<current id>`), so both
  query shapes of 2.3 are exercised against real SQL

## 6. Release

- [x] 6.1 Version 1.2.1 → 1.3.0 in `pixelflow.php` (header and `PIXELFLOW_VERSION`),
  `readme.txt` (`Stable tag` and a `= 1.3.0 =` entry) and `README.md` (`### 1.3.0`), one line:
  "WooCommerce: optional setting to send Purchase only for a customer's first paid order, so
  subscription renewals and repeat orders stop counting as new purchases."
- [x] 6.2 Add the scenarios to `docs/test-scenarios.html`
- [x] 6.3 Full run (PHP, component tests, admin E2E, live matrix), then update the report's
  version, header and "Last full run" line and publish it with
  `e2e/live/scripts/publish-report.sh`

## 7. Delivery

- [x] 7.1 Branch `feat/wordpress-first-purchase` from `main`; separate commits for this
  OpenSpec change, settings, the check and its tests, live tests, and version/changelog; no
  session links in the messages, no client site names or customer data anywhere
