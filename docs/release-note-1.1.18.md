# PixelFlow 1.1.18 — customer-facing release note

Two changes in this release are visible in Meta dashboards. Both are intended, and both should
be announced before the plugin is rolled out rather than explained afterwards.

## Reported AddToCart and InitiateCheckout volume falls

How far it falls depends on how much of a store's traffic is automated, and that varies between
sites — the figure will be visible in the dashboards within days of the release. On the sample this
change was designed from, junk was a noticeable share of both events.

The plugin now refuses to report three kinds of request that were never shopper activity:

- **Automation clients.** Five signatures were added to the bot list — `guzzle`, `httpx`,
  `aiohttp`, the `scrapy` scraping framework and Meta's `meta-externalads` — plus a
  `meta-external` catch-all that suppresses any
  further crawler in that family without waiting for a plugin release. The two Meta agents we see
  in production keep reporting themselves, so nothing that is separable today stops being
  separable; only an unrecognised family member is counted under the generic `meta-external`.
  The three general-purpose HTTP libraries never suppress a Purchase: a headless storefront or a
  mobile app makes the buyer's own request through one, and an order in the database is evidence
  that a person paid. A store whose own integration is filtered on the other two events can remove
  any signature with the `pixelflow_useragent_bot_patterns` filter, documented in `readme.txt`.
- **Speculative browser prefetch.** A request that declares itself as prefetch or prerender has
  had no human act on it.
- **Anonymous cookieless add-to-cart URLs.** WooCommerce adds to the cart whenever an
  `add-to-cart` parameter reaches it, whatever the request method, so crawlers following such a
  link produced cart activity with nobody behind it. The rule applies to the classic link however
  it is requested; an ordinary add-to-cart form, which submits in the request body, is outside it. A request is withheld only when it carries neither the visitor cookie nor the
  Facebook browser cookie **and** its headers show no browser navigation. Visitors running an ad
  blocker have neither cookie — both are written by JavaScript — so the header test is what keeps
  their events flowing; they are exactly the shoppers server-side tracking exists to recover.

Roughly one event in seven was junk of one of these kinds, so the drop is a correction, not a
loss. The withheld volume is still visible on the anonymous blocked-events channel, separated by
rule, so the numbers can be reconciled.

`guzzle`, `httpx` and `aiohttp` are general-purpose HTTP libraries that a store's own mobile app
or partner integration may legitimately use. If that happens, the site's debug log now names the
matched signature, and the `pixelflow_useragent_bot_patterns` filter removes it — see
"Filters for Developers" in the plugin readme.

## Guest identity changes over in one step

Previously the plugin sent `external_id` only when it knew a WordPress user or an order; for a
guest it sent nothing, and the backend substituted a single site-level constant. Every guest on a
site therefore looked like one person to Meta, and the browse → cart → purchase chain was broken
for all of them.

The plugin now derives `external_id` itself from the visitor id, with the same formula the
PixelFlow browser script uses, so a shopper's server-side and browser events resolve to one
person. Two consequences:

- **Audiences and attribution windows keyed on the old identifier do not carry over.** The old
  values were a site constant and hashes of account, email and order identifiers; none of them is
  produced any more.
- **Events with no visitor id now arrive with no `external_id` at all** — a shopper whose browser
  blocked the script, for instance. They still carry the hashed email in `em` and the Facebook
  browser cookie in `fbp`, which is where Meta matches on them.

The backend is removing the site-constant substitution in the same window. **Coordinate the
release order with the backend team** so the two changes do not land far apart; a long gap in
either direction makes the numbers hard to read.

A site that wants the previous behaviour back can restore it in code, without downgrading the
plugin, through the `pixelflow_external_id` filter documented in the readme.

## A site missing its credentials now stops sending events

If either the site identifier or the API key is empty, the plugin no longer sends events or
blocked-event reports. That traffic was never accepted by the API, so nothing of value is lost. It
keeps recording what it will need once the credentials are back — the tracking snapshot on new
orders and the visitor's consent decision — so a temporary misconfiguration costs no data.
