## Context

See proposal.md — Why. What shapes this design is what already exists in the plugin and
cannot be reused as-is.

The WooCommerce path is the model: `PixelFlow_WooCommerce_Cart_Hooks::post_event()`
resolves identity, attaches the consent block, classifies the request for automated
traffic, either holds or beacons, and finally POSTs `/event`. Everything it calls is a
free function in `includes/helpers.php`, `includes/consent.php`,
`includes/blocked-events.php` and `includes/held-events.php`, so the gating is reusable
without touching the Woo class. `post_event()` itself is not: it is private, it is
shaped around a cart and an order, and its context keys are documented as not uniform
across event types.

Three existing facts constrain the design.

- The hold queue is bound to WooCommerce twice over.
  `pixelflow_enqueue_held_woo_event()` returns false when `pixelflow_woo_session()`
  finds no `WC()->session` (`includes/held-events.php:274`), and
  `pixelflow_should_queue_held_event()` accepts only the names in
  `PIXELFLOW_HELD_WOO_EVENT_NAMES` (`includes/held-events.php:49`). Its flush script is
  enqueued only when `woo_enabled` is set (`pixelflow.php:318`), and its routes are
  `wc_ajax` routes, which do not exist without WooCommerce.
- The session dedupe is equally bound: `should_send_event()` returns true immediately
  when there is no `WC()->session`, so on a form-only site it would not deduplicate at
  all.
- The wire shape of `additionalData` is mixed case, and the camelCase keys are the ones
  in production: `contentName` and `contentType` are camelCase
  (`includes/woo/hooks/class-woocommerce-hooks.php:473`), while `num_items`, `contents`,
  `value` and `currency` are not. `numItems` exists only as an internal hold-recipe key
  and is written back out as `num_items` (`includes/held-events.php:101`, `:233`).

The settings surface is a React application under `app/source/`, reading and writing
the `pixelflow_general_options` option; the WooCommerce feature there is the precedent
for a new Forms feature.

## Goals / Non-Goals

**Goals:**

- Reuse the existing consent, identity, attribution, automated-traffic and reporting
  helpers unchanged, so a form event is gated as a WooCommerce event is on consent,
  automated traffic and reporting. The one deliberate difference is the excluded-roles
  setting, which form events honour server-side and WooCommerce events currently do
  not — see the decision below.
- Keep each form plugin's knowledge in one small adapter with a fixed interface, so
  adding a sixth plugin is one file and no change to the dispatcher.
- Make the default state safe: nothing sends until someone opts in, and within that,
  only forms whose identifiers are certain send without a per-form decision.
- Keep raw submission data inside the request that produced it.

**Non-Goals:**

- Changing any WooCommerce behaviour, including its hold queue, its dedupe and its
  flush script. Form events get their own equivalents.
- Reaching into `post_event()`. The dispatcher assembles its own payload from the same
  helpers.
- A shared `event_id` with the browser script, which would require a contract the
  script does not offer.

## Decisions

### Server-side only, no browser fire

A form event is POSTed from PHP on the submitting request. The browser script is not
asked to fire anything.

*Why:* the plugin's reason to exist is that a server-side event survives an ad blocker
that removes the browser script; a form POST reaches PHP regardless. *Alternative
considered:* calling `window.pixelFlow.trackEvent()` from a small listener would give
free deduplication and free consent handling, because the script generates one
`event_id` for both its pixel and its own Conversions API call. It was rejected because
it sends nothing for exactly the visitors this plugin exists to recover, and because it
duplicates what the dashboard's Visual Tagger already does without a plugin. *Cost:*
there is no shared `event_id`, so a site that has also tagged the same form in the
dashboard sends two events that Meta cannot collapse. This change warns once, above the
form list, and does not detect the overlap.

### A dispatcher beside the Woo hooks, not inside them

