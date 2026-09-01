## 1. Test harness groundwork

- [x] 1.1 Extend `tests/bootstrap-wp-stubs.php` with in-memory `set_transient()`, `get_transient()` and `delete_transient()` stubs, backed by an array that records value and expiry and honours a settable simulated clock so a test can advance time without sleeping.
- [x] 1.2 Add stubs or test seams to `tests/bootstrap-wp-stubs.php` allowing a test to control the values returned by `pixelflow_get_client_ip_address()` and `pixelflow_get_client_user_agent()` between calls, and to run with `WC()->session` absent.

## 2. Bot signature coverage

- [x] 2.1 Add a failing test `tests/test-bot-user-agent-patterns.php` asserting `pixelflow_if_is_bot()` returns true for `Python/3.14 aiohttp/3.14.1`, `python-httpx/0.28.1`, `meta-externalads/1.1` and `meta-externalagent/1.1`, and false for the Chrome and Safari user agents from the field export.
- [x] 2.2 Extend `PIXELFLOW_BOT_PATTERNS` in `includes/helpers.php:693`: replace `meta-externalagent` with `meta-external`, and add `aiohttp`, `httpx`, `okhttp`, `go-http-client`, `java/`, `libwww-perl`, `guzzle`, `node-fetch`. Keep the existing entries.
- [x] 2.3 Add a test asserting the `pixelflow_useragent_bot_patterns` filter can both add and remove a pattern, and that removal makes a previously matching agent non-bot.
- [x] 2.4 Run the tests from 2.1 and 2.3 green.

## 3. Session-less de-duplication fallback

- [x] 3.1 Add a failing test `tests/test-session-less-dedupe.php` covering the spec scenarios: repeat inside the window suppressed, repeat after the window sent, different client IPs both sent, `ttl = 0` always sent, and a session-present run writing no transient.
- [x] 3.2 In `should_send_event()` (`includes/woo/hooks/class-woocommerce-hooks.php:773`), replace the unconditional `return true` on the missing-session branch with a transient-backed guard: return `true` immediately when `$ttl_seconds <= 0`; otherwise compute `pf_dedupe_` . `md5($key . '|' . $ip . '|' . $ua)` from the resolved client IP and user agent, return `false` if that transient exists, else write it with an expiry of `max(1, $ttl_seconds)` and return `true`.
- [x] 3.3 Confirm the session-present branch is byte-for-byte unchanged and still returns before any transient call.
- [x] 3.4 Run the tests from 3.1 green.

## 4. Regression check and release

- [x] 4.1 Run the full existing test suite, including `tests/test-woo-add-to-cart-null-variation.php`, and confirm no regressions.
- [ ] 4.2 Manually verify against a local WooCommerce install that a normal browser add-to-cart still produces exactly one event, and that requesting a `?add-to-cart=<id>` URL twice in quick succession with cookies disabled produces one event instead of two.
- [x] 4.3 Bump the version to 1.1.18 in all five places listed under "Version Bump" in CLAUDE.md.
- [x] 4.4 Add the changelog entry to both `readme.txt` and `README.md`: "Filtered out bot and duplicate AddToCart events that were inflating reported conversion counts."
- [x] 4.5 Note in the release description that reported AddToCart volume will drop — roughly half on the measured client site — so the drop is expected rather than investigated as a regression.

## 5. Project convention

- [x] 5.1 Add a rule to `CLAUDE.md` requiring all specs and planning artifacts to be written in English regardless of the language of the conversation.
