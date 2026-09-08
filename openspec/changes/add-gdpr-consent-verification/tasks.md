## 1. Test-site prerequisite

- [x] 1.1 Install and activate WP Consent API and Complianz on the test site, configure the EU region so `wp_get_consent_type()` returns `optin` and the banner renders in opt-in mode with a marketing category (applied through wp-cli; recorded here as the state the suite depends on)
- [x] 1.2 Assert the prerequisite in `e2e/live/global-setup.ts`: both plugins active and the site's consent type equal to `optin`, failing the run with the actual value in the message and never repairing the site itself
- [x] 1.3 Record the prerequisite in the `test-live-woo-events` skill so an operator setting up a fresh test site knows what to install and why a run refuses to start without it

## 2. Consent harness for the live suite

- [x] 2.1 Add `e2e/live/pages/consent-banner.ts` exposing `accept()`, `deny()`, `withdraw()`, `isVisible()` and readers for the `cmplz_*`, `_pf_no_consent_decision`, `_pf_consent` and `_pf_held_woo_events` cookies, with every Complianz selector confined to this file
- [x] 2.2 Add a `consent` fixture to `e2e/live/fixtures.ts` taking `'granted' | 'undecided' | 'declined'` and defaulting to `'granted'`, driving the banner in the test's own browser context before the body runs: `granted` accepts, `declined` denies, `undecided` leaves it standing; consent specs opt out per file with `test.use({ consent: ... })`
- [x] 2.3 Add a helper that opens an independent browser context for a second visitor — a stranger who has accepted the banner and shares no cookies with the buyer — alongside the existing `withAdminPage`
- [x] 2.4 Add `blockedRecords(eventName)` to `e2e/live/helpers/debug-log.ts`, filtering on the record's `hook` field for `BLOCKED_EVENTS <name>`, plus an `expectNoIdentifiers()` assertion that a blocked payload carries no visitor identifier, email, phone or attribution
- [x] 2.5 Add a helper that reads and rewrites a single order's PixelFlow meta over wp-cli (`_pf_purchase_blocked`, `_pf_purchase_sent`, `_pf_purchase_blocked_reported`) and one that runs a named cron event, for the reporting-window scenarios

## 3. Existing live specs keep their baseline

- [x] 3.1 Verify each of the nine storefront-visiting specs under `e2e/live/tests/` passes with the banner present and the `consent` fixture at its `'granted'` default — `add-to-cart-product`, `add-to-cart-shop`, `initiate-checkout`, `purchase`, `freebies`, `excluded-sku`, `user-location`, `value-consistency`, `integration-disabled` — adjusting only where a scenario now needs the consent state stated explicitly
- [x] 3.2 Confirm `deploy.spec.ts` is deliberately out of scope: it imports `@playwright/test` directly rather than the suite's `test`, so no fixture reaches it, and it never visits the storefront
- [x] 3.3 Run the full live suite once end to end and confirm no scenario regressed against the pre-CMP baseline

## 4. Hold and flush scenarios



- [x] 4.1 `consent-hold.spec.ts` (`test.use({ consent: 'undecided' })`, so the fixture leaves the banner standing instead of accepting it before the body runs) starts by asserting the banner baseline the whole suite rests on: on a storefront page opened with no consent cookies the banner is visible through `isVisible()`, offers accept and deny, and no marketing consent is recorded until one is chosen
- [x] 4.2 AddToCart with the banner unanswered logs no event and leaves the hold queue cookie set
- [x] 4.3 InitiateCheckout with the banner unanswered logs no event and is held
- [x] 4.4 Accepting the banner flushes both held events, each carrying the product, value and contents captured when it was held
- [x] 4.5 Cart changed between the hold and the grant: the flushed InitiateCheckout reports the cart as it stood at hold time, not the changed cart
- [x] 4.6 The flush happens on the consent signal, measurably faster than the script's idle poll interval

## 5. Decline and blocked reporting

> 5.5 and 5.6 are **verified green**; the cause of the earlier silence is established and fixed
> in the plugin. Purchase hooks were registered below the `is_admin()` and cache-warmer guards
> in `init_hooks()`, so neither a wp-admin nor a WP-CLI status change ran any purchase logic at
> all — the run read as "the plugin did nothing" because nothing was listening. Filed as
> `openspec/changes/fix-admin-status-change-no-purchase/` and fixed there; both scenarios now
> pass against a browser-driven status change.

