We are working on the Plugin for WordPress

The plugin is about integration Pixelflow service, which is a service to integrate with Facebook/Meta analytics

app/ is used to build the Settings page in /wp-admin /wp-admin/options-general.php?page=pixelflow-settings
It uses React to build the settings panel

It has optional Woo integration -- when the integration is enabled, it adds hooks to capture events

## Frontend Testing Setup (v1.1.17+)

The project includes frontend test tooling for the React admin interface:

- **Unit/Component Tests**: vitest + @testing-library/react under `app/source/`
  - Run tests: `npm run test` (from `app/source/`)
  - Tests validate component behavior and state management
  
- **E2E Tests**: Playwright setup under `e2e/` (self-contained)
  - Tests verify user-facing workflows in the admin settings UI
  - Screenshots captured for key flows during test runs

## Documentation Language

All specs and planning artifacts are written in English — OpenSpec proposals, delta specs, design docs and task lists under `openspec/changes/`, PRDs, plans and analysis docs under `.claude/tasks/`, plus the changelog and README. This holds regardless of the language of the conversation that produced them: chat may be in Russian, the written artifact is English.

## Version Bump

The plugin version lives in 5 places across 3 files; all must be updated together:

- `pixelflow.php:5` — `* Version:` plugin header
- `pixelflow.php:20` — `define('PIXELFLOW_VERSION', '...')`
- `readme.txt:7` — `Stable tag:`
- `readme.txt` — new `= X.Y.Z =` entry at the top of `== Changelog ==`
- `README.md` — new `### X.Y.Z` entry at the top of `## Changelog`

`app/source/package.json` carries its own version (1.0.0) and is deliberately
not kept in sync with the plugin version.

Releases increment the third segment (1.1.16 → 1.1.17 → 1.1.18), for both
fixes and feature additions. The changelog entry is a single line, in English.
