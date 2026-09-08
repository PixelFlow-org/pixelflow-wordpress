## 1. Sanitize the cookie reads at their read site

- [x] 1.1 In `includes/helpers.php`, wrap the `$_COOKIE['_pf_uid']` read on line
      582 so the statement reads
      `$uid = sanitize_text_field( wp_unslash( $_COOKIE['_pf_uid'] ) );`.
      Leave the `substr( sanitize_text_field( $uid ), 0, 64 )` below it alone —
      it still serves the `$uid_override` path.
- [x] 1.2 In `includes/consent.php`, do the same for
      `$_COOKIE[PIXELFLOW_CONSENT_SOURCE_COOKIE_NAME]` on line 324, leaving the
      later `sanitize_text_field($raw)` in place for the `$raw_override` branch.
- [x] 1.3 In `includes/consent.php`, annotate the
      `$_COOKIE[PIXELFLOW_NO_CONSENT_DECISION_COOKIE_NAME]` read on line 406 with
      `// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
      -- compared byte-exact to a literal below` instead of sanitizing it. This one
      has no downstream sanitizer, so sanitizing here would move behaviour — see
      design.md, second decision.
- [x] 1.4 Confirm no other statement in the three files reads a superglobal
      without a sanitizer in the same statement, so the fix is not partial.

## 2. Put the nonce-verification annotation on the line it excuses

- [x] 2.1 In `includes/woo/hooks/trait-held-woo-events.php`, add a second
      `// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- routing
      only, no state is changed on this branch` onto line 73, the line holding
      `isset($_GET['wc-ajax']) && is_string($_GET['wc-ajax'])`, and keep the
      existing one on line 74. PHPCS scopes an inline ignore to its own line, and
      line 74's `sanitize_key(wp_unslash($_GET['wc-ajax']))` is itself a flagged
      read, so both lines need their own annotation.

## 3. Verify

- [x] 3.1 Run the standalone PHP suite the way CI does — `for f in tests/test-*.php;
      do php "$f" || exit 1; done` from the plugin root — and confirm every file
      exits 0. No test file may be edited to make this pass.
- [x] 3.2 Run `npm run test` in `app/source/` and confirm it stays green (it does
      not touch these files, so a failure here means something unrelated broke).
- [ ] 3.3 Push the branch and read the Plugin Check comment on PR #21 once the
      run finishes. Expected: one warning total —
      `readme_parser_warnings_trimmed_short_description` in `readme.txt` — and
      zero in `includes/`. Any surviving plugin-code warning means the fix missed.
- [ ] 3.4 Confirm the rest of CI is green: `frontend`, `php (7.4)`, `php (8.1)`,
      `php (8.3)`, `ci`.

## 4. Out of scope — do not do these

- [x] 4.1 Confirm the diff touches exactly four lines across three files under
      `includes/`, and that `readme.txt`, `.github/workflows/ci.yml`, the plugin
      version (1.1.17 in `pixelflow.php` and `readme.txt`) and every file under
      `tests/` and `e2e/` are unchanged.
