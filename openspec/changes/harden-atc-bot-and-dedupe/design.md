## Context

See proposal.md - Why for the motivation and the field data behind it.

Two existing mechanisms are relevant:

1. `pixelflow_if_is_bot()` (`includes/helpers.php:716`) does a case-insensitive substring match of the client user agent against the `PIXELFLOW_BOT_PATTERNS` constant (line 693), which site owners can override through the `pixelflow_useragent_bot_patterns` filter. `post_event()` (`includes/woo/hooks/class-woocommerce-hooks.php:1316`) consults it as the final gate before `wp_remote_post()`. The gate works; the pattern list is simply incomplete.

2. `should_send_event($key, $ttl)` (`class-woocommerce-hooks.php:773`) de-duplicates in two layers: a per-request static array `$this->sent_in_request`, then a WooCommerce session value `pf_dedupe_<md5(key)>` holding a timestamp. When `WC()->session` is unavailable it returns `true` unconditionally (line 783), so the only surviving guard is per-request - useless against a repeat that arrives as a separate request. Session-less requests are exactly the bot and prefetch case that produces the observed duplicates.

The de-duplication key for AddToCart is `add_to_cart:<cart_item_key>`. WooCommerce derives `cart_item_key` from product, variation and cart item data, so it is stable for a repeated add of the same product - it does not carry per-request entropy that would defeat matching.

Constraint: this plugin has no Composer or PHPUnit setup. Tests are standalone PHP files under `tests/` run against the hand-rolled stub harness in `tests/bootstrap-wp-stubs.php`, which is deliberately minimal and extended only as needed.

## Goals / Non-Goals

**Goals:**

- Close both defects with changes confined to the two functions named above, so the blast radius stays small and the existing session path is untouched.
- Keep the bot signature list declarative and filterable, so a site owner hitting a new crawler can respond without a plugin release.
- Make the de-duplication fallback safe for a shared-hosting WordPress install: bounded storage, no raw PII at rest, no new dependency.

**Non-Goals:**

- Reworking the de-duplication key scheme or the TTL values callers pass. Callers keep their current windows (5 seconds for AddToCart, 0 for quantity updates).
- Behavioural bot detection (request-rate analysis, honeypot links, JS challenges). User-agent matching is the mechanism this change improves, not replaces.
- Backfilling or correcting the noise already sent to Meta for the affected site.

## Decisions

**Match Meta crawlers by the `meta-external` prefix rather than by full agent name.**
The existing list carries `meta-externalagent`, and the field data shows `meta-externalads`. Enumerating variants means shipping a patch each time Meta adds one. Substring matching is already the mechanism, so shortening the entry to `meta-external` covers the family. Considered and rejected: keeping the exhaustive list and simply appending `meta-externalads`, which fixes the observed case but leaves the same defect open for the next variant. The false-positive surface of the shorter prefix is a genuine browser whose UA contains `meta-external`, which is not a realistic string in browser UAs.

**Add generic HTTP client libraries, not just the two observed in the export.**
`aiohttp` and `httpx` are what this client's noise happened to use; `okhttp`, `go-http-client`, `java/`, `libwww-perl`, `guzzle` and `node-fetch` are the same category of caller and would produce identical noise. Adding them together costs nothing and avoids a second round of this investigation on the next site. Note that `guzzle` risks matching a legitimate server-to-server integration that calls the site with a default Guzzle UA; the private-IP check in `post_event()` already covers the common self-call case, and a site owner can drop a pattern through the existing filter.

**Session-less de-duplication uses a WordPress transient, keyed on a hash of client identity plus event key.**
Transients are the standard WordPress primitive for short-lived cross-request state, work on every install without configuration, and carry native expiry - which satisfies the spec's bounded-lifetime requirement without a cleanup job. The key is `pf_dedupe_` plus `md5($key . '|' . $ip . '|' . $ua)`; storing a hash rather than the IP and UA themselves means no personal data is written to the options table. Alternatives considered: a custom table (disproportionate for state measured in seconds); the object cache directly (not persistent on a default install, so a repeat arriving on a different request would miss); a cookie (unavailable to exactly the session-less clients this targets).

**Transient expiry is set to the de-duplication window, with a small floor.**
Passing the caller's TTL directly makes the record's lifetime match the suppression window, so the transient's own presence is the suppression signal and no timestamp comparison is needed - a simpler read path than the session branch. A floor of 1 second avoids the WordPress behaviour where a non-positive expiry means "never expires", which would turn a `ttl = 0` call into a permanent record. For `ttl = 0` the function must return `true` before touching the transient at all, matching the session branch, where `($now - $last_ts) < 0` is never satisfied.

**The fallback is consulted only when `WC()->session` is genuinely unavailable.**
The session branch stays authoritative and unmodified. This keeps the change to a new `else` path on an existing early return, so a regression in the normal shopper flow would have to come from code that was not edited.

**Tests extend the existing standalone harness rather than introducing PHPUnit.**
Adding Composer and PHPUnit is a larger change than the fix it would verify, and the repo has an established pattern for this. The harness needs stubs for `set_transient`, `get_transient` and `delete_transient` backed by an in-memory array with simulated time, plus a way to swap the resolved client IP and UA between calls.

## Risks / Trade-offs

**A broad signature matches a real shopper's browser and silently drops their events.** → Every added pattern is a library identifier that does not appear in browser user agents; the one genuinely arguable entry (`guzzle`) is called out above, and the `pixelflow_useragent_bot_patterns` filter lets a site owner remove any pattern without a code change.

**Client identity from IP plus UA conflates distinct shoppers behind one NAT or corporate proxy on the same browser build, suppressing a legitimate second add.** → The window is seconds, and the collision additionally requires the same `cart_item_key`, meaning the same product added within that window. The exposure is limited to session-less requests; any shopper with a WooCommerce session takes the unchanged path. This is an accepted trade-off: under-counting a rare collision is preferable to the current systematic over-counting.

**Transient writes grow the options table on a site under heavy bot traffic.** → Records expire on their own within seconds, and WordPress purges expired transients on its regular cleanup. Sites with a persistent object cache never touch the database for these at all.

**Reported AddToCart volume drops visibly after deployment and reads as a tracking regression.** → Note it explicitly in the changelog as a correction with the measured magnitude, so the drop is expected rather than investigated as a new fault.

## Migration Plan

Deploy as a normal plugin update; no data migration and no schema change. Rollback is reverting the two files - stale `pf_dedupe_*` transients left behind expire on their own within seconds and are ignored by the reverted code.
