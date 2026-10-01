## Purpose

Defines which form submissions on a WordPress site the plugin turns into Meta events
sent server-side, what those events carry and deliberately do not carry, how a form is
enabled and its identifiers mapped, and how consent, automated traffic and repeat
submissions gate the send. The requirements are written against any form plugin: a
supported plugin contributes only the ability to list its forms and fields and to
report a successful submission.

## ADDED Requirements

### Requirement: Form tracking is inert until it is turned on

The plugin SHALL NOT send any form event until a site-wide form-tracking setting is
turned on, and that setting SHALL default to off on a fresh install and on an upgrade
of an existing install. While it is off, the settings page SHALL show the setting alone,
without the form list, as it does for WooCommerce tracking, and every per-form event,
value and field choice SHALL be preserved so that turning the setting back on shows the
list again and resumes the same configuration.

#### Scenario: Upgrade does not start sending

- **WHEN** a site running an earlier version of the plugin is upgraded to a version
  that supports form events
- **THEN** the form-tracking setting is off, and a successful submission of any form
  on that site sends no event

#### Scenario: Settings survive the master toggle

- **WHEN** form tracking is turned off after forms have been configured, and later
  turned on again
- **THEN** each form's chosen event, static value, field mapping and on/off state are
  the ones configured before, and no configuration step is repeated

#### Scenario: The form list is hidden while tracking is off

- **WHEN** the Form Settings tab is opened with form tracking off
- **THEN** the page shows the form-tracking setting and neither the form list nor the
  line naming the form plugins, and turning the setting on shows both

#### Scenario: No supported form plugin is installed

- **WHEN** the settings page is opened with form tracking on, on a site where none of
  the supported form plugins is active
- **THEN** the page states that no supported form plugin was detected, lists no form
  rows, and nothing sends

### Requirement: The form list states what it cannot see

The Forms section SHALL name which supported form plugins are active and which are not
active, where not active covers both a plugin that is not installed and one that is
installed but deactivated. Once, above the form list rather than in each row, it SHALL
warn that a form also tracked by a dashboard submit trigger or thank-you-URL trigger is
counted twice, because the plugin cannot see those triggers, and it SHALL link to the
dashboard for a form the list does not show. The warning SHALL NOT change what any form
sends.

#### Scenario: A deactivated form plugin is named as not active

- **WHEN** the settings page is opened on a site where Contact Form 7 is active and
  Gravity Forms is installed but deactivated
- **THEN** the Forms section names Contact Form 7 as active and Gravity Forms as not
  active, and lists no Gravity Forms form

#### Scenario: The double-count warning appears once

- **WHEN** the settings page lists more than one form
- **THEN** the double-count warning and the dashboard link appear once above the list,
  and no form row carries its own copy

### Requirement: The form list is loaded and saved on request, by administrators only

The settings page SHALL load the form list from the server when the Form Settings tab
is opened, and SHALL replace the list it shows with the one the server returns after
each save, so the page always shows what a submission would do. While the list is being
loaded the page SHALL show a loading indicator in its place. The route that returns
the list and the route that saves a change SHALL each verify the settings page's nonce
and SHALL refuse a user who cannot manage the site's options, changing nothing.
When the list cannot be loaded, the settings page SHALL say so in place of the list and
SHALL still offer the master toggle. When a save fails, the settings
page SHALL say so, SHALL keep showing the configuration as it was before the change, and
SHALL leave its controls usable.

#### Scenario: The form list is still loading

- **WHEN** the Form Settings tab is opened and the server has not yet answered the
  request for the form list
- **THEN** the page shows a loading indicator where the list will be, and the indicator
  is gone once the list or the failure message is shown

#### Scenario: The form list cannot be loaded

- **WHEN** the Form Settings tab is opened and the server refuses or does not answer the
  request for the form list
- **THEN** the page states that the forms could not be loaded, shows no form rows, and
  the master toggle remains available

#### Scenario: A save fails

- **WHEN** a person changes a form's event and the server refuses or does not answer the
  save
- **THEN** the page states that the form settings could not be saved, the form shows the
  event it had before, and its controls can be used again

#### Scenario: A user who cannot manage options asks for the list

- **WHEN** a signed-in user without the capability to manage the site's options requests
  the form list
- **THEN** the request is refused and no form is returned

#### Scenario: A user who cannot manage options saves a change

