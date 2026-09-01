## Context

See `proposal.md` — Why and Current State. The baseline there was re-measured on
`main` on 2026-09-01, after `task/T-002-debug-log-refresh-button` merged; it
supersedes the branch-based baseline of the first draft.

**Dependencies are private.** `@pixelflow-org/plugin-{core,ui,features}` resolve
from `npm.pkg.github.com`. Today that works only because credentials sit in the
developer's global `~/.npmrc`; nothing in the repository carries them and no
`.npmrc` is tracked. Without an authenticated install, CI cannot run one check.

**Node is unconstrained.** `app/source/package.json` declares no `engines`, and
there is no `.nvmrc`. Installed `eslint@10.1.0` requires `^20.19 || ^22.13 || >=24`
and `vite@8.0.3` requires `^20.19 || >=22.12`. The developer machine runs v22.18.0,
which satisfies both; a hosted runner's default is not guaranteed to.

## Goals / Non-Goals

**Goals:**

- Get one green, blocking pipeline in place quickly.
- Keep every check reproducible with a single local command, so a red check is
  debuggable without reading YAML.
- Keep the two concerns — fixing the code and adding the pipeline — in separate
  diffs.

**Non-Goals:**

- Any check whose cost is unknown before it is first run (WPCS specifically).
- Rewriting or extending the existing test suites. The pipeline runs what exists.
- Any change to how the plugin is released to WordPress.org.

## Decisions

### 0. Division of labour: files and local commits are mine, git remote is the maintainer's

Claude writes and edits files and creates local commits. **The maintainer** pushes
branches, opens both pull requests, merges them, and applies the ruleset. Run logs
from GitHub Actions are pasted back into the session for the next iteration.

*Consequence:* tasks that read a live run's output (the status-check names, whether
the token worked) are maintainer actions with a hand-back step, not Claude actions.
`gh` is not installed on this machine and this change does not install it.

### 1. Two pull requests, in this order

PR 1 (code fixes, tooling config, tracking changes) → PR 2 (workflow + docs) →
manual protection.

*Why:* PR 1 contains the only judgement-heavy work (three ESLint warnings, one of
which changes runtime behaviour, see Decision 6). Keeping it out of PR 2 means the
pipeline is not held hostage by that discussion, and each diff reads as one thing.

*Why fixes first:* if the workflow landed first, its own PR would be red on the
very failures PR 1 removes, and the first thing anyone would learn about the
pipeline is how to ignore it.

*Both merge while `main` is still unprotected*, so the workflow can be corrected
freely on PR 2 until its run is green.

*Alternative rejected — one branch:* mixes a nine-file hook refactor and a runtime
behaviour change with YAML, so a red check from either half blocks the other.

### 2. `getIsPixelFlowActive` reflects the "Activate PixelFlow" toggle

`PlatformAdapter` declares `getIsPixelFlowActive(): Promise<boolean>`; `WordpressAdapter`
does not implement it, which is the single TypeScript error (surfacing at the
instantiation site, `platform.context.tsx:27`, because the class itself carries a
blanket `// @ts-expect-error TS2420`).

The method returns the state of the **Activate PixelFlow** toggle on the settings
screen. That toggle is `general_options.enabled` (`0 | 1`): written by
`ActivatePixelflow.tsx:45` via `updateGeneralOption('enabled', …)`, stored in the
`pixelflow_general_options` WordPress option, and handed to the frontend at page
load as `window.pixelflowSettings.general_options.enabled` (`pixelflow.php:112`).
The same flag gates real behaviour on the PHP side (`pixelflow.php:257`,
`class-woocommerce-integration.php:79`), so it is the plugin's own definition of
"active", not an invented one.

Implementation: read that value and compare to `1`. The stale
`// @ts-expect-error TS2420` on the class is removed in the same commit — once the
method exists the directive is unused, and an unused `@ts-expect-error` is itself
an error.

*Known limitation, accepted:* `window.pixelflowSettings` is baked into the page at
load, so the value is the toggle's state at page load, not after an in-session
change without reload. Every other consumer of `pixelflowSettings` in this codebase
has the same property.

### 3. One registry credential: a PAT in `GH_PACKAGES_TOKEN`

A workflow step writes `app/source/.npmrc` from `${{ secrets.GH_PACKAGES_TOKEN }}`
before install; `.npmrc` is added to `.gitignore`. No fallback to the built-in
`GITHUB_TOKEN`: it is scoped to this repository, the packages live elsewhere in the
`PixelFlow-org` organisation, and a conditional fallback turns one clear failure
into two ambiguous ones.