`includes/forms/` gets a dispatcher that takes a normalised submission, applies the
mapping, hashes identifiers, and assembles the same payload shape, calling
`pixelflow_resolve_external_id()`, the `pixelflow_external_id` filter,
`pixelflow_append_consent_to_payload()`, `pixelflow_resolve_blocked_event_reason()`,
`pixelflow_append_cookie_params()` and `pixelflow_append_attribution_from_cookie()`
directly. The two site filters run on the submitting request before the send-or-hold
decision, as `pixelflow_should_send_add_to_cart` does
(`includes/woo/hooks/class-woocommerce-hooks.php:206`); a held recipe stores their
result and the replay does not call them, because the raw submission they would need
no longer exists. `pixelflow_append_cookie_params()` also appends TikTok's `ttp` and
`ttclid` (`includes/helpers.php:743`), so form events carry them on the same terms as
storefront events without a call of their own; the replay calls it and
`pixelflow_append_attribution_from_cookie()` again on the sending request, as the Woo
flush does (`includes/woo/hooks/trait-held-woo-events.php:193-194`), because the pixels
often write their cookies only after the grant.

*Why:* those helpers are free functions with no Woo dependency, and the parts of
`post_event()` that are not reusable — the cart, the order, the purchase claim, the
Woo-session dedupe — are most of it. *Alternative considered:* extracting a shared
base class or a common `post_event()` from the Woo hooks. Rejected for this change: it
would edit the highest-risk file in the plugin for no behavioural gain, and the
WooCommerce path is explicitly out of scope. *Cost:* the payload assembly exists twice,
so a future change to the envelope has two call sites. The camelCase key list is the
part most likely to drift.

### `contentName`, not `content_name`

The form title is sent as `additionalData.contentName`.

*Why:* that is the key the plugin already sends for WooCommerce events, and those
events are verified end to end against the dashboard, so the ingest is known to accept
it. The PRD says `content_name` in two places while also stating that form events use
the body WooCommerce already uses; those two statements conflict and the code settles
it. Whether the ingest also accepts the snake_case spelling is unknown and does not
need to be known.

### A form hold queue of its own, on a transient

A submission held for an unanswered banner is stored as a recipe in a transient keyed by
a random token the plugin keeps in its own first-party cookie, `_pf_held_form_events`,
which it sets when it holds the first submission. The token identifies no visitor; the
cookie is declared as functional through the WP Consent API cookie register, like
`_pf_no_consent_decision`. It cannot be the visitor id: the tracking script writes
`_pf_uid` and `_pf_attribution` only after consent, so a visitor under an unanswered
banner — the only visitor who is ever held — carries neither (observed on the test site:
only `_pf_consent_source` and `_pf_no_consent_decision` exist before an answer). The
WooCommerce queue has the same need and meets it the same way: it lives in `WC()->session`,
which WooCommerce keeps in its own `wp_woocommerce_session_*` cookie
(`woocommerce/includes/class-wc-session-handler.php:71`) whatever the consent state. The
transient holds an array of recipes, capped at 20 — the same number
as `PIXELFLOW_HELD_WOO_EVENTS_CAP` (`includes/held-events.php:20`) and overridable by its
own filter with the oldest recipe evicted when a new one overflows it — and expires
after 48 hours. The cap and the array shape mirror the Woo
queue deliberately; the expiry cannot, because the Woo queue has no TTL of its own and
dies with `WC()->session`, so 48 hours is chosen to match that session's default
lifetime for a guest (a signed-in customer's session lasts a week,
`woocommerce/includes/class-wc-session-handler.php:402`; a form submitter is almost
always a guest). It carries only the hashed customer keys already
listed in `PIXELFLOW_HELD_CUSTOMER_KEYS`, plus the event name, event id, submission time,
form title and the static value when one is set — the Woo recipe keeps `value` too
(`includes/held-events.php:98`). The replay builds its own `additionalData` rather than
calling `pixelflow_held_recipe_additional_data()`, which defaults `currency` to `USD`
(`includes/held-events.php:223`) and so would send a currency a form event must not
carry. A recipe evicted by the cap or lost to expiry disappears without a blocked report,
as the Woo queue's do; `no_decision` is reported only when the visitor comes back and the
flush finds the hold signal gone. A separate flush script, enqueued when form tracking is on, drives two
`admin-ajax` routes that mirror the Woo pair: a state route that takes no nonce, is
visitor-scoped and mints a fresh nonce, and a flush route that verifies it.

