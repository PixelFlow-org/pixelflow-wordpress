# Branch protection for `main`

The CI workflow (`.github/workflows/ci.yml`) only reports results. What makes it
unavoidable is the ruleset below, applied by hand in the GitHub UI by someone with
admin on the repository.

Apply it **after** the pull request that introduced the workflow has merged with a
green run — the check names below have to come from a run that actually happened.

## The ruleset

Repository → **Settings** → **Rules** → **Rulesets** → **New branch ruleset**.

| Setting | Value |
|---|---|
| Ruleset name | `main protection` |
| Enforcement status | Active |
| Bypass list | **empty — no roles, not even Organization admin / Repository admin** |
| Target branches | Include default branch (`main`) |
| Restrict deletions | on |
| Block force pushes | on |
| Require a pull request before merging | on |
| — Required approvals | **0** |
| — Dismiss stale approvals | off |
| Require status checks to pass | on |
| — Require branches to be up to date before merging | on |
| — Required checks | see below |

### Why zero required approvals

The repository has a single maintainer, and GitHub does not allow anyone to approve
their own pull request. Requiring even one approval would make every pull request
permanently unmergeable. The gate here is the status checks, not a second person.

### Required status checks

Add exactly one:

- `ci`

That is deliberate. `ci` is an aggregating job: it waits for `frontend` and the
whole `php` matrix and fails unless every one of them succeeded. The individual
results stay visible on the pull request — you still see which job broke — but the
ruleset only has to name this one.

Why not name the individual checks: a matrix expands one job into one check per
matrix value (`php (7.4)`, `php (8.1)`, `php (8.3)`), so every change to the PHP
matrix would silently invalidate the required-checks list here. Requiring `ci`
alone is immune to that.

Verified on the first green run (2026-09-01): `frontend` 26s, `php (7.4)` 21s,
`php (8.1)` 15s, `php (8.3)` 7s. The pull-request page renders check names with
the workflow in front (`CI / ci`); the ruleset's picker lists the bare job name
(`ci`). Select whatever the picker offers — the name has to match what the run
reports, not what this file predicts.

> If `ci` is ever renamed or removed from the workflow, update this ruleset in the
> same pull request. A required check that no longer reports blocks every merge.

### What `frontend` covers

`frontend` is not only the admin bundle. It also packages the plugin with
`build_plugin.sh` and runs WordPress's own Plugin Check against the unpacked
`build/pixelflow.zip`. **The required-checks list does not change for that** — the
job keeps its name, so `ci` still covers it.

The cost of keeping the name is cosmetic: a Plugin Check failure reads as
`frontend` failing until you open the log. That was preferred over renaming the
job, which would invalidate the required-checks list documented above.

Plugin Check errors fail the job; warnings are emitted as annotations on the diff
and do not. The complete report is attached to every run as the
`plugin-check-results` artifact, whatever the outcome.

> The zip that CI builds is for checking only. `.env.production` is untracked, so
> the build runs with empty environment values and the resulting plugin is not
> functionally configured. **Never publish a CI-built zip as a release artifact.**
> Releases are built locally, where `.env.production` exists.

## The workflow that must NOT be required

`.github/workflows/wordpress-version-check.yml` opens an issue when WordPress
ships a release the plugin has not declared compatibility with. It runs on a daily
schedule, on pushes to `main`, and on demand — **never on pull requests**.

Do not add it to the required-checks list. A required check that never reports on
a pull request leaves that pull request permanently unmergeable.

It exists so that a WordPress release cannot block a merge. The matching Plugin
Check rule, `outdated_tested_upto_header`, is ignored in `ci.yml` for the same
reason: it compares `readme.txt` against the live WordPress release feed, so as a
gating check it would turn `main` red with no commit in this repository.

It is also the only workflow here that needs `issues: write`, which is why it is a
separate file rather than extra permissions on `ci.yml`.

## Emergency: releasing when CI itself is broken

With an empty bypass list, nobody can push to `main` directly — including the
maintainer. If the pipeline is broken in a way that no pull request can get past,
there is no back door, by design. Lift the protection deliberately instead:

1. Settings → Rules → Rulesets → `main protection`.
2. Set **Enforcement status** to `Disabled`. (Do not delete the ruleset — recreating
   it means re-selecting the four check names.)
3. Push or merge what is needed.
4. Set the enforcement status back to `Active`. **Same day.**

## Secrets this pipeline needs

| Secret | Scope | Used by |
|---|---|---|
| `GH_PACKAGES_TOKEN` | classic PAT, `read:packages` only | `frontend` job, to install `@pixelflow-org/*` from `npm.pkg.github.com` |

The built-in `GITHUB_TOKEN` is not used for the registry: it is scoped to this
repository, while the packages live elsewhere in the `PixelFlow-org` organisation.
A classic PAT with `read:packages` was verified to work on the first run — the
`frontend` job installed all `@pixelflow-org/*` packages in 26 seconds.

**Expiry is the failure mode to watch.** A PAT that expires takes the whole
`frontend` job down, and — because the install failure surfaces as a 404 — it reads
like a missing package rather than an expired credential.

- Created: 2026-09-01
- Expires: 2027-09-01 _(update both dates whenever the token is rotated)_

To rotate: generate a new classic PAT with `read:packages`, then Settings → Secrets
and variables → Actions → `GH_PACKAGES_TOKEN` → **Update secret**. No workflow change
is needed.

## Verifying the protection works

1. `git push origin main` from a local clone → must be **rejected**.
2. Open a pull request containing a deliberate lint error (an unused variable will
   do) → the `frontend` check must fail and the merge button must be disabled.
3. Walk the disable procedure above and confirm it matches what the UI actually
   shows, then re-enable.
