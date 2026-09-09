## ADDED Requirements

### Requirement: Purchase delivery does not depend on which kind of request moves the order
A Purchase SHALL be delivered for an order that reaches a purchasing status regardless of the
request that performed the transition. A staff status change made in wp-admin, an order the
buyer never confirmed on a thank-you page, and a transition performed by automation SHALL all
deliver the event, subject to the existing consent and single-delivery rules.

A request that is not the buyer's SHALL NOT contribute its own identity to the event: the
client IP and user agent of a staff member SHALL NOT be attributed to the buyer, and an event
SHALL be sent without those fields rather than with someone else's.

#### Scenario: Staff completes an order from wp-admin
- **WHEN** an administrator moves a consented order to a purchasing status from the wp-admin
  order screen, and the buyer never returned to the thank-you page
- **THEN** exactly one Purchase is delivered for that order

#### Scenario: Staff status change on an order with an overdue blocked report
- **WHEN** an administrator moves an order whose blocked-purchase report is overdue to a new
  status
- **THEN** exactly one blocked row is reported for that order and the order is closed to
  further traffic

#### Scenario: Declined order is still not sent by a staff change
- **WHEN** an administrator moves an order whose buyer declined consent to a purchasing status
- **THEN** no Purchase is delivered, and the order's persisted decision stands

#### Scenario: An order with no identity of its own
- **WHEN** a Purchase is delivered from a request that is not the buyer's, for an order that
  carries no client IP or user agent of its own
- **THEN** the event carries no client IP or user agent at all, rather than the requester's
