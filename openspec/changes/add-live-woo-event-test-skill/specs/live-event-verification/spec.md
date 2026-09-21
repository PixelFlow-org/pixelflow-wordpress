## Purpose

Defines a repeatable verification run that proves the plugin's WooCommerce event pipeline
behaves correctly on a real WordPress install — covering deployment, settings, the
storefront event matrix, and the contents of the events the plugin records.

## ADDED Requirements

### Requirement: Plugin deployment through the WordPress upload path

The verification run SHALL build the plugin from the working tree and install the
resulting archive through the WordPress admin plugin-upload flow, replacing the version
already present on the site, so that the WordPress upgrade path is exercised on every run.

#### Scenario: Fresh build replaces the installed plugin

- **WHEN** the run starts
- **THEN** the plugin archive is built from the current working tree
- **AND** it is uploaded through wp-admin Plugins → Add New → Upload Plugin
- **AND** the upgrade screen's replace-with-uploaded option is confirmed
- **AND** the run aborts with the WordPress error text if the upload or replacement fails

#### Scenario: Deployed version matches the build

- **WHEN** the upload has completed
- **THEN** the plugin version reported by the site equals the version in the built archive
- **AND** the plugin is active

### Requirement: Site health without WooCommerce

The plugin SHALL NOT break the site when WooCommerce is absent. The verification run
deactivates the WooCommerce plugin, checks the site, then reactivates it.

#### Scenario: Storefront and admin survive WooCommerce deactivation

- **WHEN** the WooCommerce plugin is deactivated while Pixelflow stays active
- **THEN** the site home page and the wp-admin dashboard both respond successfully
- **AND** no PHP fatal error, warning or notice attributable to the plugin appears in the
  server error log for those requests

#### Scenario: WooCommerce is restored

- **WHEN** the deactivation check has finished
- **THEN** WooCommerce is reactivated before any event scenario runs

### Requirement: Settings are configured through the plugin's own settings screen

Every settings change a scenario depends on — the WooCommerce integration toggle, the
debug-log toggle, the three freebie toggles, and the excluded-SKU list — SHALL be made by
operating the plugin's React settings panel in the browser and confirming the panel
reports the settings saved.

#### Scenario: A settings change is applied and confirmed

- **WHEN** a scenario requires a different settings combination
- **THEN** the settings panel is opened and the corresponding controls are set
- **AND** the run proceeds only after the panel reports a successful save
- **AND** the run fails with the panel's own error text if the save does not succeed

### Requirement: Debug-log records are attributed to exactly one scenario

Event assertions SHALL read the plugin's debug log from the server. The log is emptied
immediately before each scenario, so every record found afterwards belongs to that
scenario and to no other.

#### Scenario: Log is isolated per scenario

- **WHEN** a scenario is about to start
- **THEN** the debug log file is truncated
- **AND** after the scenario's storefront actions the log is read back and parsed into
  individual event records

#### Scenario: Absence of events is asserted, not assumed

- **WHEN** a scenario expects no event
- **THEN** the assertion passes only if the log contains no event record for the products
  involved

### Requirement: Event matrix across product types and entry pages

With the WooCommerce integration enabled and debug logging on, the run SHALL exercise
AddToCart from the shop listing and from the product page for simple, free, variable,
grouped and external products; InitiateCheckout from the cart page; and Purchase after
completing checkout with an offline payment method. The whole matrix runs twice: once as
a guest and once as a logged-in customer.

#### Scenario: AddToCart from the shop listing

- **WHEN** a purchasable simple product — including the free one and the discounted one —
  is added to the cart from the shop listing
- **THEN** exactly one AddToCart record is logged for that product
- **AND** its content identifiers and price match the product

#### Scenario: Products whose listing control navigates instead of adding

- **WHEN** a variable, grouped or external product is activated from the shop listing,
  where the control opens the product page or an external site instead of adding to the
  cart
- **THEN** no AddToCart record is logged

#### Scenario: AddToCart from the product page

- **WHEN** a simple product is added to the cart from its own product page
- **THEN** exactly one AddToCart record is logged for that product

#### Scenario: AddToCart for a selected variation

- **WHEN** a variation of the variable product is selected on the product page and added
- **THEN** exactly one AddToCart record is logged
- **AND** it identifies the selected variation and carries that variation's price

#### Scenario: AddToCart for grouped children

- **WHEN** two children of the grouped product are added from its product page
- **THEN** one AddToCart record is logged per child added

#### Scenario: External product on its own page

- **WHEN** the external product's buy control is activated on its product page, where it
  leads to an external site
- **THEN** no AddToCart record is logged

#### Scenario: InitiateCheckout from the cart

- **WHEN** checkout is started from the cart page
- **THEN** exactly one InitiateCheckout record is logged
- **AND** it lists every product in the cart

#### Scenario: Purchase after checkout

- **WHEN** an order is placed with an offline payment method and the thank-you page is
  reached
- **THEN** exactly one Purchase record is logged for that order

#### Scenario: Guest and customer runs

- **WHEN** the matrix has completed
- **THEN** it has been run once as an anonymous visitor and once as a signed-in customer

### Requirement: No events while the WooCommerce integration is disabled

With the plugin's WooCommerce integration turned off, the storefront SHALL produce no
WooCommerce event records.

