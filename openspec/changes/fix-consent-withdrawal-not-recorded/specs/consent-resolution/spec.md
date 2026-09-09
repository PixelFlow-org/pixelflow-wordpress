# Consent resolution

## ADDED Requirements

### Requirement: A buyer's consent decision reaches the order wherever it is made

The order SHALL carry the buyer's most recent consent decision, not the one that
happened to be in force while a purchase hook was running. A decision made by a
request that owns the order SHALL be recorded regardless of which page the request
was for, and SHALL replace any earlier decision recorded for that order.

#### Scenario: A withdrawal on the order-pay page stops a later staff status change

- **WHEN** a buyer with an unpaid order opens its order-pay URL and withdraws consent
- **AND** staff later move that order to a purchasing status
- **THEN** no Purchase is sent for the order
- **AND** the order is not marked as delivered

#### Scenario: A withdrawal outranks the consent recorded at checkout

- **GIVEN** an order created in the browser while consent was granted, so the order
  carries a granted snapshot
- **WHEN** the buyer withdraws consent before the order reaches a purchasing status
- **AND** the order is later moved by a request that is not the buyer's
- **THEN** the decision that governs the send is the withdrawal, not the snapshot

#### Scenario: A grant made after checkout is recorded the same way

- **GIVEN** an order whose purchase was blocked and whose blocked report is still
  pending
- **WHEN** the buyer grants consent on a storefront page that fires no purchase hook
- **AND** the order is later moved to a purchasing status
- **THEN** the Purchase is delivered rather than reported as blocked

#### Scenario: A withdrawal after delivery changes nothing

- **GIVEN** an order whose Purchase has already been sent
- **WHEN** the buyer withdraws consent afterwards
- **THEN** nothing further is sent for the order
- **AND** no blocked row is reported for it
