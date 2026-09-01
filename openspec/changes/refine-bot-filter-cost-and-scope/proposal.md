## Why

A code review of `harden-atc-bot-and-dedupe` (implemented, not yet released) found two consequences its design considered but under-weighted. First, the new session-less de-duplication fallback writes a WordPress transient *before* the bot gate runs, so the crawler traffic the fallback exists to suppress is exactly the traffic that pays a database write per request. Second, four of the newly added user-agent signatures — `okhttp`, `node-fetch`, `go-http-client` and `java/` — are the default agents of legitimate first-party clients, not only of scrapers, and a site they misfire on loses every event with nothing in the debug log to explain why. Both are cheapest to fix before 1.1.18 ships, while the affected code is still uncommitted.

## What Changes

- Classify the request as automated *before* the session-less de-duplication fallback writes its transient. An automated request is discarded at `post_event()` regardless, so the record it leaves behind buys nothing and costs an `wp_options` row on every install without a persistent object cache. Today those rows accumulate for up to a day: the 5-second TTL governs when a record stops suppressing, not when WordPress deletes it — expired transients are purged by the daily `delete_expired_transients` cron.
- Record *which* signature matched when an event is skipped as a bot. The debug log currently records the reason `BOT_UA` alone, which cannot distinguish "a crawler was correctly filtered" from "this store's own mobile app is being filtered". Naming the matched pattern turns a silent, unattributable event loss into a one-line diagnosis.
- Document the `pixelflow_useragent_bot_patterns` filter for site owners in `readme.txt`, with a copy-pasteable snippet for removing a signature. The escape hatch already exists and the design leans on it, but it is documented nowhere a site owner reads, so in practice it is not available to the people who need it.

Not in scope: removing the generic HTTP-client signatures. On a typical WooCommerce storefront they filter genuine noise, and the field data behind `harden-atc-bot-and-dedupe` supports keeping them. The problem is that misfires are undiagnosable and the remedy is undiscoverable — this change fixes those, rather than trading a measured improvement for a hypothetical one. Also out of scope: exempting authenticated or session-carrying requests from the bot gate, which would weaken the gate for a case the private-IP check largely already covers.

## Capabilities

### New Capabilities
<!-- None: this change refines requirements introduced by harden-atc-bot-and-dedupe. -->

### Modified Capabilities
- `event-noise-filtering`: adds a requirement that automation classification precede any persistent de-duplication write, and a requirement that a bot-suppressed event be attributable to the signature that caused it.

## Impact

- **Depends on `harden-atc-bot-and-dedupe`.** That change introduces `event-noise-filtering` and is not yet archived, so `openspec/specs/` does not contain the capability this change's delta modifies. Apply the two changes in order, or fold this one into that change before archiving it.
- `includes/woo/hooks/class-woocommerce-hooks.php` — `should_send_event_without_session()` (line 818, new in the dependency change) gains a bot check ahead of its transient write; `post_event()` (line 1290) records the matched signature in the skip reason.
- `includes/helpers.php` — `pixelflow_if_is_bot()` (line 724) gains a companion that returns the matched signature rather than a boolean; the boolean function stays for existing callers and the `pixelflow_useragent_bot_patterns` filter contract is unchanged.
- `readme.txt` — new FAQ entry documenting the filter.
- `tests/test-bot-user-agent-patterns.php` and `tests/test-session-less-dedupe.php` — extended; `tests/bootstrap-wp-stubs.php` needs no new stubs.
- Debug-log format changes for skipped events: the reason string gains the matched signature. No database schema change, no API contract change, no new dependency.
- Reduces write volume relative to the dependency change; it does not change which events are sent, so reported AddToCart volume is unaffected by this change on its own.