- **WHEN** a signed-in user without the capability to manage the site's options sends a
  change to a form's configuration
- **THEN** the request is refused and the stored configuration is unchanged

### Requirement: Only high-confidence forms send without a per-form confirmation

With form tracking on, the plugin SHALL send a submission without any further
confirmation only when the form has a field whose native type is email or phone and
the form's title does not identify it as a search, login, password or comment form.
The plugin SHALL identify a form's title by matching the title, lowercased, for a
substring from a shipped pattern list, which SHALL cover English, French, German,
Spanish, Italian and Dutch wording, the same languages the identifier patterns cover. A title matching no pattern SHALL be
treated as neither excluded nor a registration form.
Any other form SHALL appear in the list with a suggested event already filled in and
its own switch off, and SHALL NOT send until that switch is turned on. Choosing an
event SHALL NOT by itself enable a form.
A form's on/off state and its event SHALL be computed at submit time unless a person
has set them: a form whose switch no person has changed SHALL be on exactly when it is
currently high confidence, and a form whose event no person has changed SHALL use its
current suggested event. Saving the settings page SHALL NOT record an on/off state or
an event for a form whose switch or event the person did not change.

#### Scenario: Contact form with a native email field

- **WHEN** form tracking is turned on and a form with a native email field, a message
  field and the title `Contact us` is submitted successfully
- **THEN** one `Lead` event is sent for that submission

#### Scenario: An untouched form that gains an email field starts sending

- **WHEN** a medium-confidence form whose switch no person has changed is edited to
  add a native email field, and is then submitted with form tracking on
- **THEN** one event is sent, with no change to the saved configuration

#### Scenario: A switch set by a person is not recomputed

- **WHEN** a person has turned off a high-confidence form, and the settings page is
  later saved again for an unrelated change
- **THEN** the form stays off, and a form in the same list whose switch nobody changed
  is still computed from its confidence

#### Scenario: Search form is not treated as a conversion

- **WHEN** form tracking is turned on and a form whose title identifies it as a search
  form is submitted
- **THEN** no event is sent, and the form's row shows its switch off

#### Scenario: Identifier only inferred from a plain text field

- **WHEN** a form has no native email or phone field and an identifier is inferred
  only from the name or label of a plain text field
- **THEN** the form's row shows a suggested event and its switch off, and submissions
  send nothing until the switch is turned on

#### Scenario: Turning on a medium-confidence form

- **WHEN** a form that appeared with its switch off has that switch turned on
- **THEN** its next successful submission sends the event shown in its row

### Requirement: Event selection is limited to standard Meta event names

Each form SHALL carry exactly one event name, chosen from the standard Meta event
names, with no plain-language aliases and no custom names; this limits what the
settings accept, and a site's own code MAY still change the name through the event
filter, which the plugin does not check against the catalogue. The standard names SHALL be
the Meta Conversions API standard event catalogue, matched case-sensitively in Meta's
own casing: `AddPaymentInfo`, `AddToCart`, `AddToWishlist`, `CompleteRegistration`,
`Contact`, `CustomizeProduct`, `Donate`, `FindLocation`, `InitiateCheckout`, `Lead`,
`PageView`, `Purchase`, `Schedule`, `Search`, `StartTrial`, `SubmitApplication`,
`Subscribe`, `ViewContent`. The default SHALL be
`CompleteRegistration` when the form's title identifies it as a newsletter, subscribe
or signup form — matched by the same lowercased-substring rule against the shipped
pattern list — or the form collects an email address and nothing else, and `Lead`
otherwise. A form collects an email address and nothing else when its email field is
its only input field; fields that carry no visitor input — hidden fields, submit
buttons, captcha and honeypot fields, consent or acceptance checkboxes, and layout
elements such as HTML blocks and dividers — SHALL NOT count as input fields. A form SHALL be stopped by its switch and never by its event name. A form
MAY additionally carry one static numeric value, and the plugin SHALL NOT collect or
send a currency for a form event.

#### Scenario: Newsletter form defaults to registration

- **WHEN** a form whose title identifies it as a newsletter is classified
- **THEN** its suggested event is `CompleteRegistration`, and the user can change it to
  any other standard Meta event name

#### Scenario: Email with a consent checkbox counts as email-only

- **WHEN** a form whose title matches no registration pattern has an email field, a
  consent checkbox and a captcha, and no other field
