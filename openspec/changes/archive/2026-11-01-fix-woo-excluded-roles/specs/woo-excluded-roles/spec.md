## Purpose

Defines how the excluded-roles setting applies to the plugin's browser script and to its server-side
WooCommerce events, including where the setting deliberately stops applying.

## ADDED Requirements

### Requirement: The plugin's browser script is not loaded for an excluded role

The plugin SHALL NOT output its tracking script or its held-events script on a storefront page
requested by a signed-in user whose role the site has excluded from tracking. A guest SHALL never be
treated as excluded. This requirement covers only scripts the plugin outputs itself.

#### Scenario: Excluded administrator opens a storefront page

- **WHEN** a signed-in user whose role is excluded opens a storefront page
- **THEN** the page contains neither the plugin's tracking script nor its held-events script

#### Scenario: Guest opens a storefront page

- **WHEN** a visitor who is not signed in opens a storefront page on a site that excludes a role
- **THEN** the page contains the plugin's tracking script

#### Scenario: A script added by hand stays on the page

- **WHEN** a tracking script has been added to the site outside the plugin and a signed-in user
  whose role is excluded opens a storefront page
- **THEN** the plugin does not remove that script, and the settings page has stated this limit

### Requirement: Live storefront events are not sent for an excluded role

The plugin SHALL NOT send an AddToCart or InitiateCheckout event from a request made by a signed-in
user whose role the site has excluded from tracking. Such a request SHALL produce no event, no
blocked report and no held event, whatever the consent state. While WooCommerce debug logging is on,
the debug log SHALL record that the event was skipped because the user's role is excluded.

#### Scenario: Excluded administrator adds a product to the cart

- **WHEN** a signed-in user whose role is excluded adds a product to the cart
- **THEN** no AddToCart event and no blocked report are sent

#### Scenario: Excluded administrator opens the checkout

- **WHEN** a signed-in user whose role is excluded opens the checkout with items in the cart
- **THEN** no InitiateCheckout event and no blocked report are sent

#### Scenario: Excluded role with an unanswered consent banner

- **WHEN** a signed-in user whose role is excluded adds a product to the cart while the consent
  banner is unanswered
- **THEN** nothing is held, and no event is sent later for that action

#### Scenario: Skip is visible in the debug log

- **WHEN** WooCommerce debug logging is on and an event is skipped because the user's role is
  excluded
- **THEN** the debug log records the skip with that reason

#### Scenario: A role that is not excluded still sends

- **WHEN** a signed-in user whose role is not excluded adds a product to the cart on a site that
  excludes another role
- **THEN** the AddToCart event is sent as usual

#### Scenario: A guest still sends

- **WHEN** a visitor who is not signed in adds a product to the cart on a site that excludes a role
- **THEN** the AddToCart event is sent as usual

### Requirement: Purchase is not sent from an excluded buyer's own request

The plugin SHALL NOT send a Purchase event from a request that belongs to the order's buyer while
that request is made by a signed-in user whose role is excluded, and SHALL send no blocked report
for it. The setting SHALL be evaluated against the current request only, not against the order's
customer, and skipping SHALL NOT mark the order as delivered. A request that does not belong to the
buyer SHALL NOT be affected by the setting, so a Purchase for such an order is sent when a later
request of that kind moves it to a purchasing status.

#### Scenario: Excluded administrator completes their own checkout

- **WHEN** a signed-in user whose role is excluded places an order and the status change happens in
  that user's own request
- **THEN** no Purchase event and no blocked report are sent

#### Scenario: Excluded staff member completes a real customer's order

- **WHEN** a signed-in staff member whose role is excluded moves another customer's order to a
  purchasing status in wp-admin
- **THEN** the Purchase event for that order is sent

#### Scenario: A later webhook moves an excluded buyer's skipped order

- **WHEN** a Purchase was skipped for an excluded buyer's order, and a payment gateway webhook or
  other request that is not the buyer's later moves that order to a purchasing status
- **THEN** the Purchase event for that order is sent

### Requirement: Held events are replayed whatever the current request's role

The plugin SHALL NOT apply the excluded-roles setting when it replays events held earlier in the
visit, because the replaying request may belong to a different identity than the action that was
held.

#### Scenario: Replay on a request by an excluded user

- **WHEN** events held earlier are replayed during a request made by a signed-in user whose role is
  excluded
- **THEN** the held events are sent
