## ADDED Requirements

### Requirement: A request is recognised as the buyer's by any one identity signal
The plugin SHALL treat the current request as belonging to an order when at least one of the following holds: the visitor identifier in the request matches the one persisted with the order, the request comes from a logged-in customer whose id matches the order's, the order is the one awaiting payment in the request's WooCommerce session, or the request's WooCommerce session customer id matches the one persisted with the order. Any single match SHALL be sufficient, and the absence of one signal SHALL NOT disqualify a request that matches another.

#### Scenario: Buyer recognised by the visitor identifier alone
- **WHEN** the buyer returns from an offsite payment gateway with a new WooCommerce session, and the request carries the same visitor identifier that was persisted with the order
- **THEN** the request is treated as belonging to the buyer

#### Scenario: Order created before the session identifier was persisted
- **WHEN** an order-scoped event is resolved for a guest order carrying no stored session customer id, and the request carries the visitor identifier persisted with that order
- **THEN** the request is treated as belonging to the buyer

#### Scenario: Store staff changes an order status
- **WHEN** a purchase hook runs during an administrator's manual status change, with the administrator's own consent cookies present and no identity signal matching the order
- **THEN** the request is not treated as belonging to the buyer

#### Scenario: Order-received URL opened by someone other than the buyer
- **WHEN** the order-received URL is opened by a request that matches none of the identity signals for that order
- **THEN** the request is not treated as belonging to the buyer

### Requirement: Only the buyer's own request may change an order's consent state
The plugin SHALL apply the consent data present in the current request to an order only when the request is recognised as the buyer's. Such a request SHALL update the decision persisted with the order in both directions: a grant SHALL resolve a hold or an earlier decline and allow the event, and a withdrawal SHALL block the event and be persisted with the order so that a later request in another context sees it. A request not recognised as the buyer's SHALL have no effect on the order's consent state, and the decision persisted with the order SHALL apply unchanged.

#### Scenario: Persisted grant with a stranger's decline in the request
- **WHEN** an order whose persisted decision is `granted` reaches a purchase hook in a request carrying somebody else's declined consent cookie
- **THEN** the purchase event is sent and the stranger's decision has no effect

#### Scenario: Buyer grants on the thank-you page after declining at checkout
- **WHEN** the shopper who placed the order accepts the banner on the order-received page and the request is recognised as theirs
- **THEN** the order resolves to `granted` and the purchase event is sent

#### Scenario: Buyer withdraws consent before the purchase was sent
- **WHEN** the buyer, whose order carries a persisted `granted` and whose purchase event has not yet been sent, withdraws consent in a request recognised as theirs in which a purchase hook runs
- **THEN** no purchase event is sent, the withdrawal is persisted with the order, and a later status change made by store staff does not send one either

#### Scenario: Stranger grants on a shared order-received URL
- **WHEN** the order-received URL of an order whose persisted decision is `denied` is opened by a request matching none of the order's identity signals, and that request carries a granted consent cookie
- **THEN** no purchase event is sent for that order

### Requirement: Order identity data is never replaced by another visitor
The plugin SHALL NOT overwrite a visitor identifier or attribution value already persisted with an order. A live grant MAY fill an identifier or attribution value that is missing from the order and MAY update the order's consent state, and only when the request is recognised as the buyer's.

#### Scenario: Existing attribution is preserved
- **WHEN** a live grant is synchronised onto an order that already carries a visitor identifier and attribution
- **THEN** those values are left unchanged and the purchase event reports the buyer's original attribution

#### Scenario: Missing identifier is filled
- **WHEN** the buyer grants on the order-received page and the order carries no visitor identifier
- **THEN** the identifier from that request is persisted with the order

### Requirement: A purchase is delivered at most once per order
The plugin SHALL ensure that at most one purchase event is POSTed for an order, including when the order-received page and a status-change request run concurrently. The plugin SHALL claim delivery on the order before POSTing rather than after, using a mechanism that cannot be held by two requests at once. A claim SHALL be treated as abandoned after an interval of five minutes or ten times the configured request timeout, whichever is longer, and the plugin SHALL delete an abandoned claim when it encounters one. The plugin SHALL NOT record delivery when the transport reports an error for that POST. The purchase `event_id` SHALL be derived from the order id together with the event name.

#### Scenario: Thank-you page and status webhook race
- **WHEN** the order-received request and a payment-gateway status change for the same order run at the same time and neither has yet delivered the purchase
- **THEN** exactly one purchase event is POSTed for that order

