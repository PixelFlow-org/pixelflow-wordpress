# Fix: a consent withdrawal made outside a purchase hook never reaches the order

## Why

A buyer who withdraws consent while their order is still unpaid leaves no trace on
the order. When staff or automation later moves that order to a purchasing status,
the send gate sees an order with no recorded objection — or with the consent the
buyer held at checkout — and the Purchase goes out. The buyer said no after the
event was queued and before it was sent, which is exactly the window in which a
withdrawal is supposed to stop it.

This became reachable with the fix for `fix-admin-status-change-no-purchase`.
Before it, a status change from wp-admin or WP-CLI registered no purchase hooks at
all, so nothing was sent in that request for any reason. The live scenario covering
this ("An order whose buyer withdraws consent sends no purchase on a later staff
status change") was therefore passing vacuously — it asserted the absence of an
event in a request that could not produce one. With the hooks registered, the
scenario fails and shows the real behaviour.

## Evidence

The order's consent snapshot is written in exactly two places, and neither one
covers a buyer who changes their mind on a page that fires no purchase hook:

- `pf_save_tracking_cookies_to_order()` (`class-woocommerce-hooks.php:604-646`) runs
  on `woocommerce_new_order`. It takes a snapshot of the consent cookies as they
  stand at order creation and never revisits it.
- `sync_live_consent_onto_order()` (`:958-982`) is the one function that moves a
  later decision onto the order, and its own docblock states the intent: "a grant
  releases a held purchase, a withdrawal stops one that has not been sent". Its only
  call site is inside `pf_purchase_hook()` at `:703-706`, guarded by
  `pixelflow_request_owns_order()`.

So the sync happens only if a purchase hook fires during the buyer's own request.
On the order-pay page, in my-account, or on any storefront page, no purchase hook
runs, and the withdrawal stays in the browser.

Observed on the test site, order 226 — a pending order the buyer opened at its
order-pay URL, withdrew consent on, and which staff then completed:

    == 226 completed cust=3
       _pf_purchase_sent = 1

That is the order's entire PixelFlow meta. No consent snapshot, no record of the
withdrawal, and a delivered purchase.

The gate itself behaves correctly on whatever it is given — probed against that
order on the live site:

    order 226 snapshot: []
    blocked reason with the order as it stands:            NULL (event is sent)
    blocked reason if the withdrawal had been recorded:    {"reason":"denied","consentSource":"complianz"}
    blocked reason with a stale granted snapshot:          NULL (event is sent)

The third line matters: the defect does not depend on the snapshot being empty.
An order created the ordinary way, in the browser, under a granted consent carries
`_pf_cookie__pf_consent = granted`, and a later withdrawal does not overwrite it —
the gate is then handed a decision the buyer has since revoked and sends for the
same reason.

Ownership is not the obstacle. `pixelflow_request_owns_order()` (`consent.php:393`)
recognises the buyer on the order-pay page through the logged-in customer id, and
order 226 belongs to customer 3, who was logged in when they withdrew. The request
that carried the withdrawal was recognised as the buyer's; there was simply nothing
listening for it.

## What must be true

1. A consent withdrawal made by the buyer stops a purchase that has not yet been
   sent, whatever page the buyer withdrew on.
2. A decision recorded at checkout does not outlive a later decision by the same
   buyer.
3. A purchase already delivered is not affected — a withdrawal arriving afterwards
   changes nothing about what was sent.

How to achieve this is for the implementer to decide; this proposal deliberately
does not pick an approach.

## Impact

- Live scenario `e2e/live/tests/consent-ownership.spec.ts:144` fails today and is
  the acceptance oracle for this change.
- The same gap applies to a grant in the opposite direction: a buyer who accepts
  after checkout leaves no record either, so their blocked purchase is reported as
  blocked rather than delivered — unless they happen to land on the thank-you page,
  which is the one storefront request that fires a purchase hook.