**Maintainer setup, before PR 2's first run:**

1. GitHub → your avatar → *Settings* → *Developer settings* →
   *Personal access tokens* → *Tokens (classic)* → *Generate new token (classic)*.
2. Note: `pixelflow-wordpress CI — read packages`. Expiration: 1 year (record the
   date; see task 4.2). Scope: tick **`read:packages`** only.
3. Generate, copy the `ghp_…` value — it is shown once.
4. Repository `PixelFlow-org/pixelflow-wordpress` → *Settings* → *Secrets and
   variables* → *Actions* → *New repository secret*.
   Name: `GH_PACKAGES_TOKEN` (exactly). Value: the token. *Add secret*.

A fine-grained token also works if it grants the organisation's packages read
access, but classic + `read:packages` is the shortest path and the one documented.

*Alternative rejected — commit `.npmrc` containing `${NODE_AUTH_TOKEN}`:* pnpm
fails hard on an unset variable, so every developer without that variable exported
would be unable to install. It trades a CI-only problem for a problem on every
machine.

*Failure-mode note:* an unauthenticated GitHub Packages request returns **404**,
not 401. Unhandled, this reads as "the package does not exist" and sends the reader
looking for a typo. The install step therefore carries an explicit message naming
the credential.

### 4. Pin Node in the repository, not in the workflow

Add `engines.node` to `app/source/package.json` and an `.nvmrc` at the repository
root, both pinning `22.18.0`; `setup-node` reads `.nvmrc` rather than declaring its
own version.

*Why:* one source of truth. A version declared only in YAML drifts from what
developers run, and the drift shows up as a check that is red in CI and green
locally — the least debuggable failure shape there is.

### 5. Two jobs: `frontend` and `php`

`frontend` runs lint, typecheck, format:check, vitest and the production build,
sequentially, each step carrying `if: always()` so one failure does not mask the
others. `php` runs a `[7.4, 8.1, 8.3]` matrix of `php -l` over the tracked PHP files
and each `tests/test-*.php`.

*Why PHP is in scope now:* the original deferral reason — two of three PHP test
files being untracked, making a `git ls-files` sweep quietly weaker than it looks —
no longer holds. Everything under `tests/` is tracked. `php -l` and a plain
`php tests/test-*.php` need no composer and no PHPUnit.

*Why three PHP versions:* `readme.txt` declares `Requires PHP: 7.4`, so 7.4 is the
floor that is actually promised to users; 8.3 is what the maintainer develops on;
8.1 is the common middle on real hosting.

*Consequence for protection:* the matrix produces **four** status-check names —
`frontend`, `php (7.4)`, `php (8.1)`, `php (8.3)` — and all four must be marked
required. Taking these from a completed run rather than from the YAML is task 5.1.

*Note on `if: always()`:* it is correct for reporting steps and wrong for any step
that consumes a previous step's output. This change has no such step; when a
packaging step arrives later it must **not** inherit `always()`, or it will package
a stale or absent bundle from a failed build.

*No path filters on the triggers.* A required check that is skipped never reports,
and a pull request waiting on a check that will never arrive cannot be merged. Both
jobs run on every pull request.

### 6. The three ESLint warnings are decided one at a time, with the maintainer

`--max-warnings 0` is kept — the threshold is not relaxed. But the three warnings
are not equivalent, and two are larger than they look:

- `platform.context.tsx:36` and `SettingsContext.tsx:36` (`react-refresh/only-export-components`)
  — mechanical hook extraction, but `useSettings` has 8 import sites, a same-named
  `src/features/settings/hooks/useSettings.ts` already exists, and
  `AdvancedSettings.test.tsx` mocks it. A rename touching nine files and a test
  double, not a one-line edit.
- `features/home/index.tsx:220` (`react-hooks/exhaustive-deps`) — the effect governs
  script generation. Adding `getHashedApiKey`, `isGeneratingScript` and
  `saveScriptCode` to the array changes when it re-runs, and the existing 10 tests
  cover `AdvancedSettings` and `LogViewerModal`, not this effect. A regression here
  passes CI.

*Process, agreed with the maintainer:* for each warning, Claude presents what it is,
the proposed fix in brief, and the options; the maintainer chooses; the fix lands as
its own commit and the suite is re-run immediately. `eslint-disable` is not among
the options.

### 7. Prettier: ignore the lockfile, format once, then enforce

`pnpm format:check` fails on 11 files, one of which is `pnpm-lock.yaml` — Prettier
must never rewrite a lockfile. Add `app/source/.prettierignore` excluding
`pnpm-lock.yaml` and build output, run `prettier --write` over the remaining 10
files as a single cosmetic commit, and add `format:check` as a CI step.