#### Scenario: Claim abandoned by a crashed request
- **WHEN** a request takes the claim for an order and dies before recording an outcome
- **THEN** a hook running for that order after the abandonment interval removes the stale claim, takes its own, and delivers the purchase

#### Scenario: Site has raised the request timeout
- **WHEN** the configured request timeout has been raised beyond thirty seconds
- **THEN** the abandonment interval grows with it, so a slow request cannot have its claim taken over while it is still running

#### Scenario: Transport reports an error
- **WHEN** the POST for a purchase event fails at the transport level, such as a refused connection or an unresolvable host
- **THEN** the order does not record delivery, and a later hook for that order may send the purchase again

#### Scenario: Purchase event carries an order-derived identifier
- **WHEN** a purchase event is POSTed for an order
- **THEN** its `event_id` is derived from that order's id and the event name, so two rows for the same order and event are recognisable as the same event

#### Scenario: Purchase skipped for a private client IP
- **WHEN** a purchase is not sent because the client IP of that request is private or reserved
- **THEN** the order records neither delivery nor a blocked marker, and a later request that resolves a public client IP still POSTs the purchase event for that order

### Requirement: A blocked purchase is reported once, after a settling delay
The plugin SHALL NOT POST a `blocked_events` row for a Purchase at the moment the send is skipped. It SHALL record the reason on the order and schedule the report for 30 minutes later. A grant recognised as the buyer's arriving before the report is sent SHALL cancel the scheduled report and send the purchase event instead. The plugin SHALL also send a report that is already due from any later purchase hook that runs for the order, so that a site whose scheduler never runs still reports. Once the report has been sent the order SHALL be closed: no purchase event SHALL be sent for it and no further `blocked_events` row SHALL be reported. Exactly one of the two SHALL reach the API for any order.

#### Scenario: Decline reversed inside the window
- **WHEN** a purchase is skipped because the resolved decision is `denied`, and the buyer grants on the order-received page twenty minutes later
- **THEN** the purchase event is POSTed and no `blocked_events` row is sent for that order

#### Scenario: Decline stands
- **WHEN** a purchase is skipped because the resolved decision is `denied` and no grant arrives
- **THEN** exactly one `blocked_events` row is POSTed for that order after the delay

#### Scenario: Scheduler never runs
- **WHEN** the report for a blocked order is overdue because the site's scheduler is disabled, and a purchase hook runs for that order
- **THEN** that hook sends the report itself, exactly once

#### Scenario: Several status changes while the report is pending
- **WHEN** a blocked order moves through further status changes and its order-received page is reloaded before the report is due
- **THEN** the report is still sent exactly once and is not rescheduled

#### Scenario: Grant after the report has been sent
- **WHEN** a grant recognised as the buyer's arrives for an order whose `blocked_events` row has already been sent
- **THEN** no purchase event is POSTed for that order

#### Scenario: Held purchase granted inside the window
- **WHEN** a purchase is skipped because the order was placed under an unanswered banner, and the buyer grants before the report is sent
- **THEN** the purchase event is POSTed and no `blocked_events` row is sent for that order

### Requirement: Held-queue signalling survives full-page caching
The plugin SHALL make the presence of a held-event queue and the credentials needed to flush it available to the storefront script from the server on every page view, in a form that a full-page cache cannot serve stale, and the script SHALL treat that server answer as authoritative. A page whose cached HTML has outlived those credentials SHALL still be able to flush the queue. The script SHALL flush on the consent-change signal of the WP Consent API as well as on its own polling interval, so that a decision made on the page is acted on without waiting for the next tick.

#### Scenario: Page served from a cache older than the flush credentials
- **WHEN** the storefront page is served from a full-page cache and the shopper grants consent on that page
- **THEN** the queued events are flushed on that same page rather than waiting for a later navigation

#### Scenario: Consent granted on the page
- **WHEN** the shopper answers the banner and the consent management platform announces the change through the WP Consent API
- **THEN** the queue is flushed on that signal rather than on the next polling tick

#### Scenario: Banner announces nothing
- **WHEN** the consent management platform changes the consent state without announcing it
- **THEN** the polling interval still detects the change and flushes the queue

#### Scenario: Queue created after output has started
- **WHEN** an event is queued during a request in which response headers have already been sent, so the queue cookie could not be written
- **THEN** the storefront script on the next page view still learns from the server that a queue exists and flushes it once consent is granted

#### Scenario: Flush is refused
- **WHEN** a flush request is refused by the server
- **THEN** the script retries on its existing backoff, doubling the delay from 2s to a 16s ceiling, and stops retrying for the lifetime of the page after the refusal that occurs at that ceiling — five consecutive refusals in total

