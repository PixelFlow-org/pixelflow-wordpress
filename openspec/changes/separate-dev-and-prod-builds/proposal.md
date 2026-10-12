## Why

A development build of the plugin is meant to talk to PixelFlow's staging environment, but
only part of it does. The admin app takes its API, dashboard and CDN addresses from Vite env
files, yet three "open the dashboard" links hard-code the production dashboard, and the PHP
side hard-codes the production API and tracking script in three places. A site running
`pixelflow_dev.zip` therefore signs in to staging, opens the production dashboard and sends
its events to production. The dev archive is also built only on request (`build_plugin.sh
dev`), so the one in `build/` is months old.

## What Changes

- **BREAKING (build):** `build_plugin.sh` takes no argument and builds both archives in one
  run: `build/pixelflow.zip` from `app/source/.env` and `build/pixelflow_dev.zip` from
  `app/source/.env.dev`. Any argument, including the old `dev` / `prod`, fails with a usage
  message. The `build-dev` npm script is removed.
- Env files are renamed: `.env.production` → `.env` (production), `.env.local` → `.env.dev`
  (staging). `.env` is committed; `.env.dev` is ignored by the repository's `.gitignore`.
  The three keys (`VITE_API_BASE_URL`, `VITE_UI_BASE_URL`, `VITE_CDN_URL`) keep their names.
- The addresses move into one generated PHP configuration file, written per archive from that
  archive's env file and never committed. The build also writes it into the working tree from
  `.env.dev`, so a plugin running from sources talks to staging.
- PHP reads the API address and the tracking script address from that file: the storefront
  tracking snippet, WooCommerce `/event` and `/blocked-events`, and form events. No PixelFlow
  service address stays hard-coded in PHP.
- The admin app's own code takes all three addresses from `window.pixelflowSettings`, which
  PHP fills from the same file. The three dashboard links use the configured dashboard
  address. The JS bundle is still built per archive, from that archive's env file, because a
  PixelFlow library (`@pixelflow-org/plugin-features`, the sign-in window) reads
  `import.meta.env.VITE_UI_BASE_URL`, which Vite inlines at build time.
- A missing `.env`, or an empty key in any env file that exists, fails the build. Without
  `.env.dev` the build makes the production archive only, warns that the dev archive was
  skipped, and writes the production configuration into the working tree with a warning.
- A plugin with no configuration file (a clone that was never built) sends no event and loads
  no tracking script.
- Callers move to the argument-free build: `.github/workflows/ci.yml`, `publish_plugin.sh`,
  `e2e/live/scripts/run.sh`, and the README.

## Capabilities

### New Capabilities
- `build-environments`: which archives one build produces, where each takes its service
  addresses from, the generated configuration file, how PHP and the admin app read it, and
  what the build and the plugin do when an env file, a key or the configuration is missing.

### Modified Capabilities
<!-- None. ci-pipeline already requires one production frontend build per run and a check
     of the packaged plugin; CI has no .env.dev, so it builds the production bundle once and
     checks the production archive. -->

## Impact

- Build: `build_plugin.sh`, `app/source/package.json` (`build-dev` removed),
  `app/source/.gitignore`, `app/source/.env` (new, committed), `.env.dev` (local only).
- PHP: `pixelflow.php` (tracking snippet, `window.pixelflowSettings`), `includes/helpers.php`
  (config reader), `includes/woo/class-woocommerce-integration.php`,
  `includes/forms/dispatcher.php`, a generated `includes/config.php`.
- Admin app: `app/source/src/main.tsx`, `config/platform.config.ts`,
  `shared/components/Header/Header.tsx`, `features/home/components/start-setup-modal.tsx`,
  `features/forms/components/FormsSettings.tsx`.
- Callers: `.github/workflows/ci.yml`, `publish_plugin.sh`, `e2e/live/scripts/run.sh`,
  `README.md`.
- Tests: PHP tests supply their own configuration; vitest covers the dashboard links and the
  config read from `window.pixelflowSettings`; a build check unpacks both archives.
- Local working tree: after the first build the local WordPress and the admin E2E run against
  staging; a local site connected to a production PixelFlow account must be reconnected.
- Outbound behaviour of the production archive: unchanged addresses.
