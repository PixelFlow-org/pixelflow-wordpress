## Context

See `proposal.md` for the motivation and `specs/` for the required behaviour.

`pixelflow_current_user_has_excluded_role()` (`includes/helpers.php:303`) already gates the tracking
script (`pixelflow.php:288`), the held-events script (`pixelflow.php:333`) and form events
(`includes/forms/dispatcher.php:76`). The WooCommerce dispatcher never calls it.

Every WooCommerce event goes through `PixelFlow_WooCommerce_Cart_Hooks::post_event()`: the two
AddToCart producers (`class-woocommerce-hooks.php:257`, `:440`), InitiateCheckout (`:683`), Purchase
(`:882`) and held replay (`trait-held-woo-events.php:134`). The only `/event` POST is reached from
it. `$this->options` already holds `pixelflow_general_options`
(`class-woocommerce-integration.php:64-66`).

Purchase differs from the other two events. `pf_purchase_hook()` runs on `processing` and `completed`
in whatever request moved the order, including wp-admin, WP-CLI and gateway callbacks
(`class-woocommerce-hooks.php:104-110`). `pixelflow_request_owns_order()` (`includes/consent.php:440`)
decides whether that request is the buyer's and feeds the `allow_live` context flag. `_pf_purchase_sent`
is written only on a `sent` outcome.

## Goals / Non-Goals

**Goals:**

- One gate that every live WooCommerce send passes through, matching the form events' behaviour:
  no event, no blocked report, no hold.
- The settings page describes what the setting really does, including its limits.

**Non-Goals:**

- Detecting, warning about or removing tracking scripts added outside the plugin. The plugin cannot
  control them; the settings text states the limit, and the site owner removes such a copy.
- Keeping excluded users' orders out of Purchase in every request (see Decision 2).
- A new blocked-report reason for excluded roles. `PIXELFLOW_BLOCKED_EVENT_REASONS` stays closed, as
  in the form-events change.

## Decisions

### 1. Gate at the top of `post_event()`

Insert the check after the context is read and before identity, consent and bot resolution:

```php
if ( ! $this->flushing_held
    && $allow_live
    && pixelflow_current_user_has_excluded_role(is_array($this->options) ? $this->options : [])
) {
    // Log 'USER ROLE IS EXCLUDED FROM TRACKING' through debug_log(), then:
    return 'skipped';
}
```

The log wording matches the form dispatcher. `debug_log()` already honours the debug switch.

*Alternative rejected:* a check in each producer. That is four places to keep in step, and it would
need a separate exemption for the replay path.

### 2. Purchase follows the current request, not the order's customer

The maintainer chose this over checking the role of the order's customer. Skipping only when the
request is the buyer's (`allow_live`) needs no Purchase-specific code and keeps one rule for all
WooCommerce events. A real customer's order completed by excluded staff is still sent.

*Accepted cost:* an excluded user's test order is skipped in their own request, but `_pf_purchase_sent`
stays unset, so a later gateway webhook, automation or other staff status change sends it. The spec
states this as required behaviour and the settings page states it to site owners.

*Alternatives rejected by the maintainer:* checking the order's customer (closes the leak, but does
not count a staff member's genuine personal purchase); leaving Purchase untouched (admins' test orders
always count).

### 3. Reuse the existing `skipped` outcome

`skipped` already means "nothing was sent or reported". `send_settled_the_event()` returns false for
it, so AddToCart and InitiateCheckout release their dedupe keys
(`class-woocommerce-hooks.php:261`, `:444`, `:690`), and Purchase releases its delivery claim without
writing the sent marker (`:891`). A user who signs out and shops as a guest in the same session is therefore
not silenced by an earlier skip.

Steps in `pf_purchase_hook()` before `post_event()`, namely the consent sync and the first-purchase
record, still run. They only write order data, which is correct for an order that may be delivered
later.

### 4. Held replay stays exempt

`flushing_held` bypasses the gate. Guests are never excluded (`includes/helpers.php:298`), so a held
recipe was captured for a guest, and the replay may run on a later request by someone else. The
existing `post_event()` code already treats replay as not belonging to the current request
(`class-woocommerce-hooks.php:2119-2120`). The gate returning early does not stop replay on page
views, because `resolve_held_events_on_page_view()` runs separately on `wp`.

### 5. Settings text

Title: `Exclude Tracking for User Roles`.

Description, always shown:

> Select user roles that should not be tracked while signed in. The tracking script is not loaded for
> them, and their form and WooCommerce events are not sent. Useful for excluding administrators,
> editors, or other internal users.

Note, shown when at least one role is selected (replaces the current note):

> **Note:** A tracking script added to the site by hand is not affected by this setting.

Note, keeping its `excluded-roles-woo-note` test id and its display condition (WooCommerce active and
tracking on):

> A Purchase can still be sent when a payment gateway, automation or another staff member changes the
> order's status, because that request does not belong to the excluded user.

FAQ answer in `readme.txt:163` and `README.md:426`:

> Yes. Under Advanced Settings, choose any user roles (Administrator, Editor, Shop manager and more)
> that should not be tracked. The tracking script is not loaded for them, and their form and
> WooCommerce events are not sent while they are signed in. A Purchase can still be sent when a
> payment gateway, automation or another staff member changes the order's status, and a tracking
> script added to the site by hand is not affected.

### 6. Move the logged-in user stubs into the shared test bootstrap

`is_user_logged_in()` and `wp_get_current_user()` exist only in `tests/bootstrap-forms.php:123-131`,
which loads `bootstrap-wp-stubs.php` first and declares them unguarded. After the gate, WooCommerce
tests that reach `post_event()` call them, so they would fatal. Adding a copy to the shared bootstrap
would redeclare them in the forms tests. Therefore the stubs move to `bootstrap-wp-stubs.php`,
defaulting to signed out through `$GLOBALS['__pf_user'] ?? null`, and leave `bootstrap-forms.php`.

## Risks / Trade-offs

- [Event counts drop on sites that already exclude roles] → Intended; the changelog line says so.
- [An excluded user's test order can still send Purchase] → Accepted (Decision 2), stated in the spec,
  settings page and FAQ, and covered by a test.
- [The gate depends on WordPress knowing the user during the send] → Observed: on a live store, a
  signed-in administrator's AddToCart and InitiateCheckout carried identity built from the current
  user, so that storefront path resolves the user. Not observed: Store API requests from block cart
  and checkout, where WordPress can drop cookie authentication. If the user is unknown there, the
  helper returns false and the event is sent as today, so it fails open. The live scenario checks the
  path the test site uses; any path found to fail open is reported before this change is called done.
- [Site owners still see page views from a hand-added script after updating] → The settings text states
  the limit. The owner of the reporting site is told separately to remove their copy; that is not part
  of this change.

## Migration Plan

No data migration. The behaviour changes when the plugin updates. Rollback is reverting to the
previous release.
