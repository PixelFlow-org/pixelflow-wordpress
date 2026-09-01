## 1. Clear the baseline

- [x] 1.1 Bump `Tested up to:` in `readme.txt` from `7.0` to `7.1`
- [x] 1.2 Re-run Plugin Check locally against the plugin with the non-shipped paths
      excluded and confirm zero errors remain (the short-description warning is
      expected and stays)

## 2. Package and check the plugin in CI

- [x] 2.1 In `.github/workflows/ci.yml`, remove the `Production build` step from
      the `frontend` job
- [x] 2.2 Add a `Build and package the plugin` step to the `frontend` job that runs
      `./build_plugin.sh prod` and unpacks `build/pixelflow.zip` into `tmp/`, with
      `working-directory: .` to escape the job's `app/source` default, and the same
      `if: always() && steps.install.outcome == 'success'` guard the sibling steps use
- [x] 2.3 Add the `wordpress/plugin-check-action` step with
      `build-dir: ./tmp/pixelflow` and
      `ignore-codes: outdated_tested_upto_header`, pinned to a released version
- [x] 2.4 Raise the `frontend` job's `timeout-minutes` from 15 to 30
- [x] 2.5 Add a comment above the Plugin Check step recording why
      `outdated_tested_upto_header` is ignored — that it queries the live WordPress
      release feed and would otherwise turn `main` red with no commit

## 3. Track WordPress releases out of band

- [x] 3.1 Add `.wordpress-version-checker.json` at the repository root containing
      `{"channel": "stable"}`
- [x] 3.2 Add `.github/workflows/wordpress-version-check.yml` running
      `skaut/wordpress-version-checker` on a daily schedule, on push to `main`, and
      on `workflow_dispatch`, with `permissions: issues: write` and no `paths:`
      filter
- [x] 3.3 Add a comment in that workflow stating that it must never be marked a
      required status check, since it does not run on pull requests

## 4. Documentation

- [x] 4.1 In `.github/BRANCH_PROTECTION.md`, record that Plugin Check runs inside
      the `frontend` job and that the required check list is unchanged
- [x] 4.2 In `.github/BRANCH_PROTECTION.md`, document the local command that
      reproduces the check, and note that warnings annotate without blocking while
      errors block
- [x] 4.3 Note in `.github/BRANCH_PROTECTION.md` that the zip CI builds carries no
      `.env.production` values and must not be used as a release artifact

## 5. Verify on a real run

- [x] 5.1 Open a pull request and confirm the `frontend` job packages the plugin,
      that `wp-env` starts, and that `wp plugin activate pixelflow` succeeds in a
      WordPress installation without WooCommerce
- [x] 5.2 Confirm the run is green and that the short-description finding appears as
      a warning annotation, not an error
- [x] 5.3 Confirm `plugin-check-results.txt` is attached to the run as an artifact
- [x] 5.4 Trigger `wordpress-version-check.yml` via `workflow_dispatch` and confirm
      it reports no gap now that `Tested up to` is 7.1
- [x] 5.5 Confirm the aggregating `ci` job still reports and that merging is
      possible with no ruleset change
