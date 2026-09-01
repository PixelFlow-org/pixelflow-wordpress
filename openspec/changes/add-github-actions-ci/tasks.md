Two pull requests, in order, then a manual step. Do not start PR 2 before PR 1 is
merged — see design.md Decision 1.

**Division of labour (design.md Decision 0):** Claude edits files and makes local
commits. The maintainer pushes, opens and merges both pull requests, provisions the
secret, applies the ruleset, and pastes run logs back into the session. Tasks below
are marked **[M]** where the maintainer acts.

## 1. PR 1 — repository hygiene and tooling config

Branch: new, from `main`.

- [ ] 1.1 `.gitignore`: add `.codex/` and `.npmrc`; **remove** the `CLAUDE.md` line so the file becomes trackable. Leave `.claude/` ignored
- [ ] 1.2 `git add` `openspec/` and `CLAUDE.md` — the specs behind this change ship with it
- [ ] 1.3 Add `.nvmrc` at the repository root containing `22.18.0`, and `engines.node` in `app/source/package.json` pinning the same (satisfies `eslint@10`'s `^22.13.0` and `vite@8`'s `>=22.12.0`)
- [ ] 1.4 Add a `typecheck` script (`tsc --noEmit`) to `app/source/package.json`, so CI and developers invoke the same command
- [ ] 1.5 Add `app/source/.prettierignore` excluding `pnpm-lock.yaml`, `node_modules`, `dist` and `../dist`. Verify `pnpm format:check` no longer lists `pnpm-lock.yaml`

## 2. PR 1 — the four ESLint errors and the TypeScript error

Same branch as group 1.

- [ ] 2.1 Fix `no-useless-assignment` at `app/source/src/adapters/wordpress-adapter.ts:159` — the value assigned to `theme` is never read afterwards
- [ ] 2.2 Fix the three `@typescript-eslint/no-unused-vars` errors at `src/features/settings/api/index.ts:58,105,174` — unused `error` bindings in `catch` clauses. Preserve the existing behaviour: do not start swallowing, and do not start logging something that was not logged before
- [ ] 2.3 Implement `getIsPixelFlowActive(): Promise<boolean>` on `WordpressAdapter`, returning the state of the **Activate PixelFlow** toggle: `Number(window.pixelflowSettings?.general_options?.enabled) === 1`. Match the file's existing pattern for reading `window.pixelflowSettings` (see `removeScript`, `wordpress-adapter.ts:38`). Add a short comment recording that this is the toggle at `ActivatePixelflow.tsx:45` / the `general_options.enabled` option, and that the value is read as baked into the page at load
- [ ] 2.4 In the same commit, remove the now-stale `// @ts-expect-error TS2420` above `export class WordpressAdapter` — once the method exists the directive is unused, which is itself an error. Verify `npx tsc --noEmit` exits 0

## 3. PR 1 — the three ESLint warnings (one decision at a time)

Same branch. `--max-warnings 0` stays. Per design.md Decision 6, **each warning is
presented to the maintainer with options before it is fixed**, and each lands as its
own commit. `eslint-disable` is not an option.

- [ ] 3.1 `react-refresh/only-export-components` at `src/contexts/platform.context.tsx:36` — present the fix (move `usePlatform` to a sibling module; 2 import sites: `src/App.tsx`, `src/features/bootstrap/components/index.tsx`) and the options; apply the chosen one
- [ ] 3.2 The same warning at `src/features/settings/contexts/SettingsContext.tsx:36`. **Larger than it looks**: `useSettings` has 8 import sites, a same-named `src/features/settings/hooks/useSettings.ts` already exists, and `AdvancedSettings.test.tsx` mocks it. Present the options, apply, re-run the suite immediately
- [ ] 3.3 `react-hooks/exhaustive-deps` at `src/features/home/index.tsx:220`. **Behaviour change**: the effect governs script generation and no test covers it. Present what re-running would change and the options (stabilise `getHashedApiKey` / `saveScriptCode` with `useCallback` vs. narrow the effect), apply the chosen one, own commit
- [ ] 3.4 **[M]** Verify 3.3 by hand: load the settings page in the local WordPress admin and confirm the script-generation flow still works

## 4. PR 1 — formatting, changelog, verification

Same branch.

- [ ] 4.1 Run `pnpm format` (`prettier --write`) and commit the result **as a separate cosmetic commit**, so the reformat noise is not mixed with the logic changes above. Expect ~10 files
- [ ] 4.2 Rewrite the `= 1.1.17 =` entry in `readme.txt` and the matching `### 1.1.17` in `README.md` to mention the static-analysis fixes and the CI pipeline, shortening the existing wording so each entry stays roughly its current length. **No version bump** — the version stays `1.1.17` in all five declaration sites
- [ ] 4.3 Verify locally, all from `app/source/` unless noted: `pnpm lint` exits 0 with no output, `pnpm typecheck` exits 0, `pnpm format:check` exits 0, `pnpm test` reports 10/10, `pnpm build` succeeds, and from the plugin root `php tests/test-woo-add-to-cart-null-variation.php` exits 0
- [ ] 4.4 **[M]** Push the branch, open PR 1 against the still-unprotected `main`, merge it

