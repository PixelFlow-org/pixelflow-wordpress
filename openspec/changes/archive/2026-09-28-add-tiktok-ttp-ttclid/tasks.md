## 1. Plugin Check warnings

- [x] 1.1 In `pixelflow_append_tiktok_params()` (`includes/helpers.php`), wrap both live reads as
  `sanitize_text_field(wp_unslash($_COOKIE[...]))` for `_ttp` and `_pf_click_ids`
- [x] 1.2 Confirm neither line is flagged any more: run Plugin Check against the worktree's
  `includes/helpers.php` (local `plugin-check`), or state which check was used instead

## 2. Tests

- [x] 2.1 Create `tests/test-tiktok-cookies.php` and move the two cases "Live cookies copy ttp and
  ttclid only" and "A missing TikTok cookie omits the field" out of
  `tests/test-configuration-gate.php`
- [x] 2.2 Add a case: only `_ttp` present → `ttp` sent, no `ttclid` key
- [x] 2.3 Add a case: `_pf_click_ids` without `ttclid`, and with `ttclid[]=x` → no `ttclid` key
- [x] 2.4 In `tests/test-automation-dedupe-release.php`, add two cases through the real hooks,
  each with `_ttp` and `_pf_click_ids=ttclid=...&gclid=...` on the request:
  `pf_add_to_cart_hook()` and `pf_initiate_checkout_hook()`; the event in
  `$GLOBALS['__pf_test_posts']` carries `ttp` and `ttclid` and no `gclid`
- [x] 2.5 In `tests/test-held-replay-context.php`, add a case through the real flush: queue a
  recipe with `pf_queue_recipe()`, then on a request carrying the consent cookie, `_ttp` and
  `_pf_click_ids` call `resolve_held_events_on_page_view()`; `pf_last_event()` carries that
  request's `ttp` and `ttclid`
- [x] 2.6 In `tests/test-purchase-customer-data.php`, add both "one id saved, the other live"
  cases on the buyer's request: order saved only `_ttp` → saved `ttp`, live `ttclid`; order
  saved only `_pf_click_ids` → saved `ttclid`, live `ttp`; plus, on someone else's request (as in
  "A stranger's fbc cookie stays off the Purchase"), order saved only `_ttp` → saved `ttp`, no
  `ttclid` key; order saved only `_pf_click_ids` → saved `ttclid`, no `ttp` key
- [x] 2.7 In `tests/test-automation-dedupe-release.php`, add a debug-log case: remove any log
  file left by earlier cases, send an InitiateCheckout carrying `_ttp` and `_pf_click_ids`, read
  `pixelflow_get_debug_log_path()`; the entry's cookie list includes both cookies
- [x] 2.8 Run every `tests/test-*.php`; all exit 0

## 3. Version 1.1.20

- [x] 3.1 `pixelflow.php`: `* Version: 1.1.20` and `define('PIXELFLOW_VERSION', '1.1.20')`
- [x] 3.2 `readme.txt`: `Stable tag: 1.1.20` and a `= 1.1.20 =` entry at the top of the
  changelog: "WooCommerce AddToCart, InitiateCheckout and Purchase now include TikTok's ttp and
  ttclid when the shopper's cookies carry them."
- [x] 3.3 `README.md`: the same line under a new `### 1.1.20` at the top of `## Changelog`
- [x] 3.4 Add the change's scenarios to `docs/test-scenarios.html` (rows `TTK-01`..`TTK-14`)
- [x] 3.5 Add `e2e/live/tests/tiktok-ids.spec.ts`: AddToCart, InitiateCheckout and Purchase carry
  `ttp`/`ttclid` from browser cookies, neither/only-one cookie cases, the debug-log cookie list,
  and a held-then-flushed AddToCart
- [x] 3.6 Full run (PHP, component tests, admin E2E, live matrix), then update the report's
  version, header and "Last full run" line and publish it with `e2e/live/scripts/publish-report.sh`

## 4. Delivery

- [x] 4.1 Commit on `fix/tiktok-ttp-ttclid` in separate commits (warnings; tests; version and
  changelog; this OpenSpec change), with no session links in the messages
- [x] 4.2 Show the operator the final commits and the PR comment; push only after
  explicit approval
- [x] 4.3 After approval, post as a comment on PR #24 (the description stays as its author
  wrote it):
  "Review follow-up: sanitize the `_ttp` and `_pf_click_ids` cookie reads inline (clears two
  Plugin Check warnings); move the live-cookie cases to tests/test-tiktok-cookies.php; test
  AddToCart, InitiateCheckout, held-event replay and the debug log through their real paths, and
  an order with only one id saved; bump version to 1.1.20."
- [x] 4.4 After CI finishes, confirm the Plugin Check comment no longer lists
  `includes/helpers.php`
