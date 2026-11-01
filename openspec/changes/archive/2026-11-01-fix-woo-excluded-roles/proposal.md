## Why

A site owner excluded the Administrator role from tracking, browsed their store while signed in as
an administrator, and still saw AddToCart and InitiateCheckout arrive. The plugin's server-side
WooCommerce dispatcher never reads the excluded-roles setting: release 1.2.0 recorded this as a
known gap, stated it on the settings page and left the alignment to a separate change. No such
change exists yet, and this is it.

The same report's browser page views have a different cause: the site carries a second copy of the
tracking script, added by hand, which no plugin setting can control. The plugin cannot fix that, but
the settings page can stop implying otherwise.

## What Changes

- Live AddToCart and InitiateCheckout are not sent while a signed-in user holds an excluded role:
  no event, no blocked report, no hold.
- Purchase is not sent when the request that would send it belongs to the buyer and that buyer is
  signed in with an excluded role.
- **Known limit, accepted:** the check looks at the current request, not at the order. If a later
  request that is not the buyer's moves the same order to a purchasing status (a payment gateway
  webhook, automation, another staff member), the Purchase is sent. An excluded staff member
  completing a real customer's order also still sends it.
- Replaying events held before the user signed in stays independent of the current request's role.
- The settings control is renamed from `Exclude Script for User Roles` to
  `Exclude Tracking for User Roles`. Its text states what is excluded and both limits: the Purchase
  limit above, and that a tracking script added to the site by hand is not affected.
- The FAQ answer in `readme.txt` and `README.md` is updated to match.
- Release 1.2.3, as agreed with the maintainer.
- Sites that already exclude roles will see fewer WooCommerce events after updating. That is the
  intended result, and the changelog line says so.

## Capabilities

### New Capabilities

- `woo-excluded-roles`: how the excluded-roles setting applies to the plugin's browser script and to
  server-side WooCommerce events.

### Modified Capabilities

- `form-events`: the requirement `The excluded-roles setting states where it does not apply`
  demands a note saying WooCommerce events ignore the setting. That note becomes false, so the
  requirement now names the new title and the two remaining limits.

## Impact

- `includes/woo/hooks/class-woocommerce-hooks.php`: the role gate in `post_event()`.
- `app/source/src/features/settings/components/AdvancedSettings.tsx` and its test: title and text.
- `tests/`: a new `test-woo-excluded-roles.php`; the logged-in user stubs move into the shared
  bootstrap, because every WooCommerce test that reaches `post_event()` now needs them.
- `e2e/live/tests/`: a new scenario for a signed-in excluded administrator on the storefront.
- `readme.txt`, `README.md`, `pixelflow.php`: FAQ, changelog and version.
- `docs/test-scenarios.html`: new rows and the record of the full run.
- No API, consent or blocked-report contract changes.
