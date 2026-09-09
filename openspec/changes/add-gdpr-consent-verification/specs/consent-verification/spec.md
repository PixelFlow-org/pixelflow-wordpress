## Purpose

Defines what the project's test suites must prove about GDPR consent gating: which
guarantees are proven against a real consent banner on the live test site, which are
proven in isolation because they depend on a clock or a race, and what the test site
must provide for a live consent run to mean anything at all.

## ADDED Requirements

Each scenario below is tagged with the layer that proves it: `[setup]` for the live
suite's global setup, `[live]` for a Playwright spec under `e2e/live/tests/`, `[php]`
for a case in `tests/test-*.php`, and `[vitest]` for
`app/source/src/test/held-events.test.ts`. A scenario tagged with two layers is proven
at both, each asserting the half of it that layer can reach.

### Requirement: The live test site presents a real opt-in consent banner

The live verification site SHALL run a consent management platform that registers
with the WP Consent API and SHALL be configured so that `wp_get_consent_type()`
returns `optin` and the banner offers a marketing category. The live suite SHALL
verify this prerequisite before running any consent scenario and SHALL fail with a
message naming the missing or misconfigured plugin rather than reporting a passing
run against a site that consents to everything by default.

#### Scenario: Consent plugin missing or set to opt-out [setup]

- **WHEN** the live suite starts and the site reports a consent type other than `optin`
- **THEN** the run stops with an error naming the site's actual consent type, and no
  consent scenario is reported as passed

#### Scenario: Both consent plugins active [setup]

- **WHEN** the live suite starts
- **THEN** it confirms over wp-cli that WP Consent API and the CMP are both active
  before any scenario runs, and stops the run naming the inactive one otherwise

#### Scenario: Banner present and in opt-in mode [live]

- **WHEN** a storefront page is opened in a browser context with no consent cookies
- **THEN** the consent banner is visible, offers accept and deny, and no marketing
  consent is recorded until one of them is chosen

### Requirement: Existing live scenarios run from an explicitly granted state

Every live scenario that is not itself about consent SHALL establish a granted
marketing consent before it acts, so that the behaviour it asserts is not silently
altered by the banner. A scenario SHALL NOT depend on the absence of a consent
platform.

#### Scenario: Event matrix under a granted banner [live]

- **WHEN** the AddToCart, InitiateCheckout, Purchase, freebie, SKU-exclusion,
  location and value-consistency scenarios run after accepting the banner
- **THEN** each logs the events it asserted before the consent platform was installed,
  with the same payloads

### Requirement: Events performed under an unanswered banner are held and flushed on acceptance

The live suite SHALL verify that a shopper acting while the banner is unanswered
produces no event at the time of the action, that the action is retained for that
shopper's session, and that accepting the banner sends the retained events carrying
the values captured when the action happened rather than the state of the site at
flush time.

#### Scenario: AddToCart under an unanswered banner [live]

- **WHEN** a product is added to the cart with the banner unanswered
- **THEN** no AddToCart record appears in the debug log, and the shopper's session
  holds the event

#### Scenario: InitiateCheckout under an unanswered banner [live]

- **WHEN** the shopper reaches checkout while the banner is still unanswered
- **THEN** no InitiateCheckout event is logged and the event is held in the shopper's
  WooCommerce session

#### Scenario: Acceptance flushes the held events [live]

- **WHEN** the shopper accepts the banner after holding an AddToCart and an
  InitiateCheckout
- **THEN** both events are logged, each with the product and value from the moment it
  was held

#### Scenario: Cart changed between the hold and the grant [live]

- **WHEN** the shopper holds an InitiateCheckout, then changes the cart, then accepts
  the banner
- **THEN** the logged InitiateCheckout reports the cart as it stood when the event was
  held, not the changed cart

#### Scenario: Acceptance acts without waiting for the poll interval [live]

- **WHEN** the banner is accepted on a page that already carries held events
- **THEN** the events are flushed on the consent signal, without waiting out the
  script's idle interval

### Requirement: A declined shopper produces no real event and exactly one blocked row per event

The live suite SHALL verify that after a decline no tracking event is sent for the
shopper's actions, that each skipped event is reported once as an anonymous blocked
row, and that the blocked row carries no identifier that could link it to the
shopper.

#### Scenario: AddToCart after a decline [live]

- **WHEN** a product is added to the cart after the banner has been declined
- **THEN** no AddToCart event is logged, and exactly one blocked row naming that event
  is reported

#### Scenario: Blocked row carries no visitor identity [live]

- **WHEN** a blocked row is reported for a declined shopper
- **THEN** it carries no visitor identifier, no email, no phone and no attribution data

#### Scenario: Repeated actions do not multiply the report [live]

- **WHEN** the declined shopper repeats the same action several times in one session
- **THEN** one blocked row is reported per action, and no real event is logged for any
  of them

### Requirement: A declined order costs exactly one unit of traffic, whichever way the shopper finally decides

