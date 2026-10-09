## 1. Shared helper

- [x] 1.1 `includes/helpers.php`: `pixelflow_append_location_from_cookie(array &$customer_data): array`
  — read `$_COOKIE['pf_loc']` (unslashed, `phpcs:ignore` naming the per-field sanitizing),
  decode JSON, copy each of `st`, `zp`, `ct`, `country` that is missing and holds a non-empty
  scalar through `sanitize_text_field()`, return the keys added
- [x] 1.2 `class-woocommerce-hooks.php` `append_request_customer_fields()`: replace the inline
  `pf_loc` block with the helper

## 2. Forms

- [x] 2.1 `includes/forms/dispatcher.php` `post_event()`: call the helper on
  `customerData` before UA and IP, append the returned keys to `$log['identifiers']`; update
  the docblock

## 3. Tests

- [x] 3.1 PHP cases for the helper: all four keys from the cookie; a key already set is kept; no
  cookie; a cookie that is not JSON or not an object; a non-scalar or empty field is skipped;
  values copied exactly as stored
- [x] 3.2 PHP form cases through the dispatcher: email-only form gets the four keys; a mapped
  city wins and the other three come from the cookie; no cookie sends no location; a held
  submission flushed on a request with `pf_loc` carries it; the debug log's identifiers list
  the added keys; `/blocked-events` unchanged
- [x] 3.3 PHP case for WooCommerce's request path: `pf_loc` keys reach AddToCart's
  `customerData` and an existing key is kept
- [x] 3.4 Run every `tests/test-*.php`; all exit 0
- [x] 3.5 Live on the test site, with the maintainer's go-ahead: `e2e/live/tests/user-location.spec.ts`
  (WooCommerce path) and a new case in `e2e/live/tests/forms-send.spec.ts` (a Contact Form 7
  form with no address fields sends the `pf_loc` location)

## 4. Release

- [x] 4.1 Changelog wording for 1.2.2 to cover both changes, agreed with the maintainer
- [ ] 4.2 Add the form-location scenarios to `docs/test-scenarios.html`
- [x] 4.3 Commit on `feat/wordpress-first-purchase` (PR #28): artifacts, code and tests in
  separate commits; no session links, no client data
