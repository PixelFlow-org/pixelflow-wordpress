## Why

A seven-day export of WooCommerce events from a sample of live client sites shows two
independent defects in what this plugin sends to the PixelFlow API. Figures below are given as
proportions of that sample; the underlying per-site data stays in the analytics backend.

**Shoppers are not distinguished from one another.** The plugin sets `external_id` only when it
knows a WordPress user or an order; for a guest it omits the field entirely. The PixelFlow
backend then substitutes a single site-level constant — proven to be exactly
`sha256(site_external_id)`, byte-for-byte on every site checked. The result is that roughly
seven in ten server-side events carried one identifier. Most of those events *did* carry a
`visitor_id` and still got the constant, because the backend keys its fallback on the absence of
the `external_id` field, not on the absence of a visitor. Meta therefore sees one shopper who
added to cart thousands of times, and the browse → cart → purchase chain is broken for every
guest.

**Roughly one event in seven is not a shopper at all.** A small number came from automation
whose user agents are missing from the bot list (`meta-externalads`, `guzzle`, `httpx`,
`aiohttp`) or from browser speculative prefetch; the large majority came from `?add-to-cart=`
GET URLs requested by crawlers and prefetchers with no browser cookies at all. Both inflate reported volume and
degrade Meta's campaign optimisation.

Now, because both defects corrupt the same data the customer is paying for, and both are cheap
to fix in one release.

## What Changes

**Identity**

- The plugin computes `external_id` itself for every event, using the same formula as the
  PixelFlow browser script: `sha256(site_external_id + '_' + visitor_id)`. The formula was
  verified against every one of the script's own events in the export (no exceptions; three
  alternative orderings and separators match none of them).
- The visitor id is the only source. It comes from the attribution payload, the order meta
  `_pf_cookie__pf_uid`, or the live `_pf_uid` cookie, in that order — the order-meta step matters
  because Purchase often fires outside the shopper's request, when no cookies are present. When
  none of the three resolves, the field is omitted. The backend team asked for one format shared
  with the browser script, and the script emits only this one.
- **BREAKING for downstream identity:** `sha256(user_id)`, `sha256(email)` and
  `sha256(order_id)` stop being used as `external_id`, and no namespaced fallback replaces them.
  The hashed email keeps travelling in `em` and the Facebook browser cookie in `fbp`, where Meta
  already matches on them. Existing audiences and attribution windows keyed on the old values
  will not carry over.
- A `pixelflow_external_id` PHP filter lets a site override the derived value, suppress it, or
  supply one where none resolved — restoring the previous behaviour without a plugin downgrade. It
  runs on every event and receives the event context, whose documented keys become part of the
  public contract. The override is code-level by design: a filter, not a setting.
- Events with no `visitor_id` are sent with no `external_id`. The backend team has confirmed they
  are removing the `sha256(site_external_id)` substitution that currently fills the gap, so these
  events will arrive genuinely unidentified rather than collapsed onto a site constant. The two
  releases need to be coordinated, not ordered strictly.

**Noise**

- Add `guzzle`, `httpx` and `aiohttp` to the bot user-agent signatures, add the newly observed
  `meta-externalads`, and add a `meta-external` prefix entry behind both Meta agents as a
  catch-all. Meta ships a family of crawlers under that prefix, and a list of exact agents means a
  plugin release every time Meta adds one. Ordering the two known agents ahead of the prefix keeps
  their reported `detail` exact, so the backend can still separate the ads crawler from the agent
  crawler, while an unrecognised family member is still suppressed and reported as the generic
  `meta-external`. Order is load-bearing here rather than incidental: signatures are matched as
  substrings and the resolver returns the first match, so a specific entry must precede the prefix
  that subsumes it.
- Treat a request carrying `Sec-Purpose: prefetch` or `Purpose: prefetch` as automated, reported
  and logged on the same terms as a user-agent match (`detail` `prefetch_header`).
- Name the matched signature in the site's own debug log when an event is suppressed as a bot,
  instead of the unattributable literal `BOT_UA`, and document the existing
  `pixelflow_useragent_bot_patterns` filter in `readme.txt`. The anonymous `/blocked-events`
  telemetry already carries the matched pattern in `detail`; only the local log does not.
  `guzzle`, `httpx` and `aiohttp` are general-purpose HTTP libraries that a store's own mobile
  app or integration may legitimately use; a misfire must be diagnosable and removable by the
  site owner.
- Do not send AddToCart for a `?add-to-cart=` GET request that carries neither `_pf_uid` nor
  `_fbp`. A real shopper with browsing history keeps both; an anonymous crawler has neither.
  The rule is scoped by the request itself — a GET carrying an `add-to-cart` query parameter —
  so AJAX, Store API and POST-form adds fall outside it by construction.
- Remove the dead cookie paths `pf_clkid`, `clkId` and the PHP reads of `pf_fbc`, in the event
  payload and in the debug log alike; read `_fbc` directly, as `_fbp` already is. The browser
  script stopped creating the old names.

