## Context

See proposal.md for the motivation. The current state that shapes the approach:

- **PHP fixes the production addresses in three places:** the storefront snippet
  (`pixelflow.php:297`: `pfm.js` and `apiEndpoint`), the WooCommerce integration
  (`includes/woo/class-woocommerce-integration.php:54`), and the form dispatcher
  (`includes/forms/dispatcher.php:24`). The snippet ignores the `apiEndpoint` and `cdnUrl`
  that the settings page stores in `pixelflow_script_params` (`pixelflow.php:777-791`).
- **The admin app reads the addresses in several ways:**
  - `import.meta.env.VITE_*` in `main.tsx:9-13`, `config/platform.config.ts:9-11` and
    `features/home/index.tsx:206,339`;
  - `getCdnUrl()` from `@pixelflow-org/plugin-core`;
  - three literal `https://dashboard.pixelflow.so/dashboard/overview` links.
- **`plugin-core` resolves addresses at call time**, from `setPixelFlowConfig()`, otherwise
  `window.__ENV__`, otherwise `process.env` (`dist/index.js:274-288`). It never reads
  `import.meta.env`.
- **`plugin-features` does read `import.meta.env`**, which Vite inlines at build time:
  `dist/headless/auth/use-auth-handler.js:61` builds the sign-in window's address from
  `VITE_UI_BASE_URL` (`modules/home/index.js:188` and
  `modules/home/components/start-setup-modal.js:9` do too, but the app does not use those
  modules). Found during implementation by the build check: a single bundle built from `.env`
  carried `dashboard.pixelflow.so`, so the development archive's sign-in would have opened the
  production dashboard with a session key issued by staging. The library's repository is not
  writable from here.
- **PHP already passes data to the admin app before the bundle runs:**
  `window.pixelflowSettings` is an inline script added before it (`pixelflow.php:156-157`).
- **Vite loads `.env`, `.env.local`, `.env.<mode>`, `.env.<mode>.local`** in that order, each
  later file overriding the earlier ones (`vite/dist/node/chunks/node.js:4944`, Vite 8.0.3).
- **The repository is public.** The env files are kept out of git today only by one user's
  global ignore rules. CI packages with no env file and gets empty addresses
  (`.github/workflows/ci.yml:114-116`).

## Goals / Non-Goals

**Goals:**
- One source of addresses per installed plugin, read by PHP and handed to the admin app.
- Both archives from one run of the build script, each with an admin bundle and a
  configuration built from the same env file.
- A build that fails loudly on a broken env file instead of shipping empty addresses.

**Non-Goals:**
- Switching environments at runtime on an installed site (a wp-config constant or a setting).
  The environment is fixed by which archive is installed.
- Rewriting the `pixelflow_script_params` values already stored on sites. They stay as stored
  and remain unused by the PHP sends, as today.
- Telling the archives apart in WordPress (plugin name, version suffix, an admin badge). Both
  are `pixelflow` with the same header.
- Staging addresses for the documentation links (`docs.pixelflow.so`). There is one
  documentation site.

## Decisions

### A generated PHP file returns the addresses

The build writes `includes/config.php` that returns an array with the keys `api_url`,
`ui_url` and `cdn_url`. It is guarded by `defined('ABSPATH') || exit;` like the other include
files. A helper in `includes/helpers.php` loads it once per request and returns the array, or
an empty array when the file is absent. Tests point the helper at another file through a
`PIXELFLOW_CONFIG_FILE` constant defined before the helper loads.

Alternatives:
- **A JSON file.** It needs a read and a decode on every request, and a web server may serve
  it as plain text. A PHP file is cached by opcache and prints nothing when requested.
- **Constants substituted into `pixelflow.php` by `sed` at build time.** The archives would
  differ in a shipped source file rather than in a configuration file, and a source checkout
  would carry whatever was substituted last.

### The admin app gets the addresses from `window.pixelflowSettings`

PHP adds `config: { apiBaseUrl, uiBaseUrl, cdnUrl }` to the settings it already inlines.
`main.tsx` passes that object to `setPixelFlowConfig()`, and `platform.config.ts` reads the
same object. `features/home/index.tsx` builds `apiEndpoint` from `getApiBaseUrl()`. The three
dashboard links use `getUiBaseUrl() + '/dashboard/overview'`.

