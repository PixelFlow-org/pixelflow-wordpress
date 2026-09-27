## Context

PR #24 (branch `fix/tiktok-ttp-ttclid`) already implements the behaviour in
`specs/tiktok-identifiers/spec.md`. It adds `pixelflow_append_tiktok_params()` to
`includes/helpers.php`, called from `pixelflow_append_cookie_params()` for the storefront events
and from `append_cookie_params_for_order()` for Purchase, and extends the order-meta cookie list
and the debug-log cookie list in `class-woocommerce-hooks.php`. All existing tests pass on the
branch.

A three-lens review (correctness, safety, quality) found no blocker. What remains is:

- Plugin Check reports `WordPress.Security.ValidatedSanitizedInput.InputNotSanitized` on the two
  live reads in `pixelflow_append_tiktok_params()`: `wp_unslash($_COOKIE['_ttp'])` and
  `wp_unslash($_COOKIE['_pf_click_ids'])` are assigned without a sanitizer in the same
  expression. The values are sanitized later inside `pixelflow_ttp_from_raw()` and
  `pixelflow_ttclid_from_click_ids_raw()`, which the scanner cannot see.
- The two live-cookie tests sit in `tests/test-configuration-gate.php`, whose header scopes it to
  the credential gate, its notice and retired cookie names.
- No test covers InitiateCheckout, held-event replay, an order that saved only one id, or the
  debug-log cookie list. The two storefront tests call `pixelflow_append_cookie_params()`
  directly, so neither would notice an AddToCart or InitiateCheckout hook losing its call to it.

## Goals / Non-Goals

**Goals:**
- Clear both `InputNotSanitized` warnings without changing what reaches the payload.
- Put every requirement in the spec under a test.

**Non-Goals:**
- Fixing percent-encoded bytes being stripped from `_pf_click_ids` (see Risks).
- Capping the length of `_ttp` / `_pf_click_ids`; `_fbp` / `_fbc` have no cap either, and
  capping only the TikTok ids would make the two paths diverge.
- The pre-existing Plugin Check warning on the `readme.txt` short description.
- Readability nits raised in review (duplicated empty-string check at the Purchase call site,
  `is_string()` used as a null check on `?string` parameters).

## Decisions

**Sanitize the live reads inline.** Each read becomes
`sanitize_text_field(wp_unslash($_COOKIE[...]))`, the same form `_fbp`, `_fbc` and `_pf_utm` use
in the same file. `sanitize_text_field()` is idempotent on its own output, so the later call
inside the `*_from_raw()` helpers changes nothing and the payload is byte-identical.
Alternative considered: a `phpcs:ignore` comment. The project reserves those for reads where
sanitizing first would be wrong (JSON decoded field by field, a byte-exact comparison); here
sanitizing first is harmless, so an ignore would only hide the scanner.

**Keep the helpers' own sanitizing.** The helpers also receive saved order-meta values, which
arrive through a different path; they stay self-contained rather than relying on every caller.

**Drive the real paths, in the files that already have their harness.**
`pixelflow_append_cookie_params()` ignores the event name, so a test that calls it directly with
`eventName: InitiateCheckout` repeats the AddToCart case and pins nothing. What can break is a hook
losing its call to the helper, and only a test through the hook catches that. Likewise
`pf_replay()` in `tests/test-external-id-events.php` calls `post_event()` directly and never
reaches `rebuild_held_event_payload()`, where a replay gets its cookies. Each case therefore goes
where a harness for its real path already exists:

- `tests/test-tiktok-cookies.php` (new) holds the helper-level cases: the two live-cookie cases
  moved from `test-configuration-gate.php`, the one-cookie-only case and the malformed
  `_pf_click_ids` cases.
- `tests/test-automation-dedupe-release.php` drives `pf_add_to_cart_hook()` and
  `pf_initiate_checkout_hook()` with its cart and session stubs and with the debug log on; the
  AddToCart, InitiateCheckout and debug-log cases join it.
- `tests/test-held-replay-context.php` drives the real flush (`resolve_held_events_on_page_view()`);
  the replay case joins it.
- `tests/test-purchase-customer-data.php` keeps the order-meta cases beside the matching `fbc`
  section; the "one id saved" cases join them, each id in turn, on the buyer's request and on
  someone else's.

Alternative considered: everything in `tests/test-tiktok-cookies.php`. Each test file carries its
own stubs, so that would copy several hundred lines of cart, session and hold-queue stubs for a
handful of cases.

## Risks / Trade-offs

- [Percent-encoded bytes in `_pf_click_ids` are stripped] → WordPress's `sanitize_text_field()`
  removes every `%XX` octet, and it runs on the whole query string before `parse_str()`, both on
  the live read and when the cookie is saved to the order. Verified against WordPress core:
  `ttclid=E_C_P_ab%2Bcd%3D%3D` becomes `ttclid=E_C_P_abcd`. It bites only if PixelFlow's browser
  script percent-encodes the value, which this repository cannot confirm. `_pf_utm` has the same
  behaviour today. The test stub replaces `sanitize_text_field()` with `trim()`, so no test here
  can detect it. Left out of scope by decision; to be tracked separately.
- [Unbounded cookie length] → an oversized `_ttp` or `_pf_click_ids` is stored in order meta and
  forwarded. Same exposure as `fbp` / `fbc`; accepted.

## Migration Plan

None. Both fields are optional additions to `eventData`. Orders created before this release have
no saved TikTok ids, so their Purchase falls back to the buyer's live cookies or omits the fields.
