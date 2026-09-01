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

Add all four. Search for them by name in the "Add checks" box — they appear only
after a run has reported them at least once.

- `frontend`
- `php (7.4)`
- `php (8.1)`
- `php (8.3)`

> Read these off a completed run, not off the YAML. A matrix expands one job into
> one check per matrix value, so `php` alone is **not** a check name and selecting
> it would block every merge on a check that never reports.
>
> Adding or removing a PHP version from the matrix changes this list. Update the
> ruleset in the same pull request that changes the matrix, or merges stop working.

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
