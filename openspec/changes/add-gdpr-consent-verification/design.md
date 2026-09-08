## Context

See proposal.md — Why. Five properties of the existing setup shape the approach.

The live suite reads its evidence from the plugin's own debug log over SSH: one
pretty-printed JSON object per event, separated by `\n---\n`, truncated before each
scenario by the `cleanSlate` fixture. Blocked rows are already in that log — the
skip path writes a record whose `hook` is `BLOCKED_EVENTS <EventName>`
(`class-woocommerce-hooks.php:1633`), carrying the exact payload that was POSTed. So
both "the event did not leave" and "exactly one blocked row left" are readable from
the same file, with no plugin change and no interception on the site.

The suite's per-scenario isolation is server-side: `resetCarts()` empties the
WooCommerce sessions for everyone. A scenario that needs two different visitors —
the buyer and a stranger — cannot get them from `resetCarts()`; it needs separate
browser contexts, which the suite already knows how to build (`withAdminPage`,
`forEachPersona` with `storageState`).

The plugin's hold queue lives in the WooCommerce session, keyed by the shopper's
session cookie. A held event therefore belongs to a browser context, not to the
site, which is what makes the hold scenarios reproducible at all.

The blocked-purchase report is deferred by a hard-coded 1800 seconds
(`BLOCKED_REPORT_DELAY`) and carries its due time in the `_pf_purchase_blocked`
order meta. There is no filter on the constant.

Complianz on the test site is now configured for the EU region, so
`wp_get_consent_type()` returns `optin` and the banner renders with a
`cmplz_marketing` category. That state is a property of the site, applied once
through wp-cli, not something a test run installs.

## Goals / Non-Goals

**Goals:**

- One consent page object that every consent scenario drives, so the banner's DOM
  is described in exactly one place and a Complianz upgrade breaks one file.
- Each guarantee proven at the cheapest layer that can actually prove it, and only
  there — no scenario duplicated between the live suite and the PHP tests.
- The existing ten live specs keep asserting what they assert today, with the banner
  now in the way — the nine that visit the storefront through the suite's own `test`,
  plus `deploy.spec.ts`, which imports `@playwright/test` directly and is out of the
  fixture's reach by construction.
- A live run that fails loudly on a misconfigured test site rather than passing
  vacuously.

**Non-Goals:**

- Any change to plugin source, plugin settings schema, or event payloads. This
  change adds tests and test-site configuration only.
- Running the live consent suite in CI. It needs SSH to the test site and sends real
  events; it stays a local, pre-release command like the rest of `e2e/live/`.
- Testing Complianz itself, or any second CMP. The plugin's contract is with the WP
  Consent API, and Complianz is one conforming implementation of it.
- Covering a consent withdrawal made on a page unrelated to the order — the design
  of `fix-consent-gating-review` deliberately does not honour it.

## Decisions

### Consent state is driven through the banner, not through cookies

Every consent scenario clicks the real banner. Writing `cmplz_*` cookies directly
would be faster and less brittle, and it is exactly the shortcut that would make the
suite blind to the failure it exists to catch: the plugin does not read Complianz's
cookies, it reads the WP Consent API state that Complianz sets, plus its own
`_pf_no_consent_decision` and `_pf_consent` cookies written by the tracking script in
response to the consent-change signal. A scenario that fabricates the cookie proves
that the plugin honours a cookie; a scenario that clicks Accept proves the chain from
banner to consent API to script to cookie to server hook, which is the thing that
breaks.

The one exception is the "banner that announces nothing" case — a CMP that changes
state without emitting a signal. That has no representative on the test site and is
proven in the vitest layer against a synthetic document.

Alternatives considered. *Cookie injection everywhere* — rejected as above.
*A stub CMP mu-plugin* — rejected: it would test the plugin against a consent
platform no shopper ever meets, and Complianz costs nothing extra now that it is
installed.

### The consent baseline is a fixture, not a line in every spec

`cleanSlate` already resets carts and truncates the log before each test. It gains a
sibling: a `consent` fixture taking `'granted' | 'undecided' | 'declined'` and
defaulting to `'granted'`, which drives the banner in the test's own browser context
before the test body runs. `'granted'` accepts it, `'declined'` denies it, and
`'undecided'` leaves it standing. The consent specs opt out per file with
`test.use({ consent: 'undecided' })` or `test.use({ consent: 'declined' })`.

A three-valued fixture rather than a boolean because a decline is a starting state for
a whole spec file (§5, and the ownership scenarios that begin from one), not a step
worth repeating in every test body; withdrawal stays in the body, because it is a
transition from granted and only one scenario needs it.

Putting it in the fixture rather than in each spec's `beforeAll` matters because the
consent state lives in the browser context, and Playwright creates a fresh context
per test. A `beforeAll` acceptance would be discarded before the first test body ran.

Alternatives considered. *A stored `storageState` with the consent cookies already
granted* — rejected for the guest persona: the granted state has to be produced by
the banner for the plugin's own cookies to exist, and a stale stored state is exactly
the failure the change is meant to surface. It stays viable as an optimisation later
if the accept click proves slow.

### Ownership scenarios use browser contexts as the identity boundary

The stranger, the buyer and the administrator are three contexts. The buyer's is the
test's own page; the stranger is a fresh incognito context with its own banner
acceptance and no shared cookies; the administrator is the existing `withAdminPage`
context. A scenario asserts on the debug log after acting in one of them.

This is the only honest way to test the ownership predicate live: it is a union of
four cookie- and session-derived signals, and the only way to have none of them match
is to be a genuinely different browser.

