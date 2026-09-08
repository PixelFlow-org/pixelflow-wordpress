## 1. Request ownership

- [x] 1.1 Add an ownership predicate answering whether the current request belongs to a given order, satisfied by any one of: the request's `_pf_uid` matching the order's `_pf_cookie__pf_uid`, a logged-in customer id matching the order's, the order being the session's `order_awaiting_payment`, or the WooCommerce session customer id matching the one persisted with the order
- [x] 1.2 Persist the WooCommerce session customer id onto the order at creation, alongside the tracking cookies already saved on `woocommerce_new_order`, writing it only when the order does not already carry one
- [x] 1.3 Make the current request's consent data apply to an order only when the request is the buyer's, and in that case in both directions — a grant lifts a hold or an earlier decline, a withdrawal blocks the send and is persisted onto the order; a request that is not the buyer's changes nothing
- [x] 1.4 Gate `sync_live_grant_onto_order()` on the ownership predicate, and stop it overwriting a non-empty `_pf_uid` or `_pf_attribution`
- [x] 1.5 Tests: staff status change with staff consent cookies present; order-received URL opened by a stranger; buyer granting on their own thank-you page after a decline; buyer withdrawing on the order-pay page before the purchase was sent, followed by a staff status change; buyer recognised by `_pf_uid` alone on an order predating the stored session id; persisted grant sending from a request carrying a stranger's decline

## 2. Purchase delivered and reported once

- [x] 2.1 Add the atomic claim around the purchase send — an `add_option()` insert keyed by order id, carrying a timestamp, released once the outcome is recorded and treated as abandoned after `max(300, request_timeout * 10)` seconds; a hook that finds an abandoned claim deletes it before taking its own
- [x] 2.2 Record the outcome on the order: delivery only when the transport did not return a `WP_Error`, a blocked marker with its reason on a skipped send; leave the private-IP skip recording neither
- [x] 2.3 Replace the immediate blocked-events POST for Purchase with a marker on the order and a `wp_schedule_single_event` report 30 minutes out; a grant from an owning request before it fires cancels the report and sends the purchase instead, and once the report has been sent the order is closed to both sending and further reporting
- [x] 2.4 Send an overdue report from any later purchase hook that runs for the order, so a site with no working scheduler still reports exactly once
- [x] 2.5 Gate hook entry on the recorded outcome, allowing re-entry only while the order is still within its reporting window
- [x] 2.6 Derive the purchase `event_id` from the order id and the event name, so a duplicate that reaches the API despite the claim is identifiable and a future second event type on the same order cannot collide
- [x] 2.7 Tests: concurrent thank-you and status change send exactly one purchase; a `WP_Error` from the POST leaves the order able to send again; an abandoned claim is removed and delivery proceeds; a raised request timeout widens the abandonment interval; declined order with no later grant emits exactly one blocked row after the delay, across several status changes and reloads; decline then buyer grant inside the window sends the purchase and no blocked row; overdue report sent from a purchase hook with the scheduler disabled; grant after the report has been sent sends nothing; private-IP skip still able to send from a later request

## 3. Line filtering in event payloads

- [x] 3.1 Add the shared predicate deciding whether a cart or order line is reported — excluded by SKU, or free while the freebie option passed to it is enabled; the option key is a parameter, so the existing per-event settings keep their separate meanings
- [x] 3.2 Apply it in the InitiateCheckout cart builder with `woo_disable_initiate_checkout_freebies`, so `contents`, `num_items` and `value` cover reported lines only
- [x] 3.3 Apply it in the Purchase order builder with `woo_disable_purchase_freebies`, and subtract the omitted lines' totals from the discount-redistribution base so the ratio runs over retained lines alone
- [x] 3.4 Replace the two all-or-nothing gates with a check that the filtered set is empty, keeping "no event when every line is excluded"
- [x] 3.5 Tests: the 100 / 50-excluded / 25 order reports two products and a value of 125; mixed free product with the setting on and off; `woo_disable_initiate_checkout_freebies` on with `woo_disable_purchase_freebies` off filters the checkout event only, and the reverse combination filters the purchase event only; every line excluded sends nothing; order-level discount with an excluded product present

## 4. Held events rebuilt from their recipe

- [x] 4.1 Store the event's line items on the recipe when it is queued, in full, and make the 20-recipe queue cap adjustable through a filter
- [x] 4.2 Rebuild AddToCart and InitiateCheckout payloads from the recipe alone, dropping the live product and live cart lookups and the fallback branches they needed
- [x] 4.3 Tests: cart changed between hold and grant still reports the held value and lines; cart emptied or product deleted before the flush still sends; a 300-line cart is replayed intact; a raised queue limit keeps more than twenty recipes

## 5. Storefront transport

- [x] 5.1 Add the read-only `wc-ajax=pixelflow_held_state` route returning `{hasQueue, nonce}` for the current session
- [x] 5.2 Make that route the script's authoritative source for the queue flag and the flush nonce, and stop baking the nonce into the page; keep the `_pf_held_woo_events` cookie as it is today, including its WP Consent API disclosure
- [x] 5.3 Flush on the WP Consent API consent-change signal as well as on the polling timer, so a decision made on the page is acted on immediately
- [x] 5.4 Point the flush call at `/?wc-ajax=pixelflow_resolve_held_events`, and reduce the `admin-ajax` handler to a thin alias of the same logic, marked for removal in the next release
- [x] 5.5 Handle a refused flush in the script — check the response status, retry on the existing 2s→16s backoff, and stop for the lifetime of the page after the refusal that occurs at the 16s ceiling (five consecutive refusals)
- [x] 5.6 Raise the idle poll interval to 10s
- [x] 5.7 Tests: flush works from a page whose HTML predates the current nonce; a consent-change signal flushes without waiting for the timer; a banner that announces nothing is still caught by the timer; queue created after headers were sent is still flushed on the next page view; a persistently refusing flush endpoint stops being called after the backoff reaches its ceiling

## 6. Dead code and specification

- [x] 6.1 Remove `pixelflow_should_send_event_for_consent()` and move its test cases onto `pixelflow_resolve_blocked_event_reason()`
- [x] 6.2 Remove `pixelflow_if_is_bot()`, leaving the `pixelflow_useragent_bot_patterns` filter in place
- [x] 6.3 Update the `blocked_events` payload requirement so `client_ip_address` is part of the contract, with the opt-in/opt-out region rationale
- [x] 6.4 Write every `blocked_events` POST to the debug log — the payload and the transport result, a line when the payload could not be built at all, and what the deferred purchase report decided — so a skipped event is as traceable as a sent one

## 7. Verification

- [x] 7.1 Run the full PHP test suite and the PHP 8.3 lint over every changed file
- [ ] 7.2 Run the live WooCommerce event verification against the test site
