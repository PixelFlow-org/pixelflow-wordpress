# tiktok-identifiers Specification

## Purpose
Carries TikTok's browser id (`ttp`) and ad-click id (`ttclid`) on the WooCommerce events the
plugin sends, so TikTok can attribute a server-side event to the shopper's browser and the ad
they clicked.

## Requirements
### Requirement: Storefront events carry TikTok ids from the shopper's cookies

AddToCart, InitiateCheckout, and a held storefront event replayed after the shopper's consent,
SHALL include `eventData.ttp` set to the value of the `_ttp` cookie and `eventData.ttclid` set to
the `ttclid` key of the `_pf_click_ids` cookie, read from the current request. `_pf_click_ids` is
a URL query string; keys other than `ttclid` SHALL NOT be forwarded. Both values SHALL be
sanitized as text before they are sent.

#### Scenario: Both cookies present
- **WHEN** an AddToCart is sent and the request carries `_ttp=tiktok-browser-1` and
  `_pf_click_ids=ttclid=E_C_P_abc&gclid=other`
- **THEN** `eventData.ttp` is `tiktok-browser-1`, `eventData.ttclid` is `E_C_P_abc`, and
  `eventData` has no `gclid`

#### Scenario: InitiateCheckout
- **WHEN** an InitiateCheckout is sent and the request carries both cookies
- **THEN** the event carries `ttp` and `ttclid` on the same terms as AddToCart

#### Scenario: Replayed held event
- **WHEN** a held AddToCart or InitiateCheckout is replayed on a later request of the same
  shopper that carries both cookies
- **THEN** the replayed event carries `ttp` and `ttclid` from that request's cookies

#### Scenario: Click-id cookie without ttclid
- **WHEN** `_pf_click_ids` is present but has no `ttclid` key, or its `ttclid` is not a single
  value (e.g. `ttclid[]=x`)
- **THEN** the event has no `ttclid` field

### Requirement: An absent TikTok id is omitted

The plugin SHALL omit `ttp` or `ttclid` when its source is absent or empty after sanitizing, and
SHALL NOT generate a value of its own for either field. Each field is decided independently.

#### Scenario: No TikTok cookies
- **WHEN** an AddToCart is sent and the request carries neither `_ttp` nor `_pf_click_ids`
- **THEN** `eventData` has neither a `ttp` nor a `ttclid` key

#### Scenario: Only one cookie present
- **WHEN** the request carries `_ttp` but no `_pf_click_ids`
- **THEN** the event carries `ttp` and has no `ttclid` key

### Requirement: Purchase takes TikTok ids from the order first

At order creation, while the shopper's browser is making the request, the plugin SHALL save the
`_ttp` and `_pf_click_ids` cookies to the order. The Purchase event SHALL take each of `ttp` and
`ttclid` from the saved value when one exists, and otherwise from the live cookie only when the
current request belongs to the buyer. When the request does not belong to the buyer, a field
with no saved value SHALL be omitted.

#### Scenario: Both ids saved on the order
- **WHEN** the order saved `_ttp=tiktok-browser-saved` and
  `_pf_click_ids=ttclid=E_C_P_saved&gclid=other`, and Purchase fires
- **THEN** the Purchase carries `ttp` `tiktok-browser-saved` and `ttclid` `E_C_P_saved`, and no
  `gclid`

#### Scenario: Buyer's own request, nothing saved
- **WHEN** the order saved neither id and the Purchase fires on a request that belongs to the
  buyer, carrying both cookies
- **THEN** the Purchase carries `ttp` and `ttclid` from those live cookies

#### Scenario: Someone else's request
- **WHEN** the order saved neither id and the Purchase fires on a request that does not belong
  to the buyer (e.g. staff changing the order status), carrying that person's `_ttp` and
  `_pf_click_ids`
- **THEN** the Purchase has neither a `ttp` nor a `ttclid` key

#### Scenario: One id saved, the other live
- **WHEN** the order saved only `_ttp`, and the Purchase fires on the buyer's request carrying a
  different `_ttp` and a `_pf_click_ids` with `ttclid`
- **THEN** `ttp` is the saved value and `ttclid` is the live one

#### Scenario: Only the click id saved
- **WHEN** the order saved only `_pf_click_ids` with `ttclid`, and the Purchase fires on the
  buyer's request carrying `_ttp` and a `_pf_click_ids` with a different `ttclid`
- **THEN** `ttclid` is the saved value and `ttp` is the live one

#### Scenario: One id saved, someone else's request
- **WHEN** the order saved only `_ttp`, and the Purchase fires on a request that does not belong
  to the buyer, carrying that person's `_ttp` and `_pf_click_ids` with `ttclid`
- **THEN** `ttp` is the saved value and the Purchase has no `ttclid` key

#### Scenario: Only the click id saved, someone else's request
- **WHEN** the order saved only `_pf_click_ids` with `ttclid`, and the Purchase fires on a
  request that does not belong to the buyer, carrying that person's `_ttp` and `_pf_click_ids`
- **THEN** `ttclid` is the saved value and the Purchase has no `ttp` key

### Requirement: The debug log records the TikTok cookies

When the WooCommerce debug log is enabled, each logged event SHALL list the `_ttp` and
`_pf_click_ids` cookies of the request among the cookies it records.

#### Scenario: Debug log enabled
- **WHEN** the WooCommerce debug log is enabled and an event is sent on a request carrying
  `_ttp` and `_pf_click_ids`
- **THEN** the log entry's cookie list includes both cookies