The staff status change is made through wp-admin rather than through wp-cli. A wp-cli
status change carries no cookies at all, which the predicate would reject for the
trivial reason that nothing is present; going through the admin UI puts the
administrator's own consent cookies in the request, which is the case the requirement
actually names. Those cookies do not come for free: `ADMIN_STATE` is captured by
logging into wp-admin, where the banner never renders, so the scenario itself sends the
admin context to the storefront and accepts the banner before touching the order.
Baking the acceptance into `auth.setup.ts` instead was rejected — it would change the
consent baseline of every admin-driven scenario in the suite to serve one.

The consent fixture splits the file accordingly: `consent-ownership.spec.ts` takes
`test.use({ consent: 'declined' })` at file level, because the stranger, staff and
grant-after-decline scenarios all start from a buyer who declined, and the withdrawal
scenario sits in its own `describe` with `test.use({ consent: 'granted' })`, being a
transition out of a granted state.

### The reporting window is compressed on the site, not waited out

A live scenario cannot wait 30 minutes. Two techniques, both through the existing SSH
helper and neither touching plugin code:

- To prove the report fires and closes the order: rewrite the `due` timestamp inside
  the order's `_pf_purchase_blocked` meta to the past with wp-cli, then trigger a
  purchase hook by changing the order status. That exercises the overdue path the
  design added for sites with no working cron, which is a scenario in its own right.
- To prove the report fires from the scheduler: run
  `wp cron event run pixelflow_report_blocked_purchase` after the same meta rewrite.

What stays in the PHP layer is the *arithmetic* — that the due time is 30 minutes out,
that a grant inside the window cancels the schedule and delivers the purchase, that a
grant after the report does nothing. Those are pure functions of a clock the PHP tests
can control and the live site cannot. Most of that arithmetic is already asserted in
`tests/test-purchase-once.php`; this change adds only the two grant-side cases missing
from it, and drives them through the purchase hook rather than by calling
`cancel_blocked_purchase_report` directly, which is what the existing in-window case
does and why it cannot speak to delivery.

Alternatives considered. *Making `BLOCKED_REPORT_DELAY` filterable* — rejected: it is
a plugin source change to serve a test, and the meta rewrite proves more, because it
also covers the overdue path.

### The concurrency case stays out of the live suite

Two purchase hooks racing on the same order cannot be provoked reliably from
Playwright: the thank-you page and a status webhook are seconds apart, and the claim
window is under a second. The PHP test asserts what the design actually promises —
that the second caller's `add_option()` insert fails and it returns without building a
payload — by taking the claim itself and then invoking the hook. The live suite covers
the weaker, reachable statement: one purchase record per order across a thank-you page
and two subsequent status changes.

### The site prerequisite is asserted in global setup

`global-setup.ts` gains a check that reads the site's consent type over wp-cli and
that both plugins are active, failing the whole run with the actual value in the
message. What it deliberately does not check is that the banner actually renders:
global setup speaks only ssh and wp-cli, and a banner is a browser fact. That half is
asserted in `consent-hold.spec.ts`, which already runs with the banner unanswered and
is the one place where an invisible banner would otherwise look like a silent pass. Placing it there rather than in a spec means a misconfigured site costs one
error rather than a screenful of confusing scenario failures, and it cannot be skipped
by running a single spec.

The setup asserts; it does not repair. A run that silently installs and configures a
CMP would hide from the operator that the site drifted.

### Blocked rows are read by hook name, not by event name

The existing `recordsNamed()` helper filters on `payload.eventData.eventName`. A
blocked record has no `eventData` — its payload is `{siteId, blocked: [...]}` plus
`client_ip_address`. The helper gains a sibling `blockedRecords(name)` that filters on
the record's `hook` field, and an `expectNoIdentifiers()` assertion for the anonymity
requirement. Extending `recordsNamed()` to cover both would make every existing call
site ambiguous about which kind of record it is counting.

## Risks / Trade-offs

- **The banner's DOM is Complianz's, and it will change.** → Confined to one page
  object; the specs address it through named methods (`accept`, `deny`, `withdraw`),
  never through selectors.
- **Installing a CMP changes the baseline for the nine storefront-visiting specs.** →
  They gain the `consent` fixture at its `'granted'` default in the same change, and the change is not done
  until a full live run is green. This is the one part of the work that can break
  tests that pass today, and it is why the fixture is on by default rather than
  opt-in.
- **`withdraw` through the preferences dialog is the most fragile interaction in the
  suite.** → It is used by exactly one scenario. If it proves unstable, the fallback
  is to assert the withdrawal case in PHP only and drop the live scenario, which the
  requirement already allows for the ownership cases it shares.
- **Consent scenarios are slower than the rest of the suite.** → Each carries a banner
  interaction and, for hold cases, a flush wait. The suite is already serial and
  pre-release only; a few minutes more is acceptable.
- **A live consent run leaves real events at the API from the granted scenarios.** →
  Unchanged from today: the whole live suite sends real events under the test site's
  own site id.
- **The reporting-window scenarios mutate order meta from outside the plugin.** → The
  rewrite touches only the `due` field of a marker the plugin wrote, on an order the
  test created, and the assertion is on what the plugin then does with it.

## Migration Plan

The test-site configuration is already applied: WP Consent API 2.0.1 and Complianz
7.5.4 are active on `rift.kskonovalov.me`, the EU region is set, and
`wp_get_consent_type()` returns `optin`. The change records that state as a
prerequisite the suite asserts, so a rebuilt site is caught rather than silently
producing a green run.

Rollback is deactivating the two plugins, which returns the site to an empty consent
type. The existing specs would then fail on the global-setup assertion rather than
passing vacuously — deliberate, so the suite cannot regress to the state that made
task 7.2 of `fix-consent-gating-review` meaningless.