## 5. Between the pull requests — provision the registry credential

- [ ] 5.1 **[M]** Create a classic PAT with **only** the `read:packages` scope (GitHub → Settings → Developer settings → Personal access tokens → Tokens (classic) → Generate new token (classic); note: `pixelflow-wordpress CI — read packages`; expiration 1 year — write the date down)
- [ ] 5.2 **[M]** Save it as repository secret `GH_PACKAGES_TOKEN` (repo → Settings → Secrets and variables → Actions → New repository secret). The name must match exactly

## 6. PR 2 — the workflow

Branch: new, from the updated `main`.

- [ ] 6.1 Create `.github/workflows/ci.yml` triggered on `pull_request` targeting `main` and on `push` to `main`. **No `paths:` filters** — a skipped required check never reports and blocks the merge forever. Set `permissions: contents: read, packages: read`, and a `concurrency` group keyed on the ref with `cancel-in-progress: true`
- [ ] 6.2 Job `frontend`: `actions/checkout`, `pnpm/action-setup`, `actions/setup-node` with `node-version-file: .nvmrc` and `cache: pnpm`. Add `timeout-minutes` so a hung install cannot occupy a runner for the six-hour default
- [ ] 6.3 Generate `app/source/.npmrc` from `${{ secrets.GH_PACKAGES_TOKEN }}` via heredoc — no `echo` of the value, no `set -x` anywhere in the job
- [ ] 6.4 Add `pnpm install --frozen-lockfile`, with an explicit failure message naming `GH_PACKAGES_TOKEN`. An unauthenticated GitHub Packages request returns 404, not 401, so the default output misleads
- [ ] 6.5 Add the `lint`, `typecheck`, `format:check`, `test` and `build` steps, each with `if: always()` so one failure does not mask the others. Build once — do not add a second build step
- [ ] 6.6 Job `php`: matrix `[7.4, 8.1, 8.3]`, `shivammathur/setup-php`, running `php -l` over the tracked `.php` files and executing each `tests/test-*.php`. No composer, no PHPUnit. If 7.4 proves unavailable or the codebase already needs 8.x syntax, drop to `[8.1, 8.3]` and record why in `.github/BRANCH_PROTECTION.md`
- [ ] 6.7 **[M]** Push the branch, open PR 2, paste the run log back. Iterate until all four checks are green — expect the registry credential to be the first thing that fails

## 7. PR 2 — documentation

Same branch as group 6.

- [ ] 7.1 Write `.github/BRANCH_PROTECTION.md` with the exact ruleset for `main`: block direct pushes **including for administrators** (no bypass list), require a pull request, **require zero approvals** (single maintainer — GitHub forbids self-approval, so requiring one would make every pull request unmergeable), require status checks, require the branch to be up to date. Include how to temporarily disable the ruleset — with administrators blocked, the maintainer has no other hotfix path
- [ ] 7.2 Document in the same file: the `GH_PACKAGES_TOKEN` secret, where its expiry date is recorded, and the four status-check names (filled in at 8.1 from a real run)
- [ ] 7.3 Add a CI section to `README.md` listing each check and the local command that reproduces it (`pnpm lint`, `pnpm typecheck`, `pnpm format:check`, `pnpm test`, `pnpm build`, `php tests/test-*.php`)
- [ ] 7.4 **[M]** Merge PR 2 while `main` is still unprotected — its own green run is the evidence the pipeline works

## 8. Manual step — enable protection

Maintainer action in the GitHub UI, after group 7 has merged.

- [ ] 8.1 **[M]** Read the four status-check names off the completed PR 2 run — from the run, not from the YAML — and append them to `.github/BRANCH_PROTECTION.md`
- [ ] 8.2 **[M]** Apply the ruleset from `.github/BRANCH_PROTECTION.md`
- [ ] 8.3 **[M]** Verify protection: a direct push to `main` is rejected, and a pull request carrying a deliberate lint error cannot be merged
- [ ] 8.4 **[M]** Verify the escape hatch: confirm the documented disable procedure in 7.1 matches the UI

## 9. Follow-up wave (separate change, not implemented here)

Recorded so the deferral is explicit rather than forgotten. Each needs its own
proposal — see design.md Decision 5 and proposal.md for why none belongs here.

- [ ] 9.1 PHPCS/WPCS on pull-request-changed files, with a security-focused ruleset and a calibration pass over the tracked PHP files. Note that `git diff origin/main...HEAD` is empty on a `push` to `main`, making such a check a silent no-op there
- [ ] 9.2 Plugin-version consistency check across the five declaration sites
- [ ] 9.3 Packaged `pixelflow.zip` artifact via `build_plugin.sh`, needing `VITE_API_BASE_URL`, `VITE_UI_BASE_URL` and `VITE_CDN_URL` as secrets. The packaging step must **not** carry `if: always()`, or it will package a stale bundle from a failed build
- [ ] 9.4 Decide whether Playwright e2e runs in CI at all — it needs a provisioned WordPress + MySQL service, since the suite targets a live site at `http://localhost`
- [ ] 9.5 Reconcile `CLAUDE.md`'s "Frontend Testing Setup" section, which says `npm run test`, with the project's actual pnpm toolchain
