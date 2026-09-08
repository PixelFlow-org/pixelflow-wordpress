# Run report — full verification, 2026-09-08

Two full passes were made on this date. The second, after the fix for the guest-order
lookup, is the one that stands: **82 live scenarios, all green**. The first pass and
the failure that drove the fix are kept below, because that failure is the evidence
for `fix-consent-withdrawal-not-recorded`.

## Second pass — after the guest-order lookup fix

| Batch | Live scenarios | Result |
| --- | --- | --- |
| `consent-ownership.spec.ts` | 5 | 5 passed |
| `consent-blocked.spec.ts` + `consent-purchase-once.spec.ts` | 8 | 8 passed |
| `consent-hold.spec.ts` | 6 | 6 passed |
| the nine remaining spec files | 63 | 63 passed |
| **Total** | **82** | **82 passed** |

PHP: 116 cases across 11 files, all green — six added since the first pass (five on
request identity and hook registration, one on `client_ip_address` in a blocked row).

## First pass — the run that found the defect

Site: `rift.kskonovalov.me`. Build: the working tree of `feature/gdpr-consent-gating`
as of this date, packaged by `build_plugin.sh prod` and installed through the
WordPress plugin uploader (version string 1.1.17 — the branch has not been bumped).
Plugin code carries the fixes for `fix-held-event-double-flush`,
`fix-admin-status-change-no-purchase` and the first half of
`fix-consent-withdrawal-not-recorded`.

## How the live suite was run

In batches by spec file rather than as one `scripts/run.sh` invocation. Two attempts
at the whole matrix in one process were killed by the environment's memory watchdog
before writing any results — with ~40 GB free, so the cause is the watchdog and not
the machine. See `e2e/live/RUN-RELIABILITY.md`. Build, deploy and the WooCommerce
deactivate/reactivate smoke ran once, at the start, and passed.

Each batch re-runs the two `[setup]` authentication projects; the counts below are
live scenarios only, with setup excluded.

| Batch | Live scenarios | Result |
| --- | --- | --- |
| `consent-ownership.spec.ts` | 5 | 4 passed, 1 failed |
| `consent-blocked.spec.ts` + `consent-purchase-once.spec.ts` | 8 | 8 passed |
| `consent-hold.spec.ts` | 6 | 6 passed |
| `add-to-cart-product` + `add-to-cart-shop` + `initiate-checkout` + `purchase` | 32 | 32 passed |
| `excluded-sku` + `freebies` + `value-consistency` + `user-location` + `integration-disabled` | 31 | 31 passed |
| **Total** | **82** | **81 passed, 1 failed** |

## The one failure

`consent-ownership.spec.ts › An order declined by its buyer › is delivered when the
buyer grants away from the order-received page`

    Timed out after 40000ms waiting for 1 dispatched Purchase record(s).
    Log contained: Purchase (Purchase).

A Purchase record is in the log, but as an attempt that went the blocked route. The
grant made on the shop listing never reached the order: order 235 kept its `denied`
snapshot and its pending blocked marker. Cause established — the candidate scan in
`orders_awaiting_this_buyers_decision()` finds a guest's orders only through
`_pf_cookie__pf_uid`, and an order created under a decline carries no `_pf_uid`
(compare 232/233/235 without it against 234/227/228 with it). Filed as
`openspec/changes/fix-consent-withdrawal-not-recorded/`, which is where the remaining
half of that change is tracked.

## Other suites

- PHP at the time of this pass: 110 cases across 11 files, all green (`php tests/test-*.php`, every file exits 0).
- vitest (`app/source/`): 16 cases across 3 files, all green.

## Cost of the per-request consent sync

`record_consent_decision_on_open_orders()` runs on `wp` for every storefront request
that carries a decision, and for a logged-in or `_pf_uid`-bearing visitor it issues up
to two `wc_get_orders()` queries, one of them a meta-value lookup. Measured on the
test site with 14 paired requests to a product-category page, same URL, cache busted:

    no decision  median 1370.3 ms   p10 1125.9   max 2716.0
    granted      median 1314.8 ms   p10 1013.4   max 2395.8

No penalty is measurable — the granted case came out 55 ms faster, which is inside the
noise of a ~1.3 s uncached page. The site holds roughly 240 orders, so this measures
the query at trivial scale; the risk it poses on a store with a large order table is
untested, not disproven.

## Task 8.2 mapping

The `[php]` cases in `tests/test-purchase-once.php` that stand in for the concurrent
and crash paths a browser cannot provoke: `'A blocked purchase is not reported at the
moment of the skip'`, `'Repeated hooks before the window closes do not reschedule or
duplicate'`, `'An overdue report is sent by a later hook, exactly once'`, `'Two
concurrent hooks: only one takes the delivery claim'`, `'A transport failure is not
recorded as a delivery'`, `'The purchase event id is derived from the order and the
event name'`, `'A claim abandoned by a crashed request is taken over and removed'`,
`'A raised request timeout widens the abandonment interval'`.
