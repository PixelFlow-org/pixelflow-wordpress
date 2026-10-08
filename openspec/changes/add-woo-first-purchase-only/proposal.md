## Why

The plugin sends one Purchase per WooCommerce order, and a subscription renewal is its own
order, so every renewal charge reaches Meta as another Purchase. A merchant selling
subscriptions asked to send Purchase only for the initial subscription, by suppressing
Purchase when the customer already has a paid order in the past N days.

Subscription plugins do not share a renewal marker. WooCommerce Subscriptions sets
`_subscription_renewal`; Frisbii Billing does not, and builds renewal lines from its invoice
with no product id. The rule "this customer already has a paid order" works for any
subscription plugin, and is what the merchant asked for.

## What Changes

- A new WooCommerce setting in the Purchase block, directly under "Enable Purchase event for
  free products": "Only the customer's first purchase" (`woo_purchase_first_only`), off by
  default, including on upgrade.
- Two controls under it: a lookback of "all previous orders" or "the last N days"
  (`woo_purchase_first_only_lookback`, `woo_purchase_first_only_days`, default `all` / 60), and
  "Ignore previous free orders" (`woo_purchase_first_only_ignore_free`, default on).
- When the setting is on, `pf_purchase_hook()` looks for another order of the same customer
  (user id or billing email) that is processing, completed or refunded, was created inside the
  lookback, and (with ignore-free on) was paid. If one exists, Purchase is not sent: no
  `/event`, no `/blocked-events`, no `_pf_purchase_sent`.
- Amount paid is the order total, or Frisbii's `_real_total` when the total is 0, and can be
  overridden through a new `pixelflow_order_amount_paid` filter.
- The decision is recorded on the order in `_pf_purchase_first_only` (`first`, or
  `skipped:<id of the matching order>`), so it is taken once for both outcomes and later
  passes over that order follow it instead of re-evaluating. The WooCommerce debug log, when
  enabled, records the skip.
- AddToCart, InitiateCheckout, the free-products settings and consent handling are unchanged.
  Merchants who leave the setting off see no change.
- Version 1.2.1 → 1.3.0 with a one-line changelog entry.

## Capabilities

### New Capabilities
- `woo-first-purchase-only`: the setting and its controls, who counts as the same customer,
  which earlier orders qualify, how the amount paid is read, what a skipped order sends and
  records, and how the setting interacts with the existing Purchase controls.

### Modified Capabilities
<!-- None: consent resolution, form events, TikTok identifiers and release tracking are
     unchanged. -->

## Impact

- Code: `includes/woo/hooks/class-woocommerce-hooks.php` (the previous-order check in
  `pf_purchase_hook()`, the skip marker, a debug-log entry), `pixelflow.php`
  (`sanitize_general_options()` gains the four keys and the day-count validation).
- Admin UI: `app/source/src/features/settings/components/WooCommerceSettings.tsx`,
  `hooks/useSettings.ts`, `types/settings.types.ts`.
- Tests: new PHP tests next to `tests/test-purchase-once.php`, a vitest case for the settings
  panel, an admin E2E case under `e2e/tests`, a live storefront spec under `e2e/live/tests`,
  and `docs/test-scenarios.html`.
- Test site: Frisbii Billing installed on the live test site to observe real renewals and
  split checkouts; another free subscription plugin if Frisbii cannot run without a paid
  account.
- Outbound payload: unchanged. A skipped order sends nothing. No API, dashboard or script
  change; no migration.
- Release: `pixelflow.php`, `readme.txt`, `README.md` (version 1.3.0).
- Source: the PRD "WordPress first purchase only" (draft, 2026-09-29), with the decisions
  recorded in `design.md`.
