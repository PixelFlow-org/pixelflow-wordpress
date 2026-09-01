## Context

See proposal.md — Why.

Three properties of the existing setup shape the approach:

- `build_plugin.sh` is the only description of what the plugin contains. It runs
  the production frontend build, copies a fixed set of paths into
  `build/staging/pixelflow/`, zips it, and deletes the staging directory. The zip's
  top-level directory is `pixelflow`, which matters because Plugin Check derives
  the plugin slug from the directory name it is pointed at.
- `ci.yml` has a single required status check, the aggregating `ci` job. Adding
  verification to an existing job therefore needs no ruleset change; adding a new
  job needs `needs:` and the aggregator's result check updated.
- The `frontend` job already runs the production build (`pnpm build`) and already
  holds the private-registry credential. Its `defaults.run.working-directory` is
  `app/source`.

The baseline was measured rather than estimated, against the local WordPress
install with `plugin-check` 's WP-CLI command:

| Scope checked | Errors | Warnings |
|---|---|---|
| Repository working tree | 50 | 59 |
| Approximation of the release zip | 2 | 2 |
| Release zip with `outdated_tested_upto_header` ignored, `Tested up to` bumped | 0 | 1 |

The remaining warning is `readme_parser_warnings_trimmed_short_description` — the
short description is 155 characters against a 150 limit. No finding appears
against `pixelflow.php`, `includes/**`, or `app/dist`.

## Goals / Non-Goals

**Goals:**

- One production frontend build per run, whose output is the thing that gets
  packaged and checked.
- No second list of shipped files anywhere in the repository.
- A red pipeline always means something in this repository is wrong.

**Non-Goals:**

- Checking a functionally configured build. `.env.production` is untracked, so the
  zip CI assembles carries empty environment values. That is sufficient for static
  and activation checks and is why this zip is not offered as a release artifact.
- Running Plugin Check against multiple WordPress versions. One (`latest`) is
  enough for the rules being enforced.
- Restricting the check to particular categories. The baseline is clean, so the
  default full set costs nothing.

## Decisions

### Point the checker at the unpacked release zip

`wordpress/plugin-check-action` takes a `build-dir`. The step sequence becomes
`./build_plugin.sh prod` → `unzip build/pixelflow.zip -d tmp/` →
`build-dir: ./tmp/pixelflow`.

*Alternative — `exclude-directories` / `exclude-files` against the repository
root.* Rejected: it introduces a second inventory of what ships, maintained by
hand, which drifts from `build_plugin.sh` on the first change to either. The
measured 50 errors on the working tree are what that drift looks like when it is
not maintained.

*Alternative — `.distignore` with `wp dist-archive`.* This is the WordPress-native
packaging convention and would give the same single source of truth. Rejected as
scope: it means rewriting a working release process for a checker, and the
existing script already yields a correctly named directory.

### Let `build_plugin.sh` perform the frontend build, and delete the `pnpm build` step

The script runs `npm run build` internally. Keeping the standalone step as well
would bundle twice per run.

*Alternative — keep `pnpm build` and teach the script to skip building.* Rejected:
it requires modifying `build_plugin.sh`, and the whole value of pointing at the
script's output is that CI exercises the unmodified release path. The cost is that
a bundler failure and a packaging failure now surface in the same step; the
bundler's own output still identifies which.

### Extend the `frontend` job rather than add a job

Packaging consumes the bundle the job just produced. A separate job would mean
uploading and downloading `app/dist` as an artifact, and a script change to stop
it rebuilding — plumbing that buys only a nicer job name.

The job keeps the name `frontend`, so the documented status-check names in
`.github/BRANCH_PROTECTION.md` and the aggregator's `needs:` stay valid. The
trade-off is cosmetic: a Plugin Check failure reads as "frontend failed" until the
log is opened.

Its `timeout-minutes` rises from 15 to 30 — the action starts WordPress in Docker
via `wp-env`, retrying up to three times with a 10-minute ceiling per attempt. The
packaging step needs `working-directory: .` to escape the job's `app/source`
default.