*Why enforce rather than just tidy:* a one-off reformat that is not gated drifts
back within weeks, and the next person's unrelated diff carries the reformat noise.

### 8. Tracking changes: `openspec/` and `CLAUDE.md` in, `.codex/` and `.npmrc` out

`openspec/` and `CLAUDE.md` become tracked — the specs that produced the workflow
should be readable in the pull request that adds it, and the project's working
rules belong to the project. This means **removing `CLAUDE.md` from `.gitignore`**.
`.claude/` stays ignored (session state, not project rules). `.codex/` is added to
`.gitignore`; `.npmrc` likewise, so a generated one can never be committed.

### 9. No version bump; the `1.1.17` changelog entry is rewritten in place

Neither PR bumps the plugin version. The existing `= 1.1.17 =` entry in `readme.txt`
and the matching `### 1.1.17` in `README.md` are rewritten to mention the static
fixes and the pipeline, with the older wording shortened so the entry stays roughly
its current length.

### 10. Branch protection is documented, applied by hand

`.github/BRANCH_PROTECTION.md` records the exact ruleset and the exact check names;
the maintainer applies it in the GitHub UI. Settings:

- Block direct pushes to `main`, **including for administrators** — no bypass list.
- Require a pull request before merging. **No required approvals** — the repository
  has a single maintainer, and GitHub does not allow self-approval, so requiring one
  would make every pull request permanently unmergeable.
- Require status checks to pass: `frontend`, `php (7.4)`, `php (8.1)`, `php (8.3)`.
- Require the branch to be up to date before merging.

*Why not scripted:* `gh` is not available in this environment, so a script could not
be tested here, and an untested script that manipulates branch protection is worse
than a checklist.

*Consequence to state plainly:* blocking administrators removes the sole
maintainer's ability to push a hotfix to `main`. That is the point of the request,
but it means a broken pipeline is also a blocked release path — hence the documented
disable procedure.

## Risks / Trade-offs

- **The registry credential does not work** → the single most likely first-run
  failure, and it fails the whole `frontend` job. Mitigated by provisioning the PAT
  before the first run (Decision 3) and by testing on a pull request while `main` is
  still unprotected.
- **Fixing `useSettings` breaks the mocked test** → its own commit, suite re-run
  before anything else is touched.
- **The `exhaustive-deps` fix changes runtime behaviour** → own commit, maintainer
  chooses the approach (Decision 6), and the script-generation flow is verified by
  hand in the local WordPress admin, since no test covers it.
- **PHP 7.4 is not a GitHub-hosted runner default** → `shivammathur/setup-php`
  provides it; if 7.4 turns out unavailable or the codebase already uses 8.x-only
  syntax, the matrix drops to `[8.1, 8.3]` and `readme.txt`'s `Requires PHP` claim
  becomes a separate question, not a blocker for this change.
- **A committed lockfile containing `yalc` links** → `package.json` carries
  `yalc:link:*` scripts; a lockfile committed after `yalc add` holds `link:` paths
  and breaks `--frozen-lockfile`. The current lockfile is clean. Accepted as a known
  failure mode with a recognisable error message.
- **Protection applied with wrong check names** → the ruleset waits forever for a
  check that never reports, blocking every merge. Mitigated by taking all four names
  from a completed run rather than from the YAML.

## Migration Plan

1. PR 1: fixes, tooling config, tracking changes. Verified locally (`pnpm lint`,
   `pnpm typecheck`, `pnpm format:check`, `pnpm test`, `pnpm build`, each
   `tests/test-*.php`). Maintainer pushes, opens, merges.
2. Maintainer provisions `GH_PACKAGES_TOKEN` (Decision 3).
3. PR 2: workflow + docs. Maintainer pushes and opens it; run logs are pasted back
   and the workflow is corrected until green. Maintainer merges.
4. Maintainer reads the four status-check names off the completed run, appends them
   to `.github/BRANCH_PROTECTION.md`, and applies the ruleset.
5. Verify: a direct push to `main` is rejected; a pull request with a deliberate
   lint error is unmergeable; the documented disable procedure matches the UI.

**Rollback:** disable the ruleset in the GitHub UI; the workflow changes no plugin
behaviour and can be deleted without consequence. The PR 1 source fixes are ordinary
code changes and revert like any other.

## Open Questions

None blocking. Two facts are learned by running rather than by deciding: whether
PHP 7.4 is usable in the matrix, and the exact rendering of the four status-check
names. Both are recorded in `.github/BRANCH_PROTECTION.md` after the first green run.
