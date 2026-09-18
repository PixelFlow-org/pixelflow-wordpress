## Purpose

Defines which requests the plugin refuses to report as shopper activity — automated clients,
speculative browser prefetch and cookieless add-to-cart URLs — and requires that any such
suppression be diagnosable by the site owner.

## ADDED Requirements

### Requirement: Requests from automated clients are not reported

The plugin SHALL classify a request as automated when its user agent matches a known automation
signature, and SHALL NOT send an event for such a request. The signature list SHALL include the
generic HTTP client libraries and advertising crawlers observed in production traffic, alongside
the existing entries. It SHALL also include the mainstream scraping frameworks whose default agent
names them, even where the export did not happen to record one: such a framework keeps cookies and
sends the headers a browser sends, so no other rule in this capability can separate it from a
shopper, and its agent string cannot collide with a browser's.

Where a vendor ships a family of crawlers under one agent prefix, the list SHALL carry that prefix
as a catch-all, so that a new member of the family is suppressed without a plugin release, and
SHALL keep the exact agents already observed in production ahead of it, so that those keep
reporting themselves. Signatures are matched as substrings and the first match decides the reported
cause, so the list SHALL order a specific entry ahead of any prefix that subsumes it. A crawler the
list names exactly is therefore reported exactly; one matched only by the prefix is reported under
the prefix.

#### Scenario: Advertising crawler

- **WHEN** a request arrives with a user agent containing `meta-externalads`
- **THEN** no event is sent, and the reported cause is `meta-externalads`, not the prefix that
  also matches it

#### Scenario: An unrecognised crawler in the same family

- **WHEN** a request arrives with a user agent carrying the `meta-external` prefix but none of the
  exact agents the list names
- **THEN** no event is sent, and the reported cause is the prefix `meta-external`

#### Scenario: Generic HTTP client library

- **WHEN** a request arrives with a user agent containing `guzzle`, `httpx` or `aiohttp`
- **THEN** no event is sent

#### Scenario: Ordinary shopper

- **WHEN** a request arrives from a mainstream desktop or mobile browser user agent
- **THEN** the event is sent as before

### Requirement: A generic HTTP client library does not suppress a Purchase

The generic HTTP client libraries in the signature list are the one group a store may legitimately
use to reach its own storefront: a headless front end or a mobile app makes the buyer's own request
through one, carrying the buyer's cookies, which is what makes the live user agent readable at all.
The plugin SHALL NOT suppress a Purchase on the strength of one of those signatures. It SHALL keep
suppressing AddToCart and InitiateCheckout for them, and SHALL keep suppressing Purchase for every
other signature, for the vendor crawler family and for a prefetch header. A signature a site adds
through the filter is not exempt: the exemption names a fixed group, not a category the filter can
extend.

An order in the database is evidence that a human paid, because completing a WooCommerce checkout
takes a payment. There is correspondingly nothing for an automation rule to protect on this event,
while the cost of a false positive is total: a suppressed Purchase is deferred and then closed
permanently, so the order can never be reported afterwards.

#### Scenario: Headless storefront completes an order

- **WHEN** an order reaches a purchasing status in a request the buyer owns, whose user agent
  contains one of the generic HTTP client libraries
- **THEN** the Purchase is sent

#### Scenario: The same client adds to the cart

- **WHEN** a request from that same client adds a product to the cart
- **THEN** AddToCart is suppressed and reported under the matched signature, as for any automation

#### Scenario: Another signature on the same event

- **WHEN** an order reaches a purchasing status in a request the buyer owns, whose user agent
  matches a crawler signature that is not one of the generic libraries
- **THEN** the Purchase is suppressed exactly as it was before this change

### Requirement: Speculative browser prefetch is not reported

The plugin SHALL treat a request as automated when it declares itself as speculative prefetch or
prerender, regardless of user agent, on any of the headers browsers use to say so — `Sec-Purpose`,
the legacy `Purpose`, and Safari's legacy `X-Purpose`. No human has acted on such a request. Such a suppression
SHALL be diagnosable on the same terms as a user-agent match: named in the site's debug log and
reported on the anonymous blocked-events channel as an automated-client suppression naming this
rule.