### Rely on the action's default error/warning behaviour

The action's result processor sets a failing exit code on `ERROR` findings only,
and emits every finding as a GitHub annotation — `::error` or `::warning`
accordingly. It also uploads the full JSON report as a run artifact
unconditionally. So "errors block, warnings show" needs no input at all.

`ignore-warnings: true` was considered and rejected: it passes `--ignore-warnings`
to the checker, which removes warnings from the output entirely rather than
merely un-gating them.

**Revised after the first green run.** "Warnings show" turned out to be weaker
than intended. Annotations render on the run summary and on the diff, but the
pull request's own checks box shows only pass/fail — a run with warnings is
indistinguishable from a clean one at the place the reviewer actually looks, and
a job cannot report a neutral conclusion to change that. The only thing that
surfaces on the pull request page itself is a comment.

The action already attempts to post one; without permission it was failing on
every run with `Resource not accessible by integration`, which was itself
appearing as a warning. So the `frontend` job now carries
`pull-requests: write`, turning a recurring failure into the visibility the
check was supposed to provide. It is set per-job, not per-workflow: job-level
permissions replace the workflow-level block rather than extending it, so the
job restates `contents: read` and `packages: read`, and no other job gains write
access to pull requests.

### Ignore `outdated_tested_upto_header` in the pipeline, and track it on a schedule

That check queries the live WordPress release feed. As a blocking check it makes
merge availability a function of WordPress's release calendar: a release turns
`main` red with no commit, and every open pull request stalls until the readme is
bumped. `ignore-codes: outdated_tested_upto_header` removes it; verified locally
that this clears the error and leaves the rest of the run intact.

The concern moves to `skaut/wordpress-version-checker` in its own workflow, which
opens an issue instead. It reads `readme.txt` from the repository root, which the
default detection already finds, so `.wordpress-version-checker.json` carries only
`{"channel": "stable"}` — the action defaults to `rc`, and declaring compatibility
with an unreleased version would be a false claim.

That workflow is the only one in the repository needing `issues: write`. Keeping
it separate avoids widening `ci.yml`'s permissions, which is also where the
registry token is handled.

*Alternative — pin `wp-version` to a fixed WordPress version.* Rejected: it would
freeze every other Plugin Check rule at that version too, so new WordPress
requirements would go unnoticed, and the pin would still need manual bumping.

### Bump `Tested up to` to 7.1 in this change

The current value of 7.0 is one release behind, so the version checker would open
an issue on its first run for a condition already known. Fixing it here starts the
change with a clean baseline. The short-description warning is deliberately left:
it does not block, and the text is marketing copy that is not this change's to
rewrite.

## Risks / Trade-offs

- **The plugin may fail to activate in `wp-env`, which has no WooCommerce.** The
  action runs `wp plugin activate pixelflow` before checking; a fatal there fails
  the whole run. The WooCommerce integration is optional by design and the plugin
  activates cleanly locally, but locally WooCommerce is present, so this is
  unverified until the first CI run. → The first run is the verification. If it
  fails, the fix belongs in the plugin's guarding of the optional integration, not
  in the pipeline.
- **The `frontend` job's wall time grows by roughly 3–5 minutes.** → Accepted; it
  runs in parallel with `php`, and the timeout is raised to absorb the Docker
  start and its retries.
- **A bundler error and a packaging error now share one step.** → Accepted
  deliberately, as the price of exercising the unmodified release script.
- **`build_plugin.sh` warns and continues when `.env.production` is absent.** A
  future change that makes it fail instead would break CI. → Documented as a
  non-goal above; the zip CI builds is for checking, never for shipping.
- **Plugin Check's rules track WordPress and can add findings without a commit
  here.** Ignoring `outdated_tested_upto_header` removes the one rule known to do
  this on a fixed schedule, but not the possibility in general. → Accepted: unlike
  the release-calendar coupling, a genuinely new rule is real feedback worth
  receiving.
