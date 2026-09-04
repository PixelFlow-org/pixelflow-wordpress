# consent-resolution Specification

## Purpose
Defines how the plugin announces itself to consent management platforms and how it determines the visitor's marketing-consent decision for the events it sends server-side, so that WooCommerce events carry the same consent signal a browser event would carry for the same visitor.
## Requirements
### Requirement: WP Consent API consumer registration
The plugin SHALL register itself as a WP Consent API consumer on every request, early enough for a consent management platform to recognise it, and SHALL declare its consent-state cookie to the WP Consent API cookie register.

#### Scenario: Consumer registration happens on a normal page load
- **WHEN** WordPress loads the plugin on any front-end or admin request
- **THEN** the plugin is registered as a WP Consent API consumer before consent management platforms query the register

#### Scenario: Cookie disclosure carries no visitor identifier
- **WHEN** the plugin declares its cookies to the WP Consent API
- **THEN** the declaration names `_pf_consent` (marketing, 183 days), `_pf_no_consent_decision` (functional, session), `_pf_consent_source` (functional, session, detected banner source only), and `_pf_held_woo_events` (functional, session, event type names only), and states that none of them store a visitor identifier

#### Scenario: Registration emits no notices
- **WHEN** the plugin registers on a WordPress version that reports early translation loading
- **THEN** no `_doing_it_wrong` notice is produced by the registration

### Requirement: Consent block attached to server-side events
The plugin SHALL attach an optional consent block of `state`, `source` and `timestamp` to a server-side event when the visitor's marketing-consent decision is knowable, or when a detected banner source is present without a grant or deny. When neither a decision nor a source is knowable the event SHALL be sent with no consent block rather than with a fabricated decision.

#### Scenario: Decision is knowable
- **WHEN** the plugin resolves a marketing-consent decision for an event
- **THEN** the event payload carries a consent block whose `state` is `granted` or `denied` and whose `source` is one of the values accepted by the ingest contract

#### Scenario: Banner detected but unanswered
- **WHEN** `_pf_consent_source` is present and no grant or deny is knowable
- **THEN** the event payload carries a consent block whose `state` is `unknown` and whose `source` is the cookie value, with no timestamp

#### Scenario: No decision is knowable
- **WHEN** neither a consent management platform, a stored consent decision, nor `_pf_consent_source` can supply a source for the event
- **THEN** the event is sent with no consent block, matching what the browser tracking script sends when no banner is detected

#### Scenario: Consent block timestamp is the moment of the decision
- **WHEN** a consent block is attached and the visitor's recorded decision time is available
- **THEN** the block's `timestamp` is the time the visitor made the decision, not the time the event was sent

### Requirement: Consent resolution order
The plugin SHALL resolve the consent decision for an event from, in order: the WP Consent API, the consent decision persisted with the order, and the consent-state cookie present in the current request. Each source SHALL be consulted only when the preceding source cannot supply a decision.

#### Scenario: A consent management platform answers for a present visitor
- **WHEN** the request carries the visitor's valid consent-state cookie and a consent management platform is active
- **THEN** the block's `state` reflects the platform's current marketing-consent answer, so a decision the visitor has just revoked or granted is honoured

#### Scenario: The request carries no visitor consent data
- **WHEN** no valid consent-state cookie is present in the request
- **THEN** the WP Consent API is not treated as authoritative for that event and resolution continues to the remaining sources

#### Scenario: No consent management platform is installed
- **WHEN** no consent management platform is registered with the WP Consent API
- **THEN** the decision is taken from the persisted or live consent-state cookie

### Requirement: Consent for purchase events sent outside the visitor's request
The plugin SHALL persist the visitor's consent-state cookie with the order when the order is created, and SHALL use that persisted decision for a purchase event sent in a request that carries no visitor consent data.

#### Scenario: Order status changes in a background request
- **WHEN** a purchase event is sent from a cron run, an administrator's order-status change, or a payment-gateway callback, and the order carries a persisted consent decision
- **THEN** the event carries that persisted decision rather than a decision inferred from the absent cookies of the current request

