## Purpose

Lets a WooCommerce merchant send Purchase only for a customer's first paid order, so a
subscription renewal or any later order of the same customer does not reach the ad platforms
as another conversion.

## ADDED Requirements

### Requirement: The first-purchase setting is off by default

The WooCommerce settings SHALL offer "Only the customer's first purchase"
(`woo_purchase_first_only`) in the Purchase block, directly under "Enable Purchase event for
free products". It SHALL default to off, including for a site upgrading from an earlier
version, and while it is off Purchase SHALL be sent exactly as before this change, with no
order lookup and nothing written to the order for this feature. The only exception is an
order already recorded as skipped while the setting was on (see "The decision is taken once
per order").

#### Scenario: Upgrade with no saved value
- **WHEN** a site upgrades and its saved settings have no `woo_purchase_first_only` key
- **THEN** the setting reads as off and every order sends Purchase as it did before

#### Scenario: Setting off
- **WHEN** the setting is off and a customer with an earlier paid order places a second order
- **THEN** the second order sends Purchase, and the order has no first-purchase meta

#### Scenario: Purchase disabled
- **WHEN** Purchase itself is disabled (`woo_disable_purchase` is 1) and the setting is on
- **THEN** no Purchase is sent, no order lookup runs, and the first-purchase controls are shown
  disabled with their saved values kept

### Requirement: Lookback and free-order controls

Under the setting the plugin SHALL offer a lookback and an "Ignore previous free orders"
control. The lookback (`woo_purchase_first_only_lookback`) SHALL be either `all` (every
earlier order counts) or `days` (only orders created within the last N days count, where N is
`woo_purchase_first_only_days`). N SHALL be a whole number of 1 or more, written, after
surrounding whitespace is trimmed, with the digits 0-9 only (`007` reads as 7; `7.0`, `1e3`,
`-5` and `7.5` are invalid). The settings panel
SHALL NOT save an empty, non-integer or smaller N: it SHALL mark the field invalid with the
message "Enter a whole number of days, 1 or more" and send nothing. If such a value reaches the
server anyway, the server SHALL keep the previously saved lookback and N and save the other
settings. With lookback `all`, an invalid N SHALL be replaced by the stored N, or 60 when none
is stored, so switching to `all` and back keeps the configured N. N has no upper bound: any whole
number of 1 or more is accepted (stored capped at the largest integer the server holds), and a
window reaching back before 1970 counts every order, as lookback `all` does. A save
that omits the lookback or N, or carries a lookback other than `all` or `days`, SHALL keep the
stored lookback and N (`all` and 60 when nothing is stored). An order created exactly N days ago SHALL count; one created more than N days
ago SHALL NOT. "Ignore previous free orders" (`woo_purchase_first_only_ignore_free`) SHALL
default to on. Lookback SHALL default to `all` and N to 60.

#### Scenario: Defaults when the setting is first turned on
- **WHEN** a merchant turns the setting on for the first time
- **THEN** lookback is `all`, N is 60, and "Ignore previous free orders" is on

#### Scenario: Invalid N with lookback all
- **WHEN** the stored N is 90 and a save request carries lookback `all` with N empty
- **THEN** lookback `all` and N 90 are stored

#### Scenario: Lookback kept when absent or unknown
- **WHEN** the stored lookback is `days` with N 90, and a save request either omits both keys
  or carries lookback `weekly`
- **THEN** lookback `days` and N 90 stay stored

#### Scenario: Ignore-free kept when absent from a save
- **WHEN** "Ignore previous free orders" is on and a save request does not include its key
- **THEN** it stays on; with nothing stored yet, it is saved as on

#### Scenario: Invalid day count in the panel
- **WHEN** a merchant enters N as empty, `0`, `-5`, `7.5`, `7.0` or `1e3` and leaves the field
- **THEN** the field is marked invalid with "Enter a whole number of days, 1 or more", no save
  request is sent, and the previously saved lookback and N stay in effect

#### Scenario: Invalid day count reaching the server
- **WHEN** a save request carries lookback `days` with N `0` together with another changed
  setting
- **THEN** the stored lookback and N are kept and the other setting is saved

#### Scenario: Window boundary
- **WHEN** lookback is `days` with N = 60, and the customer's only other paid order was
  created exactly 60 days ago
- **THEN** that order counts and the current order does not send Purchase

#### Scenario: Outside the window
- **WHEN** lookback is `days` with N = 60, and the customer's only other paid order was
  created 61 days ago
- **THEN** the current order sends Purchase

### Requirement: Same customer

With the setting on, two orders SHALL belong to the same customer when their user ids match
(user id greater than 0) or their billing emails match, compared case-insensitively and
ignoring surrounding whitespace. A match on either one SHALL be enough. A billing email that
is not a valid email address SHALL be ignored, so the order is matched by its user id alone.
An order with no user id and no valid billing email SHALL be sent and recorded `first`, and,
when the WooCommerce debug log is enabled, the log SHALL record that the check had no customer
to look up.

#### Scenario: Guest orders with the same email
- **WHEN** a guest order with billing email `buyer@example.test` was paid, and a later guest
  order uses ` Buyer@Example.test `
- **THEN** the later order is treated as the same customer and does not send Purchase

#### Scenario: Same user, no email on the later order
- **WHEN** a logged-in customer (user id 7) has a paid order, and a later order for user id 7
  has an empty billing email
- **THEN** the later order does not send Purchase

#### Scenario: Invalid email keeps the user-id match
- **WHEN** an order for user id 7 has the billing email `n/a`, and user id 7 has another paid
  order
- **THEN** the order does not send Purchase

#### Scenario: No identity
- **WHEN** an order has user id 0 and an empty billing email
- **THEN** it sends Purchase and, with the WooCommerce debug log enabled, the log records that
  the first-purchase check was skipped for lack of a customer

### Requirement: Which other orders count as a previous purchase

With the setting on, an order SHALL NOT send Purchase when the same customer has another
order, other than the order being sent, that is in status processing, completed or refunded
and was created inside the lookback. Orders in any other status (pending, on-hold, failed,
cancelled, draft) SHALL NOT count. The other order SHALL count whether it was created before
or after the order being sent. If any order already has the recorded decision
`skipped:<id of the order being sent>`, other orders were suppressed because of this one, so
it SHALL be recorded `first` and sent without looking at other orders. With
"Ignore previous free orders" on, an order whose amount paid is 0 SHALL NOT count; with it off,
it SHALL count.

#### Scenario: Subscription renewal
- **WHEN** a customer's checkout order was paid and a renewal order for the same customer
  reaches processing
- **THEN** the renewal does not send Purchase

#### Scenario: Order still on hold does not count
- **WHEN** the customer's only other order is on-hold, pending, failed or cancelled
- **THEN** the current order sends Purchase

#### Scenario: Refunded order counts
- **WHEN** the customer's only other order was paid and later refunded
- **THEN** the current order does not send Purchase

#### Scenario: Another customer's order does not count
- **WHEN** the setting is on and the only other paid order in the store belongs to a different
  user id and a different billing email
- **THEN** the current order sends Purchase

#### Scenario: Later order counts
- **WHEN** order A is created on Monday and stays pending without reaching the thank-you page
  (e.g. a phone order entered by staff), order B of the same customer is paid on Tuesday and
  sends Purchase, and staff mark A completed on Thursday
- **THEN** A does not send Purchase, because B counts even though it was created later

#### Scenario: Unpaid order already sent from the thank-you page
- **WHEN** order A waits on-hold for a bank transfer and sends Purchase from the thank-you page
  on Monday, order B of the same customer is paid by card on Tuesday, and A is paid on Thursday
- **THEN** B sends Purchase on Tuesday, because an on-hold order does not count, and A sends
  nothing more on Thursday, because its Purchase was already sent on Monday

#### Scenario: Order that sent no Purchase still counts
- **WHEN** the customer's only other order is completed with total 50 but contains only a SKU
  listed in the excluded SKUs, so it never sent Purchase
- **THEN** it counts, and the current order does not send Purchase

#### Scenario: No mutual suppression
- **WHEN** paid order X has no recorded decision because its Purchase was held for consent
  while the setting was off, the merchant turns the setting on, order Y of the same customer
  is recorded `skipped:X`, and X later moves to completed with consent granted
- **THEN** X is recorded `first` because Y names it, X sends Purchase, and Y still sends
  nothing

#### Scenario: No suppression through a chain
- **WHEN** paid order X has no recorded decision (held for consent while the setting was off),
  the setting is turned on, order Y is recorded `skipped:X`, a newer order Z is recorded
  `skipped:Y`, and X later moves to completed with consent granted
- **THEN** X is recorded `first` and sends Purchase, and Y and Z still send nothing

#### Scenario: Renewals suppressed because of the original order still count
- **WHEN** lookback is `days` with N = 60, the original order is 90 days old, and the
  customer's renewal from 30 days ago is recorded `skipped:<original order id>`
- **THEN** that renewal counts for the next renewal, which does not send Purchase

#### Scenario: Free trial ignored
- **WHEN** "Ignore previous free orders" is on and the customer's only other order is a paid
  status order with amount paid 0
- **THEN** the current order sends Purchase

#### Scenario: Free trial counted
- **WHEN** "Ignore previous free orders" is off and the customer's only other order is a paid
  status order with amount paid 0
- **THEN** the current order does not send Purchase

### Requirement: Amount paid of another order

The amount paid of another order SHALL be the value returned by the
`pixelflow_order_amount_paid` filter when the filter returns a number or a numeric string
(a value above 0 is paid, 0 or below is free; `null`, `false` or any other value is ignored
and the default below applies); otherwise the order
total when it is greater than 0; otherwise the order's `_real_total` meta when that is greater
than 0; otherwise 0. The filter SHALL receive the default amount and the order. The amount
paid, and the filter, SHALL be used only while "Ignore previous free orders" is on. The filter
SHALL be able to turn an order paid by total or `_real_total` into a free one, and an order
with total 0 and no positive `_real_total` (missing, empty, or 0 or less) into a paid one. Whether another order counts SHALL NOT
depend on how many other orders the customer has, except where the filter decides. The
plugin SHALL ask the filter about at most 20 orders paid by total or `_real_total` (newest
first) and, separately, at most 20 orders with total 0 and no positive `_real_total` (newest
first);
orders beyond either limit SHALL NOT count.

#### Scenario: Frisbii zeroed total
- **WHEN** another order has total 0 and `_real_total` 29, and "Ignore previous free orders"
  is on
- **THEN** that order counts as paid and the current order does not send Purchase

#### Scenario: Filter overrides
- **WHEN** a site's `pixelflow_order_amount_paid` filter returns 0 for an order whose total
  is 40, and "Ignore previous free orders" is on
- **THEN** that order counts as free and does not block the current order

#### Scenario: Filter turns a zero-total order into a paid one
- **WHEN** "Ignore previous free orders" is on, the customer's only other order has total 0 and
  no `_real_total`, and the site's filter returns 15 for it
- **THEN** that order counts and the current order does not send Purchase

#### Scenario: Filter value forms
- **WHEN** "Ignore previous free orders" is on, the customer's only other order has total 40,
  and the filter returns `"0"`, `-5`, `null` or `"n/a"` for it
- **THEN** with `"0"` or `-5` the order counts as free and the current order sends Purchase;
  with `null` or `"n/a"` the filter is ignored, the order counts as paid, and the current
  order does not send Purchase

#### Scenario: Filter promotes an order whose _real_total is 0
- **WHEN** "Ignore previous free orders" is on, the customer's only other order has total 0 and
  `_real_total` `"0"`, and the site's filter returns 15 for it
- **THEN** that order counts and the current order does not send Purchase

#### Scenario: Long free history
- **WHEN** "Ignore previous free orders" is on and the customer has 25 paid-status orders with
  amount paid 0, all newer than one order with total 40
- **THEN** the order with total 40 counts and the current order does not send Purchase

#### Scenario: Filter marks more than 20 orders free
- **WHEN** "Ignore previous free orders" is on, the customer has 21 orders with total 40, and
  the site's filter returns 0 for every one of them
- **THEN** the current order sends Purchase

### Requirement: A suppressed order sends and reports nothing

When an order does not send Purchase because of this setting, the plugin SHALL NOT send the
event, SHALL NOT send a `/blocked-events` report for it, and SHALL NOT mark it as sent
(`_pf_purchase_sent`). When the WooCommerce debug log is enabled (`woo_debug_enabled`), it SHALL
record the suppression with the order id and the id of the order that matched. AddToCart, InitiateCheckout, and the free-products and excluded-SKU
rules for the order being sent SHALL behave as without this setting.

#### Scenario: Suppressed renewal
- **WHEN** a renewal order is suppressed
- **THEN** no `/event` and no `/blocked-events` request is made for it, `_pf_purchase_sent` is
  not set, and, with the WooCommerce debug log enabled, the log has a first-purchase skip entry
  naming both order ids

#### Scenario: Other events unaffected
- **WHEN** the setting is on and a returning customer adds to cart and reaches checkout
- **THEN** AddToCart and InitiateCheckout are sent as without the setting

### Requirement: The decision is taken once per order

With the setting on, the first time an order reaches the point where Purchase would be sent,
the plugin SHALL record its decision on the order in `_pf_purchase_first_only`: `first` when
no other order counts, or `skipped:<id>` naming the order that counted. Every later attempt
to send Purchase for that order (another status change, the thank-you page, a send after the
buyer's consent decision) SHALL follow the recorded decision without looking up orders again,
even if other orders or the settings have changed since. An order without a recorded decision
SHALL be evaluated when it is next processed.

#### Scenario: Skipped order completes later
- **WHEN** a renewal was recorded `skipped:<id>` at processing, the matching order is then
  cancelled, and the renewal moves to completed
- **THEN** the renewal still does not send Purchase

#### Scenario: First order held for consent
- **WHEN** a customer's first order A is recorded `first` while its Purchase waits for the
  buyer's consent decision, order B of the same customer is then suppressed because of A, and
  the buyer later grants consent and A moves to completed
- **THEN** A sends Purchase, and B still sends nothing

#### Scenario: Setting turned off after a skip
- **WHEN** an order was recorded `skipped:<id>` and the merchant then turns the setting off
  before the order moves to completed
- **THEN** the order still does not send Purchase
