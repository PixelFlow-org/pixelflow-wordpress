## Purpose

Defines how the plugin announces itself to consent management platforms and how it determines the visitor's marketing-consent decision for the events it sends server-side, so that WooCommerce events carry the same consent signal a browser event would carry for the same visitor.

## ADDED Requirements

### Requirement: WP Consent API consumer registration
The plugin SHALL register itself as a WP Consent API consumer on every request, early enough for a consent management platform to recognise it, and SHALL declare its consent-state cookie to the WP Consent API cookie register.

#### Scenario: Consumer registration happens on a normal page load
- **WHEN** WordPress loads the plugin on any front-end or admin request
- **THEN** the plugin is registered as a WP Consent API consumer before consent management platforms query the register

#### Scenario: Cookie disclosure carries no visitor identifier
- **WHEN** the plugin declares its consent-state cookie to the WP Consent API
- **THEN** the declaration names the cookie's marketing purpose, its lifetime, and states that it stores no visitor identifier

#### Scenario: Registration emits no notices
- **WHEN** the plugin registers on a WordPress version that reports early translation loading
- **THEN** no `_doing_it_wrong` notice is produced by the registration

### Requirement: Consent block attached to server-side events
The plugin SHALL attach an optional consent block of `state`, `source` and `timestamp` to a server-side event when, and only when, the visitor's marketing-consent decision is knowable for that event. When no decision is knowable the event SHALL be sent with no consent block rather than with a fabricated decision.

#### Scenario: Decision is knowable
- **WHEN** the plugin resolves a marketing-consent decision for an event
- **THEN** the event payload carries a consent block whose `state` is `granted` or `denied` and whose `source` is one of the values accepted by the ingest contract

#### Scenario: No decision is knowable
- **WHEN** neither a consent management platform nor a stored consent decision can supply a decision for the event
- **THEN** the event is sent with no consent block, matching what the browser tracking script sends in the same situation

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

### Requirement: Rejection of untrustworthy consent cookies
The plugin SHALL treat a consent-state cookie as absent unless it decodes to a decision with a recognised state, a recognised source, a numeric decision time and a numeric policy version, and SHALL reject any consent-state cookie that carries a visitor identifier.

#### Scenario: Malformed or tampered cookie
- **WHEN** the consent-state cookie is not decodable, or carries an unrecognised state or source
- **THEN** it supplies no decision and resolution continues as though the cookie were absent

#### Scenario: Cookie carrying a visitor identifier
- **WHEN** the decoded consent-state cookie contains a visitor-identifying field
- **THEN** the whole cookie is rejected and no consent block is derived from it
