# Live run reliability — observed failures

Notes from a single long session (2026-09-07/08) of running `e2e/live` against
`rift.kskonovalov.me`. Sixteen runs were started; **five did not produce a usable verdict**,
and several more produced a verdict that had to be re-read because the reporter's own output
was ambiguous. None of these were product defects — they are costs of the harness and the way
it is driven. Collected here so they can be fixed rather than re-discovered.

## 1. Runs killed for memory, mid-flight

Two runs were killed outright by the system ("running low on memory"), and one more ended
with no summary line at all — its log stopped at test 18 of 20.

| Run | Tests started | Outcome |
| --- | --- | --- |
| `live-final` | 4 of 20 | killed, no summary |
| `fix56` | 0 | killed before the first test |
| `live-final2` | 20 | log truncated at 18, summary only appeared later |

Free memory at the time was 22–24 GB of 64 GB, so this is not a machine that is out of
memory — it is Playwright plus Chromium plus the rest of the desktop crossing a threshold.

**Why it hurts more than it looks:** Playwright's `list` reporter prints failure *bodies*
only at the end of a run. A killed run therefore leaves a list of ✘ marks with no reasons at
all, and the whole run has to be repeated to learn why anything failed.

Worth trying: `--reporter=list,json` is already configured, so read `results.json` instead of
the log tail; or switch to a reporter that prints each failure inline as it happens
(`--reporter=line` does not help; a small custom reporter or `--reporter=github` does).
Reducing peak memory is the other half — the suite already runs `workers: 1`, so the
remaining lever is not keeping traces/videos for passing tests (`trace: 'retain-on-failure'`
is already set) and closing the browser between spec files.

## 2. Headless was not an option before this session

`playwright.config.ts` had `headless: false` hard-coded, so every run needed a display and
opened windows. It is now `headless: !HEADED`, headless by default, with `PF_HEADED=1` to
watch a run.

**The trap that made this non-trivial:** Chromium's headless user agent contains
`HeadlessChrome`, which the plugin's own bot filter matches. A naive switch to headless makes
every event log `EVENT SENDING SKIPPED BECAUSE USER AGENT MATCHED BOT SIGNATURE` — the suite
goes green while sending nothing. The config therefore overrides `userAgent` for headless
runs only. If that override is ever removed, the suite will quietly stop proving anything.

## 3. Shell working directory silently resets between commands

Two runs failed before starting with `cd: e2e/live: No such file or directory` and
`/run.log: Permission denied` — the command assumed a relative path from the plugin root
while the shell had been left somewhere else by a previous command.

Cheap fix: `scripts/run.sh` already resolves its own directory; every invocation should go
through it, or use absolute paths. A `--grep` pass-through in `run.sh` would remove the main
reason to bypass it (running a single spec).

## 4. The reporter's progress lines and its final tally disagree

Three times a test was reported ✘ while streaming, and the completed log showed the same
numbered test as ✓ with a different duration — for example
`✘ 15 … stranger opening its order-received URL (33.8s)` while the final log holds
`✓ 15 … (55.2s)`.

The final summary was correct each time. Anything that watches the stream rather than the
finished file will draw wrong conclusions; treat only the completed run as authoritative.

## 5. Real network flakes, low rate but they cost a whole scenario

- `page.goto: net::ERR_TIMED_OUT` on the fixture listing (once)
- `page.goto: net::ERR_ABORTED` on the settings page **inside `beforeAll`** (once) — this one
  took the rest of the file with it: the next scenario was reported as failed in 0 ms, having
  never run.

`retries: 0` is deliberate for a suite that asserts on a shared debug log, but a `beforeAll`
that applies settings is pure setup and could retry safely on a navigation error.

## 6. Log noise from the site itself

36 lines of `sh: 1: /usr/sbin/sendmail: not found` and
`touch(): Utime failed: Operation not permitted` are interleaved into the run output by
WordPress. Harmless, but they break `grep`-based reading of the log and pad every artifact.
Silencing them on the site (or filtering them in `run.sh`) would make the logs readable.

## 7. Scenario-level isolation gaps found while chasing the above

Not infrastructure, but they produced the same symptom — a red run with no product defect
behind it — so they belong in the same list:

- `wp cron event run <hook>` fires **every** event registered under a hook, not only the ones
  that are due, so one scenario's cron run reported three orders left over from earlier
  scenarios. Fixed by clearing other orders' pending reports first.
- A checkout paid with an offline gateway already leaves the order in `processing`, so a
  scenario that "changes the status to processing" fires no transition and no hook at all.
  Fixed by transitioning to `completed`.
- The plugin's purchase hooks are **not attached in a WP-CLI request** — verified on the site:
  the singleton exists and `order_has_reported_lines()` is true, yet
  `has_action('woocommerce_order_status_processing', [$instance, 'pf_purchase_hook'])` is
  false. A wp-cli status change runs no PixelFlow logic, which reads as a plugin defect and is
  not one. Scenarios that need those hooks must go through wp-admin.
