## Why

The GDPR consent-gating work on `feature/gdpr-consent-gating` (PR #21) ships the intended behaviour, but a review of the branch found defects that either corrupt order data, inflate the backend's blocked-event counts, or let a Purchase be sent for a shopper who declined. The product side has since answered the open questions, so every item now has a decided outcome.

Two of the answers turn into requirements the branch does not currently satisfy at all: blocked events are counted exactly like real events on the API side, and the API performs no Purchase deduplication — so the plugin alone is responsible for emitting each row once, and for emitting exactly one row per order rather than a blocked row followed by a real event. A separate gap surfaced during the same review: products excluded by SKU, and free products when the freebie option is on, still contribute to the value and contents of checkout and purchase events.

## What Changes

**Consent resolution and order data**

- A request is recognised as the buyer's by a union of independent signals — the visitor identifier already persisted with the order, a matching logged-in `customer_id`, the order awaiting payment in the request's session, or a matching session customer id. Any one is enough, so no single lost cookie disowns a genuine buyer, and the predicate works on orders created before this change.
- Only the buyer's own request can change an order's consent state, and it can change it in both directions: a grant lifts a hold or an earlier decline, and a withdrawal blocks the send and is persisted so a later staff-triggered status change sees it. A request that is not the buyer's changes nothing, so a failed ownership check falls back to the persisted decision and can never turn a working flow into a silent loss.
- `sync_live_grant_onto_order()` is gated the same way, and additionally never overwrites a non-empty `_pf_uid` or `_pf_attribution` — a live grant may fill missing identifiers and upgrade the consent state, never replace attribution already recorded for the order.

**Blocked events and Purchase delivery**

- A blocked Purchase is no longer beaconed at the moment of the skip. The reason is recorded on the order and the report is scheduled 30 minutes out; a grant from the buyer inside that window cancels the report and sends the purchase instead, and once the report has been sent the order is closed to both. Exactly one unit — a purchase event or a blocked row — reaches the API per order. An overdue report is also sent by any later purchase hook, so a site with no working scheduler still reports.
- A Purchase is claimed on the order *before* the POST, so the thank-you page and a concurrent status-change request cannot both send one. The claim is abandoned after five minutes or ten times the configured request timeout, whichever is longer, and a hook that finds an abandoned claim deletes it.
- Delivery is not recorded when the transport reports an error, so an unreachable API leaves the order able to send on its next status change instead of losing the purchase silently.
- The purchase `event_id` is derived from the order id and the event name, so a duplicate that slips past the claim is identifiable in the backend's data and a future second event type on the same order cannot collide.
- Blocked events reach the debug log. Until now only sent events were logged, so a skipped one left nothing to inspect; every `blocked_events` POST now records its payload and transport result, an unbuildable payload records why, and a deferred purchase report records when it is due or that it has just fired.

**Held storefront events**

- A flushed held event is rebuilt entirely from its stored recipe. The live cart and live product are no longer consulted, so the replayed payload matches the `event_id` and `eventTime` it carries. Recipes gain the event's line items, stored in full, and the 20-recipe queue cap becomes adjustable through a filter.
- The storefront script takes the queue flag and its flush nonce from a dedicated read-only `wc-ajax=pixelflow_held_state` route, called when it is about to act, and treats that answer as authoritative rather than the values baked into cached HTML. This fixes both a nonce that has outlived the cached page and a queue created in a request where `setcookie()` was skipped because output had already started. The `_pf_held_woo_events` cookie and its WP Consent API disclosure are unchanged.
- The script flushes on the WP Consent API's consent-change signal as well as on its timer, so accepting the banner acts immediately; the idle interval is relaxed from 1s to 10s as the fallback path.
- The flush call is pointed at `wc-ajax`; the `admin-ajax` handler is reduced to a thin alias for one release so pages cached before the deployment keep working. A refused flush is retried on the script's existing backoff and abandoned once that backoff reaches its ceiling.

**Event payload composition**

- Products excluded by SKU are dropped from `contents`, `num_items` and `value` on InitiateCheckout and Purchase instead of only suppressing the event when *every* line is excluded. Free products are dropped on the same terms when the freebie setting belonging to that event is enabled — `woo_disable_initiate_checkout_freebies` for InitiateCheckout, `woo_disable_purchase_freebies` for Purchase. The three per-event freebie settings stay independent; none is merged, renamed or removed. The order-level discount redistribution is computed over the retained lines only.

**Specification and dead code**

- The `blocked_events` payload requirement is corrected: `client_ip_address` is part of the contract. The API uses it to determine whether the visitor is in an opt-in or opt-out region; it is neither stored nor forwarded.
- `pixelflow_should_send_event_for_consent()` is removed and its tests move onto the gate that actually runs, `pixelflow_resolve_blocked_event_reason()`. The unused `pixelflow_if_is_bot()` wrapper is removed; the `pixelflow_useragent_bot_patterns` filter is unaffected.

## Capabilities

### New Capabilities

- `woo-event-payload`: which order and cart lines contribute to `contents`, `num_items` and `value` on WooCommerce events, and how order-level discounts are redistributed across the retained lines.

### Modified Capabilities

- `consent-resolution`: how a request is recognised as the buyer's; whose consent data may change an order's state and in which direction; single delivery of a Purchase across concurrent hooks; one reported unit per order, after a settling delay; held recipes replayed from stored state; the queue signal and flush transport for the storefront script; `client_ip_address` acknowledged in the `blocked_events` payload.

## Impact

- `includes/consent.php` — ownership predicate and owner-only, bidirectional live consent for order-scoped events; removal of the unused send gate.
- `includes/blocked-events.php` — the POST helper returns its transport result so the caller can log it; the spec text catches up with the shipped payload.
- `includes/held-events.php` — recipes carry their line items in full; the queue cap becomes filterable.
- `includes/helpers.php` — removal of `pixelflow_if_is_bot()`.
- `includes/woo/hooks/class-woocommerce-hooks.php` — Purchase claim with its abandonment rule, transport-error handling, blocked marker and scheduled report on the order; order-derived `event_id`; ownership-gated grant sync; SKU and freebie filtering in the InitiateCheckout and Purchase builders.
- `includes/woo/hooks/trait-held-woo-events.php` — rebuild from recipe only; the new read-only state route.
- `pixelflow.php` — the `admin-ajax` handler reduced to an alias; the nonce stops being baked into the page.
- `assets/js/held-events.js` — queue flag and nonce from the state route, flush on the consent-change signal, response-status handling with a bounded retry, idle interval.
- `tests/` — coverage moves from the removed gate onto the live one; new cases for ownership, withdrawal, one reported unit per order, single Purchase, transport errors, recipe replay and line filtering.
- No database migration, and no version bump or changelog entry in this change.
