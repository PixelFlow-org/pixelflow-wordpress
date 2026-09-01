## 1. Signature matcher returns what matched

- [ ] 1.1 Add a failing test to `tests/test-bot-user-agent-patterns.php` asserting `pixelflow_matched_bot_pattern()` returns `'okhttp'` for `okhttp/4.12.0`, `'meta-external'` for `meta-externalads/1.1`, and `null` for the Chrome and Safari agents from the field export.
- [ ] 1.2 Add a failing test asserting the returned signature is one the agent actually contains when several could match, and that a signature removed via `pixelflow_useragent_bot_patterns` is never returned.
- [ ] 1.3 In `includes/helpers.php`, add `pixelflow_matched_bot_pattern(string $ua): ?string` holding the resolve-filter / lowercase / `strpos` loop currently inside `pixelflow_if_is_bot()` (line 724), returning the matched pattern or `null`. Keep the non-array guard on the filtered pattern list.
- [ ] 1.4 Reduce `pixelflow_if_is_bot()` to `pixelflow_matched_bot_pattern((string)$userAgent) !== null`, preserving its existing signature and its tolerance of a non-string argument.
- [ ] 1.5 Run `tests/test-bot-user-agent-patterns.php` green, including every pre-existing case in it — the boolean contract must be unchanged.

## 2. De-duplication fallback stops writing for automated requests

- [ ] 2.1 Add a failing test to `tests/test-session-less-dedupe.php` asserting that a session-less AddToCart from `Python/3.14 aiohttp/3.14.1` with a 5-second window writes no transient (`pf_test_all_transients()` stays empty) while `should_send_event()` still returns `true`.
- [ ] 2.2 Add a failing test asserting that fifty session-less requests from an automated agent for fifty distinct dedupe keys leave zero transients behind.
- [ ] 2.3 Add a test asserting the non-automated session-less path is untouched: a repeat within the window is still suppressed and a transient was written.
- [ ] 2.4 In `should_send_event_without_session()` (`includes/woo/hooks/class-woocommerce-hooks.php:818`), after the `$ttl_seconds <= 0` early return, return `true` without touching the transient when `pixelflow_if_is_bot(pixelflow_get_client_user_agent())` is true. Add a comment recording why this returns `true` rather than `false` — suppression stays with `post_event()`, which is the only path that logs the skip.
- [ ] 2.5 Confirm the session-present branch of `should_send_event()` is still byte-for-byte unchanged.
- [ ] 2.6 Run `tests/test-session-less-dedupe.php` green, including its pre-existing cases.

## 3. Skip reason names the matched signature

- [ ] 3.1 In `post_event()` (`includes/woo/hooks/class-woocommerce-hooks.php:1290`), resolve the matched signature once via `pixelflow_matched_bot_pattern($ua)` and derive `$is_bot` from it, replacing the separate `pixelflow_if_is_bot()` call at line 1299.
- [ ] 3.2 At line 1358, build the bot skip reason as `'BOT_UA:' . $matched` so `BOT_UA` remains a literal prefix; leave the `PRIVATE_IP` branch and the private-IP precedence unchanged.
- [ ] 3.3 Verify by inspection that the `$is_bot || $is_private_ip` fall-through to `debug_log()` (lines 1365-1368) is unchanged, so a suppressed event is still logged, and that nothing about the send decision depends on whether debug logging is enabled.

## 4. Document the escape hatch

- [ ] 4.1 Add an FAQ entry to `readme.txt` explaining that events from generic HTTP-client user agents are filtered as automated, that the debug log names the matched signature, and how to remove one — with a copy-pasteable `add_filter('pixelflow_useragent_bot_patterns', ...)` snippet that drops a named signature.
- [ ] 4.2 Cross-check the signature names quoted in the FAQ against `PIXELFLOW_BOT_PATTERNS` in `includes/helpers.php` so the snippet works verbatim.

## 5. Regression check and release

- [ ] 5.1 Run every file under `tests/` and confirm no regressions, including `tests/test-woo-add-to-cart-null-variation.php`.
- [ ] 5.2 Manually verify on a local WooCommerce install that a normal browser add-to-cart still produces exactly one sent event and one debug-log entry with no skip reason.
- [ ] 5.3 Manually verify that requesting `?add-to-cart=<id>` with a bot user agent and no cookies produces a debug-log entry whose reason names the matched signature, and leaves no `pf_dedupe_*` row in `wp_options`.
- [ ] 5.4 Decide the release vehicle per design.md — Migration Plan: fold into the unreleased 1.1.18, or bump to 1.1.19 across all five places listed under "Version Bump" in `CLAUDE.md` if 1.1.18 has already shipped.
- [ ] 5.5 Add the changelog line to `readme.txt` and `README.md` only if this ships as its own version; if folded into 1.1.18, leave that entry as it stands, since no user-visible send behaviour changes.