*Why:* the Woo queue cannot carry a `Lead`, cannot be written without a `WC()->session`,
and its flush script does not load on a site with WooCommerce tracking off — which is
the typical site for this feature. The nonce split is copied deliberately: a full-page
cache bakes a stale nonce into the HTML, which is why the existing state route mints one
at request time (`includes/woo/hooks/trait-held-woo-events.php:39-54`). `admin-ajax`
rather than `wc_ajax`, because the latter does not exist without WooCommerce.
*Alternative considered:* detaching the existing hold store from WooCommerce so both
event families share it. Rejected because the PRD puts changes to the WooCommerce hold
queue out of scope, and because widening a store that today has exactly two callers and
two event names is a larger blast radius than a parallel store. *Cost:* two hold
mechanisms with near-identical grant/deny/abandon logic live in the plugin. If a third
event family ever needs holding, the right move is to unify them, and this design makes
that later work larger, not smaller.

### Dedupe on an options row claimed by `INSERT IGNORE`, not a session

A repeat of the same form by the same visitor within two minutes is dropped using an
options row keyed by the form key together with the visitor id, falling back to the client
IP when no visitor cookie is present, so two different forms submitted in a row are
never merged. The row is claimed with one `INSERT IGNORE`: `option_name` carries a unique
index, so of two concurrent requests of one double click exactly one inserts it. Closed
windows are deleted by the next claim, and on uninstall.

*Why:* the existing dedupe reads `WC()->session` and silently passes everything through
when there is none. *Alternative considered:* a WordPress session or a cookie. Rejected:
a cookie is writable by the client, and starting a PHP session for this is heavier than
the guard is worth. *Alternative considered:* a transient, the first implementation.
Rejected: reading it and then writing it lets two concurrent requests both pass, and
the submit handler of Contact Form 7 does not guard against a second submission while
one is in flight.
`wp_cache_add()` is atomic only with a persistent object cache, which most sites lack.
*Cost:* two queries per submission that bypass the options cache.

Whether a submission closes the window follows the WooCommerce rule in
`send_settled_the_event()` (`includes/woo/hooks/class-woocommerce-hooks.php:1494`)
exactly: `sent` and `held` close it, `blocked` closes it unless the reason is `bot`, and
`skipped` and `failed` leave it open. `held` closes it so that a double click while the
banner is unanswered stores one recipe rather than two that would both replay on a
grant; a `bot` block leaves it open so that an automated request sharing a visitor's IP
does not spend the window the visitor's real submission needs.

*Cost:* an IP fallback merges two visitors behind one NAT for two
minutes, which drops a real second lead in a rare case. The window is short for that
reason.

### Mapping stored sparsely, detection at submit time

One plugin option holds a record per form, keyed `<source>:<form id>` — for example
`cf7:12`, `gravity:5`, `fluent:5`, `elementor:<post id>:<widget id>` — because Gravity
Forms, Fluent Forms and Ninja Forms number their forms in tables of their own, each
starting at 1, so a bare id collides across plugins and with post ids. The record holds
enabled, event, value, and a `fields` map, and every one of them is stored only when a
person changed it. Everything absent is resolved at submit time: `enabled` falls back to
"the form is currently high confidence", `event` to the current suggested event, and
each identifier to detection. The settings app writes `enabled` or `event` for a form
only when the person changed that control, so saving the page never freezes a computed
state.

*Why:* a form edited later — a field renamed, a field added — keeps working without
anyone re-opening the settings page, while a deliberate choice is never silently
overridden. *Alternative considered:* writing the full resolved map at save time.
Rejected because it goes stale the moment the form changes, and the staleness is
invisible. The same holds for `enabled`: writing every row's switch on save would leave a
medium-confidence form that later gains an email field switched off, against the rule
that a high-confidence form sends once the master toggle is on. The consequence accepted
instead is that an untouched form which becomes high confidence after an edit starts
sending without anyone opening the settings page. *Cost:* the settings page shows a detected choice that is computed, not
stored, so the UI must distinguish "detected" from "chosen" — which is what the
wording beside each identifier is for: found by field type, found by field name, or
needs the person's choice, and nothing beside a chosen field or when no field supplies it. A person's choice
stores the field key together with the field's type and label as they were when it was
saved; the key is what is read at submit time, and the type and label exist only so the
settings page can highlight a choice whose field has since been removed or changed.

### Adapter interface

