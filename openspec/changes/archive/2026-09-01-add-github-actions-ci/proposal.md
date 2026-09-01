## Why

The repository has no automated verification. `main` accepts direct pushes and
nothing runs the frontend build, the unit tests or the linters before code lands.
Five static checks fail today (4 ESLint errors, 3 warnings under `--max-warnings 0`,
1 TypeScript error) and nobody noticed, because nothing looks. The plugin ships to
end-user WordPress sites, so a broken bundle is a user-visible incident.

## Current State (re-measured on `main`, 2026-09-01)

The earlier draft of this proposal was written while the test tooling lived only on
`task/T-002-debug-log-refresh-button`. That branch has since merged (PR #14), so the
"Phase 0" it described is **done and removed from this change**. The trunk now holds:

| Fact | Value |
|---|---|
| Branch / version | `main`, plugin version `1.1.17` |
| Test tooling in `main` | `app/source/vitest.config.ts`, both `*.test.tsx`, `e2e/` (Playwright), `tests/bootstrap-wp-stubs.php`, `tests/test-woo-add-to-cart-null-variation.php` — all tracked |
| `pnpm test` | 10/10 pass |
| `pnpm build` | passes |
| `pnpm lint` | **fails** — 4 errors, 3 warnings (`--max-warnings 0` makes warnings fatal) |
| `npx tsc --noEmit` | **fails** — 1 error at `src/contexts/platform.context.tsx:27` |
| `pnpm format:check` | **fails** — 11 files, no `.prettierignore` exists |
| `tests/test-*.php` | 1 test file + bootstrap, passes under PHP 8.3 |
| `.gitignore` | already excludes `e2e/test-results/` and `e2e/screenshots/`; does **not** exclude `.npmrc` or `.codex/`; **does** exclude `CLAUDE.md` |
| Package manager | pnpm (`pnpm-lock.yaml`); the `npm run test` in `CLAUDE.md` is stale wording, not a second toolchain |
| Untracked | `.codex/`, `openspec/` |

## What Changes

Two pull requests, in order, then a manual step.

**PR 1 — make the checks green.** Fix the 4 ESLint errors, the 1 TypeScript error
and the 3 ESLint warnings; add `.prettierignore` and run Prettier; pin Node via
`engines` + `.nvmrc`; add a `typecheck` script. Also start tracking `openspec/` and
`CLAUDE.md`, and ignore `.codex/` and `.npmrc`. No workflow file in this PR.

**PR 2 — the pipeline.** `.github/workflows/ci.yml` on pull requests targeting
`main` and on pushes to `main`, with two jobs: `frontend` (authenticated install
from GitHub Packages, lint, typecheck, format:check, vitest, production build) and
`php` (a `7.4 / 8.1 / 8.3` matrix running `php -l` and the standalone
`tests/test-*.php`). Plus `.github/BRANCH_PROTECTION.md` and a CI section in
`README.md`.

**Manual step — protection.** After PR 2 merges green, the maintainer applies the
documented ruleset to `main` in the GitHub UI.

Deliberately **not** in this change:

- PHPCS/WPCS. It needs calibration against a 4-space-indented codebase and a
  composer toolchain the project has never had; bundling it with the first working
  pipeline risks stalling both.
- Plugin-version consistency check and the packaged `pixelflow.zip` artifact.
- Playwright e2e in CI. The suite targets a live WordPress at `http://localhost`
  and would need a provisioned WordPress + MySQL service in the runner.
- WordPress.org SVN publishing — stays a manual `publish_plugin.sh` run.

## Capabilities

### New Capabilities
- `ci-pipeline`: automated pre-merge verification of the plugin — which checks run,
  when they run, what makes them fail, and how `main` is protected.

### Modified Capabilities

(none — `openspec/specs/` holds no existing specs)

## Impact

- **New files**: `.github/workflows/ci.yml`, `.github/BRANCH_PROTECTION.md`,
  `.nvmrc`, `app/source/.prettierignore`.
- **Modified source**: `app/source/src/adapters/wordpress-adapter.ts`,
  `src/features/settings/api/index.ts`, `src/contexts/platform.context.tsx`,
  `src/features/settings/contexts/SettingsContext.tsx`,
  `src/features/home/index.tsx`, plus the 10 files Prettier reformats.
- **Modified config/docs**: `app/source/package.json` (`typecheck` script,
  `engines`), `.gitignore`, `README.md`, `readme.txt`.
- **Newly tracked**: `openspec/`, `CLAUDE.md`.
- **External dependency**: `@pixelflow-org/plugin-{core,ui,features}` resolve from
  GitHub Packages (`npm.pkg.github.com`). CI cannot install a single dependency
  without an authenticated `.npmrc`. This is the one hard blocker for the whole
  pipeline; everything else is downstream of it.
- **Required GitHub Secret**: `GH_PACKAGES_TOKEN` — a classic PAT with
  `read:packages`, provisioned by the maintainer before PR 2's first run. See
  design.md Decision 3 for the exact steps.
- **No version bump.** Neither PR bumps the plugin version; the existing `1.1.17`
  changelog entry is rewritten in place in both `readme.txt` and `README.md`, with
  the older wording shortened so the entry stays about the same length.
- **Process change**: after the manual step, `main` is push-protected for everyone
  including administrators, and all work goes through pull requests.