The header SHALL be read from the live request only when that request demonstrably belongs to the
buyer, on the same gate that already governs the live user-agent read. On an order path where it
does not — a gateway callback, a status change made in wp-admin, a scheduled task — this rule
SHALL NOT fire, because the headers of a staff member's or an automation client's request say
nothing about the shopper whose event is being sent.

#### Scenario: Chrome prerender

- **WHEN** a request carries the header `Sec-Purpose` with a value containing `prefetch`
- **THEN** no event is sent, the debug log entry names the prefetch signal as the cause, and a
  blocked-events row is reported with `reason` `bot` and `detail` `prefetch_header`

#### Scenario: Legacy prefetch header

- **WHEN** a request carries the header `Purpose: prefetch`
- **THEN** no event is sent, and the suppression is logged and reported the same way

#### Scenario: Legacy Safari prefetch header

- **WHEN** a request carries the header `X-Purpose: preview` or `X-Purpose: prefetch`
- **THEN** no event is sent, and the suppression is logged and reported the same way

#### Scenario: Prefetch header on a request that is not the buyer's

- **WHEN** a Purchase is sent from a request that does not belong to the buyer — a gateway
  callback, a wp-admin status change or a scheduled task — and that request carries a prefetch
  header
- **THEN** the rule does not fire, the Purchase is sent, and no prefetch suppression is logged or
  reported

### Requirement: One automation classification wins, in a fixed precedence

A single request can satisfy more than one automation rule — a crawler following an `add-to-cart`
link matches both the user-agent list and the cookieless rule, and a prefetched request can carry
an automation user agent. The plugin SHALL report exactly one cause for such a suppression, chosen
in this order: the matched user-agent signature, then the prefetch header, then an unresolved or
declined consent decision, then the rule supplied by the calling event path. The same cause SHALL
appear in the debug log and in the blocked-events row, so the two never disagree.

The consent state outranks a caller-supplied rule because such a rule infers automation from the
*absence* of something the request should have carried, and a consent decision that is pending or
declined explains that absence with no automation involved. A request whose event is withheld for
a pending decision SHALL therefore be reported as awaiting a decision, so that it stays eligible to
be held and replayed if the visitor grants, and SHALL NOT be reported as automation. Evidence about
the request itself — a matched signature, a prefetch header — is not an inference from absence and
SHALL keep outranking the consent state, as it does today.

#### Scenario: Crawler follows an add-to-cart link with a known agent

- **WHEN** an `add-to-cart` GET carries no browser cookies and its user agent matches `httpx`
- **THEN** one suppression is reported, naming `httpx` rather than `no_cookies_in_wp_plugin`

#### Scenario: Prefetched request with an ordinary browser agent

- **WHEN** a request carries a prefetch header and a mainstream browser user agent
- **THEN** the suppression is reported with `prefetch_header`

#### Scenario: Undecided shopper on an add-to-cart link

- **WHEN** an `add-to-cart` GET carries neither the visitor cookie nor the Facebook browser
  cookie because the visitor has not answered the consent banner yet, and the hold cookie is
  present
- **THEN** the event is reported as awaiting a decision rather than as automation, so it remains
  eligible to be held and replayed on a grant

#### Scenario: Declined shopper on an add-to-cart link

- **WHEN** the same request arrives from a visitor whose resolved decision is `denied`
- **THEN** the suppression is reported as denied rather than as automation

#### Scenario: A crawler carries no cookies of any kind

- **WHEN** an `add-to-cart` GET arrives with no browser cookies at all, consent cookies included,
  as a client running no JavaScript produces
- **THEN** the cookieless rule still applies and the suppression is reported under it

### Requirement: A suppressed automated request leaves no trace on the shopper's next event

The plugin deduplicates events with short-lived dedupe windows and per-cart guards kept in the
WooCommerce session, so that several hooks firing for one shopper action produce one event. A
request classified as automated SHALL NOT consume that state. The shopper's own request arrives
seconds behind the speculative one that preceded it — a prefetch of the checkout page, a preloaded
`add-to-cart` link — and state burned by the suppressed request would silence the shopper's real
event; for a guard keyed on the cart, it would silence it for that cart altogether rather than
merely delay it.