Each adapter exposes whether its plugin is active, lists forms, lists a form's fields as
key, label and type, and registers its success hook. Keys are the plugin's own stable
ids, never labels.

`list_forms()` returns every form that is neither deleted nor in its plugin's trash,
whatever its active or published state, and a configured form it does not return is
missing. The Elementor walk below is that same rule applied to posts. How each plugin
marks a form as trashed is confirmed against the installed plugin, as the spam
representation is.

| Plugin | Form identity | Field key | Success hook | Sends when |
| --- | --- | --- | --- | --- |
| Contact Form 7 | post id | tag name (`your-email`) | `wpcf7_submit` | status is `mail_sent` or `mail_failed`; skip spam, validation failure, aborted |
| WPForms | form id | field id (`3`, `3.first`) | `wpforms_process_complete` | skip spam entries |
| Gravity Forms | form id | field or input id (`2`, `1.3`) | `gform_after_submission` | skip when the entry status is spam |
| Elementor Pro | post id + widget id | field `custom_id` | `elementor_pro/forms/new_record` | listed from `_elementor_data`, then the same rules |
| Fluent Forms | form id | field `name` attribute (`email`, `email_1`, `phone`), composite name sub-inputs as separate keys | `fluentform/submission_inserted` | fires after the entry is stored and the plugin's own integrations have run; skip an entry the plugin flagged as spam |
| Ninja Forms | form id | field key | the plugin's post-submission action, confirmed against the installed plugin | skip an entry the plugin flagged as spam |

`mail_failed` counts because the visitor completed the form; a broken SMTP is the site's
problem, not evidence that no conversion happened.

Ninja Forms' hook name and the shape of its submitted data are not assumed here: like
Fluent Forms, its adapter task confirms both against the installed plugin before the
adapter is written.

### Elementor forms are read from `_elementor_data`, not discovered on submission

Elementor has no form registry: a form is a widget inside a page's layout. The adapter
therefore enumerates forms by reading the `_elementor_data` post meta — the JSON widget
tree Elementor stores per post — and walking it for form widgets, taking each one's
widget id and its fields' `custom_id`, label and type. The walk covers every post type
Elementor renders, including `elementor_library` templates and popups, not only pages.

*Why:* the alternative, registering a form the first time it is submitted, has three
consequences that reading the tree removes outright. A medium-confidence form could
never be pre-enabled, because it does not exist in the list until someone submits it, so
its first conversion is always lost. A site with Elementor Pro active and no submissions
yet would show an empty form list, which reads as a broken settings page. And a
discovered form would need a store of its own, with a cap and a pruning rule, that no
other adapter needs. *Alternative considered:* lazy registration on first submission
(the earlier design). Rejected for those three reasons. *Cost:* the adapter depends on
Elementor's internal JSON shape, which is not a public contract and can change between
major versions, and listing forms means reading post meta across Elementor posts rather
than querying a registry. The submission hook stays the source of truth for sending, so
a form the walk misses still sends once it is submitted, with its computed
configuration. It is not added to the list, because that would need the discovered-form
store rejected above.
The walk applies one rule, to listing and to deciding whether a configured form still
exists alike: every post Elementor renders — an ordinary post or an `elementor_library`
template, popups included — counts in any status except `trash` and `auto-draft`, and a
revision never counts.

The rule follows from one invariant: anything that can send must be configurable,
otherwise a high-confidence form sends events with no switch to stop it short of the
master toggle — the send-without-control defect that reading the widget tree was chosen
to avoid. Post status turned out to be weak evidence of whether a form can send. A
private page is live for authorised users. A draft, pending or scheduled page can be
opened in WordPress's preview, and Elementor's form handler checks no post status at all
before accepting a submission (`elementor-pro/modules/forms/classes/ajax-handler.php:32`),
so a form tested in preview sends. A template's own status says nothing about whether
its content is pulled into a published page, and Elementor records no link from a
template to the places it is used. Every non-deleted status can therefore send, and every
non-deleted status is listed. An earlier version of this design listed published pages
only and used a wider net for existence; it was collapsed into one rule once preview
showed that the narrower listing left submittable forms without a switch.

