# Live WooCommerce event verification

Runs the whole WooCommerce event matrix against the **live test site**, using the
plugin's own debug log as the oracle. Real events are sent to a test Pixelflow
account and test orders accumulate on the site.

This suite is deliberately separate from `../playwright.config.ts` (the admin UI
tests) so it can never run by accident.

## Prerequisites

- `cp .env.example .env`, then fill in every value. The site's address, SSH host, key path and
  WordPress root have no defaults in the repository — the suite refuses to start without them
  rather than pointing somewhere unintended:

  | Variable | What it is |
  | --- | --- |
  | `PF_BASE_URL` | the storefront's address |
  | `PF_SSH_HOST` | `user@host` for the deploy account |
  | `PF_SSH_KEY` | path to that account's private key |
  | `PF_WP_ROOT` | the WordPress root on the server |

  The WordPress passwords (`PF_ADMIN_PASS`, `PF_CUSTOMER_PASS`) go in the same file.
- Site fixtures in place: the `pf-fixtures` product category, the `discount`
  coupon, and the `pfcustomer` account with a billing address.

## Running

```sh
./scripts/run.sh              # build, deploy, smoke, full matrix
./scripts/run.sh tests/purchase.spec.ts   # extra args go to Playwright
```

The run stops at the first failing scenario. Artifacts land in a temp directory
whose path is printed at the start and end of the run.
