## Why

An AddToCart export from a live client site (poolbarlondon.com, 175 events over 5 days, 13-18 Aug 2026) shows roughly half the events are noise rather than real shopper activity: 33 events (19%) came from scripted clients whose user agents slip past our bot filter, and 16 events are same-visitor/same-product repeats within 60 seconds caused by WooCommerce `?add-to-cart=` URLs being re-requested by prefetchers and crawlers. Noise inflates the client's reported AddToCart volume and degrades Meta's campaign optimisation, and both root causes are defects in this plugin rather than site misconfiguration.

## What Changes

- Extend the bot user-agent signature list so that generic HTTP client libraries and Meta's ads crawler are recognised as bots. Confirmed misses in the export: `Python/3.14 aiohttp/3.14.1` (23 events), `python-httpx/0.28.1` (4 events), `meta-externalads/1.1` (6 events - the list has `meta-externalagent`, but Meta sends `meta-externalads`).
- Make event de-duplication work when no WooCommerce session exists. `should_send_event()` currently falls back to a per-request static guard when `WC()->session` is unavailable, which is exactly the case for a session-less bot or prefetch request - every hit produces a fresh request and therefore a fresh event. Add a session-less fallback keyed on a hash of client IP, user agent and event identity, stored in a WordPress transient with the same TTL semantics.
- Keep the existing session-based path unchanged as the primary mechanism; the new fallback applies only when a Woo session is genuinely absent.

Not in scope: the client-side click-trigger noise (28 payload-less events with `click_selector = "a"`), which is a per-site trigger configuration issue rather than a plugin defect, and the site-side recommendation to enable AJAX add-to-cart.

## Capabilities

### New Capabilities
- `event-noise-filtering`: Rules governing which events the plugin refuses to send to the Pixelflow API - bot/automation detection by user agent, private-IP suppression, and de-duplication of repeated events including the session-less case.

### Modified Capabilities
<!-- None: openspec/specs/ is empty, so no existing capability requirements change. -->

## Impact

- `includes/helpers.php` - `PIXELFLOW_BOT_PATTERNS` constant (line 693) consumed by `pixelflow_if_is_bot()` (line 716).
- `includes/woo/hooks/class-woocommerce-hooks.php` - `should_send_event()` (line 773), called by `pf_add_to_cart_hook()` (line 104), `pf_cart_item_quantity_update_hook()` (line 197) and `maybe_send_initiate_checkout()` (line 412).
- `post_event()` (line 1251) already gates on `pixelflow_if_is_bot()`; no change needed there, it simply starts catching more agents.
- Behaviour change for site owners: reported AddToCart volume will drop. This is the intended correction, but it is visible in client dashboards and should be noted in the changelog.
- New WordPress transient keys (`pf_dedupe_*`) written on session-less requests. Bounded by the TTL already passed to `should_send_event()`.
- No database schema changes, no API contract changes, no new dependencies.
