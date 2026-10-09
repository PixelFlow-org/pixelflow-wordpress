## Why

A form event from a form without address fields reaches Meta with no city, state, postcode
or country, while an AddToCart from the same visit carries all four: WooCommerce events copy
them from the `pf_loc` cookie, which the browser script fills with the hashed location of the
visitor's IP. The form dispatcher never reads that cookie. A merchant testing forms reported
that a form without these fields sends none of them.

## What Changes

- A form event fills any of `ct`, `st`, `zp` and `country` that the form did not provide
  from the `pf_loc` cookie of the request that sends it, copied as stored (already hashed). A
  value the form provided is kept. With no cookie the event is sent as today.
- This happens in the one send path, so a submission held for consent and sent after the grant
  takes the location from the request that sends it.
- The debug log's `identifiers` list names the keys added from the cookie; it still records no
  values.
- The cookie is read by one helper shared with WooCommerce events, replacing WooCommerce's own
  copy of the same logic. A cookie field that is not a plain value is now ignored instead of
  being written as an empty string.
- No setting, no GeoIP lookup in PHP, nothing added to `/blocked-events`.

## Capabilities

### New Capabilities
<!-- None. -->

### Modified Capabilities
- `form-events`: a form event's `customerData` also carries location from `pf_loc` when the
  form provided none.

## Impact

- Code: `includes/helpers.php` (shared helper), `includes/forms/dispatcher.php`
  (`post_event()`), `includes/woo/hooks/class-woocommerce-hooks.php`
  (`append_request_customer_fields()` calls the helper).
- Tests: PHP cases for the helper, the form payload and the WooCommerce request path;
  `e2e/live/tests/user-location.spec.ts` covers WooCommerce on the test site.
- Outbound payload: form events gain `ct`, `st`, `zp`, `country` when the cookie is present;
  keys and value format are those WooCommerce events already send.
- Release: ships in 1.2.2 with `add-woo-first-purchase-only`, same branch and PR.
- Source: the PRD "Form events take location from the visitor cookie" (draft, 2026-10-09).