- **THEN** its suggested event is `CompleteRegistration`

#### Scenario: Email with a message field is a lead

- **WHEN** a form whose title matches no registration pattern has an email field and
  a message field
- **THEN** its suggested event is `Lead`

#### Scenario: Static value is attached when set

- **WHEN** a form carries a static value and its submission is sent
- **THEN** the event carries that value and carries no currency

#### Scenario: Custom event names are rejected

- **WHEN** an event name that is not a standard Meta event name is supplied for a form
- **THEN** the form's event is not changed to that name

### Requirement: Identifiers are detected automatically and manual choices are sticky

For each supported identifier the plugin SHALL determine which form field supplies it,
preferring a field's native type, then patterns in the field's key and label covering
English, French, German, Spanish, Italian and Dutch wording, and SHALL take the first
field in the form's order when more than one field qualifies at the same stage, and
SHALL split a single combined name field on its first space into first and last name,
after trimming surrounding whitespace from the value so that a leading space cannot
produce an empty first name. When such a field's trimmed value contains no space, the
whole value SHALL supply the first name and the last name SHALL be omitted.
A choice made by a person SHALL never be overwritten by later detection, and SHALL
survive a later edit to the form. When the field a person chose no longer exists, the
identifier SHALL be omitted from the event and the choice SHALL be kept, so it applies
again if the field returns. The settings page SHALL highlight a person's choice whose
field no longer exists, or whose field's type or label differs from what it was when
the choice was saved, until the person saves that identifier again; the highlight
SHALL NOT change what is sent. An identifier with no field chosen SHALL be omitted
from the event, and an event SHALL be sent even when no identifier resolves. The plugin
SHALL NOT search a message or free-text field for an identifier. A plain text field whose
key or label only contains a whole-name or state word, such as `Company name` or
`Statement of interest`, rather than being one, SHALL NOT supply that identifier until a
person chooses it, and the settings page SHALL mark it as unconfirmed on the form's row
and in its field list; a field whose whole key or label is such a word SHALL be preferred
over it.

#### Scenario: Native field types are detected without configuration

- **WHEN** a form has fields whose native types are email and phone and no manual
  mapping has been saved
- **THEN** the submission's email and phone are taken from those fields

#### Scenario: Two fields of the same native type

- **WHEN** a form has two separate fields of the native email type, such as an email
  field followed by a confirm-email field, and no manual mapping has been saved
- **THEN** the email identifier is taken from the first of them in the form's order, and
  a person can point it at the other one

#### Scenario: A manual override outlives a change to the form

- **WHEN** a person points the phone identifier at a specific field, and the form is
  later edited
- **THEN** the phone identifier still reads that field, and detection does not move it

#### Scenario: A field added later is picked up without re-saving

- **WHEN** a form gains a field that supplies an identifier for which no manual choice
  was ever saved
- **THEN** the next submission includes that identifier, with no change to the saved
  configuration

#### Scenario: A label that only contains a name word

- **WHEN** a form's only name-like field is a plain text field labelled `Company name`,
  and no manual mapping has been saved
- **THEN** the event carries no first or last name, and the settings page marks that
  field as unconfirmed for the first and last name until a person chooses it

#### Scenario: A combined name field is split

- **WHEN** a form supplies the visitor's full name in one field and that field's value
  contains a space
- **THEN** the text before the first space becomes the first name and the remainder
  becomes the last name

#### Scenario: A combined name field holds a single word

- **WHEN** a form supplies the visitor's name in one field and that field's value
  contains no space
- **THEN** the whole value becomes the first name and no last name is sent

#### Scenario: A combined name field value has a leading space

- **WHEN** a form supplies the visitor's name in one field and that field's value
  begins with a space
- **THEN** the value is trimmed before splitting, so the first name is not empty

#### Scenario: Message bodies are not searched for identifiers

- **WHEN** a form's only email address appears inside the text of a message field
- **THEN** no email identifier is taken from that field

#### Scenario: Clearing an identifier

- **WHEN** a person clears the field chosen for an identifier
- **THEN** that identifier is absent from the event, and detection does not refill it

#### Scenario: A chosen field is removed from the form

- **WHEN** a person pointed the phone identifier at a field, and that field is later
  removed from the form
- **THEN** the phone identifier is absent from the event, detection does not replace
  it, the choice is still stored, and the settings page highlights it