- [x] 5.1 `consent-blocked.spec.ts` (`test.use({ consent: 'declined' })`): AddToCart after a decline logs no event and exactly one `BLOCKED_EVENTS AddToCart` record
- [x] 5.2 The same for InitiateCheckout, and the blocked record carries no visitor identity
- [x] 5.3 Repeated actions under a decline produce one blocked row each and no real event
- [x] 5.4 A purchase completed under a decline logs no purchase and no blocked row at checkout time, and leaves the blocked marker on the order with a due time in the future
- [x] 5.5 With the marker's due time rewritten to the past, a status change reports exactly one blocked row and closes the order to further traffic
- [x] 5.6 With the marker's due time rewritten to the past, running the scheduled event reports the same single row

## 6. Ownership scenarios


- [x] 6.1 `consent-ownership.spec.ts` with file-level `test.use({ consent: 'declined' })`, since 6.1–6.3 all start from a buyer who declined: the order-received URL of a declined order opened in a stranger's granted context sends no purchase
- [x] 6.2 An administrator's status change on a declined order sends no purchase — the admin context first visits the storefront through `withAdminPage` and accepts the banner, so the status change carries the administrator's own consent cookies rather than no cookies at all; `auth.setup.ts` and `ADMIN_STATE` are left alone
- [x] 6.3 The buyer granting on their own order-received page after declining at checkout sends the purchase and reports no blocked row
- [x] 6.4 In its own `describe` block with `test.use({ consent: 'granted' })`, because withdrawal is a transition from granted: the buyer withdrawing on their own order-pay page blocks a purchase that a later staff status change would otherwise send

## 7. Purchase delivered once

- [x] 7.1 `consent-purchase-once.spec.ts`: an order driven through the thank-you page and two subsequent status changes produces exactly one purchase record
- [x] 7.2 The record's event identifier is derived from the order id and the event name

## 8. PHP coverage for the clock and the race

Most of this layer is already green in `tests/test-purchase-once.php`. Only 8.1 is new
work; 8.2 is a verification pass over cases that already exist.

- [x] 8.1 Add the two missing grant-side cases to `tests/test-purchase-once.php`, both driven through the purchase hook rather than by calling `cancel_blocked_purchase_report` directly:
  - a grant from an owning request inside the window cancels the scheduled report **and delivers the purchase**, with no blocked row ever reported — the existing `'A grant inside the window cancels the report'` asserts only the cancellation
  - a grant arriving after the blocked report has already been sent sends nothing
- [x] 8.2 Verify — do not rewrite — the cases already covered in `tests/test-purchase-once.php`, and note the mapping in the run report: the deferred marker and its non-duplicating repeat hooks (`'A blocked purchase is not reported at the moment of the skip'`, `'Repeated hooks before the window closes do not reschedule or duplicate'`), the overdue report with the scheduler disabled (`'An overdue report is sent by a later hook, exactly once'`), the claim race (`'Two concurrent hooks: only one takes the delivery claim'`), the transport failure (`'A transport failure is not recorded as a delivery'`), the derived event identifier (`'The purchase event id is derived from the order and the event name'`, the `[php]` half of the spec's "Event identifier derived from the order"), and the abandoned claim plus the widened interval (`'A claim abandoned by a crashed request is taken over and removed'`, `'A raised request timeout widens the abandonment interval'`)

## 9. Storefront script coverage

One case is missing from `app/source/src/test/held-events.test.ts`; the rest are green.

- [x] 9.1 Add the missing case: a consent grant that emits no consent-change signal is still caught by the 10s polling timer — distinct from the existing `'waits while the banner is still unanswered'`, which asserts the wait rather than the silent pickup
- [x] 9.2 Verify — do not rewrite — the cases already covered there: `'acts on the consent-change signal without waiting for the timer'`, `'flushes with a nonce minted by the server, not one baked into the page'`, `'finds a queue the server knows about even when no cookie was written'`, `'stops calling a permanently refusing endpoint after the backoff reaches its ceiling'`

## 10. Verification

- [x] 10.1 Run the full PHP test suite on PHP 8.3 and confirm every `tests/test-*.php` exits zero
- [x] 10.2 Run `pnpm test` in `app/source/` and confirm the storefront-script cases pass
- [x] 10.3 Run the full live suite against the test site, consent specs included, and record the result in the run report
