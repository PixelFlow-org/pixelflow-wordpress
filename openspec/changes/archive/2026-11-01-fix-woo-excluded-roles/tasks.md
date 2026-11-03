## 1. Setup

- [x] 1.1 Create the branch `fix/woo-excluded-roles` from `main`; the fix does not depend on the unreleased build work on `feat/separate-dev-and-prod-builds`

## 2. Test bootstrap

- [x] 2.1 Move `is_user_logged_in()` and `wp_get_current_user()` from `tests/bootstrap-forms.php` to `tests/bootstrap-wp-stubs.php`, defaulting to signed out
- [x] 2.2 Run the PHP suite; every test passes before the gate exists

## 3. Dispatcher gate (test first)

- [x] 3.1 Add `tests/test-woo-excluded-roles.php`, failing first, covering: excluded role skips AddToCart, InitiateCheckout and Purchase with `allow_live` true, with no `/event` or `/blocked-events` POST and nothing held under an unanswered banner
- [x] 3.2 Add cases: another role sends; a guest sends; `allow_live` false sends for an excluded user; replay under `flushing_held` sends for an excluded user
- [x] 3.3 Add cases through `pf_purchase_hook()`: a skip leaves `_pf_purchase_sent` unset and releases the claim; a later non-buyer request for the same order sends the Purchase
- [x] 3.4 Add a case: the debug log records `USER ROLE IS EXCLUDED FROM TRACKING` when debug logging is on
- [x] 3.5 Add the gate to `post_event()` as in design Decision 1; `php tests/test-woo-excluded-roles.php` passes
- [x] 3.6 Run the whole PHP suite; every test passes

## 4. Settings page and docs

- [x] 4.1 Update `AdvancedSettings.test.tsx` to the new title and both notes, keeping the checks that hide the Purchase note when WooCommerce tracking is off or WooCommerce is inactive; add a check for the hand-added script note
- [x] 4.2 Update the title, description and notes in `AdvancedSettings.tsx` as in design Decision 5; the frontend suite passes
- [x] 4.3 Update the FAQ answer in `readme.txt` and `README.md` as in design Decision 5
- [x] 4.4 Check that no user-facing text outside `openspec/changes/archive/` still uses `Exclude Script for User Roles` or says WooCommerce events ignore the setting

## 5. Live scenario

- [x] 5.1 Add `e2e/live/tests/excluded-role.spec.ts` with the role excluded for a signed-in administrator and restored afterwards: no plugin scripts on storefront pages, and no AddToCart or InitiateCheckout sent, with the skip in the debug log
- [x] 5.2 Extend the scenario: that administrator's own checkout sends no Purchase, and a status change from a request that is not theirs then sends it
- [x] 5.3 Determine whether the test site's cart and checkout use the Store API; if any path sends because the user is unknown, report it before continuing (design Risks)

## 6. Release

- [x] 6.1 Bump to 1.2.3: `pixelflow.php` header and `PIXELFLOW_VERSION`, `readme.txt` `Stable tag`
- [x] 6.2 Add the one-line English changelog entry to `readme.txt` and `README.md`, stating that excluded roles now also stop WooCommerce events and that event counts on those sites may drop

## 7. Full run and report

- [x] 7.1 Run the PHP suite, frontend suite, admin E2E (`e2e/tests`) and live matrix (`e2e/live`); every suite passes
- [x] 7.2 Add `ROLE-*` rows to `docs/test-scenarios.html` for the scenarios above, including the accepted Purchase limit
- [x] 7.3 Update the report's version, header, "Last full run" line and counts, then publish it with `e2e/live/scripts/publish-report.sh`
- [x] 7.4 Check that no commit, spec or report text contains a client name, domain, IP address or absolute event count