#### Scenario: A chosen field changes type or label

- **WHEN** a person pointed the phone identifier at a field of type telephone labelled
  `Phone`, and that same field is later changed to a text area labelled `Comment`
- **THEN** the phone identifier still reads that field, and the settings page
  highlights the choice until the person saves it again

#### Scenario: No identifier resolves

- **WHEN** an enabled form is submitted and none of its identifiers resolves to a value
- **THEN** the event is sent without customer identifiers, carrying the visitor
  identifier, client IP address, user agent and Meta and TikTok browser identifiers it would otherwise
  carry

### Requirement: A form event carries hashed identifiers, the form title and nothing else from the form

A form event SHALL be sent to the existing event endpoint in the same payload shape
WooCommerce events use, carrying the event name, a server-generated event id, the time
of the submission, the website action source and the site URL. Its `additionalData`
SHALL carry the form title as `contentName`, and a value only when the form has a
static value set. Its `customerData` SHALL carry the mapped identifiers — email,
phone, first name, last name, city, state, postcode and country — normalised and
hashed by the same rules WooCommerce events use, together with the visitor identifier,
the public client IP address and the user agent of the submitting request. Its
`eventData` SHALL carry the browser and click identifiers and the attribution that the
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

### Requirement: An unanswered consent banner holds the submission, and a refusal reports it

The plugin SHALL resolve the visitor's marketing-consent decision for a form
submission on the submitting request, using the same resolution the plugin applies to
other server-side events. A granted or absent-banner decision SHALL send the event; a
denial SHALL send an anonymous blocked report and store nothing; an unanswered opt-in
banner SHALL store a hold recipe and send nothing yet. Because the plugin's visitor
cookie does not exist until consent is given, a hold recipe SHALL be keyed to a
first-party cookie the plugin sets when it holds a submission: it SHALL carry only a
random token that identifies no visitor, SHALL be declared as a functional cookie in the
site's cookie register, and SHALL be how a later request of the same browser finds the
recipe. A hold recipe SHALL contain only hashed identifiers, the event name, the event
id, the submission time, the form title and the static value when one is set. On a later grant the event SHALL be sent
with the original submission time and the stored value, and with no currency, and its
browser and click identifiers and attribution SHALL be read from the cookies of the
request that sends it, as for a replayed WooCommerce event; on a
later denial, and when the visitor returns with the no-decision signal gone and neither
a grant nor a denial recorded, the recipe SHALL be discarded and an anonymous blocked
report sent. A visitor SHALL hold at most 20 recipes, the oldest being discarded when a
new one would exceed that, and a recipe SHALL expire 48 hours after it was stored. A
recipe discarded for the cap or by expiry SHALL disappear without a blocked report, as
the WooCommerce hold queue does.

#### Scenario: Submission while the banner is unanswered

- **WHEN** a form is submitted on a request that carries the plugin's
  no-decision-yet signal
- **THEN** no event is sent, and a hold recipe carrying only hashed identifiers is
  stored

#### Scenario: Consent granted after the submission

- **WHEN** the visitor grants marketing consent after a held form submission
- **THEN** the event is sent once, with the time of the original submission rather
  than the time of the grant, and carrying the identifiers hashed at hold time

#### Scenario: Consent denied after the submission

- **WHEN** the visitor denies marketing consent after a held form submission
- **THEN** no event is sent, an anonymous blocked report records the denial, and the
  hold recipe no longer exists

#### Scenario: Visitor leaves without deciding

- **WHEN** a held form submission's no-decision signal disappears with neither a grant
  nor a denial recorded
- **THEN** no event is sent, an anonymous blocked report records the absent decision,
  and the hold recipe no longer exists

#### Scenario: A granted hold takes cookies from the sending request

- **WHEN** a form is submitted while the banner is unanswered and before any `_fbp` or
  `_ttp` cookie exists, and the visitor's later request that sends it after the grant
  carries `_fbp` and `_ttp`
- **THEN** the event sent after the grant carries `fbp` and `ttp` from that later
  request

#### Scenario: A held static value survives the grant

- **WHEN** a form with a static value is submitted while the banner is unanswered, and
  the visitor then grants consent
- **THEN** the event sent after the grant carries that value and no currency

#### Scenario: A visitor's twenty-first held submission

