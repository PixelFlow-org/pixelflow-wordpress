## Why

An order whose status is moved by staff in wp-admin sends no Purchase event, and never will:
the plugin registers its purchase hooks only outside the admin context, and there is no
scheduled path for a real Purchase to fall back on.

For a shop that completes orders by hand — bank transfer, invoice, cash on delivery, phone
orders, anything where the buyer does not return to the thank-you page — the conversion is
lost entirely. Nothing in the log, nothing at the API, no later retry.

## Evidence

`init_hooks()` returns before registering the purchase hooks whenever the request is an admin
request (`includes/woo/hooks/class-woocommerce-hooks.php:99-101`):

```php
if (is_admin()) {
    return;
}
```

Everything below that guard is skipped, including:

```php
add_action('woocommerce_order_status_processing', [$this, 'pf_purchase_hook'], 10, 1);
add_action('woocommerce_order_status_completed',  [$this, 'pf_purchase_hook'], 10, 1);
add_action('woocommerce_thankyou',                [$this, 'pf_purchase_hook'], 10, 1);
```

Only the blocked-report hook is registered above the guards
(`class-woocommerce-hooks.php:93`), with a comment stating why: the scheduler runs in neither
a front-end nor an admin context, and a report that never fires would undercount the backend.
That reasoning applies just as much to a real Purchase, which has no such fallback — the one
`wp_schedule_single_event()` call in the file schedules the blocked report only
(`class-woocommerce-hooks.php:852`).

Observed on `rift.kskonovalov.me` with the current build:

- order 217, moved `processing → completed` through the wp-admin order screen with an overdue
  blocked marker present: nothing written to the debug log, `_pf_purchase_blocked` still on
  the order, `_pf_purchase_blocked_reported` unset. The status change itself applied — the
  order reads `completed`.
- order 212, same state, with `pf_purchase_hook()` invoked directly: reported immediately —
  `_pf_purchase_blocked_reported = 1` and one `BLOCKED_EVENTS Purchase` record in the log. So
  the hook body works; it simply is never called from an admin request.
- the scheduled path does fire: the live scenario driving
  `pixelflow_report_blocked_purchase` passes, because that hook is registered above the guards.

The buyer's identity is not the obstacle. IP and user agent are read from the order meta saved
at creation from the real browser request, falling back to the current request only when the
order has none (`class-woocommerce-hooks.php:1197-1205`), and customer data is built from the
order. The ownership predicate added by `fix-consent-gating-review` already answers "this
request is not the buyer's" correctly — the live scenario in which an administrator changes the
status of an order the buyer declined passes today, sending nothing, from the persisted
decision alone.

## What must be true

- A Purchase SHALL be delivered for an order that reaches a purchasing status, whatever kind of
  request performs the transition — including a staff status change made in wp-admin, and
  including an order the buyer never confirmed on a thank-you page.
- Exactly one Purchase per order still, and the existing consent rules unchanged: a declined or
  held order still sends nothing, and a decision made by someone who is not the buyer still
  changes nothing about the order.
- No request that is not the buyer's may contribute its own identity to the event — in
  particular the client IP and user agent of a staff member must never be attributed to the
  buyer when the order carries none of its own.

How to achieve this is for the implementer to decide; this proposal deliberately does not pick
an approach.

## Impact

- `includes/woo/hooks/class-woocommerce-hooks.php` — hook registration and, depending on the
  approach chosen, the request-derived fallbacks in the purchase payload builder.
- Task 5.5 of `add-gdpr-consent-verification` ("with the marker's due time rewritten to the
  past, a status change reports exactly one blocked row and closes the order to further
  traffic") cannot pass until this is settled: it drives the status change through wp-admin,
  where no purchase hook runs. It is written and waiting.
- Worth checking alongside: WP-CLI requests also register no purchase hooks — measured on the
  site, `has_action('woocommerce_order_status_processing', [$instance, 'pf_purchase_hook'])` is
  false there while the singleton exists. The `pixelflow_is_cache_warmer_request()` guard
  immediately above `is_admin()` is the likely reason. Automation that moves orders over wp-cli
  would lose conversions the same way.
