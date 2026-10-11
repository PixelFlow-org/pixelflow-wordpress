## Context

See proposal.md. `pf_loc` is written by PixelFlow's browser script (served from its CDN, not
this repository) as a JSON object whose `ct`, `st`, `zp` and `country` are already hashed; the
live spec `e2e/live/tests/user-location.spec.ts` compares the WooCommerce payload with the
cookie's values directly. WooCommerce events read it in
`PixelFlow_WooCommerce_Cart_Hooks::append_request_customer_fields()`; Purchase reads the copy
saved on the order (`_pf_cookie_pf_loc`), which this change does not touch. Form events are
sent only from `PixelFlow_Form_Dispatcher::post_event()`, both directly and when a held
submission is flushed.

## Goals / Non-Goals

**Goals:** one implementation of "fill missing location keys from `pf_loc`", used by forms and
by WooCommerce's live-request path.

**Non-Goals:** a setting; a GeoIP lookup in PHP; location on `/blocked-events`; the Purchase
path that reads the cookie saved on the order.

## Decisions

### One helper, `pixelflow_append_location_from_cookie()`, in `includes/helpers.php`
Takes `customerData` by reference, fills each missing key from the cookie, and returns the
keys it added (the form dispatcher logs them). WooCommerce's
`append_request_customer_fields()` calls it in place of its inline block. Chosen by the
maintainer over a private copy in the dispatcher, which the PRD proposed and which would have
left two copies of the same cookie parsing.

### Read `$_COOKIE`, not `filter_input(INPUT_COOKIE)`
The WooCommerce block used `filter_input()`, the only such call in the plugin; every other
cookie is read from `$_COOKIE` with `wp_unslash()` and a `phpcs:ignore` naming where the value
is sanitized. Both see the same request cookie, but `filter_input()` ignores `$_COOKIE`, so the
PHP tests could not reach it. The helper follows the `$_COOKIE` pattern; each field is passed
through `sanitize_text_field()` after decoding.

### Only plain values are copied
A field is copied when it is a non-empty scalar. WooCommerce's block had no scalar check: a
field holding an array went through `sanitize_text_field()`, which returns an empty string, so
the key was set to `''`. Now such a key is left out. Real cookies hold strings, so this changes
nothing for a well-formed cookie.

### Debug log
The keys returned by the helper are appended to the form log's `identifiers`; values are not
logged, as before.

## Risks / Trade-offs

- [The WooCommerce path changes code] → same behaviour for well-formed cookies; covered by the
  new PHP case for the request path and by `user-location.spec.ts` on the test site.
- [`pf_loc` is absent before consent] → a held submission is sent after the grant on a request
  that has the cookie; a submission sent before the cookie exists goes without location, as a
  WooCommerce event does.
