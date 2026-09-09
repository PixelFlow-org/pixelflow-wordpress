## Why

The WordPress Plugin Check job on pull request #21 reports six warnings. Five of
them are in plugin code, and all five are false positives in substance: the three
`InputNotSanitized` findings sit on cookie reads whose value is sanitized a few
lines further down, and the two `NonceVerification.Recommended` findings sit on a
read-only routing test whose sibling line already carries a `phpcs:ignore` with
the right rationale. Warnings do not fail CI today, but they are
the first thing a wordpress.org reviewer reads, and a report that is noisy by
default is a report nobody scans for the finding that matters.

## What Changes

- `includes/helpers.php:582` — sanitize `$_COOKIE['_pf_uid']` in the statement
  that reads it, rather than six lines later.
- `includes/consent.php:324` — same for `$_COOKIE[PIXELFLOW_CONSENT_SOURCE_COOKIE_NAME]`.
- `includes/consent.php:406` — annotate `$_COOKIE[PIXELFLOW_NO_CONSENT_DECISION_COOKIE_NAME]`
  with a `phpcs:ignore` rather than sanitize it: this value is compared byte-exact
  to a literal, so sanitizing it would move behaviour.
- `includes/woo/hooks/trait-held-woo-events.php:73` — add a second
  `phpcs:ignore WordPress.Security.NonceVerification.Recommended` onto line 73,
  which also reads `$_GET['wc-ajax']`; the existing one on line 74 stays.
- No behaviour change is intended, and no test is expected to change its verdict.

Out of scope, by the requester's explicit instruction:

- The sixth warning, `readme_parser_warnings_trimmed_short_description` in
  `readme.txt`. It stays as it is.
- Making Plugin Check warnings fail CI. The readme warning would keep such a gate
  red, so a gate is not part of this change.
- The plugin version stays at 1.1.17.

## Capabilities

### New Capabilities

None. This change alters no requirement — it makes the existing behaviour legible
to a static analyser. `.openspec.yaml` sets `skip_specs: true` accordingly.

### Modified Capabilities

None.

## Impact

- Code: `includes/helpers.php`, `includes/consent.php`,
  `includes/woo/hooks/trait-held-woo-events.php` — four lines in total.
- CI: the Plugin Check step in `.github/workflows/ci.yml` should report one
  warning (the readme one) instead of six. The workflow file itself is untouched.
- Tests: `tests/test-consent.php`, `tests/test-request-owns-order.php` and the
  live suite under `e2e/live/` all exercise these code paths and must stay green
  without being edited.