#### Scenario: Purchase event sent from the visitor's own request
- **WHEN** a purchase event is sent while the visitor's valid consent-state cookie is present in the request
- **THEN** the decision for that request takes precedence over the decision persisted with the order

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
- **WHEN** a purchase event is sent from a background request and the order persisted `_pf_no_consent_decision=true`
- **THEN** the plugin does not POST the purchase event

#### Scenario: Cookie-less Purchase with no snapshot
- **WHEN** a purchase event is sent from a background request and the order has neither a hold cookie nor a denied consent decision
- **THEN** the event is POSTed

### Requirement: Storefront holds wait for a decision
The plugin SHALL NOT POST `/blocked-events` at the moment it skips AddToCart or InitiateCheckout because of a live hold. It SHALL store a compact recipe in the WooCommerce session (event name, product ids, quantity, original event time, value, currency, hashed customer data when present), capped at 20 recipes. On grant it SHALL POST `/event` with the rebuilt payload and the original event time. On deny it SHALL POST `/blocked-events` with `reason` `denied`. When the hold cookie is gone and no grant or deny is present it SHALL POST `/blocked-events` with `reason` `no_decision` and clear the queue. Purchase skipped for a hold SHALL still POST `/blocked-events` immediately.

#### Scenario: Unanswered banner then accept
- **WHEN** AddToCart or InitiateCheckout is skipped because `_pf_no_consent_decision=true`, and the visitor later grants
- **THEN** the plugin POSTs `/event` for the held recipe with its original `eventTime`, stored value, and stored hashed customer data, and does not POST `/blocked-events` for that event

#### Scenario: Unanswered banner then decline
- **WHEN** AddToCart or InitiateCheckout is queued on a live hold, and the visitor later denies
- **THEN** the plugin POSTs `/blocked-events` with `reason` `denied` for each queued event type and does not POST `/event`

#### Scenario: Hold cookie gone without a decision
- **WHEN** queued recipes remain after `_pf_no_consent_decision` is absent and no grant or deny is knowable
- **THEN** the plugin POSTs `/blocked-events` with `reason` `no_decision` and clears the queue

#### Scenario: Purchase during a hold
- **WHEN** a Purchase is skipped because of a live or persisted hold
- **THEN** the plugin POSTs `/blocked-events` with `reason` `no_decision` immediately and does not store a recipe

### Requirement: Skipped sends report anonymous blocked events
The plugin SHALL POST an anonymous `blocked_events` payload to `/blocked-events` when it skips a server-side send for a bot user agent, a denied consent decision, a Purchase skipped for a hold, or a storefront hold queue that ends without a grant. The payload SHALL contain only `siteId` and `blocked` rows (`eventType`, `reason`, optional `detail` on bot, optional `consentSource` on denied and no_decision). The plugin SHALL NOT beacon for a private or reserved IP skip, for GPC, or when the event is sent.

#### Scenario: Unanswered opt-in banner
- **WHEN** the plugin skips AddToCart or InitiateCheckout because `_pf_no_consent_decision` is the literal value `true`
- **THEN** it does not POST `/blocked-events` on that request; it queues a recipe instead

#### Scenario: Visitor declined
- **WHEN** the plugin skips a send because the resolved consent decision is `denied`
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

### Requirement: Rejection of untrustworthy consent cookies
The plugin SHALL treat a consent-state cookie as absent unless it decodes to a decision with a recognised state, a recognised source, a numeric decision time and a numeric policy version, and SHALL reject any consent-state cookie that carries a visitor identifier.

#### Scenario: Malformed or tampered cookie
- **WHEN** the consent-state cookie is not decodable, or carries an unrecognised state or source
- **THEN** it supplies no decision and resolution continues as though the cookie were absent

#### Scenario: Cookie carrying a visitor identifier
- **WHEN** the decoded consent-state cookie contains a visitor-identifying field
- **THEN** the whole cookie is rejected and no consent block is derived from it
