## 1. Playwright project scaffold

- [x] 1.1 Add an `e2e/live/` Playwright project with its own config: base URL of the test
      site, headed run, `fullyParallel: false`, `workers: 1`, `maxFailures: 1`, artifacts
      written to a temporary directory outside the repo
- [x] 1.2 Wire the new project into the existing `e2e/` setup so the admin suite and the
      live suite are separately invocable and the live suite never runs by default
- [x] 1.3 Add auth fixtures producing two storage states — the admin account for the
      settings panel, the customer account for the signed-in matrix pass — plus a guest
      context with no stored state

## 2. Server-side helpers

- [x] 2.1 Add an SSH helper that runs a command in the WP root of the test site using the
      recorded key, and a WP-CLI wrapper on top of it
- [x] 2.2 Add a debug-log helper: resolve the log path from `pixelflow_debug_log_key`,
      truncate it, and read it back
- [x] 2.3 Add a log parser that splits the file on the `\n---\n` separator and returns
      typed event records
- [x] 2.4 Add a polling read that waits for an expected record with a bounded timeout and
      distinguishes "no record appeared" from "a different record appeared"
- [x] 2.5 Add a helper that reads the server PHP error log for a given time window, used
      by the WooCommerce-deactivation check

## 3. Settings panel driver

- [x] 3.1 Add a page object for the plugin settings screen covering the WooCommerce
      integration toggle, the debug-log toggle, the three freebie flags and the
      excluded-SKU field
- [x] 3.2 Make every setter wait for the panel's own save confirmation and fail with the
      panel's error text when the save does not succeed
- [x] 3.3 Add a named settings-preset helper so each scenario declares the combination it
      needs rather than toggling controls inline

## 4. Storefront page objects

- [x] 4.1 Add page objects for the shop listing, the product page (including variation
      selection), the cart and the checkout
- [x] 4.2 Add a checkout helper that completes an order with an offline payment method and
      waits for the thank-you page
- [x] 4.3 Add a coupon helper that applies the `discount` code on the cart page and waits
      for the recalculated totals

## 5. Event matrix specs

- [x] 5.1 AddToCart from the shop listing for simple, free and variable products, asserting
      one record with matching identifiers and price
- [x] 5.2 AddToCart from the shop listing for grouped and external products, asserting no
      record is logged
- [x] 5.3 AddToCart from the product page for all five product types, asserting the variable
      case identifies the selected variation
- [x] 5.4 InitiateCheckout from the cart, asserting one record listing every cart product
- [x] 5.5 Purchase after checkout, asserting one record for the placed order
- [x] 5.6 Parameterise the whole matrix to run once as guest and once as signed-in customer

## 6. Conditional-behaviour specs

- [x] 6.1 Integration disabled: full flow produces no records
- [x] 6.2 Each freebie flag enabled alone with the free product: its event is absent, the
      other two present
- [x] 6.3 All freebie flags off with the free product: all three events present
- [x] 6.4 Repeat the freebie cases with the zero-price variation of the variable product
- [x] 6.5 SKU exclusion with `PF-EXCLUDED` alone, and alongside a tracked product

## 7. Payload specs

- [x] 7.1 `pf_loc` cookie is set on the storefront
- [x] 7.2 `user_data` city/state/postcode/country resolved from the `pf_loc` cookie
- [x] 7.3 `user_data` location still populated for a signed-in customer with a filled
      billing address after the `pf_loc` cookie is removed
- [x] 7.4 Discounted product purchased alone: `custom_data.value` equals the entry's
      `item_price` and reflects the sale price
- [x] 7.5 Several products under the 37% coupon: `custom_data.value` equals the sum of the
      entries' `item_price` and reflects the discounted total

## 8. Skill

- [x] 8.1 Create `.claude/skills/test-live-woo-events/SKILL.md` describing the run and its
      preconditions
- [x] 8.2 Build step: run `build_plugin.sh` and locate the produced archive
- [x] 8.3 Deploy step: upload the archive through wp-admin Plugins → Add New → Upload,
      confirm the replace-with-uploaded screen, and abort with the WordPress error text on
      failure
- [x] 8.4 Post-deploy assertion: installed plugin version matches the built archive and the
      plugin is active
- [x] 8.5 WooCommerce smoke: deactivate WooCommerce, assert the home page and wp-admin
      respond and no plugin-attributable PHP error is logged, then reactivate
- [x] 8.6 Invoke the `e2e/live/` suite and stop the run at its first failure
- [x] 8.7 Report: chat summary of scenarios covered and the failure detail, plus the path to
      the artifact directory holding log excerpts and screenshots
- [x] 8.8 State explicitly in the skill that the run leaves site state untouched afterwards

## 9. Verification

- [ ] 9.1 Run the full suite end to end against the test site and confirm every scenario
      passes
- [ ] 9.2 Verify the failure path: force one scenario to fail and confirm the run stops,
      the report names it, and the artifacts are written
- [ ] 9.3 Confirm the existing admin `e2e/` suite and the vitest suite are unaffected