- **WHEN** a visitor already holds 20 recipes and submits another form while the
  banner is unanswered
- **THEN** the oldest recipe is discarded without a blocked report, and the new one is
  stored

#### Scenario: A visitor who never returns

- **WHEN** a visitor holds a recipe and does not return to the site within 48 hours
- **THEN** the recipe expires, and no event and no blocked report are sent for it

#### Scenario: A visitor with no visitor cookie is held

- **WHEN** a form is submitted while the banner is unanswered by a visitor who carries
  no PixelFlow visitor cookie, as every visitor who has not answered the banner is
- **THEN** the submission is held under the plugin's hold cookie, and sent after a grant
  on a later request of the same browser

#### Scenario: Submission cannot be keyed to a visitor

- **WHEN** a form is submitted while the banner is unanswered and the plugin cannot set
  its hold cookie, because the response has already been sent
- **THEN** an anonymous blocked report records the absent decision immediately, and the
  submission is neither sent nor silently dropped

#### Scenario: Denial keeps no form data

- **WHEN** a submission is refused because the visitor denied consent
- **THEN** no identifier, hashed or raw, from that submission is stored anywhere

### Requirement: Automated traffic, excluded roles and repeat submissions do not produce events

The plugin SHALL classify a form submission for automated traffic by the same rules it
applies to other server-side events, and SHALL send an anonymous blocked report
instead of an event when the submitting request is classified as automated or as a
speculative prefetch. A submission by a user whose role the site has excluded from
tracking SHALL send neither an event nor a blocked report. A repeated submission of the
same form by the same visitor within two minutes SHALL produce one event only, including
while the submission is held for an unanswered banner, so that a held repeat yields one
hold recipe. A submission whose client
IP address is private SHALL send neither an event nor a blocked report. The
cookie-absence rule that suppresses anonymous add-to-cart requests SHALL NOT apply to
form submissions.

#### Scenario: Double-clicked submit button

- **WHEN** the same visitor submits the same form twice within two minutes
- **THEN** exactly one event is sent

#### Scenario: Double-click while the banner is unanswered

- **WHEN** the same visitor submits the same form twice within two minutes while the
  consent banner is unanswered, and later grants consent
- **THEN** one hold recipe is stored, and exactly one event is sent after the grant

#### Scenario: An automated request does not use up a visitor's window

- **WHEN** a request classified as automated submits a form from the same client IP as
  a visitor without a visitor cookie, and that visitor submits the same form within two
  minutes
- **THEN** the automated request produces a blocked report, and the visitor's
  submission sends its event

#### Scenario: Automated client submits a form

- **WHEN** a form submission arrives on a request classified as automated
- **THEN** no event is sent and an anonymous blocked report records the classification

#### Scenario: Excluded role submits a form

- **WHEN** a signed-in user whose role the site excludes from tracking submits a form
- **THEN** no event and no blocked report are sent

#### Scenario: Ad-blocked visitor is not treated as automated

- **WHEN** a form is submitted by a visitor whose request carries none of the plugin's
  cookies because an ad blocker prevented them
- **THEN** the submission is not classified as automated on the strength of the absent
  cookies, and the event is sent

#### Scenario: Spam submission

- **WHEN** the form plugin reports a submission as spam, or reports that validation
  failed or the submission was aborted
- **THEN** no event is sent and no blocked report is sent

#### Scenario: Mail delivery fails but the submission succeeded

- **WHEN** a form plugin records a successful submission whose notification email
  could not be delivered
- **THEN** the event is sent, because the visitor completed the form

### Requirement: A form is listed before it has ever been submitted

The plugin SHALL list a form that exists on the site whether or not that form has ever
been submitted, including a form that has no record of its own and exists only as part
of a page's layout. A form SHALL NOT have to be submitted once in order to be
configurable. A form that exists only inside a reusable template SHALL be listed even
when no page uses that template, because the site stores no link from a template to the
places it is used. Listing SHALL be resilient to unreadable layout data: the plugin
SHALL list the forms it could read and SHALL NOT fail the whole list because part of the
stored layout could not be parsed. A form that can be submitted SHALL be offered for
configuration, and a page that is not published, including one only reachable through
a preview, SHALL NOT by itself keep its form out of the list. A form's identity SHALL
include the form plugin that owns it, so that forms of two plugins that share an id
are listed, configured and sent independently.

#### Scenario: Two plugins with the same form id

