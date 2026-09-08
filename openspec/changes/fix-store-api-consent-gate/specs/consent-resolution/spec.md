## MODIFIED Requirements

### Requirement: Hold and deny skip server-side sends
The plugin SHALL NOT POST a server-side event when the shopper's consent decision is a
hold (the banner is unanswered) or `denied`. This SHALL hold for every request path that
carries a WooCommerce event, including a WooCommerce Store API request from a block
storefront: a shopper's decision cannot depend on which storefront template the site uses.
When neither a hold nor a denied decision is present the event SHALL still be sent.

#### Scenario: Opt-in banner still unanswered
- **WHEN** the request carries `_pf_no_consent_decision=true`
- **THEN** the plugin does not POST the event

#### Scenario: AddToCart through a Store API request
- **WHEN** a shopper whose banner is unanswered adds a product from a block storefront, so
  the event is dispatched from a WooCommerce Store API request that carries
  `_pf_no_consent_decision=true`
- **THEN** the plugin holds the AddToCart event rather than sending it, and the hold is
  visible to the storefront script for a later flush

#### Scenario: Declined shopper on a block storefront
- **WHEN** a shopper who has declined adds a product, reaches checkout, or completes an
  order from a block storefront
- **THEN** no real event is POSTed and exactly one anonymous blocked row is reported per
  suppressed event

#### Scenario: Visitor declined
- **WHEN** the resolved consent decision for the event is `denied`
- **THEN** the plugin does not POST the event

#### Scenario: Visitor accepted
- **WHEN** the resolved consent decision is `granted` and no hold is in force
- **THEN** the event is POSTed with the consent block attached

#### Scenario: No banner and no hold
- **WHEN** neither a hold nor a denied consent decision is present for the request
- **THEN** the event is POSTed (no-banner / script not loaded), with a consent block only if a decision is knowable

#### Scenario: Purchase after a held checkout
- **WHEN** a purchase event is sent from a background request and the order persisted `_pf_no_consent_decision=true`
- **THEN** the plugin does not POST the purchase event

#### Scenario: Live grant after a held checkout
- **WHEN** a purchase hook runs on a browser request whose live consent decision is `granted`, even if the order persisted `_pf_no_consent_decision=true`
- **THEN** the plugin POSTs the purchase event, writes `_pf_purchase_sent` only after that POST, and persists the grant and `_pf_uid` on the order

#### Scenario: Cookie-less Purchase with no snapshot
- **WHEN** a purchase event is sent from a background request and the order has neither a hold cookie nor a denied consent decision
- **THEN** the event is POSTed
