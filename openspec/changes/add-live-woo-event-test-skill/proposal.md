## Why

WooCommerce event tracking is the most fragile part of the plugin: the code paths for
AddToCart, InitiateCheckout and Purchase branch on product type, entry page, consent
state, freebie flags, SKU exclusion and discounts, and none of that is covered by the
existing vitest/Playwright suites — those only exercise the React settings panel. Today
every release is verified by hand on the live test site, which is slow, inconsistent, and
easy to cut short. A repeatable, scripted pass over the whole event matrix on a real
WordPress + WooCommerce install turns that manual ritual into a single command.

## What Changes

- New Claude skill `test-live-woo-events` that drives a full verification run against the
  `rift.kskonovalov.me` test site: build the plugin, deploy it, configure the plugin
  through its own settings UI, run the event matrix, read the debug log over SSH, and
  report a pass/fail summary in chat.
- Deploy step installs the freshly built `pixelflow.zip` through the wp-admin
  Plugins → Add New → Upload UI, exercising WordPress's own upgrade path (including the
  "replace current with uploaded" screen) rather than dropping files onto the server.
- New Playwright project under `e2e/live/` holding the storefront scenarios: per product
  type (simple, free, variable, grouped, external), per entry page (Shop, product page),
  per event (AddToCart, InitiateCheckout, Purchase), run once as guest and once as a
  logged-in customer.
- Debug-log assertions read `wp-content/.../pixelflow-debug-<key>.log` over SSH, splitting
  records on the plugin's `\n---\n` separator, with the log truncated before each case so
  a case's records are unambiguous.
- Dedicated verification of `pf_loc` / `user_data` population (with and without the
  cookie), of `custom_data.value` consistency with `custom_data.contents[].item_price`
  under a product discount and under the 37% `discount` coupon, of SKU exclusion via
  `PF-EXCLUDED`, of each of the three freebie flags individually, and of the site staying
  healthy while the WooCommerce plugin itself is deactivated and reactivated.

## Capabilities

### New Capabilities
- `live-event-verification`: end-to-end verification of the WooCommerce event pipeline on a
  real site — deployment, settings configuration, the storefront event matrix, debug-log
  assertions, and run reporting.

### Modified Capabilities
<!-- none: plugin behavior is unchanged; this change only adds verification tooling -->

## Impact

- New: `.claude/skills/test-live-woo-events/SKILL.md`, `e2e/live/` (Playwright config,
  fixtures, specs, helpers).
- Depends on the existing `build_plugin.sh` entry point and on the plugin's debug log
  format (`pixelflow_write_debug_log_entry`, `\n---\n` record separator) — both are read,
  neither is modified.
- Depends on prepared state on the test site: WooCommerce with offline gateways, the `PF-*`
  product fixtures, the `discount` coupon, an admin account and a customer account with a
  filled billing address, and an already-configured Pixelflow connection.
- Runs against a live site with a real Pixelflow test account: events are actually sent,
  and test orders accumulate. No production system is touched.
- No plugin source code changes; no changes to the existing `e2e/` admin specs or to the
  vitest suite.
