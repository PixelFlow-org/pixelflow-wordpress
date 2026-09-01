## Why

The GDPR consent work on `feat/gdpr-compliance` states its intent explicitly: register PixelFlow as a WP Consent API consumer, attach the *optional* consent block to WooCommerce events "matching browser ingest", and on async purchase hooks "reuse the persisted `_pf_consent` order meta **when live cookies are unavailable**". The shipped code diverges from that intent in three places, and the most severe divergence disables the feature outright in production: the consumer registration hook is attached too late to ever fire.

The second divergence is silent and worse for data quality — when a CMP is installed, the WP Consent API branch answers unconditionally, so on background purchase hooks (cron, an admin changing order status, a payment-gateway callback) it reports `denied` for a visitor who did consent, and the persisted order-meta fallback is never reached.

## What Changes

- Register the WP Consent API consumer from the plugin constructor so the `plugins_loaded` callback actually runs (today it is attached from a callback on `init`, after `plugins_loaded` has already fired).
- Split the registration hooks: the consumer `add_filter` stays on `plugins_loaded`; `wp_add_cookie_info()` — which calls `__()` — moves to `init`, avoiding the WP 6.7+ `_doing_it_wrong` notice for early translation loading that fixing the registration would otherwise expose.
- Make the WP Consent API branch express the "live cookies are unavailable" condition already named in the intent: it answers only when the request carries a decodable `_pf_consent` cookie, and returns "unknown" otherwise, so resolution falls through to the order-meta override and then to the live cookie exactly as its docblock describes.
- Take the consent `timestamp` from the visitor's decision recorded in `_pf_consent` rather than from the moment the server event is sent, so the block matches browser ingest field for field.
- Accept `consent_mode_disabled` as a consent source, matching the ingest contract's enum (already applied to `PIXELFLOW_CONSENT_SOURCES`).
- Cover the corrected behaviour with tests: registration timing, fall-through when the request carries no visitor data, and the order-meta override winning in that case.

No breaking changes: the consent block stays optional, its wire shape is unchanged, and an event whose consent cannot be determined carries no consent block — as it does in the browser script today.

## Capabilities

### New Capabilities
- `consent-resolution`: how PixelFlow registers as a WP Consent API consumer and how it resolves the consent block attached to server-side WooCommerce events, across live visitor requests and background order-status requests.

### Modified Capabilities
<!-- None: no existing spec in openspec/specs/ covers consent behaviour. -->

## Impact

- `includes/consent.php` — `pixelflow_register_wp_consent_api_consumer()`, `pixelflow_get_consent_from_wp_api()`, `pixelflow_resolve_event_consent_block()`.
- `pixelflow.php` — hook registration moves from `PixelFlow::init()` to the constructor.
- `tests/test-consent-consumer.php` — new cases for the corrected branches.
- No change to the PixelFlow ingest contract, to `includes/woo/hooks/class-woocommerce-hooks.php`, or to the browser tracking script.
- Ships inside the unreleased 1.1.17; no version bump.