- **WHEN** two active form plugins each have a form with the id `5`, and only one of
  them is configured
- **THEN** the list shows two rows, and the other plugin's form keeps its own computed
  state and does not take the configured form's event, switch or field mapping

#### Scenario: A form built into a page is listed before any submission

- **WHEN** the settings page is opened on a site whose only forms are built into page
  layouts, and none of them has ever been submitted
- **THEN** each of those forms appears in the list with its fields, its computed
  confidence and its suggested event, and a medium-confidence one among them can be
  turned on before its first submission

#### Scenario: Unreadable layout data does not empty the list

- **WHEN** the stored layout of one post cannot be parsed, or contains a kind of element
  the plugin does not recognise, while other posts hold readable forms
- **THEN** the forms that could be read are listed, and no error is shown in place of
  the list

#### Scenario: A form on a page that is still a draft

- **WHEN** a form sits on a page that has not been published, and a signed-in editor
  opens that page's preview and can submit the form from there
- **THEN** the form appears in the list with a switch of its own, so a submission made
  while testing the page can be switched off without disabling form tracking for the
  whole site

#### Scenario: A form on a page visible only to authorised users

- **WHEN** a form sits on a page that is live but restricted to authorised users, so an
  ordinary visitor cannot reach it while a signed-in editor can
- **THEN** the form appears in the list with a switch of its own, because a submission
  of it would otherwise send an event that nobody could turn off short of disabling form
  tracking for the whole site

#### Scenario: A form inside an unused template

- **WHEN** a form exists only inside a reusable template that no page currently uses
- **THEN** it appears in the list, and enabling it is allowed

#### Scenario: A form the listing missed still sends

- **WHEN** a form that the listing did not find is submitted successfully, and it is
  high confidence
- **THEN** the submission is sent with the form's computed event and identifiers, and
  the form is not added to the list

### Requirement: A form removed from the site keeps its configuration and sends nothing

When a form that was configured no longer exists on the site, the plugin SHALL keep
its row, its event and its field mapping, SHALL prevent the row from being switched on,
and SHALL send nothing for it. The row SHALL state that the form is missing and its
configuration SHALL become effective again if a form with the same identity reappears.
A form SHALL NOT be treated as missing merely because it, or the page holding it, is
not currently published or active. A form is missing when its form plugin no longer
lists it: the form was deleted or moved to that plugin's trash, or, for a form that
lives in a page layout, the page or reusable template holding it no longer exists, has
been moved to the trash, or no longer contains the form.

#### Scenario: A form deleted in its own plugin

- **WHEN** a configured form is deleted, or moved to the trash, in the admin of a form
  plugin that keeps its own list of forms
- **THEN** its row states that the form is missing, its switch cannot be turned on, and
  its event and field mapping are still visible

#### Scenario: A deactivated form is not missing

- **WHEN** a configured form is deactivated or unpublished in its own plugin without
  being deleted
- **THEN** its row does not state that it is missing, and its event and field mapping
  are unchanged

#### Scenario: Configured form is deleted

- **WHEN** a form that was on and configured is deleted from the site
- **THEN** its row states that the form is missing, its switch cannot be turned on, and
  its event and field mapping are still visible

#### Scenario: An unpublished page does not lose its configuration

- **WHEN** a configured and enabled form sits on a page whose status changes from
  published to a draft, and the page itself still exists
- **THEN** the form's row does not state that it is missing, its switch stays as it was,
  and its event and field mapping are unchanged

#### Scenario: A trashed page's form is missing

- **WHEN** a configured and enabled form sits on a page that is moved to the trash
- **THEN** its row states that the form is missing, its switch cannot be turned on, and
  its event and field mapping are still visible

#### Scenario: A trashed template's form is missing

- **WHEN** a configured and enabled form exists only inside a reusable template that is
  moved to the trash
- **THEN** its row states that the form is missing, on the same terms as a form whose
  page was trashed

#### Scenario: Form reappears

- **WHEN** a form with the same identity as a missing configured form exists on the
  site again
- **THEN** its stored event and field mapping apply to its next submission

### Requirement: Raw form values never leave the request

