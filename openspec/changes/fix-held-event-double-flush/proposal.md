## Why

Every event held for a consent decision is dispatched **twice** when the shopper finally
grants consent. The two copies carry the same `event_id`, so the backend counts one unit
of traffic per copy for an event the shopper performed once.

Found by the live consent suite added in `add-gdpr-consent-verification`, reproduced in
two independent runs across three scenarios, each off by exactly one copy:

| Scenario | Expected | Received |
| --- | --- | --- |
| accepting flushes two held AddToCart events | 2 | 3 |
| cart changed between hold and grant (one InitiateCheckout) | 1 | 2 |
| flush happens on the consent signal (one AddToCart) | 1 | 2 |

## Evidence

From the debug log of a single run, the three `AddToCart` records dispatched after one
grant on a cart holding two products:

| # | product | `event_id` | `REQUEST_URI` |
| --- | --- | --- | --- |
| 1 | T-Shirt (17) | `6a9eec624db203.47182221` | `/?wc-ajax=pixelflow_held_state` |
| 2 | PF On Sale (63) | `6a9eec62bc74e5.18697016` | `/?wc-ajax=pixelflow_held_state` |
| 3 | T-Shirt (17) | `6a9eec624db203.47182221` | `/?wc-ajax=pixelflow_resolve_held_events` |

Record 3 repeats record 1 exactly, including its `event_id`, from the other endpoint.

Two facts from the code bear on this:

- `ajax_held_state()` is documented as a "read-only state route" that only answers
  `hasQueue` and mints a nonce (`includes/woo/hooks/trait-held-woo-events.php:45`). It
  dispatches nothing itself — yet records 1 and 2 were sent from that request.
- The flush is also hooked on `wp`: `add_action('wp', [$this, 'resolve_held_events'], 30)`
  (`includes/woo/hooks/class-woocommerce-hooks.php:124`). `wp` fires on every front-end
  request, the `wc-ajax` state route included, so that request flushes the queue as a side
  effect before the read-only handler answers.

The storefront script then POSTs to `pixelflow_resolve_held_events` — the flush it was told
to make — and one recipe is dispatched a second time. Why the queue still held that recipe
at that point is **not established here**: `flush_held_events()` clears the queue before
dispatching (`trait-held-woo-events.php:88`), so the second send implies the clear had not
reached the shopper's session by the time the second request read it. Confirming that needs
instrumentation of the two requests and is left to the fix.

## What Changes

A held event must reach the API exactly once per grant, whichever route observes the
decision first. The direction is not chosen here; the diagnosis above should settle it.

## Impact

- `includes/woo/hooks/trait-held-woo-events.php` and the `wp` hook registration in
  `includes/woo/hooks/class-woocommerce-hooks.php:124`.
- `e2e/live/tests/consent-hold.spec.ts` is the acceptance oracle: its three flush scenarios
  fail today and must pass afterwards, unchanged.
- The hold and decline scenarios around them already pass, so the gating itself is sound —
  this is specifically about the flush being performed twice.