The exclusions are the states in which nothing can send. `trash` is how a site owner
deletes a page or template, so a trashed one's form reads as missing, and restoring it
brings the configuration back. `auto-draft` is the empty placeholder WordPress creates
before a post's first save; Elementor writes no `_elementor_data` there. WordPress's own
`post_status => 'any'` excludes both, because each is registered as an internal status
(`wp-includes/post.php:732`, `:747`). Revisions are excluded by post type, because
Elementor copies `_elementor_data` onto them and one form would otherwise appear once
per saved revision.

*Cost:* the list shows forms from pages that are still being built, and from templates
that were saved and never used. The spec states both as expected rather than leaving
them to read as bugs; a form that is not wanted is switched off like any other.

Fluent Forms' hook signature is `($submissionId, $formData, $form)`, and `$formData` is
keyed by each field's `name` attribute, so the adapter's field keys and the submitted
values share one vocabulary. How a spam-flagged entry is represented differs from
Gravity Forms — Fluent Forms leans on honeypot and captcha rejection rather than a stored
spam status — so the adapter task confirms that against the installed plugin instead of
assuming the Gravity shape.

An embedded HubSpot form is deliberately absent: it posts to HubSpot from the browser, so
nothing reaches WordPress and there is no server-side hook to adapt. Such a form is a
Visual Tagger case, which is what the settings page's fallback link is for.

### Excluded roles are gated server-side for forms only

A submission by a user whose role the site excludes sends nothing.

*Why:* a site owner who excludes a role expects that role's activity not to be
tracked, and a form is the one surface where a signed-in administrator testing the site
would otherwise generate a `Lead`. *What this is not:* a reuse of existing behaviour.
`should_exclude_current_user()` (`pixelflow.php:403`) is called only when the browser
pixel is injected (`pixelflow.php:270`) and when the Woo flush script is enqueued
(`pixelflow.php:320`); the server-side Woo dispatcher in
`includes/woo/hooks/class-woocommerce-hooks.php` never reads the setting, so an excluded
role's WooCommerce events still send today. *Cost:* until the Woo dispatcher is aligned,
the same role is treated differently by the two event families, which support staff may
notice. Aligning it is a separate change, deliberately kept out of this one to hold the
blast radius at the forms feature.

An excluded-role submission sends no blocked report either; the debug log records the
skip reason. A report would need a new reason, and the reason list is closed:
`PIXELFLOW_BLOCKED_EVENT_REASONS` holds `denied`, `no_decision` and `bot`
(`includes/blocked-events.php:15-17`), the body builder drops any other
(`includes/blocked-events.php:273`), and `consent-resolution` states when a report is
sent. An `excluded_role` reason is wanted, but it needs the backend to accept it and
`consent-resolution` to list it, so it is deferred to a separate change and is not part
of this one.

### Form debug logging has its own switch

Form entries go to the existing debug log file, but only while a `forms_debug_enabled`
general option is on. The WooCommerce switch cannot serve: its control is shown only when
WooCommerce tracking is on (`AdvancedSettings.tsx:203`), so a form-only site could not turn
logging on. The admin notice that warns while logging is on covers either switch.

### One shared standard-event catalogue

The full Meta Conversions API standard event catalogue is defined once as a shared
constant and read by both the option sanitizer and the settings dropdown.

*Why:* the spec's own defaults (`Lead`, `CompleteRegistration`) are members of that
catalogue, and the requirement that a non-standard name be rejected needs a definite
list to reject against; the plugin today has no such list and hard-codes event strings
ad hoc. Matching is case-sensitive in Meta's casing, because Meta treats
`completeregistration` as a custom event. *Alternative considered:* shipping only the
handful of names the dropdown needs. Rejected because the spec lets a person choose any
other standard name. *Cost:* Meta occasionally adds a standard event, so the constant is
a maintenance surface; it is one edit in one place.

### Form-title classification by lowercased substring

A form title is classified by lowercasing it and testing it for substrings from two
shipped lists: exclusions (search, login, password, comment) and registrations
(newsletter, subscribe, signup). A title matching neither is neither excluded nor a
registration form.

The other route to `CompleteRegistration`, an email-only form, counts input fields by
the type each adapter already reports: the email field must be the only one, and types
that carry no visitor input — hidden, submit, captcha and honeypot, consent or
acceptance checkboxes, HTML and divider blocks — are not counted. A newsletter box with
a GDPR checkbox therefore reads as email-only, and a contact form with a message field
does not. *Cost:* each adapter carries its plugin's list of non-input field types.

