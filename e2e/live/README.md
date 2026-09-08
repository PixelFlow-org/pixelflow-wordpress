# Live WooCommerce event verification

Runs the whole WooCommerce event matrix against the **rift test site**, using the
plugin's own debug log as the oracle. Real events are sent to a test Pixelflow
account and test orders accumulate on the site.

This suite is deliberately separate from `../playwright.config.ts` (the admin UI
tests) so it can never run by accident.

## Prerequisites

- SSH access to the test site as the `claude` user (key at `~/.claude/keys/rift`).
- `cp .env.example .env` and fill in the WordPress passwords.
- Site fixtures in place: the `pf-fixtures` product category, the `discount`
  coupon, and the `pfcustomer` account with a billing address.

## Running

```sh
./scripts/run.sh              # build, deploy, smoke, full matrix
./scripts/run.sh tests/purchase.spec.ts   # extra args go to Playwright
```

The run stops at the first failing scenario. Artifacts land in a temp directory
whose path is printed at the start and end of the run.
