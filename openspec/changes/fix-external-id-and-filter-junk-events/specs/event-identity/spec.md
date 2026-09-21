## Purpose

Defines how the plugin derives the identifier that lets Meta recognise the same shopper across
separate events, so that server-side events and the browser script's events resolve to one
person rather than to a site-wide constant.

## ADDED Requirements

### Requirement: Every event carries a derived external identifier when one can be resolved

The plugin SHALL include an `external_id` in the event payload whenever it can resolve a browser
visitor id for the request. The value SHALL be a SHA-256 hash whose input is prefixed with the
site's configured `site_external_id`, so that the same literal visitor id on two different sites
produces two different hashes. The visitor id is the only source of this identifier: no other
value SHALL be substituted for it.

#### Scenario: Guest shopper with a visitor cookie

- **WHEN** a guest adds a product to the cart and the request carries a `_pf_uid` cookie
- **THEN** the event payload contains `external_id` equal to
  `sha256(site_external_id + '_' + visitor_id)`

#### Scenario: Two sites, same visitor id value

- **WHEN** the same literal visitor id value is seen on two sites with different
  `site_external_id` values
- **THEN** the two events carry different `external_id` values

#### Scenario: Nothing identifies the request

- **WHEN** no visitor id can be resolved for a request, whether or not a WordPress user is
  logged in and whether or not the request carries `_fbp`
- **THEN** the event payload omits the `external_id` field entirely rather than sending an
  empty string, a null, a placeholder, or a value derived from another source

### Requirement: The primary formula matches the PixelFlow browser script exactly

When a visitor id is available, the hashed input SHALL be `site_external_id`, a single
underscore, and the visitor id, with no additional separator, marker or ordering. The plugin SHALL
NOT case-fold either value, and SHALL NOT put the hash input through
`pixelflow_normalize_external_id()` or any equivalent normalisation step, because the browser
script applies none to this input. The visitor id SHALL be hashed as the existing visitor-id
resolver returns it. This is a compatibility constraint, not a stylistic one: the browser script
emits the same value for the same visitor, and any divergence would split one shopper into two
identities.

#### Scenario: Server and browser agree

- **WHEN** the browser script and the plugin both emit an event for the same visitor in the
  same session
- **THEN** both events carry the same `external_id`

#### Scenario: Visitor id containing uppercase characters

- **WHEN** a visitor id or a `site_external_id` contains uppercase characters
- **THEN** the hashed input contains them in their original case, and the resulting `external_id`
  still matches the one the browser script produces for the same visitor

### Requirement: The visitor id resolves in a fixed order

The plugin SHALL resolve the visitor id from the attribution payload, then from the order's
stored visitor id, then from the live `_pf_uid` cookie, using the first source that yields a
non-empty value. When none does, the plugin SHALL omit `external_id` rather than fall back to a
WordPress user id, a Facebook cookie, an email address or any other value.

Live request cookies SHALL be consulted only when the request demonstrably belongs to the buyer.
On an order path where it does not — a gateway callback, a status change made in wp-admin, a
scheduled task — the plugin SHALL NOT read any cookie from the request for this purpose, neither
`_pf_uid` nor the visitor id embedded in the `_pf_attribution` payload, so that a staff member's
own browser identity cannot be attributed to the shopper. On such a path only the values stored on
the order are eligible. This holds even when the order has no stored value of its own: an absent
override SHALL NOT be treated as permission to fall back to the request's cookies.

#### Scenario: Logged-in shopper whose visitor cookie is missing

- **WHEN** a logged-in shopper triggers an event and no visitor id can be resolved
- **THEN** the event omits `external_id`, and the WordPress user id is not used in its place

#### Scenario: Guest with no visitor cookie but with a Facebook cookie

- **WHEN** a guest triggers an event, no visitor id can be resolved, and the request carries
  `_fbp`
- **THEN** the event omits `external_id`, and `_fbp` still travels in its own `fbp` field

#### Scenario: Purchase fired outside the shopper's request

- **WHEN** an order reaches a paid status from a payment gateway callback, a status change in
  wp-admin, or a scheduled task, so that no cookies are present on the request