The plugin SHALL NOT include any raw form field value in an event payload, in a hold
recipe, in an anonymous blocked report, or in the debug log. Form entries SHALL be
written to the debug log only while a form debug-logging switch of their own, off by
default, is on; it sits beside the WooCommerce debug switch in the Advanced settings, is
offered whether or not WooCommerce is active, is independent of the WooCommerce debug
switch, and both write to the same log file. Identifiers SHALL be
normalised and hashed before being sent or stored. The debug log for a form submission
SHALL record which identifiers were present, never their values, and SHALL mask the
client IP address the same way the WooCommerce debug log masks it, so that the final
octet is not written.

#### Scenario: Debug logging is on

- **WHEN** debug logging is enabled and a form carrying a message, an email address and
  a phone number is submitted
- **THEN** the log entry names the identifiers that were present and contains neither
  the message text nor the email address or phone number in readable form

#### Scenario: Form logging has its own switch

- **WHEN** the WooCommerce debug switch is on and the form debug switch is off, and a
  form submission is sent
- **THEN** no form entry is written to the debug log

#### Scenario: Form logging is offered without WooCommerce

- **WHEN** WooCommerce is not active and the Advanced settings are opened
- **THEN** the form debug switch and the log file controls are offered, and the
  WooCommerce debug switch is not

#### Scenario: Client IP is masked in the log

- **WHEN** debug logging is enabled and a form submission is logged
- **THEN** the logged client IP address has its final octet masked, matching the
  WooCommerce debug log's treatment of the same field

#### Scenario: Hold recipe contents

- **WHEN** a submission is held pending a consent decision
- **THEN** the stored recipe contains no raw field value

### Requirement: A site can suppress, adjust or supply a form event programmatically

The plugin SHALL expose a filter that can suppress the event for a single submission,
a filter that can change the event name, the value and the customer data before the
event is sent, and an action through which a form plugin the plugin does not support
can submit a normalised submission into the same path, subject to the same gating.
Both filters SHALL run once, on the submitting request and before the decision to send
or hold; a held submission SHALL store their result, and sending it after a grant SHALL
NOT run them again.

#### Scenario: A filtered event is held and later granted

- **WHEN** a site's code changes a submission's event name through the event filter,
  the banner is unanswered, and the visitor later grants consent
- **THEN** the event sent after the grant carries the changed name, and neither filter
  runs on the grant

#### Scenario: A site suppresses one form

- **WHEN** a site's code returns a refusal from the suppression filter for a given
  submission
- **THEN** no event is sent for that submission

#### Scenario: A site renames the event in code

- **WHEN** a site's code changes a submission's event name through the event filter to
  a name outside the standard catalogue
- **THEN** the event is sent under that name

#### Scenario: An unsupported form plugin supplies a submission

- **WHEN** a site's code passes a normalised submission through the action
- **THEN** that submission is subject to the same consent, automated-traffic, dedupe
  and privacy rules as a submission from a supported plugin

### Requirement: The excluded-roles setting states where it does not apply

Because the excluded-roles setting suppresses form events server-side but does not
suppress WooCommerce events server-side, the settings page SHALL state that limitation
next to the `Exclude Script for User Roles` control. The statement SHALL be shown only
when WooCommerce tracking is on, since on a site without it the setting has no such
exception. The statement SHALL NOT change what any event sends.

#### Scenario: The limitation is stated on a store

- **WHEN** the settings page is viewed on a site where WooCommerce is active and
  WooCommerce tracking is on
- **THEN** the `Exclude Script for User Roles` control carries a note that the setting
  is not applied to WooCommerce event sending

#### Scenario: The limitation is not stated without WooCommerce tracking

- **WHEN** the settings page is viewed on a site with no WooCommerce, or with
  WooCommerce tracking off
- **THEN** no such note is shown

### Requirement: WooCommerce event behaviour is unchanged

Form events SHALL NOT alter the behaviour of WooCommerce events, and a held form
submission SHALL NOT be written into the WooCommerce hold queue or be replayed by its
flush. Form tracking SHALL work on a site where WooCommerce is absent or where
WooCommerce tracking is off.

#### Scenario: Form hold on a store

- **WHEN** a form submission is held on a site where WooCommerce tracking is also on
- **THEN** the WooCommerce hold queue does not contain that submission, and a
  WooCommerce flush does not send it

#### Scenario: Form tracking without WooCommerce

- **WHEN** form tracking is on for a site with no WooCommerce installed
- **THEN** form submissions are sent, held, replayed and reported exactly as they are
  on a store
