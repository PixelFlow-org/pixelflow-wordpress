## Why

The PixelFlow service now forwards events to TikTok as well as Meta, and TikTok matches a
server-side event to an ad click and a browser only through its own identifiers: `ttp` (the
browser id TikTok's pixel keeps in the `_ttp` cookie) and `ttclid` (the ad-click id, which
PixelFlow's browser script records in the `_pf_click_ids` cookie). The WooCommerce events this
plugin sends carry the Meta equivalents (`fbp`, `fbc`) but neither TikTok id, so TikTok cannot
attribute a WooCommerce AddToCart, InitiateCheckout or Purchase to the campaign that produced it.

PR #24 adds both ids. Its review found the code correct but left two Plugin Check warnings, gaps
in test coverage, and no spec of record for the new fields; this change covers the feature and
that follow-up together so the behaviour ships with a written contract.

## What Changes

- AddToCart, InitiateCheckout and the replay of a held storefront event add `eventData.ttp` from
  the live `_ttp` cookie and `eventData.ttclid` from the `ttclid` key of the live `_pf_click_ids`
  query-string cookie. Other keys in `_pf_click_ids` (e.g. `gclid`) are not forwarded.
- At order creation the plugin saves `_ttp` and `_pf_click_ids` to order meta
  (`_pf_cookie__ttp`, `_pf_cookie__pf_click_ids`), alongside the cookies it already saves.
- Purchase takes each id from order meta first, and falls back to the live cookie only when the
  request belongs to the buyer — the same rule already applied to `fbp` and `fbc`.
- A field whose source is absent or empty is omitted; the plugin never mints a TikTok id.
- The WooCommerce debug log lists `_ttp` and `_pf_click_ids` among the cookies it records.
- The two live cookie reads are sanitized inline, clearing the Plugin Check
  `InputNotSanitized` warnings on `includes/helpers.php`.
- Tests: the live-cookie cases move to a dedicated `tests/test-tiktok-cookies.php`; new cases
  drive AddToCart, InitiateCheckout, held-event replay and the debug log through their real code
  paths, and cover an order that saved only one of the two ids, in either direction. A live
  storefront spec checks the same ids on the test site, and the scenarios join the test matrix.
- Version 1.1.19 → 1.1.20 with a one-line changelog entry.

## Capabilities

### New Capabilities
- `tiktok-identifiers`: which WooCommerce events carry `ttp` and `ttclid`, where each value comes
  from, the precedence between saved order meta and live cookies, and when a field is omitted.

### Modified Capabilities
<!-- None: consent gating, bot filtering and external_id are unchanged. -->

## Impact

- Code: `includes/helpers.php` (`pixelflow_append_cookie_params()` and the new TikTok helpers),
  `includes/woo/hooks/class-woocommerce-hooks.php` (order-meta save, Purchase cookie params,
  debug log).
- Tests: `tests/test-tiktok-cookies.php` (new), `tests/test-configuration-gate.php` (two cases
  moved out), `tests/test-automation-dedupe-release.php`, `tests/test-held-replay-context.php`,
  `tests/test-purchase-customer-data.php`, `e2e/live/tests/tiktok-ids.spec.ts` (new),
  `docs/test-scenarios.html`.
- Outbound payload: two optional fields in `eventData`; no field is removed or renamed.
- Release: `pixelflow.php`, `readme.txt`, `README.md` (version 1.1.20).
- Delivery: commits on the PR #24 branch `fix/tiktok-ttp-ttclid`, plus a short "review
  follow-up" comment on the PR.