## MODIFIED Requirements

### Requirement: Consent resolution order
The plugin SHALL resolve the consent decision for an event from, in order: the WP Consent API, the consent decision persisted with the order, and the consent-state cookie present in the current request. Each source SHALL be consulted only when the preceding source cannot supply a decision. For an order-scoped event, the WP Consent API and the consent-state cookie of the current request SHALL be consulted only when the request is recognised as the buyer's.

#### Scenario: A consent management platform answers for a present visitor
- **WHEN** the request carries the visitor's valid consent-state cookie and a consent management platform is active
- **THEN** the block's `state` reflects the platform's current marketing-consent answer, so a decision the visitor has just revoked or granted is honoured

#### Scenario: The request carries no visitor consent data
- **WHEN** no valid consent-state cookie is present in the request
- **THEN** the WP Consent API is not treated as authoritative for that event and resolution continues to the remaining sources

#### Scenario: No consent management platform is installed
- **WHEN** no consent management platform is registered with the WP Consent API
- **THEN** the decision is taken from the persisted or live consent-state cookie

#### Scenario: Order-scoped event in a request that is not the buyer's
- **WHEN** an order-scoped event is resolved in a request that is not recognised as the buyer's
- **THEN** the decision persisted with the order is used and the current request's consent sources are not consulted

### Requirement: Consent for purchase events sent outside the visitor's request
The plugin SHALL persist the visitor's consent-state cookie with the order when the order is created, and SHALL use that persisted decision for a purchase event sent in a request that is not recognised as the buyer's or that carries no visitor consent data.

#### Scenario: Order status changes in a background request
- **WHEN** a purchase event is sent from a cron run, an administrator's order-status change, or a payment-gateway callback, and the order carries a persisted consent decision
- **THEN** the event carries that persisted decision rather than a decision inferred from the cookies of the current request

#### Scenario: Purchase event sent from the buyer's own request
- **WHEN** a purchase event is sent while a decision is present in a request recognised as the buyer's
- **THEN** that decision resolves the order and is persisted with it

#### Scenario: No consent decision was ever recorded for the order
- **WHEN** a purchase event is sent in a background request and no consent decision was persisted with the order
- **THEN** the event is sent with no consent block

### Requirement: Hold and deny skip server-side sends
The plugin SHALL NOT POST a server-side event when the session hold cookie `_pf_no_consent_decision` is the literal value `true` (live on the request, or persisted on the order for Purchase), or when the resolved consent decision is `denied`. When neither a hold nor a denied decision is present the event SHALL still be sent.

#### Scenario: Opt-in banner still unanswered
- **WHEN** the request carries `_pf_no_consent_decision=true`
- **THEN** the plugin does not POST the event

#### Scenario: Visitor declined
- **WHEN** the resolved consent decision for the event is `denied`
- **THEN** the plugin does not POST the event

#### Scenario: Visitor accepted
- **WHEN** the resolved consent decision is `granted` and the hold cookie is absent
- **THEN** the event is POSTed with the consent block attached

#### Scenario: No banner and no hold cookie
- **WHEN** neither `_pf_no_consent_decision=true` nor a denied consent decision is present
- **THEN** the event is POSTed (no-banner / script not loaded), with a consent block only if a decision is knowable

#### Scenario: Purchase after a held checkout
- **WHEN** a purchase event is sent from a request that is not the buyer's and the order persisted `_pf_no_consent_decision=true`
- **THEN** the plugin does not POST the purchase event

#### Scenario: Live grant after a held checkout
- **WHEN** a purchase hook runs on a request recognised as the buyer's whose live consent decision is `granted`, even if the order persisted `_pf_no_consent_decision=true`
- **THEN** the plugin POSTs the purchase event, records delivery on the order, and persists the grant and the buyer's identifier on the order without replacing identity data already stored there

#### Scenario: Cookie-less Purchase with no snapshot
- **WHEN** a purchase event is sent from a background request and the order has neither a hold cookie nor a denied consent decision
- **THEN** the event is POSTed

