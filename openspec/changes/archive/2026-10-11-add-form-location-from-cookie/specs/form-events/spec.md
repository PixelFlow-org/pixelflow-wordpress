## MODIFIED Requirements

### Requirement: A form event carries hashed identifiers, the form title and nothing else from the form

A form event SHALL be sent to the existing event endpoint in the same payload shape
WooCommerce events use, carrying the event name, a server-generated event id, the time
of the submission, the website action source and the site URL. Its `additionalData`
SHALL carry the form title as `contentName`, and a value only when the form has a
static value set. Its `customerData` SHALL carry the mapped identifiers — email,
phone, first name, last name, city, state, postcode and country — normalised and
hashed by the same rules WooCommerce events use, together with the visitor identifier,
the public client IP address and the user agent of the submitting request. For each of
city (`ct`), state (`st`), postcode (`zp`) and country (`country`) that the form did not
provide, `customerData` SHALL carry the value of the same key from the `pf_loc` cookie of
the request that sends the event, copied as stored; a value the form provided SHALL be kept,
and a key the cookie lacks, or holds as anything but a non-empty plain value, SHALL be left
out. Its `eventData` SHALL carry the browser and click identifiers and the attribution that the
same helpers read from the submitting request's cookies for WooCommerce events — Meta's
`fbp` and `fbc`, TikTok's `ttp` and `ttclid` — each omitted when its cookie is absent.
No other field from the form SHALL appear in the event.

#### Scenario: Browser and click identifiers come from the request's cookies

- **WHEN** a form is submitted on a request carrying `_fbp`, `_ttp` and
  `_pf_click_ids=ttclid=E_C_P_abc&gclid=other`
- **THEN** the event's `eventData` carries `fbp`, `ttp` and `ttclid` `E_C_P_abc`, and no
  `gclid`

#### Scenario: Contact form submission payload

- **WHEN** a form titled `Contact us` is submitted with an email address, a phone
  number and a message, and its event is `Lead`
- **THEN** the event's name is `Lead`, its `additionalData.contentName` is
  `Contact us`, its `customerData` carries the hashed email and hashed phone, and the
  message text appears nowhere in the payload

#### Scenario: Location comes from the cookie when the form has none

- **WHEN** a form with only an email field is submitted on a request whose `pf_loc` cookie
  holds `ct`, `st`, `zp` and `country`
- **THEN** the event's `customerData` carries the hashed email and those four values exactly
  as the cookie holds them

#### Scenario: A location field of the form wins over the cookie

- **WHEN** a form with an email field and a mapped city field is submitted on a request whose
  `pf_loc` cookie holds all four location keys
- **THEN** `ct` is the hashed city the visitor typed, and `st`, `zp` and `country` come from
  the cookie

#### Scenario: No location cookie

- **WHEN** a form with only an email field is submitted on a request without `pf_loc`, or
  whose `pf_loc` is not a JSON object
- **THEN** the event is sent with no `ct`, `st`, `zp` or `country`

#### Scenario: A held submission takes location from the sending request

- **WHEN** a submission is held while the banner is unanswered and sent on a later request of
  the same visitor after the grant, and only that later request carries `pf_loc`
- **THEN** the sent event carries the location from that later request

#### Scenario: Visitor identity matches WooCommerce events

- **WHEN** a form event is sent for a visitor who carries the plugin's visitor cookie
- **THEN** the event's visitor identifier is the one the same visitor's WooCommerce
  events would carry, derived by the same formula

#### Scenario: Visitor identifier cannot be resolved

- **WHEN** a form is submitted by a visitor carrying no visitor cookie, for instance
  because an ad blocker prevented it from being written
- **THEN** the event is still sent, the visitor identifier is omitted rather than
  substituted, and the hashed identifiers are present

#### Scenario: Credentials are missing

- **WHEN** a form submission would be sent on a site whose site identifier or API key
  is empty
- **THEN** nothing is sent
