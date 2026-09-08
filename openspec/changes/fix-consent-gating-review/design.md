## Context

See proposal.md — Why. Three constraints shape the approach.

The purchase hook is bound to `woocommerce_order_status_processing`, `woocommerce_order_status_completed` and `woocommerce_thankyou`, so it runs in three unrelated request contexts for the same order — two of which frequently belong to someone other than the buyer, and two of which can overlap in time. The `$sent_in_request` guard covers only a single PHP request; anything durable has to live on the order.

The API performs no purchase deduplication and counts blocked events exactly like real events, so both "send once" and "report once" are the plugin's responsibility alone, not something a downstream system will absorb.

The storefront script runs on pages that are routinely served from a full-page cache, so nothing it depends on can be baked into the cached HTML.

## Goals / Non-Goals

**Goals:**

- One durable place on the order that records what happened to its purchase event, sufficient to answer "may this hook send?" and "may this hook report?" without consulting the current request's cookies.
- An ownership predicate, used by both consent resolution and the grant sync, that works for guest checkouts as well as logged-in customers, and for orders created before this change.
- Exactly one unit of traffic per order — either a purchase event or a blocked row, never both.
- Line filtering implemented once and shared by the checkout and purchase builders, so the two cannot drift.

**Non-Goals:**

- Delivery confirmation. Events are POSTed with `blocking => false`, so the plugin sees transport-level failures but never an HTTP status. A purchase the API answers with a 500 is recorded as delivered and never retried.
- Reworking how `additionalData` is priced. The discount redistribution keeps its current algorithm; only the set of lines it runs over changes.
- Any change to the browser tracking script's own consent handling.

## Decisions

### Ownership is a union of independent signals

A request belongs to an order when **any one** of these matches:

- the request's `_pf_uid` equals the `_pf_cookie__pf_uid` already persisted with the order,
- the order's logged-in customer id is non-zero and equals `get_current_user_id()`,
- the order is the one held in the session's `order_awaiting_payment`,
- the current WooCommerce session customer id equals the one persisted with the order.

No single signal is required, so no single failure disowns a genuine buyer. `_pf_uid` carries most of the weight: it is long-lived, survives a redirect to an offsite gateway, a cleared cart, a session regeneration and a login or logout, and it is already written to the order today — which makes the predicate work retrospectively for orders created before this change. `order_awaiting_payment` covers the window between checkout and payment confirmation. The session customer id is the fourth signal, written once at order creation and never rewritten.

Alternatives considered. *Session identifier alone* — rejected: it is absent on orders predating the change and is lost whenever the session cookie is. *Order key in the request URL* — rejected: the order-received and order-pay URLs are deliberately shareable, which is the exact hole being closed. *Logged-in customer only* — rejected: guest checkout is the majority case for many stores.

Residual risk: a shared device. A second person on the same browser carries the buyer's `_pf_uid` and is recognised as the buyer. This is inherent to cookie-based identity and is what the browser tracking script on that page would conclude too.

### Only the buyer's own request changes an order's consent state

| Request | Effect on the order |
| --- | --- |
| not the buyer's | none — the persisted decision applies, whatever the request's cookies say |
| the buyer's, granting | lifts a hold or an earlier decline, and is persisted |
| the buyer's, withdrawing | blocks the send, and is persisted so a later staff request sees it |

The asymmetry that matters is between *owning* and *non-owning* requests, not between grants and declines. A failed ownership check falls back to the persisted decision, which for an order whose buyer consented means the event is sent — the behaviour that works today. So a missed identity signal can never turn a working flow into a silent loss; it can only fail to act on a change of mind.

Making the buyer's own withdrawal effective is what keeps the plugin honest about consent: an order can sit in `pending` for days, and a Purchase POSTed after the shopper has withdrawn is processing after withdrawal, not before it.

The limit of this is a withdrawal made in a request where no purchase hook runs — the shopper opens the shop, revokes in the banner, and a week later staff move the order to `completed`. Catching that would mean a meta query for the visitor's unsent orders on every storefront request, which is out of proportion to the case; the withdrawal is honoured on the thank-you and order-pay pages, and not elsewhere.

### Delivery is claimed with an atomic lock, and the outcome is recorded on the order

Two pieces of state, doing two different jobs.

A short-lived lock guards concurrency: an `add_option()` insert named after the order id, which is atomic because `option_name` carries a unique index — the loser of a race gets `false` and returns without building a payload. Because events are POSTed with `blocking => false`, the claim is held for the time it takes to build a payload and hand it to a socket, well under a second even when the API is unreachable.

It is abandoned after `max(300, request_timeout * 10)` seconds. The floor of five minutes is a margin of two orders of magnitude over the default 5s timeout; tying it to the timeout as well means a site that raises `pixelflow_request_timeout` cannot end up with a running request whose claim another hook feels entitled to take. A hook that finds an abandoned claim deletes it before taking its own, which is also the only cleanup these rows get.

