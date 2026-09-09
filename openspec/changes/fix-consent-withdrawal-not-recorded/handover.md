# Handover — a consent decision made outside a purchase hook never reaches the order

## Status after the 2026-09-08 build (read this first)

Half done. `record_consent_decision_on_open_orders()` works, and the withdrawal
scenario is green — order 236 recorded the buyer's `denied` and sent nothing.

What is still open is the **grant after a decline**, and the cause is the candidate
scan, not the sync. `orders_awaiting_this_buyers_decision()` finds a guest's orders
only through `_pf_cookie__pf_uid`, and an order created while consent was denied never
gets a `_pf_uid` stamped on it — compare orders 232/233/235 (declined, no uid) against
234/227/228 (granted, uid present). So the one population whose grant matters — a
declined buyer with a blocked purchase still pending — is exactly the one the scan
cannot see. Order 235 kept its `denied` snapshot and its pending marker.

Observation, not a prescription: `_pf_session_customer_id` is on order 235 and is one
of the signals `pixelflow_request_owns_order()` already accepts, but the scan does not
query by it. The same blind spot applies to a guest who withdraws consent having
placed the order under a decline.

Everything else below still stands.

## In one paragraph

An order carries the consent snapshot taken at its creation, and nothing updates it
afterwards unless a purchase hook happens to run in the buyer's own request. So a
buyer who changes their mind while the order is still unpaid — withdrawing or
granting — leaves no trace on the order. When staff or automation later move that
order to a purchasing status, the gate is handed a stale decision, or none at all,
and does the wrong thing in both directions: it sends a purchase the buyer has since
forbidden, and it withholds one the buyer has since allowed.

## The single call site

`sync_live_consent_onto_order()` (`includes/woo/hooks/class-woocommerce-hooks.php:958-982`)
is the only function that moves a later decision onto an order, and its own docblock
states the intent: *"a grant releases a held purchase, a withdrawal stops one that has
not been sent"*. Its only caller is `pf_purchase_hook()` at `:703-706`:

    $owns_order = pixelflow_request_owns_order($order);
    if ($owns_order) {
        $this->sync_live_consent_onto_order($order);
    }

The other write path, `pf_save_tracking_cookies_to_order()` (`:604-646`), runs on
`woocommerce_new_order` and takes a one-time snapshot.

On the storefront, the only request that fires a purchase hook is the order-received
page. Every other page a buyer might reconsider on — order-pay, my-account, the shop
listing — runs no purchase hook, so the decision stays in the browser.

Ownership is not the obstacle: `pixelflow_request_owns_order()` (`includes/consent.php:393`)
already recognises the buyer on those pages, through the `_pf_uid` cookie, the
logged-in customer id, the awaiting-payment order in the session, or the stored
session customer id.

## Evidence from the live site

Order 226 — a pending order whose buyer opened its order-pay URL, withdrew consent
there, and which staff then completed. Its entire PixelFlow meta afterwards:

    == 226 completed cust=3
       _pf_purchase_sent = 1

No consent snapshot, no record of the withdrawal, purchase delivered.

The gate itself is correct; the input it is given is wrong. Probed against that order:

    order 226 snapshot: []
    blocked reason with the order as it stands:            NULL (event is sent)
    blocked reason if the withdrawal had been recorded:    {"reason":"denied","consentSource":"complianz"}
    blocked reason with a stale granted snapshot:          NULL (event is sent)

The third line is the important one: the defect does not depend on the snapshot being
empty, which is an artefact of the test creating its order over WP-CLI. An order
created the ordinary way under a granted consent carries `_pf_cookie__pf_consent =
granted`, a later withdrawal does not overwrite it, and the gate sends for the same
reason.

## Why this surfaced now

The fix for `fix-admin-status-change-no-purchase` moved the purchase hooks above the
`is_admin()` and cache-warmer guards. Before it, a status change from wp-admin or
WP-CLI registered no purchase hooks at all, so nothing was sent in those requests for
any reason — and the live scenario covering the withdrawal was passing vacuously,
asserting the absence of an event in a request that could not produce one. That fix
is correct and is not the cause; it removed the screen that hid this.

## Acceptance

Two live scenarios in `e2e/live/tests/consent-ownership.spec.ts`, both red on the
current build. They are the oracle — do not edit them to go green:

1. `An order whose buyer withdraws consent › sends no purchase on a later staff status change`
   Currently fails with: `Expected no event to be sent, but these were dispatched: Purchase (Purchase).`
2. `An order declined by its buyer › is delivered when the buyer grants away from the order-received page`
   Currently fails with: `Timed out after 40000ms waiting for 1 dispatched Purchase record(s).
   Log contained: Purchase (Purchase).` — the wording matters: a Purchase record *is* in the
   log, but as an attempt that went the blocked route, not as a dispatch. The grant never
   reached the order.

Plus the requirements in `specs/consent-resolution/spec.md` of this change, and the
existing PHP suites, which must stay green: `php tests/test-*.php`, 110 cases across
11 files.

The already-green scenarios that must not regress:
`is unaffected by a stranger opening its order-received URL`,
`is unaffected by a staff status change made from the wp-admin session`,
`is delivered when the buyer grants on their own order-received page`, and the whole
of `consent-blocked.spec.ts`.

## Also in scope: regression tests for the admin/WP-CLI fix

The 17 ad-hoc cases written while fixing `fix-admin-status-change-no-purchase` live in
a scratchpad directory and are not in the repository — `tests/` contains no case that
exercises hook registration under a raised `is_admin()` or cache-warmer guard. Move
them into `tests/` as part of this task, following the existing style (plain PHP
scripts run as `php tests/test-<name>.php`, exit 0 = green). At minimum the ones that
pin the behaviour a future edit to `init_hooks()` would silently undo:

- purchase hooks are registered when both guards are raised;
- storefront hooks are still not registered in an admin request;
- a status change sends exactly one Purchase carrying the order's IP and UA;
- a repeat status change sends nothing;
- an order with no identity of its own goes out without IP, UA and `_fbp`, and not
  with the staff member's;
- a declined order sends nothing;
- an overdue marker produces exactly one blocked row and closes the order.

## Out of scope

Anything about how the decision should be carried onto the order — which hook, which
request, whether the order is found through the session or through the buyer's recent
unsent orders. That is the implementer's call; this handover deliberately does not
pick an approach.
