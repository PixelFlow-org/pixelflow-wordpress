## MODIFIED Requirements

### Requirement: Skipped sends report anonymous blocked events

The plugin SHALL POST an anonymous `blocked_events` payload to `/blocked-events` when it skips a server-side send for a bot user agent, a request that declared itself as speculative prefetch, an add-to-cart request classified as automated because it carries no browser cookies, a denied consent decision, a Purchase that was skipped and not resolved within its reporting window, or a storefront hold queue that ends without a grant. The payload SHALL contain `siteId`, `blocked` rows (`eventType`, `reason`, optional `detail` on bot, optional `consentSource` on denied and no_decision), and `client_ip_address` when the client IP is public — the API uses the address to determine whether the visitor is in an opt-in or an opt-out region, and neither stores nor forwards it. The payload SHALL carry no other visitor data. On a `bot` row, `detail` SHALL name the cause of the suppression: the matched user-agent pattern when the classification came from the user agent, or a fixed identifier naming the rule when it came from another signal. The plugin SHALL NOT beacon for a private or reserved IP skip, for GPC, or when the event is sent.

#### Scenario: Unanswered opt-in banner
- **WHEN** the plugin skips AddToCart or InitiateCheckout because `_pf_no_consent_decision` is the literal value `true`
- **THEN** it does not POST `/blocked-events` on that request; it queues a recipe instead

#### Scenario: Hold with no Woo session
- **WHEN** AddToCart or InitiateCheckout should queue but the WooCommerce session is unavailable
- **THEN** the plugin POSTs `/blocked-events` with `reason` `no_decision` instead of dropping the event silently

#### Scenario: Visitor declined
- **WHEN** the plugin skips a storefront send because the resolved consent decision is `denied`
- **THEN** it POSTs `/blocked-events` with `reason` `denied` and `consentSource` from the resolved decision when that source is allow-listed

#### Scenario: Bot user agent
- **WHEN** the client user agent matches a bot pattern
- **THEN** the plugin skips the event POST and POSTs `/blocked-events` with `reason` `bot` and `detail` set to the matched pattern, not the raw user agent

#### Scenario: Speculative prefetch
- **WHEN** the plugin skips an event because the request declared itself as speculative prefetch on `Sec-Purpose`, the legacy `Purpose`, or Safari's legacy `X-Purpose`
- **THEN** it POSTs `/blocked-events` with `reason` `bot` and `detail` set to `prefetch_header`

#### Scenario: Cookieless add-to-cart URL
- **WHEN** the plugin skips AddToCart because the request is an `add-to-cart` GET carrying neither `_pf_uid` nor `_fbp`
- **THEN** it POSTs `/blocked-events` with `reason` `bot` and `detail` set to `no_cookies_in_wp_plugin`

#### Scenario: Bot wins over hold or deny
- **WHEN** the request's own evidence marks it as automated — its user agent matches a signature,
  or it declares itself as speculative prefetch — and a consent hold or deny also applies
- **THEN** the blocked row reason is `bot`

#### Scenario: A hold outranks automation inferred from missing cookies
- **WHEN** the only evidence of automation is that the request carries none of the cookies a
  shopper would have, and a consent hold or deny applies
- **THEN** the blocked row reason is the consent one — `no_decision` or `denied` — because the
  consent decision is what withheld those cookies

#### Scenario: Private IP and cookie-less Purchase
- **WHEN** the plugin skips because the client IP is private, or sends a cookie-less Purchase with no hold or deny snapshot
- **THEN** it does not POST `/blocked-events`