### Requirement: Storefront holds wait for a decision
The plugin SHALL NOT POST `/blocked-events` at the moment it skips AddToCart or InitiateCheckout because of a live hold. It SHALL store a compact recipe in the WooCommerce session (event name, product ids, quantity, every line item of the event, original event time, value, currency, hashed customer data when present). The queue SHALL hold at most 20 recipes per shopper, replacing the oldest first, and that limit SHALL be adjustable through a filter. A recipe SHALL carry all of its line items, with no cap. On grant the plugin SHALL POST `/event` with the payload rebuilt from the stored recipe alone, carrying the original event time, value and line items, and SHALL NOT substitute the state of the cart or catalogue at flush time. On deny it SHALL POST `/blocked-events` with `reason` `denied`. When the hold cookie is gone and no grant or deny is present it SHALL POST `/blocked-events` with `reason` `no_decision` and clear the queue.

#### Scenario: Unanswered banner then accept
- **WHEN** AddToCart or InitiateCheckout is skipped because `_pf_no_consent_decision=true`, and the visitor later grants
- **THEN** the plugin POSTs `/event` for the held recipe with its original `eventTime`, stored value, stored line items, and stored hashed customer data, and does not POST `/blocked-events` for that event

#### Scenario: Cart changes before the visitor answers
- **WHEN** a held InitiateCheckout is flushed after the shopper has added further items to the cart
- **THEN** the flushed event reports the value, line items and item count recorded when the event was held, so its value and contents describe the same moment

#### Scenario: Large cart held and replayed
- **WHEN** a held InitiateCheckout was queued from a cart of three hundred lines
- **THEN** the flushed event reports every one of those lines

#### Scenario: Queue limit raised by a site
- **WHEN** a site raises the recipe limit through the filter and a shopper queues more than twenty events before answering
- **THEN** the queue keeps up to the raised limit before it starts replacing the oldest recipe

#### Scenario: Cart emptied or product removed before the flush
- **WHEN** a held event is flushed after the cart was emptied or the product was deleted from the catalogue
- **THEN** the event is still POSTed from the stored recipe with its original value and line items

#### Scenario: Unanswered banner then decline
- **WHEN** AddToCart or InitiateCheckout is queued on a live hold, and the visitor later denies
- **THEN** the plugin POSTs `/blocked-events` with `reason` `denied` for each queued event type and does not POST `/event`

#### Scenario: Hold cookie gone without a decision
- **WHEN** queued recipes remain after `_pf_no_consent_decision` is absent and no grant or deny is knowable
- **THEN** the plugin POSTs `/blocked-events` with `reason` `no_decision` and clears the queue

#### Scenario: Purchase during a hold
- **WHEN** a Purchase is skipped because of a live or persisted hold
- **THEN** no recipe is stored and the blocked report follows the delayed once-per-order rule rather than being POSTed immediately

### Requirement: Skipped sends report anonymous blocked events
The plugin SHALL POST an anonymous `blocked_events` payload to `/blocked-events` when it skips a server-side send for a bot user agent, a denied consent decision, a Purchase that was skipped and not resolved within its reporting window, or a storefront hold queue that ends without a grant. The payload SHALL contain `siteId`, `blocked` rows (`eventType`, `reason`, optional `detail` on bot, optional `consentSource` on denied and no_decision), and `client_ip_address` when the client IP is public — the API uses the address to determine whether the visitor is in an opt-in or an opt-out region, and neither stores nor forwards it. The payload SHALL carry no other visitor data. The plugin SHALL NOT beacon for a private or reserved IP skip, for GPC, or when the event is sent.

#### Scenario: Unanswered opt-in banner
- **WHEN** the plugin skips AddToCart or InitiateCheckout because `_pf_no_consent_decision` is the literal value `true`
- **THEN** it does not POST `/blocked-events` on that request; it queues a recipe instead

#### Scenario: Hold with no Woo session
- **WHEN** AddToCart or InitiateCheckout should queue but the WooCommerce session is unavailable
- **THEN** the plugin POSTs `/blocked-events` with `reason` `no_decision` instead of dropping the event silently

#### Scenario: Visitor declined
- **WHEN** the plugin skips a storefront send because the resolved consent decision is `denied`
- **THEN** it POSTs `/blocked-events` with `reason` `denied` and `consentSource` from the resolved decision when that source is allow-listed

#### Scenario: Bot user agent
- **WHEN** the client user agent matches a bot pattern
- **THEN** the plugin skips the event POST and POSTs `/blocked-events` with `reason` `bot` and `detail` set to the matched pattern, not the raw user agent

#### Scenario: Bot wins over hold or deny
- **WHEN** the request is both a bot and a consent hold or deny
- **THEN** the blocked row reason is `bot`

#### Scenario: Private IP and cookie-less Purchase
- **WHEN** the plugin skips because the client IP is private, or sends a cookie-less Purchase with no hold or deny snapshot
- **THEN** it does not POST `/blocked-events`
