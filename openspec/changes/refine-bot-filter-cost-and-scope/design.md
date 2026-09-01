## Context

See proposal.md — Why for the motivation. This change refines code introduced by `harden-atc-bot-and-dedupe`, which must be read first; its design.md carries the rationale for the mechanisms adjusted here.

Two facts about the current call order shape everything below.

**The de-duplication decision runs early; the bot decision runs late.** `should_send_event()` (`class-woocommerce-hooks.php:773`) is consulted at the top of `pf_add_to_cart_hook()` (line 123). Everything after it — loading the product, assembling the payload — leads to `post_event()` (line 1290), and only there does `pixelflow_if_is_bot()` decide whether `wp_remote_post()` actually fires (line 1355). So `should_send_event_without_session()` (line 818) writes its transient several hundred lines of control flow before anything has asked whether the caller is a bot.

**`post_event()` is also the only place a skipped event gets logged.** The bot and private-IP branches do not return early: they substitute a message for the HTTP response, append a marker to the event name, and fall through to `debug_log()` (lines 1355-1368). An event refused before `post_event()` is reached leaves no trace at all. This is the constraint that decides how the first fix is implemented — suppressing bot events earlier would be the obvious way to save the write, and it would silently delete the log line the second fix depends on.

`pixelflow_if_is_bot()` (`helpers.php:724`) resolves `PIXELFLOW_BOT_PATTERNS` through the `pixelflow_useragent_bot_patterns` filter, lowercases the agent, and returns `true` on the first `strpos()` hit — it discards which pattern hit.

Constraint carried over unchanged: no Composer, no PHPUnit. Tests are standalone PHP files under `tests/` run against `tests/bootstrap-wp-stubs.php`, which already stubs transients with a simulated clock and can toggle `WC()->session`.

## Goals / Non-Goals

**Goals:**

- Remove the storage cost of automated traffic without moving where automated traffic is *decided*, so the existing gate keeps its single authority.
- Preserve, and make more useful, the debug-log record of every refused event — including the ones this change stops writing transients for.
- Keep both fixes inside functions the dependency change already touches, so the two can be squashed into one release without a merge conflict.

**Non-Goals:**

- Making bot requests cheap in CPU terms. This change removes a database write, not the payload assembly that precedes it; see the trade-off below.
- Changing the signature set, the `pixelflow_useragent_bot_patterns` contract, or the de-duplication key scheme and TTLs.
- Restructuring `post_event()`, which is long and does several things, but is not what this change is about.

## Decisions

**The fallback skips the write and returns `true`; it does not itself suppress the event.**
The tempting implementation — return `false` for an automated request, suppressing it right there — saves both the write and the downstream work. It also destroys the log entry, because `debug_log()` is only reached through `post_event()`, and a bot event refused in `should_send_event()` never gets there. That directly contradicts the attributability requirement this same change adds, and it would degrade observability for the private-IP case's neighbour without anyone noticing. So the fallback treats "automated" purely as "a record here would be pointless", returns `true`, and leaves the actual refusal to the gate that already owns it. The result is exactly one place in the codebase that decides whether an event is sent — unchanged — and the transient write becomes conditional on that decision rather than blind to it.

**Matching moves into a function that returns the matched signature; the boolean becomes a wrapper.**
`pixelflow_matched_bot_pattern(string $ua): ?string` does the resolve-filter/lowercase/scan loop and returns the first matching pattern or `null`. `pixelflow_if_is_bot()` becomes `pixelflow_matched_bot_pattern($ua) !== null`. One implementation, one place the filter is applied, and the existing public-ish boolean keeps working for any third-party or future caller. The alternative — an out-parameter on `pixelflow_if_is_bot()`, or a second parallel loop in `post_event()` — either changes a signature other code may rely on or creates two matchers that can drift apart.

**No memoisation of the match result.**
The matcher now runs twice per session-less event instead of once: in the fallback and again in `post_event()`. Each run is a `strpos()` scan over roughly thirty short patterns against one string, and `apply_filters` on a tag that normally has no callbacks. A static per-request cache keyed on the agent would remove the second run, but it would also have to be invalidated when a site adds or removes a pattern mid-request, which is a real thing to get wrong in exchange for an unmeasurable saving. Rejected as premature.

**The skip reason gains the signature as a suffix: `BOT_UA` becomes `BOT_UA:okhttp`.**
Keeping `BOT_UA` as a literal prefix means anything a site owner already greps for in the log keeps matching, and the new detail is additive. Alternatives considered: a separate structured field in the log entry (the entry is a JSON blob, so this is possible, but the reason currently lives inside a human-readable message string and splitting it would change the entry's shape for consumers); and logging the full pattern list (useless — the point is which one fired). The private-IP branch is untouched and reports `PRIVATE_IP` with no suffix, so the two reasons stay distinguishable.

**The filter is documented in `readme.txt`, not only in code.**
The dependency change's risk mitigation for an over-broad signature is "a site owner can drop a pattern through the existing filter". That mitigation is only real if the site owner can find the filter. A FAQ entry with a copy-pasteable `add_filter` snippet that removes a named signature turns the mitigation from theoretical to usable, and pairs with the log line that now tells them which name to remove. `README.md` is developer-facing and already lists hooks; `readme.txt` is what a site owner reads on the plugin page, so the entry goes there.

## Risks / Trade-offs

**Automated requests still pay the full payload-assembly cost — product load, customer-data hashing, JSON encoding — before being refused.** → Unchanged from today; this change does not make it worse, and fixing it means moving the bot gate earlier, which is the log-destroying design rejected above. If profiling later shows it matters, the right fix is an early gate that logs on its own, which is a separate change.

**Skipping the write for automated traffic means a bot that later stops matching a signature gets no de-duplication grace period.** → It gets the same treatment as any other unmatched client from that point on: the fallback writes and suppresses normally. No state has to be carried across the transition.

**The reason string is consumed by something that parses it exactly.** → The `BOT_UA` prefix is preserved, so prefix and substring matching both keep working; only an equality comparison against the whole string breaks, and the string already varies by reason today.

**A signature name in the debug log is new content in a file a site owner may share when asking for support.** → Signature names are static plugin constants, not request data — no personal data is added. The agent string itself is already recorded in the entry's `server` block.

**Squash risk: this change edits lines the dependency change introduced but has not committed.** → Both changes touch `should_send_event_without_session()` and `PIXELFLOW_BOT_PATTERNS`'s consumers. Apply this one on top of the uncommitted 1.1.18 work rather than in parallel, and let 1.1.18 ship with both.

## Migration Plan

Fold into the 1.1.18 release rather than shipping separately: the dependency change is implemented but uncommitted, so there is no released behaviour to migrate from. No data migration, no schema change. Rollback is reverting the same two files; `pf_dedupe_*` transients written under either version expire on their own and are read compatibly by both, since neither the key derivation nor the stored value changes.

If 1.1.18 has already shipped by the time this is implemented, it becomes 1.1.19 and the changelog line describes it as a follow-up correction — the observable behaviour change for site owners is limited to the debug-log reason string, so no upgrade notice is warranted.