**Not in scope**

- The wp-admin notice telling an unconfigured site why it is silent. It was implemented
  (`display_unconfigured_notice()` in `pixelflow.php`) and ships switched off: it renders only
  when the `pixelflow_show_unconfigured_notice` filter returns true, and defaults to false while
  its wording and placement are reworked. The requirement comes back with that change. The credential gate itself ships — a site without both credentials sends
  nothing, silently.

- Event de-duplication. A guard already exists in `should_send_event()`, and the product
  behaviour it encodes is correct (a repeat click on a product already in the cart is a
  separate event, by design). Its storage has weaknesses, but only one is proven from data —
  it is skipped entirely when `WC()->session` is absent — and an unknown share of the observed
  duplicates may already have been fixed by 1.1.17, which no site in the export was running. Re-measure after the fleet upgrades before changing it.
- The backend's own removal of the `sha256(site_external_id)` substitution. That work is agreed
  and owned by the backend team; this change only stops depending on the substitution.

## Capabilities

### New Capabilities
- `event-identity`: how the plugin derives and sends the identifier that lets Meta recognise a
  shopper across events — resolution order, hashing formula, the single-format guarantee shared
  with the browser script, and the extension point for overriding it.
- `event-noise-filtering`: which events the plugin refuses to send — automation detection by
  user agent and by prefetch headers, cookieless add-to-cart URLs, and the diagnosability of a
  suppression.

### Modified Capabilities
- `consent-resolution`: the anonymous blocked-events contract gains a second producer of `bot`
  rows. `detail` on such a row is now either the matched user-agent pattern, as before, or a fixed
  identifier naming the rule that suppressed the event — `prefetch_header` for a speculative
  prefetch, `no_cookies_in_wp_plugin` for the cookieless add-to-cart rule. Nothing else about consent resolution changes; the identity cookies
  and the decision logic are untouched.
- **Release order — this change is not independent of `fix-consent-gating-review`.** That change
  is older, still unarchived, and rewrites this same requirement from the same baseline, with a
  different Purchase condition (`not resolved within its reporting window` rather than
  `skipped for a hold`) and a `client_ip_address` field this change's payload text does not carry.
  `openspec validate --strict` checks each change against the baseline in isolation and cannot see
  the collision, so archiving them in the wrong order would silently drop one set of edits.
  `fix-consent-gating-review` is archived first; this delta is then rebased onto the requirement
  text it leaves behind — keeping the reporting-window condition and the `client_ip_address`
  field, and adding only the `detail` rules above.

## Impact

- `includes/woo/hooks/class-woocommerce-hooks.php` — `build_customer_data_from_current_user()`
  (:1379) and `build_customer_data_from_order()` (:1417) stop deriving `external_id` from user id,
  email and order id; `post_event()` (:1868) resolves the identity once, after its `$context` is
  unpacked, and writes it into `customerData` for every event type; `pf_add_to_cart_hook()` (:152) gains the cookieless add-to-cart rule; the cookie
  list (:628), the cookie maps (:1230) and the debug-log key list (:1803) lose the dead names;
  the bot skip's debug line (:1947) names the matched signature instead of `BOT_UA`.
- `includes/woo/class-woocommerce-integration.php` — `load_hooks()` (:52) gains the
  configuration gate.
- `pixelflow.php` — `display_unconfigured_notice()`, off by default behind the
  `pixelflow_show_unconfigured_notice` filter until the notice is reworked (see Not in scope).
- `includes/helpers.php` — `PIXELFLOW_BOT_PATTERNS` (:~715) gains four signatures; the default
  cookie map (:679) loses the dead names; `pixelflow_resolve_attribution_visitor_id()` (:572)
  becomes the single source of the visitor id for identity as well as attribution. The existing
  `pixelflow_get_bot_detail_pattern()` (`includes/blocked-events.php:42`) already returns the
  matched signature and is reused unchanged.
- Backend coordination: `/blocked-events` gains `bot` rows whose `detail` is not a user-agent
  pattern. `bot` rows may also carry the new value `meta-external` when a Meta crawler outside
  the two known agents is filtered; `meta-externalagent` keeps reporting exactly as it does today. The plugin does not allow-list `detail`, but the ingest may — confirm before release,
  and confirm that bot volume is aggregated by `(reason, detail)` so the two detection methods
  stay separable.
- `readme.txt`, `README.md` — changelog entry for 1.1.18 and documentation of the
  `pixelflow_useragent_bot_patterns` and `pixelflow_external_id` filters.
- Client-visible: reported AddToCart and InitiateCheckout volume falls as junk
  stops being sent, and guest identity changes over in one step. Both are intended, both are
  visible in Meta dashboards, and both must be announced before release. A site missing either
  setting also stops sending events entirely; for now it does so without an admin notice.
- No database schema change and no new dependency. No admin-facing change ships in this
  release; the React settings app is untouched.