Order meta records the durable outcome so later hooks can decide without guessing: delivery on a send, and a blocked marker carrying the reason on a skipped send. Delivery is *not* recorded when `wp_remote_post()` returns a `WP_Error` — with a non-blocking request the connection is still established synchronously, so a refused connection, an unresolvable host or a TLS failure is visible, and those are the shapes an outage usually takes. The order stays open and its next status change tries again.

The purchase `event_id` is derived from the order id and the event name. With no deduplication on the API side this changes nothing today; it costs one line, makes any duplicate that slips past the claim identifiable in the backend's data, collapses on its own if the API ever gains deduplication, and the event name in the key leaves room for a second event type on the same order without a collision.

Alternatives considered. *Meta write before the POST, as on `main`* — rejected: it closes the race but permanently blocks the thank-you grant, which is the feature being shipped. *A transient as the lock* — rejected: transients can be backed by a non-atomic object cache, so two workers can both believe they hold it. *A blocking POST for Purchase* — rejected: it would catch an API error status too, at the cost of making the thank-you page wait on the API.

### A blocked purchase is reported after a settling delay, not at the moment of the skip

Because blocked rows are counted like real events, an order that is skipped and then granted would otherwise cost two units — the pattern the branch already produces for a hold that is later accepted.

So a skipped purchase records its reason on the order and schedules the report through `wp_schedule_single_event` 30 minutes out. A grant from an owning request inside that window cancels the scheduled report and sends the purchase instead. Once the report has been sent the order is closed: no purchase is sent for it afterwards, and no second row is reported. Exactly one unit reaches the API per order, whichever way the shopper decided.

Because WP-Cron can be disabled outright, an overdue report is also sent by any later purchase hook that runs for the order. The scheduler is the normal path, not the only one.

This makes a decline reversible for half an hour, which is the practical length of a checkout session — long enough for a shopper who declines at checkout and accepts on the thank-you page, short enough that the report is not held indefinitely.

Alternatives considered. *Report immediately, decline final* — rejected: it loses the purchase of a shopper who changes their mind, and still double-counts the hold-then-grant path. *Report immediately, decline reversible* — rejected: double-counts both change-of-mind paths. *One recurring sweep instead of a scheduled event per order* — rejected as unnecessary: each entry lives at most thirty minutes, so even a store blocking a thousand orders a day holds only a couple of dozen at a time.

### A skipped event is as traceable as a sent one

The debug log only ever carried events that were POSTed to `/event`, which made the whole blocked path invisible: a store owner asking why an order produced no purchase had nothing to read. Every `blocked_events` POST now writes an entry carrying the payload and the transport result, a payload that could not be built writes a line saying so rather than returning silently, and the deferred purchase report writes what it decided — the deadline it is waiting for, or that it has just fired. `pixelflow_post_blocked_events()` returns its transport result for this; it stays fire-and-forget.

### A private `skipped` outcome does not consume the claim

A send skipped because the client IP is private or reserved is a property of the environment, not of the shopper, and the same hook firing again from the same server will reach the same conclusion. It records no blocked marker (the spec forbids beaconing for it) and no delivery. It leaves the claim released, so a later hook in a different request context — one that does resolve a public address — can still send. This does not reintroduce the race: the lock is still taken for the duration of each attempt.

### One line filter, applied in both builders

A single helper decides whether an order item or cart item is reported: excluded by SKU, or free while the freebie setting for the event being built is on. The freebie setting is a parameter of the helper, not a constant inside it — the checkout builder passes `woo_disable_initiate_checkout_freebies` and the purchase builder passes `woo_disable_purchase_freebies`, so the three existing per-event freebie toggles keep their current, independent meanings and none is merged or retired. The checkout builder and the purchase builder both run the helper before their existing loop, and the existing all-or-nothing gates become a consequence of the filter emptying the set rather than a separate check.

For the purchase builder the discount base has to move with the lines. `order_products_value` is currently the order total less shipping and tax, which includes money paid for omitted products. An order-level discount is therefore spread across *every* line first, and only the retained lines' share is reported: the reported value becomes the sum of the retained line totals times the ratio the order applies to its lines as a whole. Subtracting the omitted lines' raw totals instead would push their share of the discount onto the retained products and report less than the shopper paid for them. When nothing is filtered out the figure is left exactly as it is computed today.

AddToCart needs no change: it already declines to send for an excluded product under `woo_disable_add_to_cart_freebies`, and it reports exactly one product, so filtering and skipping are the same act.

### The storefront learns its state from a read-only endpoint

A `wc-ajax` route returns `{hasQueue, nonce}` for the current session. It takes no nonce of its own — it is read-only, session-scoped, and reveals nothing a visitor cannot already infer — which is what lets it work from a cached page. The script calls it when it is about to act, and uses the nonce it receives for the flush call itself. This is what fixes the two failures the cookie alone cannot: a nonce baked into cached HTML that has outlived its lifetime, and a queue created in a request where `setcookie()` was skipped because output had already started.