The same holds whenever the send did not happen for a reason that has nothing to do with this
shopper — an absent credential, a transport failure. Such state SHALL close only once the event was
delivered, deliberately parked awaiting a decision, or deliberately withheld by a decision about
the visitor. A suppression for a consent reason SHALL keep consuming that state exactly as it does
today: there it is the decision, not the request, that withheld the event, and the guard is what
stops the path queueing another recipe or sending another beacon on every page view.

#### Scenario: The credentials are absent when the shopper reaches the checkout

- **WHEN** an event cannot be sent because a credential is empty, and the shopper returns to the
  same page with the same cart after the credentials are restored
- **THEN** the event is sent for that later visit, because the guard never closed

#### Scenario: Prefetched checkout page, then the real visit

- **WHEN** a speculative prefetch of the checkout page is suppressed, and the shopper then loads
  the checkout page with the same cart
- **THEN** InitiateCheckout is sent for the shopper's load

#### Scenario: Preloaded add-to-cart link, then the real click

- **WHEN** an `add-to-cart` request is suppressed as automated, and the shopper's own request for
  the same product arrives inside the dedupe window
- **THEN** AddToCart is sent for the shopper's request

#### Scenario: A consent hold still consumes the guard

- **WHEN** an event is withheld because the consent decision is pending, and the shopper loads the
  same page again
- **THEN** the guard from the first request still applies, so one recipe is queued rather than one
  for every page view

### Requirement: The site's debug log names the cause that suppressed an event

When an event is withheld because the request was classified as automated, the entry the plugin
writes to the site's own debug log SHALL name the cause rather than only stating that a bot was
detected: the matched signature when the user agent matched one, and the rule that fired when the
classification came from another signal. A site whose own client is filtered by mistake must be
able to identify what caused it by reading its own log. The anonymous telemetry sent to the
PixelFlow API is out of scope here: `consent-resolution` already governs the `detail` field on a
`bot` blocked-event row, and this change does not alter that contract.

#### Scenario: Diagnosing a misfire

- **WHEN** an event is withheld because the user agent matched `httpx`
- **THEN** the debug log entry for that skip names `httpx` as the matched signature

#### Scenario: Diagnosing a non-user-agent suppression

- **WHEN** an event is withheld because the request declared itself as prefetch, or because an
  `add-to-cart` GET carried no browser cookies
- **THEN** the debug log entry names the rule that fired, and does not state that a user agent
  matched a bot signature

#### Scenario: Telemetry is unchanged

- **WHEN** an event is withheld because the user agent matched an automation signature
- **THEN** the blocked-event row sent to the API still carries `reason` `bot` and `detail` set to
  the matched pattern, exactly as before this change

### Requirement: Site owners can adjust the signature list

The filter that lets a site add or remove automation signatures SHALL be documented for site
owners with a usable example, so that a false positive can be corrected without a plugin change.

#### Scenario: Removing a signature

- **WHEN** a site registers a callback that removes a signature from the list
- **THEN** requests matching only that signature are reported as normal shopper activity

### Requirement: Anonymous cookieless add-to-cart URLs are not reported

WooCommerce adds a product to the cart whenever an `add-to-cart` parameter reaches it, on any
request method, so crawlers and prefetchers that follow such a link produce cart activity without a
shopper. The rule SHALL therefore be scoped by where the parameter arrived rather than by the
request method: it applies when `add-to-cart` came in the **query string**, which covers the
classic link however it is requested, and leaves a genuine add-to-cart form outside the rule by
construction, because a form submits the parameter in the request body. The
plugin SHALL NOT send AddToCart for such a request when it carries neither the visitor cookie nor
the Facebook browser cookie **and** its headers carry no sign that a browser navigated to it.
Both cookies are written by JavaScript, so a visitor running a mainstream ad blocker has neither —
and a server-side event is the only signal left for that visitor, which is the reason this plugin
exists. Cookie absence alone therefore cannot separate them from a crawler, and the headers SHALL
have to agree before anything is withheld. Any one of the headers a browser navigation carries
SHALL count as that agreement, because no single one of them is universal. This rule SHALL NOT
apply when the visitor's consent decision is pending or declined — in
which case the consent state is the reported cause, because both cookies are withheld until
consent is granted and their absence therefore proves nothing about automation. A request carrying
either cookie SHALL be reported normally. The suppression SHALL be reported on the anonymous
blocked-events channel as an automated-client suppression naming this rule, so the withheld volume
stays visible rather than vanishing.

