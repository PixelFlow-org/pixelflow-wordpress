## Context

The plugin already ships two frontend test layers — vitest under `app/source/` and a
Playwright project under `e2e/` — but both stop at the React settings panel. Nothing
exercises the PHP event pipeline in `includes/woo/hooks/class-woocommerce-hooks.php`,
which is where the branching lives. See proposal.md — Why.

Constraints that shape the approach:

- The only realistic environment is the live test site the live test site. Agent access
  to it is key-based SSH as the `claude` user, with WP-CLI available at `~/bin/wp`; the WP
  root is `/var/www/<live-test-site>/www`. Sibling sites under `/var/www/` are off
  limits.
- The plugin's evidence trail is the debug log: `pixelflow_get_debug_log_path()` resolves
  to `wp-content/pixelflow_debug_<key>.log`, where `<key>` is the `pixelflow_debug_log_key`
  option. Records are pretty-printed JSON separated by `\n---\n`, and the writer trims the
  file at 1 MB.
- Relevant settings live in `pixelflow_general_options` (`enabled`, `woo_enabled`,
  `woo_excluded_skus`, the three `woo_disable_*_freebies` flags) and
  `pixelflow_debug_options` (`woo_debug_enabled`).
- The site is already seeded: `PF-*` product fixtures for all Woo types and states, offline
  payment gateways (COD, cheque, BACS), a `discount` coupon at 37%, a `PF-EXCLUDED`
  product, an admin account and a customer account, and a configured Pixelflow connection.

## Goals / Non-Goals

**Goals:**

- One command produces a trustworthy verdict on the whole WooCommerce event matrix.
- Every assertion is grounded in an artifact a human can re-read afterwards: a log excerpt
  or a screenshot.
- Failures are legible — the report says which scenario, which expectation, what was found.

**Non-Goals:**

- Running in CI, or against any site other than the live test site.
- Asserting anything about what the Pixelflow backend does with the events. The debug log
  is the sole oracle.
- Cleaning up after the run, or asserting on pre-existing site state.
- Covering the plugin's non-Woo event paths (PageView, consent gating, bot filtering).

## Decisions

**The site is fixed by configuration, not parameterised per run.** There is exactly one test site and
its access details (SSH key, user, paths, accounts) are recorded outside the repo. A
`--site` parameter would be speculative configurability for a second site that does not
exist; adding it later is a small change if one appears.

**Split of labour: skill orchestrates, Playwright executes.** The skill owns the run's
spine — build, deploy, WooCommerce deactivate/reactivate smoke, invoking the Playwright
project, reading the log over SSH, writing the report. The Playwright project under
`e2e/live/` owns the mechanical matrix: browser navigation, settings-panel manipulation,
storefront actions. Putting the matrix in Playwright specs keeps it declarative and
re-runnable in isolation; putting deployment in the skill keeps SSH and build steps out of
test code. *Alternative rejected:* driving everything from the skill with the browser
tooling directly — the matrix is large and repetitive, exactly what a test runner is for.

**`e2e/live/` is a separate Playwright project inside the existing `e2e/` tree.** It shares
the installed Playwright and its config conventions but has its own project entry, base
URL and specs, so a `npm run test:e2e` of the admin suite never accidentally fires real
events at a live site. *Alternative rejected:* a standalone runner inside the skill folder —
duplicates the toolchain for no gain.

**Deployment goes through the wp-admin upload UI.** Uploading `pixelflow.zip` at
Plugins → Add New → Upload and confirming the "replace current with uploaded" screen
exercises WordPress's own upgrade path, so a broken update — a bad file layout, a fatal in
an upgrade routine, a version header mismatch — surfaces here rather than in production.
*Alternative rejected:* `wp plugin install --force` over SSH, which runs the same
unpacking code but skips the upgrade screen the requirement is about.

**Settings are driven through the React panel, not WP-CLI.** Every settings combination the
matrix needs is applied by clicking the panel and waiting for its save confirmation. That
makes the panel's persistence part of what the run verifies, and it means the run fails
loudly if the settings API regresses. The cost is speed — roughly a dozen settings
transitions per run — which is acceptable for a pre-release check. *Alternative rejected:*
`wp option update --format=json`, faster but it would silently paper over a broken panel.

**The debug log is truncated before each scenario.** Whatever is in the file afterwards
belongs to that scenario, so "no event was sent" is a direct assertion on an empty file
rather than an inference from a diff. The run loses log history, which does not matter
because relevant excerpts are copied into the report directory as they are read.
*Alternative rejected:* recording the byte offset and reading the tail — preserves history
but makes every assertion depend on correct offset bookkeeping across an async pipeline.

**Run the whole matrix, never stop at the first failure.** A run costs roughly half an
hour against a live site, so stopping early would spend that time to learn about exactly
one defect and hide the rest until the next run. Cascading noise is avoided by making each
scenario establish its own starting state — carts cleared server-side, log truncated,
settings preset applied — rather than by aborting. *Alternative rejected:* `maxFailures: 1`,
which was the original choice and proved to be the wrong trade for a slow, expensive suite.

**Preconditions are checked in code, not by the operator.** A `globalSetup` verifies over
SSH that the site is reachable and holds every fixture the matrix names before a browser
opens. A missing coupon then fails in the first seconds with a message naming it, instead
of surfacing thirty minutes in as a confusing assertion. *Alternative rejected:* a checklist
in the skill for the operator to eyeball — anything a machine can verify should not be a
human's job.

**Both passes of the matrix run: guest and signed-in customer.** Guest is the common
storefront path; the signed-in customer is the only way to reach the address-derived
`user_data` case, and running the whole matrix under both catches session-scoped
regressions in the hold/flush logic.

**Grouped and external products on the shop listing expect no event.** Their listing
controls navigate rather than add to the cart, so a logged AddToCart there would be wrong.
This is asserted as an explicit negative, not skipped, so a future regression that starts
firing on navigation is caught.

**Browser runs headed.** The operator watches the run; headed mode makes a stuck scenario
diagnosable at a glance and matches how the site is used.

**Nothing is rolled back.** Settings, plugin activation states and test orders are left as
the run left them, so a failure can be inspected on the live site immediately afterwards.
The next run configures whatever it needs, so no run depends on the previous one's cleanup.

## Risks / Trade-offs

- **The run mutates a live site and sends real events.** → The site is a dedicated test
  install on a test Pixelflow account, explicitly sanctioned for this. The skill touches
  only the the live test site tree.
- **Events are dispatched asynchronously; the log may lag the browser action.** → Log reads
  poll with a bounded timeout rather than reading once, and the timeout failure message
  distinguishes "no record appeared" from "wrong record appeared".
- **A failed deploy can leave the site with a half-installed plugin.** → The deploy step
  asserts version and active state before any scenario runs, and aborts with the WordPress
  error text otherwise, so the run never proceeds against an unknown build.
- **The 1 MB log trim could discard records mid-scenario.** → No single scenario produces
  anything near 1 MB after truncation, so the trim is unreachable in practice; if a
  scenario ever approached it, the polling read would report a missing record rather than
  passing silently.
- **The skill hardcodes site-specific selectors and fixture SKUs.** → Both are pinned to a
  site whose state is recorded and reproducible; a fixture change breaks the run loudly at
  its first scenario rather than producing a wrong pass.
- **Headed, full-matrix, UI-driven settings makes the run slow (tens of minutes).** → It is
  a pre-release check, not an inner-loop test. Accepted deliberately over a fast mode that
  would drift out of sync with the full one.