The `_pf_held_woo_events` cookie stays exactly as it is, including its WP Consent API disclosure. It remains a cheap local hint that a queue may exist; the endpoint is what the script trusts.

The script also flushes on the WP Consent API's consent-change signal rather than waiting for its own timer, so a shopper who accepts the banner does not sit through up to ten seconds before their queued events are sent. The timer stays as the fallback for banners that change the state without announcing it — which is exactly why the interval can be relaxed from 1s to 10s without making the common case slower.

Alternatives considered. *`wc_get_refreshed_fragments`* — rejected as the sole source: Woo refreshes fragments on cart changes and on a `wc_cart_hash` mismatch, and otherwise serves them from `sessionStorage`, so a shopper who queued an event, browsed on, and then granted can be handed the same stale values the cached HTML held. *Removing the cookie* — rejected: it is already declared to consent management platforms, and removing it buys nothing once the endpoint is authoritative.

### The flush keeps one route, with the old one as an alias

`wc-ajax` becomes the route the script calls: it avoids loading the admin bootstrap, sits beside the new state route, and is the endpoint cache plugins already know to leave alone. The `admin-ajax` handler in `pixelflow.php` is reduced to a thin alias of the same logic rather than removed outright, because a visitor whose page was cached before the deployment will still call it; it is marked for removal in the next release.

A refused flush reuses the retry budget the script already has rather than introducing a counter of its own: the existing backoff doubles the delay from 2s up to its 16s ceiling, and the refusal that occurs once the delay is already at the ceiling is the last one — the script then stops for the lifetime of the page. That is five consecutive refusals, spaced 2s, 4s, 8s and 16s apart, long enough to ride out a brief network or cache failure and short enough that a permanently unavailable endpoint is not polled forever.

### Recipes become self-describing

A recipe stores the line items it was built from, in full, so a flush rebuilds the payload without consulting the cart or the catalogue — a truncated recipe would silently under-report a wholesale cart.

The queue keeps its cap of 20 recipes per shopper, as specified, now adjustable through a filter for stores that need more. The cap is worth stating plainly: each held InitiateCheckout carries its own copy of the cart as it stood at that moment, because each is a separate event with its own `event_id` and `eventTime` and they cannot be collapsed without under-reporting. A shopper who reaches checkout three times before answering the banner therefore stores the cart three times. In practice that is a handful of copies; twenty is the pathological ceiling, and the recipes are discarded as soon as the shopper answers.

## Risks / Trade-offs

- **A shared browser is read as the buyer.** → `_pf_uid` identifies a browser, not a person. Accepted: it is the same identity the tracking script uses on that page.
- **A withdrawal made away from the order's own pages is not honoured.** → The buyer's withdrawal counts on the thank-you and order-pay pages; a revocation made elsewhere leaves the persisted grant standing, and a later status change sends the purchase. Finding the shopper's unsent orders on every storefront request is out of proportion to the case.
- **A change of mind after the reporting window is not honoured.** → An order granted more than 30 minutes after its blocked row was reported sends nothing. Accepted: it is the price of exactly one unit per order.
- **An API error status is invisible.** → Transport failures are caught and leave the order retryable, but a 500 from a reachable API is recorded as delivered. `blocking => false` cannot see it, and making Purchase blocking was rejected.
- **A blocked report can arrive late.** → It depends on WP-Cron, which fires on traffic, with a purchase hook as the fallback. An order that is blocked and then never touched again on a site with no cron and no further status change reports nothing — an undercount, never a duplicate.
- **The state endpoint adds a request per storefront page that has a queue.** → It is called only when the script is about to flush, not on the idle path.
- **The `admin-ajax` alias keeps a duplicate route alive for one release.** → Deliberate, so pages cached before the deployment keep working; it carries no logic of its own.
- **Filtering changes the reported value of purchases on stores that use SKU exclusions.** → Intended, and the reason the change exists. It will show as a step in reported revenue for those stores. There is no changelog entry in this change, so whoever releases it has to carry the warning.
- **Concurrency and scheduling are hard to cover in the test suite.** → The race and the 30-minute report cannot be reproduced by the PHP unit tests; they need the live WooCommerce verification and manual clock manipulation.

## Migration Plan

No schema or data migration. The new order meta is additive and read only by code that also writes it. Orders already carrying `_pf_purchase_sent` keep their meaning — delivery recorded — so no order re-sends after deployment. Ownership works on pre-existing orders through `_pf_cookie__pf_uid`, which the plugin already writes. The `admin-ajax` flush route survives the deployment as an alias, so storefront pages cached under the previous version keep flushing.

Rollback is a plugin version rollback. Order meta written by this version is inert for the previous one, and any blocked report still scheduled at rollback fires against code that does not recognise its hook, so it is dropped rather than misapplied.