*Why:* substring matching survives the real titles sites use (`Search our site`,
`Newsletter signup — footer`) where exact matching would not, and it reuses the shape
already chosen for identifier patterns. Both lists cover the same EU languages as the
identifier patterns, because an English-only list would put a French or German search
form into high confidence on day one and start sending false `Lead`s — the exact risk
the exclusion list exists to prevent. *Cost:* a substring list has false positives; a
form titled `Comment on our service` is excluded and needs a per-form switch. That
direction of error costs a lead, not a polluted audience, which is the safer failure.

### Settings panel wording departs from the PRD's pills

The PRD draws an Auto / Suggested / Custom / Empty pill on each identifier and a
Sending / Not sending pill in the open panel. Review of the built page found the pill
words unclear and the sending pill redundant next to the form's own switch, so the panel
says in words how each detected field was arrived at, says nothing beside a chosen one,
shows a dash for an identifier with no field or with sending refused, and has no sending pill. The static value stays, as the PRD specifies it: a
number, with no currency. The PRD also keeps the list on screen with the master toggle
off; the page hides it instead, as the WooCommerce tab hides its settings, and keeps the
stored choices.

*Why:* the owner's review of the running page. *Cost:* the page no longer states in one
place whether the master toggle, the plugin switch and the form's switch together result
in sending; each is visible on its own control.

The controls come from the UI kit — `Switch`, `Label`, `Button`, `Input`, and `Dropdown`
for the single-choice lists, built as the kit's currency picker is — and the overlap
warning is the app's shared `Notification`. *Alternative considered:* the kit's
`ExpandableTable`. Rejected: its rows are a fixed 48px, several can be open at once
while this list opens one at a time, and its expand button carries no text.

## Risks / Trade-offs

- A wrongly classified form sends a false `Lead` and pollutes ad optimisation → the
  denylist of search, login, password and comment titles, plus the rule that only a
  native email or phone field makes a form high confidence; everything inferred waits
  for a person.
- A site that already tags the same form in the dashboard double-counts, because the two
  events carry different ids → one static warning above the form list. Not
  detected in this change; the overlap lives in the dashboard, which the plugin cannot
  read.
- Storing raw field values in a hold recipe would undercut the consent gate → hash
  before storing, store only the known customer keys, and store nothing at all on a
  denial.
- The debug log is the plugin's own record and is read by support → it records which
  identifiers were present, never their values, and never message text.
- Elementor and some Gravity add-ons can record a submission on a request that is not
  the visitor's → then no visitor cookie exists, the visitor identifier is omitted, and
  a hold cannot be keyed; that case reports `no_decision` rather than sending or
  dropping silently.
- Elementor's `_elementor_data` shape is internal and can change between major versions
  → the walk tolerates unknown node types and returns the forms it did recognise rather
  than failing, and the submission hook remains the source of truth for sending, so a
  shape change degrades the settings list without stopping events.
- A static value with no currency may be ignored by Meta on events it prices → accepted
  for this change; no currency control is added.
- Two parallel hold mechanisms and two payload assemblies increase the cost of the next
  envelope change → noted above as the price of leaving WooCommerce untouched.

## Migration Plan

No database migration and no new endpoint. The new option does not exist until the
settings page is saved, and its absence means off, so an upgrade changes nothing about
what a site sends. Rollback is deactivating the version or turning the master toggle
off; the stored mapping is inert either way and is preserved for a later re-enable.

The dashboard note telling WordPress sites that form events are controlled in the plugin
is copy in another repository and does not gate this change in either direction.

## Open Questions

- Elementor form identity is the post id plus the widget id. A widget duplicated onto
  another page becomes a second row with the same title. Whether those should be
  presented as one form or two can be decided after the first real use; it changes the
  settings page only.
- The identifier-name and form-title patterns cover English, French, German, Spanish,
  Italian and Dutch. The form-title words are fixed in `tasks.md` 2.6; the
  identifier-name words are assembled during implementation. Both lists are data,
  extendable without touching the detection order or the matching rule.