Alternative: setting `window.__ENV__`, which `plugin-core` also reads. Rejected because it
works only through the library's fallback path, while `setPixelFlowConfig()` is the explicit
entry point the app already uses.

### One Vite build per archive, from env values the build script reads

`build_plugin.sh` reads and validates every env file first, then stages the plugin's PHP files
once. For each environment it runs `npm run build` with the three values passed as process
environment variables (Vite gives those precedence over its own env files), copies the staged
tree, adds that bundle and a `config.php` from the same values, and zips it. The development
archive is built last, so the working tree's `app/dist` matches its `includes/config.php`. The
script parses `KEY=VALUE` lines, skips comments and blank lines, strips one pair of surrounding
quotes, and never `source`s the file, so an env file cannot run commands.

The app's own code no longer reads `import.meta.env`; only `plugin-features` does. A build
check asserts that each bundle names its own dashboard and no host of the other environment.

Alternatives:
- **One shared bundle, with Vite `define` mapping the three `import.meta.env.VITE_*` keys to
  `window.pixelflowSettings.config` in every module, libraries included.** It works (checked
  with a probe build) and keeps the archives different only in `config.php`, but it rewrites
  a library's internals to compensate for its bug, and turns redundant once the library is
  fixed. The maintainer chose the per-archive build.
- **Fix `plugin-features` first** (read `getUiBaseUrl()` / `getApiBaseUrl()` at call time).
  The right fix in the library, but it needs another team's review and release; it can follow
  separately and does not conflict with building per archive.

A leftover `.env.local` or `.env.production` from before the rename is unused. The build warns
that it should be renamed or deleted, so a stale file is not mistaken for the active one.

### The working tree gets `includes/config.php` from `.env.dev`, falling back to `.env`

This follows the maintainer's decisions: the working tree runs against staging, and without
`.env.dev` the build still succeeds. `includes/config.php` is added to the root `.gitignore`.
`app/source/.gitignore` stops ignoring `.env` and adds `.env.dev`, `.env.local` and
`.env.production`.

### A missing configuration counts as an unconfigured plugin

With no `api_url`, the WooCommerce hooks and the form dispatcher take the same path they take
today when the site id or API key is missing: nothing is sent, and a debug-log entry is
written when logging is on. The storefront snippet is not added without `cdn_url` and
`api_url`. No new admin notice is added. A clone that was never built has no `app/dist`
either, so its settings page does not load in any case.

### Callers drop the argument

`.github/workflows/ci.yml`, `publish_plugin.sh` and `e2e/live/scripts/run.sh` call
`./build_plugin.sh` and keep using `build/pixelflow.zip`. The live suite therefore keeps
testing the production archive against the production PixelFlow account it uses today. The
CI comment about empty environment values is rewritten: with `.env` committed, CI packages
the real production configuration and skips the development archive.

## Risks / Trade-offs

- [The local site and the admin E2E switch to staging] → A local site connected to a
  production PixelFlow account stops authenticating until it is reconnected on staging. The
  first admin E2E run after the change is checked for this, and the README states it.
- [A stale `build/pixelflow_dev.zip` is mistaken for a current one] → A build without
  `.env.dev` deletes it.
- [An address in `.env.dev` repeats a production host by mistake] → Out of scope to detect.
  The development archive uses exactly what `.env.dev` says.
- [The bundle stops having addresses when `window.pixelflowSettings.config` is absent, for
  example a cached page from the previous version] → `plugin-core` then resolves empty
  strings, as it does today with an empty env. The settings page is not cached by WordPress,
  and the inline script ships with the same plugin version as the bundle.
- [Shell parsing of env files is stricter than Vite's (no multi-line values, no variable
  expansion)] → The three values are single-line URLs. The build fails on anything it cannot
  parse.

## Migration Plan

1. On each maintainer's machine, rename `app/source/.env.production` to `app/source/.env` and
   `app/source/.env.local` to `app/source/.env.dev`.
2. Merge. The first `./build_plugin.sh` writes both archives and the working-tree
   configuration.
3. Rollback: revert the merge. The old script and the old env names come back, and the
   generated `includes/config.php` is ignored by the old code.
