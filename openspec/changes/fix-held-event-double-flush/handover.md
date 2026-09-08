# Handover

Written after the live consent verification of `add-gdpr-consent-verification`. It carries
what the next implementer needs that the proposal and delta spec do not say: the second
open question, the environment preconditions, and the traps that cost a full session.

## Work item 1 — the confirmed defect

See `proposal.md` and `specs/consent-resolution/spec.md` in this folder for the evidence.

In short: every held event is dispatched **twice** on a grant, both copies carrying the same
`event_id` — once from `?wc-ajax=pixelflow_held_state`, once from
`?wc-ajax=pixelflow_resolve_held_events`. Reproduced in two runs across three scenarios,
each off by exactly one copy.

The lead: `ajax_held_state()` is documented as a read-only state route and dispatches
nothing itself (`includes/woo/hooks/trait-held-woo-events.php:45`), but the flush is also
hooked on `wp` — `add_action('wp', [$this, 'resolve_held_events'], 30)`
(`includes/woo/hooks/class-woocommerce-hooks.php:124`) — and `wp` fires on that wc-ajax
request too.

**Not established, and worth establishing rather than assuming:** why the recipe is still in
the queue for the second request at all, given that `flush_held_events()` clears the queue
before dispatching (`trait-held-woo-events.php:88`).

Acceptance oracle: `e2e/live/tests/consent-hold.spec.ts`, the two flush scenarios
("accepting the banner flushes both held events…" and "the flush happens on the consent
signal…"). They are red today and must go green **without being edited**.

## Work item 2 — an open diagnosis (tasks 5.5 / 5.6)

Driving the overdue blocked-purchase report from a wp-cli status change produces nothing at
all: no log record, the `_pf_purchase_blocked` marker untouched,
`_pf_purchase_blocked_reported` unset.

Already ruled out:

- the plugin's Woo hooks **do** load under WP-CLI — `has_action('woocommerce_order_status_processing')`
  returns true there, and `PixelFlow_WooCommerce_Cart_Hooks::instance()` is non-null
- the blocked-events payload builder does **not** gate on a missing client IP; it only omits
  the field (`includes/blocked-events.php:206-210`)

Two candidates remain, not yet distinguished:

1. the **send** path skips silently on a private or empty client IP
   (`dispatch_event_post`, `class-woocommerce-hooks.php:1736-1756`) — a CLI request has no
   client IP at all, so if the consent gate does not block, the event dies there with no
   trace in the log;
2. the order carries no persisted `_pf_cookie__pf_consent`, so the gate never blocks in the
   first place and the event takes the send path above.

One probe distinguishes them: dump the order's PixelFlow meta immediately before the status
change and see whether a denied decision is actually on the order.

Likely shape of the fix for the scenarios themselves: a browser-driven status change through
wp-admin (as the ownership scenario at `consent-ownership.spec.ts` already does) plus
`runCronOverHttp()`, already added to `e2e/live/helpers/order-meta.ts`, which runs due cron
over a loopback HTTP request instead of a CLI process.

## Environment preconditions

- **Deploy the build before any live run.** The test site was found running a build dated
  2026-09-06 that reports the same version string (1.1.17) as the working tree but contains
  none of the `fix-consent-gating-review` work — `purchase_event_id`,
  `pixelflow_request_owns_order` and `defer_blocked_purchase_report` were all absent. Half a
  session of "defects" were measured against it. Step 2 of `e2e/live/scripts/run.sh` is not
  optional, even when the change under test alters no plugin source.
- The consent platform: WP Consent API and Complianz both active, with
  `wp_get_consent_type() === 'optin'`. `global-setup.ts` asserts this and refuses to run
  otherwise; it deliberately does not repair the site.
- If global setup reports `product fixtures missing from the site: wp-pennant`, the product
  is fine — its row in `wc_product_meta_lookup` is gone, and `wc_get_product_id_by_sku()`
  reads that table. `$product->save()` does not recreate the row; run
  `wp wc tool run regenerate_product_lookup_tables --user=1`.

## Traps

- **The debug log records attempts, not sends.** A held or skipped event is written with its
  full `eventData` and a `response` string explaining what happened to it
  ("EVENT SENDING HELD UNTIL…", "EVENT SENDING SKIPPED (denied)…"). Only a real dispatch has
  a `response` object carrying `code`. `e2e/live/helpers/debug-log.ts` exposes
  `wasDispatched`, `sentRecords`, `heldRecords` and `skippedRecords` for exactly this —
  counting records by event name alone reads a correctly held event as a leak.
- **Playwright's `request.headers()` omits cookie headers by design.** Use
  `await request.allHeaders()` when checking what cookies a request carried, or you will
  conclude a request had none when it had them.
- `wp wc order update` does not exist on this site — there is no `order` subcommand. Status
  changes go through `wp eval` (`setOrderStatus()` in `e2e/live/helpers/order-meta.ts`).
- The thank-you URL on this site is `?page_id=11&order-received=<id>&key=…`, not the pretty
  `/order-received/<id>/` permalink.
- Granting consent on an already-loaded page changes cookies only. The purchase hook runs on
  a request, so a scenario that grants on the order-received page must reload it afterwards.

## Do not touch

`e2e/live/tests/consent-*.spec.ts`, `e2e/live/fixtures.ts`,
`e2e/live/pages/consent-banner.ts` and `e2e/live/helpers/*` are the acceptance oracle.
They must go green from a code fix, not from an edited expectation.

One exception already applied and deliberate: `e2e/live/tests/excluded-sku.spec.ts` asserted
that a SKU-excluded product still appears in the `contents` of InitiateCheckout and Purchase.
`fix-consent-gating-review` changed that on purpose ("Products excluded by SKU are dropped
from `contents`, `num_items` and `value`"), so the spec encoded pre-change behaviour and its
expectations were updated to match the decision.

## Also in the repo

`openspec/changes/fix-store-api-consent-gate/` is marked **WITHDRAWN**. Its conclusions were
drawn from the stale build described above and must not be acted on. It is kept only as a
record; deleting it is fine.
