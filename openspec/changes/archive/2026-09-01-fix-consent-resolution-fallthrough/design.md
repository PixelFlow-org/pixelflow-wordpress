## Context

See proposal.md — Why. The relevant constraints of the existing implementation:

- `pixelflow_resolve_event_consent_block(?string $cookie_raw_override)` (`includes/consent.php`) is documented as a fall-through chain — "WP Consent API, then cookie override, then live cookie" — but its first branch never falls through. `pixelflow_get_consent_from_wp_api()` returns `null` only when `wp_has_consent()` is undefined or `wp_get_consent_type()` is empty, i.e. only when no CMP is installed at all. Otherwise it always returns a decision, because `wp_has_consent()` is boolean and has no "unknown" answer: with an opt-in consent type and no visitor cookie it returns `false`, which the branch records as a deliberate `denied`.
- The only caller that passes the override is `pf_purchase_hook()`, reading `_pf_cookie__pf_consent` from order meta. Purchase is also hooked to `woocommerce_order_status_processing` / `_completed`, which fire in requests with no visitor cookies.
- `PixelFlow::init()` runs on the `init` hook, so the `plugins_loaded` registration it adds is attached after that hook has fired. `PixelFlow::get_instance()` itself runs at plugin file inclusion, before `plugins_loaded`.
- `PIXELFLOW_CONSENT_COOKIE_NAME` (`_pf_consent`) and `pixelflow_decode_consent_cookie()` already exist and already carry the validation and tamper-rejection logic the specs require.

## Goals / Non-Goals

**Goals:**
- Make the implementation match the intent already stated in the PR description and in the function's own docblock, by adding the missing condition rather than reordering the chain.
- Derive the "live cookies are unavailable" signal from data the plugin already owns.

**Non-Goals:**
- Detecting request context (`is_admin()`, `wp_doing_cron()`, `REST_REQUEST`) as a proxy for visitor presence.
- Changing the ingest contract, the cookie format, the order-meta persistence, or the browser script.
- Gating event sending on consent — the block is reported, not enforced, here.

## Decisions

**1. Visitor presence is signalled by a decodable `_pf_consent` cookie, not by request context.**
The WP Consent API branch answers only when `pixelflow_decode_consent_cookie()` succeeds on `$_COOKIE[PIXELFLOW_CONSENT_COOKIE_NAME]`; otherwise it returns `null` and the chain proceeds to the override and then to the live cookie.

*Alternative rejected — context detection.* `is_admin() || wp_doing_cron()` is an indirect proxy and a leaky one: a payment gateway calling back over `?wc-api=` is an ordinary front-end request with no cookies, and would still be answered with a fabricated `denied`. It is also the wrong predicate: the PR states the condition as "when live cookies are unavailable".

*Alternative rejected — moving the override ahead of the WP Consent API.* It fixes background requests but makes the order-creation snapshot outrank the platform's live answer on the thank-you page, so a consent revoked between checkout and that page would still be reported as granted. It also contradicts both the docblock and the PR wording.

*Alternative rejected — accepting any non-empty cookie as the signal.* Presence without decodability would let a malformed or tampered cookie unlock a branch whose `timestamp` is then unavailable, and it splits the trust rule the plugin already applies to that cookie everywhere else.

**2. Consent state remains the platform's, the timestamp becomes the visitor's.**
When the branch answers, `state` and `source` come from the WP Consent API (`source: api`) so a freshly changed decision is honoured, while `timestamp` is taken from the decoded cookie's decision time. The block then matches browser ingest field for field. If a future path yields a platform answer with no decision time available, the current time remains the fallback.

**3. No special case for opt-out consent types.**
Under an opt-out regime the WP Consent API treats a missing cookie as granted, but the plugin does not exploit that: with no decodable cookie the branch falls through like any other. One rule covers every consent type, and it keeps the order-meta override reachable in background requests — an opt-out exception would re-introduce a branch that short-circuits ahead of the override exactly where the override is needed.

**4. Registration moves to the constructor and splits across two hooks.**
`add_action('plugins_loaded', 'pixelflow_register_wp_consent_api_consumer')` moves into `PixelFlow::__construct()`, which runs before `plugins_loaded`. Because the consumer filter must be in place early while `wp_add_cookie_info()` calls `__()`, the cookie disclosure moves to a separate `init` callback: WordPress 6.7+ reports translation loading before `init` as `_doing_it_wrong`, and that notice is only latent today because the registration never runs at all.

## Risks / Trade-offs

- **A visitor whose consent decision predates the plugin's cookie loses the platform's answer for server-side events** → The cookie is written by the tracking script on every decision, so the gap closes on the visitor's next page view; until then the event is sent with no consent block, which is the specified "unknown" outcome rather than a wrong one.
- **Under an opt-in platform, a visitor who has not yet chosen now yields no consent block where the code previously sent `denied`** → Intended, and required by "matching browser ingest": absence of a block means "unknown" downstream, and the decision on how to treat unknown belongs to the ingest side, not to a value the plugin fabricates.
- **`wp_add_cookie_info()` on `init` runs later than some consent platforms enumerate the cookie register** → The consumer filter itself, which is what registration is judged by, stays on `plugins_loaded`; only the human-readable cookie description moves. If a platform is found to enumerate earlier, the description can move to `plugins_loaded` with literal strings instead of `__()`.
- **The override path stays exercised only by purchase events** → Unchanged from today, and the specs describe it as such; other events have no persisted decision to fall back to.