- **THEN** the Purchase event carries `external_id` derived from the visitor id stored on the
  order when the order was created

#### Scenario: Staff member with their own visitor cookie completes an order

- **WHEN** a staff member marks an order paid in wp-admin, their request carries their own
  `_pf_uid` cookie, and the order has no stored visitor id of its own
- **THEN** the Purchase event does not carry the staff member's visitor id, and omits
  `external_id` unless another source on the order resolves one

#### Scenario: Staff member with their own attribution cookie completes an order

- **WHEN** a staff member marks an order paid in wp-admin, their request carries their own
  `_pf_attribution` cookie whose payload contains a visitor id, and the order has no stored
  attribution of its own
- **THEN** that visitor id is not used, even though the attribution payload is the first source in
  the resolution order

### Requirement: Held events keep the identity captured when they were held

An event withheld awaiting a consent decision SHALL carry the identity resolved at the moment it
was held, and the plugin SHALL NOT resolve a new one when the event is finally sent. The request
that flushes the queue is not necessarily the request that produced the event, so resolving there
would attribute the event to whoever triggered the flush. The `pixelflow_external_id` filter still
runs on the replayed value, as it does on every event, so these guarantees describe the plugin's
own resolution; a site whose callback returns a different answer on the flushing request overrides
them deliberately.

#### Scenario: Held event sent on someone else's request

- **WHEN** an event held for a consent decision is finally sent on a later request that carries a
  different visitor cookie, or none at all
- **THEN** the event reports the identity captured when it was held, not one resolved from the
  sending request

#### Scenario: Event held with no identity

- **WHEN** an event is held at a moment when no visitor id resolves, and by the time it is flushed
  the request would resolve one
- **THEN** the event is still sent without `external_id`, rather than acquiring one on the way out

### Requirement: Account and order identifiers are not used as browser identity

The plugin SHALL NOT derive `external_id` from a WordPress user id, a customer email address, a
Facebook cookie or an order id. The hashed email SHALL continue to be sent in the `em` field and
the Facebook browser cookie in `fbp`, which is where Meta matches on them.

#### Scenario: Guest checkout with an email and no browser identity

- **WHEN** a guest completes an order, the request carries no visitor id, and the order has a
  billing email and no stored visitor id
- **THEN** the Purchase event omits `external_id` and still carries the hashed email in `em`

#### Scenario: Logged-in checkout with no visitor id

- **WHEN** a logged-in customer completes an order and no visitor id resolves from the request
  or from the order
- **THEN** the Purchase event omits `external_id` rather than hashing the WordPress user id or
  the order id

### Requirement: Sites can override the derived identifier

The plugin SHALL expose a filter that receives the derived `external_id` and the context of the
event it is being derived for, allowing a site to replace it, to suppress it, or to supply one
when none resolved — restoring the previous behaviour without downgrading the plugin. The filter
SHALL be applied on every event, including when no identifier resolved, so that the supply case is
reachable. The set of context keys the filter receives SHALL be documented for site owners and
treated as a public contract.

#### Scenario: Site replaces the identifier

- **WHEN** a site registers a callback on the identity filter that returns its own value
- **THEN** the event payload carries that value as `external_id`

#### Scenario: Site suppresses the identifier

- **WHEN** a registered callback returns an empty value
- **THEN** the event payload omits the `external_id` field

#### Scenario: Site supplies an identifier when none resolved

- **WHEN** no visitor id resolves for an event and a registered callback returns a value
- **THEN** the event payload carries that value as `external_id`

#### Scenario: No callback registered and nothing resolved

- **WHEN** no visitor id resolves and no callback changes the value
- **THEN** the event payload omits the `external_id` field rather than carrying a null

#### Scenario: Nothing at all is known about the customer

- **WHEN** an event carries no customer data of any kind — no identifier resolved and no billing
  detail to hash, as a staff-created order with a blank billing address produces
- **THEN** the payload omits the customer-data object entirely rather than sending it empty, as
  the events that never had a guaranteed identifier already do


