# Which form plugins to support first

Background for the adapter list in `proposal.md`. Figures are shares of the sample, not
counts: the raw numbers identify the install base and stay out of the repository.

## Method

- Source: a sample of several dozen WordPress sites drawn from sites where the plugin is
  installed. Local addresses, placeholder domains, temporary sandboxes and our own sites
  were excluded before scanning; a duplicate address was counted once.
- Each site's home page was fetched, plus a deeper page when one was listed, because a
  landing page often carries a form the home page does not.
- A site counted as WordPress when its HTML referenced the standard WordPress paths, the
  REST discovery link or a generator tag.
- Plugins were identified from the slug in asset URLs, and form plugins additionally from
  form markup and inline configuration.
- About one site in six could not be inspected: unreachable, or behind a bot challenge.

## Limitations

- Only plugins that load front-end assets on the fetched pages are visible, so
  back-end-only plugins are undercounted — including, on some installs, this plugin.
- A site may have a form plugin installed and not use it on the pages fetched, and a form
  on an inner page is missed entirely.
- The sample is a snapshot and skews toward sites reachable without a challenge.

## Form plugins, by share of the WordPress sites sampled

| Form plugin | Share |
| --- | --- |
| Contact Form 7 | roughly one site in five |
| Elementor Pro Forms | about one in ten |
| Fluent Forms | about one in twenty |
| Gravity Forms | about one in twenty |
| WPForms | a few percent |
| HubSpot embed | a few percent |
| Bricks, JetFormBuilder, Ninja Forms, Spectra, RegistrationMagic, Mailchimp and MailerLite sign-up forms | isolated sites, one each |

Five of the six adapters this change ships are every form plugin in the sample that
appears on more than an isolated site and can be adapted at all: Contact Form 7,
Elementor Pro Forms, Fluent Forms, Gravity Forms and WPForms. The sixth, Ninja Forms, is
included by decision rather than by share — see the note below.

Two groups are left out, for different reasons.

- **The HubSpot embed**, despite appearing on more than one site, cannot be adapted. An
  embedded HubSpot form posts to HubSpot from the browser, so no submission reaches
  WordPress and there is no server-side hook to hang an adapter on. Such a form is a
  Visual Tagger case.
- **Bricks Form, JetFormBuilder, Spectra, RegistrationMagic and the Mailchimp and
  MailerLite sign-up forms** each appear on a single site. An adapter per plugin at that
  share is not worth its maintenance, and the `pixelflow_track_form` action lets any of
  them be wired up from a site's own code in the meantime.

**Ninja Forms is an explicit exception to that rule.** By share it belongs in the group
above — it was found on a single site in the sample. It ships an adapter anyway, by
decision, not because the sample supports it. The sample's own limitations are the
reason the share is weak evidence either way: only plugins loading front-end assets on
the pages fetched are visible, a form on an inner page is missed entirely, and about one
site in six could not be inspected at all. The cost is one more adapter to maintain and
one more plugin to keep installed on the test site.

Elementor itself is present on about half the sites sampled and Elementor Pro on roughly
a third, but Pro form markup was found on only a minority of those. The scan sees a form
only where it renders on a fetched page, so this undercounts: a Pro site whose form sits
on an inner page reads as having none. The adapter therefore does not assume every Pro
site has a form, and does not infer their absence either — it reads the
`_elementor_data` widget tree and reports whatever forms are actually there.

## Context that affects the design

- WooCommerce is on somewhat under half the sites sampled, so a form-only site is the
  common case, not the exception. The form hold queue therefore cannot depend on a
  WooCommerce session.
- A competing pixel or tag-management plugin is present on a noticeable minority of
  sites — several different ones, each on a handful. A site can therefore already be
  sending its own form conversions, which is the practical reason for the overlap
  warning in the form row.
- A consent platform is present on a noticeable minority as well, spread across several
  vendors, so the unanswered-banner path is a real scenario rather than a theoretical one.