#### Scenario: Integration off produces no events

- **WHEN** the integration is disabled and a product is added to the cart, checkout is
  started, and an order is placed
- **THEN** no AddToCart, InitiateCheckout or Purchase record is logged

### Requirement: Freebie suppression flags act independently

Each of the three freebie flags SHALL suppress its own event for a zero-price product and
leave the other two events unaffected.

#### Scenario: One flag at a time

- **WHEN** exactly one freebie flag is enabled and the full add-to-cart → checkout →
  purchase flow is completed with a free product
- **THEN** the event that flag governs is absent from the log
- **AND** the other two events are present

#### Scenario: All flags off

- **WHEN** all three freebie flags are disabled and the same flow is completed
- **THEN** all three events are logged for the free product

#### Scenario: Free variation

- **WHEN** the flow is completed with the zero-price variation of the variable product
- **THEN** the flags govern its events exactly as they do for the standalone free product

### Requirement: SKU exclusion suppresses events for the excluded product only

A product whose SKU appears in the excluded-SKU setting SHALL never produce an AddToCart
event of its own. The cart-level events — InitiateCheckout and Purchase — SHALL be skipped
only when every item in the cart is excluded; with at least one tracked item they fire
normally, reporting the whole cart.

#### Scenario: Excluded product alone

- **WHEN** the excluded product is added to the cart and purchased on its own
- **THEN** no event record is logged at all

#### Scenario: Excluded product alongside a tracked one

- **WHEN** the excluded product and a tracked product are both added to the cart
- **THEN** an AddToCart record is logged for the tracked product and none for the excluded
  one
- **AND** the cart-level events fire and report both products, because the cart is not
  entirely excluded

### Requirement: User location data is populated

Events SHALL carry the shopper's location — city, state, postcode and country. For an
anonymous visitor it comes from the `pf_loc` cookie; for a signed-in customer the address
stored on the account takes precedence over the cookie, and remains available when the
cookie is absent. In the logged payload these fields are the `ct`, `st`, `zp` and
`country` keys of `eventData.customerData`, and a signed-in customer's values are hashed.

#### Scenario: Location cookie is set on the storefront

- **WHEN** the storefront is visited
- **THEN** a `pf_loc` cookie is set

#### Scenario: Anonymous visitor takes location from the cookie

- **WHEN** an anonymous visitor with a `pf_loc` cookie adds a product to the cart
- **THEN** the record's `customerData` carries city, state, postcode and country
  consistent with the cookie

#### Scenario: A signed-in customer's own address wins over the cookie

- **WHEN** a signed-in customer with a filled billing address and a `pf_loc` cookie adds a
  product to the cart
- **THEN** the record's `customerData` carries the account's address rather than the
  cookie's values

#### Scenario: Location resolved without the cookie

- **WHEN** a signed-in customer with a filled billing address has the `pf_loc` cookie
  removed and then adds a product to the cart
- **THEN** the record's `customerData` location fields are still populated and non-empty

### Requirement: Reported value matches the sum of item prices

The `value` of an event's custom data SHALL equal the sum of the `item_price` values of
its `contents`, both for a product whose own price is discounted and for a cart under a
coupon. In the logged payload the custom data is `eventData.additionalData`.

#### Scenario: Discounted product

- **WHEN** a product carrying a sale price is purchased on its own
- **THEN** `additionalData.value` equals the single entry's `item_price`
- **AND** that price is the discounted price, not the regular one

#### Scenario: Multiple products under a coupon

- **WHEN** several products are in the cart and a percentage coupon is applied
- **THEN** `additionalData.value` equals the sum of every entry's `item_price`
- **AND** the sum reflects the discounted total, not the pre-coupon total

### Requirement: Preconditions are verified before the run starts

The run SHALL check, before opening a browser, that the site is reachable and holds
everything the matrix names — the plugin and WooCommerce active, the Pixelflow connection
on, a debug log key, a visible storefront, the product fixtures and their category, the
coupon, the customer account with an address, and an offline payment method. A missing
precondition SHALL abort the run immediately, naming what is missing.

#### Scenario: A fixture is missing

- **WHEN** any precondition is not met
- **THEN** the run aborts before the first scenario
- **AND** the message names every unmet precondition

#### Scenario: Everything is in place

- **WHEN** all preconditions hold
- **THEN** the run proceeds to the scenarios without further manual checks

### Requirement: Run reporting and failure behaviour

The run SHALL execute every scenario, whether or not earlier ones failed, and report what
was verified, what failed, and where the evidence is. Each scenario establishes its own
starting state, so a failure does not invalidate the scenarios after it.

#### Scenario: A scenario fails

- **WHEN** an assertion fails
- **THEN** the remaining scenarios still run
- **AND** the report names every failing scenario, its expectation, and what was found in
  the log

#### Scenario: The run completes

- **WHEN** every scenario passes
- **THEN** the report lists the scenarios covered and states that the run passed

#### Scenario: Evidence is retained

- **WHEN** the run ends, whether passing or failing
- **THEN** captured log excerpts and browser screenshots are written to a temporary
  location outside the repository and their path is reported
- **AND** the site's settings, plugin states and test orders are left exactly as the run
  left them
