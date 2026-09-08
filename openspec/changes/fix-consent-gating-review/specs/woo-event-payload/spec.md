## Purpose

Defines which WooCommerce cart and order lines contribute to the contents, item count and value of the events the plugin sends, so that products a store has chosen not to track never reach the analytics platform and never inflate the reported value of a purchase.

## ADDED Requirements

### Requirement: Excluded products are removed from event payloads
The plugin SHALL omit a product excluded by SKU from the `contents`, `num_items` and `value` of every event that reports them. An order or cart that also contains at least one tracked product SHALL still produce its event, describing only the tracked products. When every line is excluded the event SHALL NOT be sent at all.

#### Scenario: Mixed order with one excluded product
- **WHEN** an order contains a tracked product at 100, an excluded product at 50 and a tracked product at 25
- **THEN** the purchase event reports the two tracked products, an item count covering only those two, and a value of 125

#### Scenario: Checkout started with an excluded product in the cart
- **WHEN** the shopper reaches checkout with a cart containing tracked and excluded products
- **THEN** the checkout event reports only the tracked products, and its value covers only those products

#### Scenario: Every line is excluded
- **WHEN** every product in the cart or order is excluded by SKU
- **THEN** no event is sent for that cart or order

#### Scenario: Nothing is excluded
- **WHEN** the store has configured no SKU exclusions
- **THEN** every line contributes to the event exactly as it does today

### Requirement: Free products follow the freebie setting of their own event
The plugin SHALL omit products with no price from the `contents`, `num_items` and `value` of an event when the freebie setting belonging to that event type is enabled, and SHALL include them when it is disabled. This SHALL apply to a mixed cart or order, not only to one made up entirely of free products. Each event type SHALL keep its own independent setting — `woo_disable_add_to_cart_freebies` for AddToCart, `woo_disable_initiate_checkout_freebies` for InitiateCheckout and `woo_disable_purchase_freebies` for Purchase — so enabling one SHALL NOT change the reporting of another event type.

#### Scenario: Free product alongside paid products, setting enabled
- **WHEN** `woo_disable_purchase_freebies` is enabled and an order contains a paid product and a free product
- **THEN** the purchase event reports only the paid product, and the free product contributes nothing to the item count or value

#### Scenario: Free product alongside paid products, setting disabled
- **WHEN** `woo_disable_purchase_freebies` is disabled and an order contains a paid product and a free product
- **THEN** the purchase event reports both products

#### Scenario: One event type's freebie setting does not affect another
- **WHEN** `woo_disable_initiate_checkout_freebies` is enabled while `woo_disable_purchase_freebies` is disabled, and a cart containing a paid product and a free product is checked out and paid for
- **THEN** the checkout event reports only the paid product and the purchase event reports both products

#### Scenario: Order made up entirely of free products, setting enabled
- **WHEN** `woo_disable_purchase_freebies` is enabled and every product in the order is free
- **THEN** no purchase event is sent

### Requirement: Order-level discounts are distributed across reported products only
The plugin SHALL redistribute an order-level discount, bundle price or similar gap between line totals and the amount actually paid across the products the event reports, and SHALL exclude the amounts paid for omitted products from that calculation, so the event's value equals what the shopper paid for the reported products.

#### Scenario: Order-level discount with an excluded product present
- **WHEN** an order carries an order-level discount and one of its products is excluded by SKU
- **THEN** the discount is distributed across the reported products only, and neither the excluded product's price nor its share of the discount appears in the event's value

#### Scenario: No discount to distribute
- **WHEN** the sum of the reported line totals already equals what the shopper paid for those products
- **THEN** the reported prices are unchanged