#### Scenario: Crawler follows an add-to-cart link

- **WHEN** a request arrives with `add-to-cart` in its query string, with neither `_pf_uid` nor
  `_fbp` and without the headers a browser navigation carries
- **THEN** no AddToCart event is sent, and a blocked-events row is reported for it with `reason`
  `bot` and `detail` `no_cookies_in_wp_plugin`

#### Scenario: Ad-blocked shopper clicks an add-to-cart link

- **WHEN** an `add-to-cart` link is requested with neither cookie, because the visitor's ad blocker
  stopped the scripts that write them, but its headers show a browser navigation
- **THEN** the AddToCart event is sent

#### Scenario: Returning shopper clicks an add-to-cart link

- **WHEN** an `add-to-cart` GET request arrives carrying `_pf_uid`, `_fbp`, or both
- **THEN** the AddToCart event is sent

#### Scenario: Consent decision still pending

- **WHEN** an `add-to-cart` GET arrives with neither cookie because the visitor has not answered
  the consent banner, and the hold cookie is present
- **THEN** this rule does not decide the outcome: the event is withheld as awaiting a decision and
  stays eligible to be held and replayed on a grant

#### Scenario: Add to cart by other means

- **WHEN** a product is added through the AJAX endpoint, the Store API, or a POST form — none of
  which carries an `add-to-cart` parameter in the query string
- **THEN** this rule does not apply and the event is sent subject to the other rules

#### Scenario: Crawler probes the classic link with another method

- **WHEN** the same cookieless, header-less request carries `add-to-cart` in its query string but
  uses `HEAD`, or `POST` to an unrelated URL — both of which still add the product to the cart
- **THEN** the rule applies exactly as it does to the `GET` form of the same link

### Requirement: Events are not sent when the integration is unconfigured

The plugin SHALL NOT send an event or a blocked-events beacon unless both the site identifier and
the API key are configured and non-empty, reusing the same credential check that gates the
browser script. Only that check: the browser-script gate also tests the plugin's enable toggle and
the role exclusion, neither of which applies here — the toggle is already resolved before the
hooks are loaded, and role exclusion has never applied to server-side events.

The gate SHALL withhold the outbound request and nothing else. The plugin SHALL keep recording what
exists only for the duration of the buyer's own request and is written once — the tracking cookies
it persists onto the order, the consent decision it records on open orders — and SHALL keep
flushing events already queued awaiting a decision. A credential is empty for reasons that are
temporary and invisible to the shopper: a rotated key, a paste error, a half-finished migration.
Withholding the recording as well would turn that window into a loss no reconfiguration can repair,
because the cookies the snapshot is taken from are gone once the request has ended.

#### Scenario: Half-configured site

- **WHEN** the site identifier is set but the API key is empty
- **THEN** no event and no blocked-events beacon leaves the site

#### Scenario: A half-configured site still records

- **WHEN** an order is placed while either credential is empty
- **THEN** its tracking cookies are still persisted onto the order and its consent decision is
  still recorded, so the order keeps the attribution and the decision that only its own request
  could supply

#### Scenario: Fully configured site

- **WHEN** both the site identifier and the API key are non-empty
- **THEN** events are sent as before

### Requirement: Retired cookie names are no longer read

The plugin SHALL read the Facebook browser and click identifiers from their current cookie names
only. The retired names the browser script no longer writes SHALL NOT be read, forwarded, or
reported anywhere — neither in the event payload, nor in the order meta it persists, nor in the
entries it writes to the site's debug log.

#### Scenario: Retired cookie present from an old session

- **WHEN** a request carries a stale cookie under a retired name
- **THEN** its value does not appear in the event payload

#### Scenario: Debug log of a request with a retired cookie

- **WHEN** an event is logged for a request that carries a stale cookie under a retired name
- **THEN** the log entry neither names the retired cookie nor reports its value
