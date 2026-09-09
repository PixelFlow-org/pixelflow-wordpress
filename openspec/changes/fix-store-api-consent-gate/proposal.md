> **WITHDRAWN — the evidence below was gathered against a stale build.**
>
> The test site `rift.kskonovalov.me` was running a plugin build dated 2026-09-06 that
> reports the same version string (1.1.17) as the working tree but does **not** contain the
> `fix-consent-gating-review` work: `purchase_event_id`, `pixelflow_request_owns_order` and
> `defer_blocked_purchase_report` are all absent from the deployed `includes/`. Every live
> failure recorded here was therefore produced by code that predates the gating being
> tested. The deploy step of `e2e/live/scripts/run.sh` had been skipped on the mistaken
> reasoning that this change alters no plugin source — true of the change, false of the
> branch it sits on.
>
> Nothing here should be acted on until the current build is deployed and the consent specs
> are re-run. If the failures survive that, this proposal can be revived with fresh evidence.

## Why

The GDPR consent gating shipped by `fix-consent-gating-review` does not engage for
WooCommerce AddToCart and InitiateCheckout on this site. The live suite added by
`add-gdpr-consent-verification` caught it on its first real run: with Complianz in opt-in
mode, both while the banner is **unanswered** and after an explicit **decline**, the plugin
POSTs real events instead of holding or blocking them.

The consent cookies are present on the very request that sends the event. The gate simply
does not act on them.

## Evidence

Measured on `rift.kskonovalov.me` (plugin 1.1.x, WooCommerce 11.1.0, Complianz 7.5.4,
`wp_get_consent_type() === 'optin'`), headed Chromium, all facts below captured in a
single run and correlated through `REQUEST_URI`:

Request that produced the event — headers read with Playwright's `allHeaders()`, which
unlike `headers()` does include cookie headers:

```
POST https://rift.kskonovalov.me/index.php?rest_route=/wc/store/v1/batch
  cookie header present : true
  consent cookies sent  : _pf_consent_source=complianz, _pf_no_consent_decision=true
```

The debug-log record it produced:

```
hook        : AddToCart
REQUEST_URI : /index.php?rest_route=/wc/store/v1/batch
consent     : {"state":"unknown","source":"complianz"}
blocked     : null
```

So `_pf_no_consent_decision=true` reached PHP on that request — `source: "complianz"`
independently proves the cookie jar arrived, since that value can only come from
`$_COOKIE['_pf_consent_source']` (`pixelflow_get_consent_source_from_cookie()`,
`includes/consent.php:288`) and the AddToCart call site passes no override
(`class-woocommerce-hooks.php:220`).

Reading the code, that request should have been held:

- `pixelflow_resolve_blocked_event_reason()` consults the hold first
  (`includes/blocked-events.php:84`)
- `pixelflow_has_no_consent_decision_hold(null, true)` falls back to
  `$_COOKIE['_pf_no_consent_decision']` and returns true for the literal `true`
  (`includes/consent.php:369-379`)
- a true hold makes `hold_or_block_event()` queue the event or beacon a blocked row,
  never dispatch it

It dispatched it. **Why the hold does not engage is not yet established** — determining it
needs PHP-level instrumentation of that request on the site, which this proposal
deliberately stops short of rather than guessing.

An earlier draft of this proposal claimed the Store API request carried no `Cookie` header
at all. That was a measurement artifact: Playwright's `request.headers()` omits
cookie-related headers by design. The claim is withdrawn; the request does carry them.

### Failing scenarios

`e2e/live/tests/consent-blocked.spec.ts` and `consent-hold.spec.ts`, headed, against the
current plugin:

- unanswered banner: `AddToCart` and `InitiateCheckout` logged as real events, hold queue
  never created
- explicit decline: the same, plus a real `Purchase` — the log holds
  `AddToCart, InitiateCheckout, Purchase` where it should hold three blocked rows and no
  real event
- no `BLOCKED_EVENTS` row is written in any of these cases

Not affected, verified passing in the same runs: a purchase delivered exactly once across a
thank-you page and two status changes; a withdrawal on the buyer's own order-pay page
stopping a later staff status change; and the whole pre-existing 65-test live matrix under
a granted banner.

## What Changes

The hold and deny decision must be honoured for AddToCart and InitiateCheckout on a block
storefront, so that:

- an unanswered banner holds the event in the shopper's WooCommerce session, to be flushed
  on a later grant
- a decline sends no real event and reports exactly one anonymous blocked row

The first step is diagnostic, not a fix: instrument the gate on the failing request and
establish why `pixelflow_has_no_consent_decision_hold()` does not return true when the
cookie is present. The fix follows from that finding and is not chosen here.

## Impact

- Likely `includes/consent.php`, `includes/blocked-events.php`, and the AddToCart /
  InitiateCheckout call sites in `includes/woo/hooks/class-woocommerce-hooks.php` — to be
  confirmed by the diagnosis.
- `e2e/live/tests/consent-hold.spec.ts` and `consent-blocked.spec.ts` are the acceptance
  oracle: they fail today and must pass afterwards, unchanged.
- A PHP-level regression test is needed that reproduces the failing request's conditions
  rather than only setting `$_COOKIE` in isolation, since the existing unit tests do that
  and pass while the live behaviour is wrong.
