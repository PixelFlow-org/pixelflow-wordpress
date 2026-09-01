## Why

The pipeline verifies the frontend bundle and the PHP sources, but nothing checks
the plugin *as WordPress sees it*: readme metadata, plugin headers, WordPress
coding requirements, or whether the packaged plugin activates at all. Those are
exactly the rules WordPress.org enforces at review time, so today the first
feedback on a violation arrives from the WordPress.org reviewer, after release.

The obstacle is that the repository is not the plugin. `build_plugin.sh` copies a
subset of the tree into a zip; the rest — `app/source/`, `e2e/`, `tests/`,
`openspec/`, `.github/`, dotfiles — never ships. Running WordPress's own checker
over the repository root reports 50 errors and 59 warnings across 53 files, of
which exactly one error and one warning are real. The checker has to see the
built artifact, not the working tree.

## What Changes

- The `frontend` job builds and packages the plugin with `build_plugin.sh`, then
  runs `wordpress/plugin-check-action` against the unpacked release zip. The
  separate `pnpm build` step is removed: `build_plugin.sh` already runs the
  production build, so keeping both would bundle twice.
- Plugin Check errors block the pull request; warnings appear as annotations on
  the diff without blocking. This is the action's default behaviour and needs no
  configuration.
- The `outdated_tested_upto_header` check is ignored in the pipeline. It compares
  `readme.txt` against the live WordPress release feed, so a WordPress release
  would turn `main` red with no commit and block every open pull request until
  someone bumps the readme.
- That concern moves to a new scheduled workflow running
  `skaut/wordpress-version-checker`, which opens an issue instead of blocking a
  merge. It tracks the `stable` channel, configured through
  `.wordpress-version-checker.json`.
- `readme.txt` declares `Tested up to: 7.1`, clearing the one real error in the
  current baseline.

Not in scope: fixing the `readme_parser_warnings_trimmed_short_description`
warning (the short description is 155 characters against a 150 limit), and
publishing the built zip as a downloadable release artifact.

## Capabilities

### New Capabilities
- `wordpress-release-tracking`: scheduled detection of a WordPress release that
  the plugin has not yet declared compatibility with, reported as a repository
  issue rather than as a merge blocker.

### Modified Capabilities
- `ci-pipeline`: adds a requirement that the pipeline verify the *packaged*
  plugin against WordPress's own plugin checker, and constrains which findings
  may block a merge. Also narrows the existing frontend-build requirement, which
  currently implies a dedicated build step that this change folds into packaging.

## Impact

- `.github/workflows/ci.yml` — the `frontend` job gains packaging and Plugin
  Check steps, loses its standalone build step, and needs a longer timeout
  because the action starts a WordPress environment in Docker.
- `.github/workflows/wordpress-version-check.yml` — new, and the only workflow in
  the repository that needs `issues: write`.
- `.wordpress-version-checker.json` — new, at the repository root.
- `readme.txt` — `Tested up to` bumped to 7.1.
- `.github/BRANCH_PROTECTION.md` — documents the new check and the local command
  that reproduces it.
- `build_plugin.sh` — unchanged. It stays the single source of truth for what the
  plugin contains, which is what makes the check faithful.
