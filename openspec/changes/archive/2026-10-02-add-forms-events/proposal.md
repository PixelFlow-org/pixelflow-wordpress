## Why

Lead and signup conversions on a WordPress site happen in form plugins, not in
WooCommerce. The plugin already sends WooCommerce events server-side with consent
gating, visitor `external_id`, bot filtering and `/blocked-events` reporting, but a
form submission — which arrives on the visitor's own request, with the same cookies,
IP and user agent — has no path into that machinery. The only alternatives today are
a dashboard submit trigger or a thank-you-URL trigger, both of which fire from the
browser script: they miss AJAX submissions that never navigate, they die with an ad
blocker, and they rarely recover a clean email or phone.

Source: PRD "WordPress form tracking" (draft, 2026-09-24), which records the product
decisions this change implements.

## What Changes

**One master toggle, off by default, and a per-form list**

- A new Forms section on the settings page with a master toggle `Track form
  submissions`, default off including on upgrade. Under it, one row per form that the
  installed form plugins actually have: on/off switch, standard Meta event dropdown,
  a mapped-identifier summary, and an expandable panel with per-identifier field
  mapping and an optional static value.
- The list is hidden while the master toggle is off, as the WooCommerce settings are,
  with every event and field choice preserved, so turning the toggle back on resumes the
  same setup.
- **Not breaking**: a site that never turns the toggle on sends nothing new. No
  WooCommerce event behaviour changes, including its hold queue.

**Server-side sending only**

- A successful, non-spam submission is dispatched from PHP to the existing `/event`
  endpoint, in the same payload shape WooCommerce events use, reusing the existing
  consent resolution, bot and prefetch classification, identity resolution, cookie
  and attribution enrichment, and debug logging.
- **New behaviour, not a reuse**: the excluded-roles setting gains a server-side gate
  for form events. Today `should_exclude_current_user()` is consulted only when the
  browser pixel and the WooCommerce flush script are enqueued (`pixelflow.php:270`,
  `pixelflow.php:320`); the server-side WooCommerce dispatcher never reads it, so an
  excluded role's WooCommerce events still send. Form events deliberately honour the
  setting server-side, which makes them stricter than WooCommerce events for the same
  role. The settings page states this next to the `Exclude Script for User Roles`
  control, shown only when WooCommerce tracking is on. Aligning the WooCommerce
  dispatcher itself is tracked as a separate change and is not part of this one.
- No second event is fired from the browser: `trackEvent` sends its own Conversions
  API event, so a browser fire on the same submission would double-send. There is no
  shared `event_id` between the plugin and the browser script.

**Confidence-based defaults instead of blanket sending**

- A form with a native email or phone field whose title is not search, login,
  password or comment is high confidence: it sends as soon as the master toggle is
  on. `CompleteRegistration` when the title reads newsletter, subscribe or signup or
  the form is email-only; `Lead` otherwise.
- A form whose identifier is only inferred from a plain text field is medium
  confidence: the row appears with the suggested event filled in and its switch off,
  and nothing sends until someone turns that form on.

**Adapters for six form plugins**

- Contact Form 7, Elementor Pro, Fluent Forms, Gravity Forms, Ninja Forms and WPForms,
  each a thin adapter that reports whether its plugin is active, lists forms and fields,
  and listens to that plugin's success hook. Elementor has no form registry, so its
  adapter enumerates forms by reading the `_elementor_data` widget tree.
- Five of the six are the form plugins the install-base sample ranks above isolated
  single sites, minus one that cannot be adapted: an embedded HubSpot form posts to
  HubSpot, so no submission reaches WordPress and no server-side hook exists. Ninja Forms
  is included by decision despite its share in the sample. See `plugins.md`.
- An action lets an unsupported form plugin enter the same dispatcher.

**A form hold queue independent of WooCommerce**

- A submission made while an opt-in banner is unanswered is held as a recipe of
  hashed identifiers and replayed on a grant with its original `eventTime`. The
  WooCommerce session queue is not reused: it accepts only `AddToCart` and
  `InitiateCheckout`, and its flush script loads only when WooCommerce tracking is on.

**Privacy boundary**

- Only hashed identifiers and the form title leave the server. Message bodies and
  free-text field values are never sent, stored in a hold recipe, or written to the
  debug log.

## Capabilities

### New Capabilities

- `form-events`: which form submissions the plugin turns into Meta events, what those
  events carry, how a form is enabled and its identifiers mapped, and how consent,
  automated traffic and repeat submissions gate the send.

### Modified Capabilities

<!-- None. `consent-resolution` is unchanged by this change: its requirements name the
     WooCommerce events, and `form-events` states the equivalent consent, hold and
     blocked-report behaviour for form events on its own. Form events resolve consent
     through the same helpers and attach the same consent block. -->

## Impact

- `includes/forms/` — new: six plugin adapters, the submission dispatcher, identifier
  detection and mapping, the `_elementor_data` form walk, the form hold queue and its
  flush route.
- `assets/js/` — a flush script for the form hold queue, loaded when form tracking is
  on, independent of the existing `held-events.js`.
- `pixelflow.php` — settings registration and sanitizing for the master toggle and the
  per-form mapping option; enqueueing the new flush script.
- `app/source/src/features/` — new Forms feature in the React settings app: master
  toggle, form list, expandable mapping panel. One line added to
  `settings/components/AdvancedSettings.tsx` disclosing that excluded roles are not
  applied to WooCommerce event sending.
- New `admin-ajax` routes for the form hold flush, and an `admin-ajax` read route for
  the settings app to list forms and their fields; the plugin has no REST routes and
  this change adds none.
- New plugin option holding the per-form mapping record, keyed by form plugin and form
  id; no database migration.
- `tests/` — PHP unit coverage for the dispatcher, detection, hashing, dedupe, the
  adapters and the `_elementor_data` walk, and the hold queue. `app/source/src/` — component tests for the Forms UI. `e2e/` — admin
  coverage of the settings surface.
- Version bump from 1.1.20 to 1.2.0 and changelog entries in `pixelflow.php`,
  `readme.txt`, `README.md`; the project `CLAUDE.md` versioning rule updated so a
  feature addition bumps the second segment; the `README.md` privacy section extended
  with what a form event sends; `docs/test-scenarios.html` regenerated after the full
  run.
- `e2e/live/README.md` — the six form plugins and the form fixture matrix recorded as
  standing prerequisites of the live suite, beside the existing product and account
  fixtures. Gravity Forms needs a licence, which is an external dependency of this
  change.
- Out of this repo, not part of this change: a note on WordPress sites in the
  dashboard saying that form events are controlled in the plugin.
- Deferred to a separate change: an `excluded_role` blocked-event reason. It needs the
  backend to accept the reason and `consent-resolution` to list it; until then an
  excluded-role form submission sends neither an event nor a blocked report.
