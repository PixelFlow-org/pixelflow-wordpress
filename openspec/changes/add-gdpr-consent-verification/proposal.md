## Why

`fix-consent-gating-review` ships the GDPR consent gating, and its PHP unit tests
cover the decision logic well — ownership, the purchase claim, blocked reporting,
line filtering, recipe replay. What none of them cover is the pipeline the shopper
actually walks: a real consent banner, a real WooCommerce session, a real cached
storefront page, and the plugin's debug log as the record of what left the site.

The live suite under `e2e/live/` is the only place that exercises that pipeline, and
it has ten specs and not one consent scenario. Worse, until now the test site had
no consent plugin at all, so every live run happened with `wp_get_consent_type()`
empty — the branch where the plugin sends everything unconditionally. Task 7.2 of
`fix-consent-gating-review` ("run the live verification") therefore cannot say
anything about the feature it is meant to verify.

The unit layer is in better shape: `tests/test-purchase-once.php` already covers the
claim race, the abandoned claim, the raised request timeout, the overdue report with
the scheduler disabled and the transport failure, and
`app/source/src/test/held-events.test.ts` already covers the consent-change flush, the
server-minted nonce, the cookie-less queue and the refusal backoff up to its ceiling.
Two holes remain there, both on the grant side of the reporting window: a grant that
arrives inside the window is only proven to cancel the schedule, never to deliver the
purchase, and a grant arriving after the report has already fired is not exercised at
all. On the storefront, a CMP that changes state without emitting a signal is likewise
untested.

## What Changes

**Test site prerequisite (already applied)**

- `rift.kskonovalov.me` now runs WP Consent API 2.0.1 and Complianz 7.5.4, configured
  for the EU region so `wp_get_consent_type()` returns `optin` and the banner renders
  in opt-in mode with a `cmplz_marketing` category. This is a standing property of the
  test site, not something a test run installs.
- **BREAKING for the existing live suite**: with an opt-in banner present, every
  existing live spec now starts from an undecided consent state and would hold its
  events. The suite gains a consent baseline that accepts the banner before the
  non-consent scenarios run, so `add-to-cart-product`, `add-to-cart-shop`,
  `initiate-checkout`, `purchase`, `freebies`, `excluded-sku`, `user-location`,
  `value-consistency` and `integration-disabled` keep asserting what they assert today.
  `deploy.spec.ts` is unaffected: it imports `@playwright/test` directly rather than the
  suite's own `test`, so no fixture reaches it, and it never visits the storefront.

**Live GDPR scenarios (`e2e/live/tests/consent-*.spec.ts`)**

- A consent page object driving the Complianz banner: accept, deny, withdraw through
  the preferences dialog, and reading the resulting `cmplz_*` cookies and the plugin's
  `_pf_no_consent_decision` / `_pf_held_woo_events` cookies.
- Hold → accept: AddToCart and InitiateCheckout performed while the banner is
  unanswered log nothing, the hold queue is created in the Woo session, and accepting
  the banner flushes them with the value and contents captured at hold time — verified
  by changing the cart between the hold and the grant.
- Decline: AddToCart, InitiateCheckout and Purchase performed after a decline log no
  real event and exactly one anonymous `blocked_events` row each, and the row carries
  no visitor identifiers.
- Purchase under a decline: the order carries the blocked marker and no purchase, and
  the report is scheduled rather than sent; a grant on the thank-you page inside the
  window cancels the report and sends the purchase, and the order ends with exactly
  one unit of traffic either way.
- Ownership: an order-received URL opened from a clean browser context that carries a
  granted banner changes nothing about a declined order; an admin status change made
  from the wp-admin session sends nothing for an order the buyer declined; the buyer's
  own withdrawal on the order-pay page blocks a purchase that a later status change
  would otherwise send.
- Delivery once: an order driven through thank-you and then through two status changes
  produces exactly one purchase record in the debug log, carrying an `event_id` derived
  from the order id.

**Unit coverage for what the live suite cannot reach (`tests/`)**

Most of this layer already exists and is green; the change adds the two grant-side
cases it is missing and verifies the rest rather than rewriting it.

- A grant from an owning request inside the reporting window delivers the purchase, not
  merely the cancellation of the schedule that the existing case asserts.
- A grant arriving after the blocked report has already been sent sends nothing.
- Already covered, verified rather than rewritten: the reporting window and its repeated
  hooks, the overdue report with the scheduler disabled, the claim race, the `WP_Error`
  transport result, the abandoned claim and the raised `pixelflow_request_timeout`.

**Storefront script coverage (`app/source/src/test/held-events.test.ts`)**

- One case is missing and gets added: a consent grant that emits no consent-change
  signal is still caught by the 10s polling timer. The consent-change flush, the
  server-minted nonce, the cookie-less queue and the 16s backoff ceiling are already
  asserted there and are only verified.

## Capabilities

### New Capabilities

- `consent-verification`: what the project's test suites must prove about GDPR consent
  gating, at which layer each guarantee is proven, and what the test site must provide
  for the live layer to mean anything.

### Modified Capabilities

<!-- None. `consent-resolution` states the behaviour under test and is unchanged by
     this change; `live-event-verification` is not yet in openspec/specs. -->

## Impact

- `e2e/live/pages/consent-banner.ts` — new page object for the Complianz banner.
- `e2e/live/presets.ts`, `e2e/live/fixtures.ts` — a `consent` fixture
  (`'granted' | 'undecided' | 'declined'`) alongside the settings state, and
  per-scenario browser contexts for the stranger and admin cases.
- `e2e/live/tests/consent-hold.spec.ts`, `consent-blocked.spec.ts`,
  `consent-ownership.spec.ts`, `consent-purchase-once.spec.ts` — new specs.
- The nine storefront-visiting specs under `e2e/live/tests/` — the granted consent
  baseline, applied by the fixture rather than by a line in each spec.
- `e2e/live/helpers/debug-log.ts` — reading `blocked_events` records and order meta.
- `tests/test-purchase-once.php` — the two missing grant-side cases.
- `app/source/src/test/held-events.test.ts` — the silent-grant polling case.
- `.claude/skills/test-live-woo-events/` — the run now includes the consent specs and
  states the CMP prerequisite.
- No plugin source change, no database migration, no version bump.
