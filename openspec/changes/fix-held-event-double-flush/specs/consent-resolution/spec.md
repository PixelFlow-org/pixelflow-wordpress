## MODIFIED Requirements

### Requirement: Storefront holds wait for a decision
Events performed while a consent banner is unanswered SHALL be queued in the shopper's
WooCommerce session rather than sent, and SHALL be dispatched when the shopper grants
consent. Each queued event SHALL reach the API **exactly once** per grant, no matter how
many requests observe the decision — the read-only state route, the explicit flush route
and any ordinary page view that happens to run the flush hook must not each produce a copy.

#### Scenario: Held events are flushed once on a grant
- **WHEN** a shopper with queued events grants consent and the storefront script both reads
  the state route and POSTs the flush route
- **THEN** each queued event appears in the log exactly once, and no `event_id` repeats

#### Scenario: The state route reports without dispatching
- **WHEN** the storefront script asks the state route whether a queue exists
- **THEN** it is told whether one exists and given a fresh nonce, and no event is dispatched
  by that request

#### Scenario: Events wait while the banner is unanswered
- **WHEN** a shopper adds to cart or reaches checkout with the banner still unanswered
- **THEN** no event is sent and the event is queued in the shopper's session
