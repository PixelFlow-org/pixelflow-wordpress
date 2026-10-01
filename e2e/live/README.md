# Live WooCommerce event verification

Runs the whole WooCommerce event matrix against the **live test site**, using the
plugin's own debug log as the oracle. Real events are sent to a test Pixelflow
account and test orders accumulate on the site.

This suite is deliberately separate from `../playwright.config.ts` (the admin UI
tests) so it can never run by accident.

## Prerequisites

- `cp .env.example .env`, then fill in every value. The site's address, SSH host, key path and
  WordPress root have no defaults in the repository — the suite refuses to start without them
  rather than pointing somewhere unintended:

  | Variable | What it is |
  | --- | --- |
  | `PF_BASE_URL` | the storefront's address |
  | `PF_SSH_HOST` | `user@host` for the deploy account |
  | `PF_SSH_KEY` | path to that account's private key |
  | `PF_WP_ROOT` | the WordPress root on the server |

  The WordPress passwords (`PF_ADMIN_PASS`, `PF_CUSTOMER_PASS`) go in the same file.
- Site fixtures in place: the `pf-fixtures` product category, the `discount`
  coupon, and the `pfcustomer` account with a billing address.
- The six supported form plugins installed and active: Contact Form 7, WPForms Lite,
  Fluent Forms, Ninja Forms (free editions), Gravity Forms and Elementor with Elementor Pro.
  Gravity Forms and Elementor Pro are paid: Elementor Pro needs an active licence for its
  form widget, and both are external dependencies of the form specs. Install them over
  WP-CLI and give the `www-data` group write access to their directories, as for any plugin
  the uploader should be able to update later.
- The form fixture matrix, recorded in the `pf_form_fixtures` option as
  title → `{source, form_id, shape, page_id, url}`. Each form sits alone on a page of its
  own, labels its fields `Name`, `Email`, `Phone`, `Your name`, `Message`, `Query` or
  `Phone number`, and labels its button `Send`, so `pages/form-page.ts` drives every plugin
  the same way. Titles are `PF-<code> <shape>`, with codes `CF7`, `WPF`, `GF`, `FF`, `NF`
  and `EL`, and these shapes:

  | Shape | Fields | Read as |
  | --- | --- | --- |
  | `email` | Name (text), Email | high confidence, `Lead` |
  | `phone` | Name (text), Phone | high confidence, `Lead` |
  | `email-only` | Email | high confidence, `CompleteRegistration` |
  | `combined name` | Your name (text), Email | high confidence, `Lead`, name split |
  | `message` | Email, Message (textarea) | high confidence, `Lead` |
  | `search` | Email, Query — title matches the deny-list | medium confidence |
  | `inferred` | Phone number (plain text) | medium confidence |

  WPForms Lite and the free Fluent Forms have no native phone field, so `PF-WPF phone` and
  `PF-FF phone` do not exist. Elementor also has `PF-EL popup` (shape `message`), a form
  inside an `elementor_library` popup, which exercises the `_elementor_data` walk on a post
  type that is not a page. Like the product fixtures, the matrix was seeded once with
  `wp eval-file` and the seed is not part of the repository.

## Running

```sh
./scripts/run.sh              # build, deploy, smoke, full matrix
./scripts/run.sh tests/purchase.spec.ts   # extra args go to Playwright
```

The run stops at the first failing scenario. Artifacts land in a temp directory
whose path is printed at the start and end of the run.

### EU egress

PixelFlow picks the consent regime from the visitor's IP address, not from the site's
consent banner: outside the EU it answers `opt_out`, and the tracking script then clears
`_pf_no_consent_decision` and sends events before any answer. The consent scenarios only
mean something under `opt_in`, so `run.sh` sends the browser through a SOCKS tunnel to
`PF_SSH_HOST` (`ssh -D`, local port `PF_EU_PROXY_PORT`, default 1089) and refuses to start
unless the tunnel exits in an EEA country. On a machine that already has an EU address,
`PF_EU_EGRESS=off` skips the tunnel.