The suites SHALL verify that an order placed under a decline sends no purchase event
at checkout, that its blocked report is not emitted at the moment of the skip, that a
grant from the buyer inside the reporting window sends the purchase and no blocked
row, and that once the blocked row has been reported no purchase is sent for that
order afterwards, and that hooks running while the window is still open neither
reschedule nor duplicate the pending report. Because the window is measured in
wall-clock time, the delay, its expiry and the scheduler-disabled path SHALL be proven
with a controlled clock rather than by waiting on the live site.

#### Scenario: Purchase under a decline sends nothing at checkout [live]

- **WHEN** the shopper declines and completes checkout
- **THEN** no purchase event is logged, and no blocked row is reported at that moment

#### Scenario: Grant on the thank-you page inside the window [live, php]

- **WHEN** the buyer accepts the banner on their own order-received page after
  declining at checkout
- **THEN** the purchase event is logged for that order and no blocked row is ever
  reported for it

#### Scenario: No grant before the window expires [live, php]

- **WHEN** the reporting window passes with no grant, across several order status
  changes and page reloads
- **THEN** exactly one blocked row is reported for the order and no purchase event is
  sent

#### Scenario: Repeated hooks before the window closes [php]

- **WHEN** further purchase hooks run for a blocked order while its window is still open
- **THEN** the pending report is neither rescheduled nor duplicated, and the marker's
  due time is unchanged

#### Scenario: Grant after the report has been sent [php]

- **WHEN** the buyer grants consent for an order whose blocked row has already been
  reported
- **THEN** nothing further is sent for that order

#### Scenario: Scheduler disabled [live, php]

- **WHEN** the scheduled report cannot fire and a later purchase hook runs for the
  overdue order
- **THEN** the blocked row is reported once by that hook

### Requirement: Only the buyer's own request changes what an order sends

The live suite SHALL verify, through separate browser contexts, that a consent
decision made by someone who is not the buyer has no effect on an order, and that the
buyer's own change of mind does.

#### Scenario: Order-received URL opened by a stranger [live]

- **WHEN** the order-received URL of a declined order is opened in a browser context
  that has accepted the banner and shares no identity with the buyer
- **THEN** no purchase event is sent for that order

#### Scenario: Staff status change [live]

- **WHEN** an administrator moves a declined order to a new status from the wp-admin
  session, with the administrator's own consent cookies present
- **THEN** no purchase event is sent for that order

#### Scenario: Buyer withdraws before the purchase was sent [live]

- **WHEN** the buyer withdraws consent on their own order-pay page, and staff later
  change the order status
- **THEN** no purchase event is sent for that order

### Requirement: A purchase reaches the log once per order

The suites SHALL verify that an order which passes through the thank-you page and
several status changes produces exactly one purchase record, that the record's event
identifier is derived from the order id, that a transport failure leaves the order
able to send on a later hook, and that a claim left behind by a request that never
finished does not block delivery forever, and that the interval after which a claim
counts as abandoned follows the configured request timeout. The concurrent case SHALL
be proven in
isolation, because two simultaneous hooks cannot be provoked reliably on the live
site.

#### Scenario: Thank-you page followed by status changes [live]

- **WHEN** an order is completed and then moved through processing and completed again
- **THEN** exactly one purchase record appears in the debug log for that order

#### Scenario: Event identifier derived from the order [live, php]

- **WHEN** a purchase event is logged
- **THEN** its event identifier is derived from the order id and the event name, and is
  the same on any duplicate that reaches the log

#### Scenario: Concurrent thank-you and status change [php]

- **WHEN** two hooks for the same undelivered order run at the same time
- **THEN** exactly one purchase event is sent

#### Scenario: Transport failure [php]

- **WHEN** the POST for a purchase reports a transport error
- **THEN** delivery is not recorded, and the next status change for that order sends
  the purchase

#### Scenario: Claim left behind by a request that never finished [php]

- **WHEN** a purchase hook finds a claim older than the abandonment interval
- **THEN** the stale claim is removed and the purchase is delivered

#### Scenario: Slower requests widen the abandonment interval [php]

- **WHEN** `pixelflow_request_timeout` is raised above its default
- **THEN** the interval after which a claim counts as abandoned widens with it, so a
  request that is merely slow is not treated as crashed

### Requirement: The storefront script recovers from a cached page and from a refusing endpoint

The storefront script's transport SHALL be proven against the failures a full-page
cache and an unavailable endpoint produce, in isolation from the live site.

#### Scenario: Page HTML older than the current nonce [vitest]

- **WHEN** the script acts on a page whose HTML predates the current flush nonce
- **THEN** it takes the nonce from the state route and the flush succeeds

#### Scenario: Banner that announces nothing [vitest]

- **WHEN** consent is granted by a banner that emits no consent-change signal
- **THEN** the queue is still flushed by the script's polling timer

#### Scenario: Queue created after headers were sent [vitest]

- **WHEN** a hold is recorded in a request where the queue cookie could not be written
- **THEN** the next page view still learns of the queue from the state route and
  flushes it

#### Scenario: Endpoint refuses every flush [vitest]

- **WHEN** the flush endpoint refuses repeatedly
- **THEN** the script retries on its backoff and stops calling the endpoint for the
  lifetime of the page once the backoff reaches its ceiling
