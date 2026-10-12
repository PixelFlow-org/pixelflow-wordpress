## 1. Branch and env files

- [x] 1.1 Branch `feat/separate-dev-and-prod-builds` from `main`, after the live run on
  `feat/wordpress-first-purchase` has finished
- [x] 1.2 Locally rename `app/source/.env.production` → `app/source/.env` and
  `app/source/.env.local` → `app/source/.env.dev`; commit `app/source/.env` holding the three
  production keys; check that it carries no key, token or site id
- [x] 1.3 `app/source/.gitignore`: stop ignoring `.env`; ignore `.env.dev`, `.env.local`,
  `.env.production`, `.env.*.local`; root `.gitignore`: ignore `includes/config.php`;
  `git check-ignore` with the global ignore file disabled
  (`git -c core.excludesFile=/dev/null`) confirms `.env.dev` and `includes/config.php` are
  ignored and `.env` is not

## 2. PHP reads the configuration

- [x] 2.1 `includes/helpers.php`: a reader that loads `includes/config.php` once (or the file
  named by `PIXELFLOW_CONFIG_FILE`) and returns `api_url`, `ui_url`, `cdn_url`, or an empty
  array when the file is absent or does not return an array
- [x] 2.2 `pixelflow.php`: the storefront snippet takes the script address and
  `apiEndpoint` from the configuration and is not added when either is empty
- [x] 2.3 `includes/woo/class-woocommerce-integration.php` and `includes/forms/dispatcher.php`:
  the API origin comes from the configuration; an empty origin takes the existing "not
  configured" path (no send, a debug-log entry when logging is on)
- [x] 2.4 `pixelflow.php`: `window.pixelflowSettings.config` carries `apiBaseUrl`,
  `uiBaseUrl`, `cdnUrl`
- [x] 2.5 `grep` finds no PixelFlow service host in `pixelflow.php` or `includes/`
  (the `Author URI` header excepted)

## 3. Admin app reads the configuration

- [x] 3.1 `main.tsx` and `config/platform.config.ts` take the addresses from
  `window.pixelflowSettings.config`; nothing under `src/` reads `import.meta.env.VITE_*`
- [x] 3.2 `features/home/index.tsx`: `apiEndpoint` from `getApiBaseUrl()`
- [x] 3.3 `Header.tsx`, `start-setup-modal.tsx`, `FormsSettings.tsx`: the dashboard link is
  `getUiBaseUrl() + '/dashboard/overview'`
- [x] 3.4 `app/source/package.json`: remove `build-dev`

## 4. Build script

- [x] 4.1 `build_plugin.sh`: reject any argument with a usage message; parse each env file
  without `source`; fail when `.env` is missing or a key of an existing file is missing or
  empty, naming the file and key; warn about a leftover `.env.local` or `.env.production`
- [x] 4.2 Stage once; for each environment run `npm run build` with its three values in the
  process environment, add that bundle and a `config.php` to a copy of the staged tree and zip
  it to `build/pixelflow.zip` / `build/pixelflow_dev.zip`, development last; without
  `.env.dev`, skip the dev archive with a warning and delete a stale one
- [x] 4.3 Write the working-tree `includes/config.php` from `.env.dev`, or from `.env` with a
  warning

## 5. Callers and docs

- [x] 5.1 `.github/workflows/ci.yml`: `./build_plugin.sh`; rewrite the comment about empty
  environment values
- [x] 5.2 `publish_plugin.sh` and `e2e/live/scripts/run.sh`: call `build_plugin.sh` with no
  argument
- [x] 5.3 `README.md`: build section (both archives, env files, what the working tree gets,
  reconnecting a local site on staging)

## 6. Tests

- [x] 6.1 PHP: the reader (file present, absent, not an array); the snippet, a WooCommerce
  event, a blocked report and a form event use the configured addresses; with no
  configuration nothing is sent and no snippet is added. The suite's bootstrap sets no
  configuration by default; only the cases about its presence point `PIXELFLOW_CONFIG_FILE` at
  a fixture with `example.test` addresses. `tests/test-configuration-gate.php:308` moves to
  the fixture address
- [x] 6.2 vitest: the config is read from `window.pixelflowSettings.config`; each of the three
  dashboard links opens the configured origin plus `/dashboard/overview`
- [x] 6.3 Build check (shell): with both env files, both archives exist, differ only in
  the bundle and `includes/config.php`, each config holds its env's values, and each bundle
  names its own dashboard and no host of the other environment; with an argument, a missing `.env` or an empty key, the script exits
  non-zero and leaves no new archive; without `.env.dev`, no `pixelflow_dev.zip` remains
- [x] 6.4 Run the PHP suite, vitest, `tsc`, `eslint`, the admin E2E (now against staging; a
  failure from a production-only account is recorded, not hidden) and Plugin Check on the
  unpacked `pixelflow.zip`
- [x] 6.5 Live: one targeted run of `e2e/live` on the production archive (deploy plus the
  storefront tracking and form-send specs) to confirm production addresses are unchanged

## 7. Release

- [x] 7.1 Version and changelog line agreed with the maintainer: no bump, no changelog line;
  the production archive behaves as 1.2.2 does, and the code ships with the next release
- [ ] 7.2 Commit (artifacts, code, tests separately) and open a PR from the new branch; no
  session links, no client data, no staging addresses in the PR text
