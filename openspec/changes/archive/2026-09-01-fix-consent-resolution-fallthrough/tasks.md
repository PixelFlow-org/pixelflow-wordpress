## 1. Consumer registration

- [x] 1.1 Move `add_action('plugins_loaded', 'pixelflow_register_wp_consent_api_consumer')` out of `PixelFlow::init()` into `PixelFlow::__construct()`, alongside the other `add_action` calls
- [x] 1.2 Split `pixelflow_register_wp_consent_api_consumer()`: keep the `wp_consent_api_registered_{plugin}` filter there, and move the `wp_add_cookie_info()` call into a new callback registered on `init`
- [x] 1.3 Verify on a WordPress 6.7+ install that no `_doing_it_wrong` notice for early translation loading is emitted on a front-end request

## 2. Consent resolution fall-through

- [x] 2.1 Add a helper that returns the decoded live `_pf_consent` decision for the current request, or `null` — reusing `pixelflow_decode_consent_cookie()` and `wp_unslash()` as the existing live-cookie branch does
- [x] 2.2 Gate `pixelflow_get_consent_from_wp_api()` on that helper: return `null` when no decodable live cookie is present, so resolution falls through to the order-meta override and then to the live cookie
- [x] 2.3 Take the returned block's `timestamp` from the decoded live cookie's decision time, keeping `state` from `wp_has_consent('marketing')` and `source` as `api`; fall back to the current time only when no decision time is available
- [x] 2.4 Confirm `pixelflow_resolve_event_consent_block()` and its docblock need no edit — the documented order now holds — and that the live-cookie branch is unchanged
- [x] 2.5 Remove `pixelflow_format_consent_block()` if its call sites reduce to returning the decoded decision unchanged; leave it in place otherwise

## 3. Tests

- [x] 3.1 Add a test that the `plugins_loaded` consumer registration is attached from the constructor, i.e. that it is in place by the time `plugins_loaded` fires
- [x] 3.2 Add a test that the cookie disclosure runs on `init`, not on `plugins_loaded`
- [x] 3.3 Add a test: WP Consent API active, no `_pf_consent` cookie in the request, order-meta override present → the override's decision is used
- [x] 3.4 Add a test: WP Consent API active, no `_pf_consent` cookie, no override → no consent block is attached to the payload
- [x] 3.5 Add a test: WP Consent API active with a valid live `_pf_consent` cookie whose state differs from the platform's → `state`/`source` come from the platform, `timestamp` from the cookie
- [x] 3.6 Add a test: WP Consent API active, live cookie present but malformed or carrying a visitor identifier → treated as absent, resolution falls through
- [x] 3.7 Add a test: opt-out consent type with no live cookie → the branch still falls through, with no opt-out special case
- [x] 3.8 Extend `tests/bootstrap-wp-stubs.php` with whatever stubs the new cases need, keeping the file PHP 7.4-parseable — no new stubs turned out to be needed
- [x] 3.9 Run the full PHP test suite — three pre-existing WP Consent API cases asserted the old behaviour (a decision with no visitor present) and were updated to place the visitor's cookie on the request

## 4. Documentation

- [x] 4.1 Confirm the existing 1.1.17 changelog line in `readme.txt` and `README.md` still describes the shipped behaviour, and adjust its wording only if the fall-through changes what it claims; no version bump
