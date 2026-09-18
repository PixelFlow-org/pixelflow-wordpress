## Context

See `proposal.md` — Why. Three facts from the export constrain the approach and are worth
stating once, because every decision below follows from them.

1. **The browser script's formula is known, not assumed.** `sha256(site_external_id + '_' +
   visitor_id)` reproduces the `external_id` on every event emitted by the script itself in the
   export, with no exceptions. `sha256(visitor_id)`, `sha256(site_external_id + visitor_id)` and
   `sha256(visitor_id + '_' + site_external_id)` each reproduce zero.
2. **The backend's fallback is known, not assumed.** Where the plugin sends no `external_id`,
   the stored value is exactly `sha256(site_external_id)` — verified byte-for-byte on several
   sites in the export. The plugin cannot produce that value:
   `site_external_id` is never hashed anywhere in the codebase. The backend team has since
   confirmed the substitution was a deliberate EMQ measure and is being removed, so this
   describes the state this change leaves behind, not the state it designs for.
3. **The fallback is triggered by the missing field, not by a missing visitor.** Most of the
   affected events carried a `visitor_id`, across a large number of distinct visitors, and still
   received the constant. Improving `visitor_id` coverage therefore cannot fix this; only sending the field
   can.

The plumbing needed already exists. `pixelflow_resolve_attribution_visitor_id()`
(`includes/helpers.php:572`) resolves the visitor id from the attribution payload, then an
order-meta override, then the live `_pf_uid` cookie; and `pf_save_tracking_cookies_to_order()`
(`includes/woo/hooks/class-woocommerce-hooks.php:616`) already persists `_pf_cookie__pf_uid` on
the order. Neither is currently consulted when building `external_id`.

## Goals / Non-Goals

**Goals:**

- One identity function, used by every server-side event path, whose primary output is
  byte-identical to the browser script's.
- Junk filtered as early as the request allows, with every suppression attributable to a named
  cause.
- No new storage and no new dependency. The only admin-facing addition is a notice telling an
  unconfigured site why it is silent; the React settings app is not touched.

**Non-Goals:**

- Improving `visitor_id` coverage. The plugin only reads `_pf_uid`; the browser script owns it.
  Creating it server-side would cross into the script's territory and, from 1.1.17 onwards,
  into consent territory. Out of scope here, raised with the backend team instead.
- De-duplication. See `proposal.md` — Not in scope.
- Removing the backend's site-constant substitution. Agreed and owned by the backend team; the
  plugin's job is only to stop depending on it.

## Decisions

### Match the script's formula rather than choosing a better one

A namespaced separator such as `'_pixelflow_'` would be the safer construction in isolation —
it removes any chance of a concatenation collision. It is rejected for the primary path because
the formula is already fixed in the browser script: a server value that differs by one byte
produces a second identity for the same person, which is the failure this change exists to
remove. Concatenation ambiguity is not a live risk here anyway, because `site_external_id` has a
fixed shape (`wp_` + 32 hex characters), so the boundary between the two parts is unambiguous.
That shape is an observation from the exported data, not something the plugin enforces: the value
arrives from the backend through `pixelflow_script_params` and is never validated locally. The
formula does not depend on the argument — the script fixes it either way — but if the shape ever
changes, this particular reassurance stops holding.

Changing the separator remains possible, but only as a coordinated change across script,
plugin and backend. It is raised as a question to the backend team, not decided here.

### The visitor id is the only identity source; there are no fallbacks

An earlier draft resolved `visitor_id` → WordPress user id → `_fbp`, with the two fallbacks
namespaced (`_u_`, `_f_`) so a user id and a visitor id of the same literal value could not hash
alike. The backend team ruled that out: the plugin and the script must emit one format, and when
no visitor id resolves the field is to be omitted.

That is the right call on its own merits. A `_u_`-namespaced hash is a value the browser script
never produces, so it cannot join a server event to that same person's script events — it defeats
the single-format goal rather than extending it. And Meta already receives `fbp` and `em` as their
own fields and matches on them, so an `external_id` derived from `_fbp` adds no matching power at
all; its only effect was to stop the backend substituting the site constant, which the backend is
removing anyway. Dropping both branches also retires the namespacing question, since only the
unmarked primary path remains.

The cost is honest and small: a shopper whose browser blocked the script leaves no visitor id, so
their server-side events now carry no identity rather than a weak one. Those events still carry
`em`, `fbp` and the rest of the customer data.

### Order Purchase reads the visitor id from order meta

Purchase frequently fires outside the shopper's request — a gateway callback, a status change in
wp-admin, a scheduled task — where `$_COOKIE` is empty. Reading the live cookie at send time
would silently lose identity for exactly those orders. The order-meta value captured at order
creation is therefore the authoritative source on that path, with the live cookie as a fallback
only when the request demonstrably belongs to the buyer.

### Filter the cookieless add-to-cart URL on cookies, not on the URL alone

Dropping every `?add-to-cart=` GET would be simpler, but some themes put those links on product
pages that real shoppers use — on one site in the export, every such event came from a
product page. So the rule tests the request instead.

Testing it on cookies alone is not enough, and the reason is close to the heart of the product.
Both `_pf_uid` and `_fbp` are written by JavaScript, and a mainstream ad blocker stops both. For
that visitor the server-side event is the only signal that survives — which is what this plugin is
sold to recover: the readme's first line promises to bypass ad blockers, and the README puts the
loss at 30-50% of conversions. A rule keyed on cookie absence alone cannot tell that shopper from
a crawler, so on classic links it would delete exactly the conversions the customer is paying to
get back.

The fix is to require positive evidence to be missing too, not just cookies. A browser navigation
announces itself: `Sec-Fetch-Mode: navigate`, and an `Accept-Language` that HTTP client libraries
do not send by default. Either one is enough to spare the request, deliberately — `Sec-Fetch-*` is
not universal across browser versions, and requiring both signals would put old browsers back in
the crawler bucket next to the ad-blocked ones. An event is withheld only when the cookies are
absent *and* nothing in the headers speaks for a browser.

This does not catch a headless-Chrome crawler, which sends all of these. That is the signature
list's job, and it already covers it. What this rule targets is the bare client that follows links
and runs nothing, which is where the volume was.

Worth recording plainly: the claim that the cookieless add-to-cart traffic in the export was
crawlers and prefetchers is an interpretation of the aggregate, not something verified request by
request. Requiring the headers to agree is what makes the rule safe to ship on an unverified
premise — a wrong guess now costs nothing rather than costing conversions.

The rule is scoped by the shape of the request: `$_SERVER['REQUEST_METHOD'] === 'GET'` together
with an `add-to-cart` key in `$_GET`. `woocommerce_add_to_cart` fires identically for the classic
link, the AJAX endpoint, the Store API and a POST form, so the scope has to come from the request
rather than from the hook. That one condition is also the whole of the carve-out: the classic
AJAX add posts `product_id` to `?wc-ajax=add_to_cart`, the Store API posts to
`/wp-json/wc/store/…`, and the product-page form posts — none of them set `$_GET['add-to-cart']`.
No `wp_doing_ajax()` or `REST_REQUEST` probe is needed, and adding one would only widen the rule
beyond the traffic it targets. The threat model supports the narrow scope: crawlers and
prefetchers run no JavaScript, so they only ever follow links, never issue a script-initiated
add.

### Every automation rule reports through one mechanism

Three rules now classify a request as automated: the user-agent signature list, the prefetch
headers, and the cookieless `add-to-cart` GET. They share one path rather than growing three.
`pixelflow_resolve_blocked_event_reason()` (`includes/blocked-events.php:75`) already returns
`reason` `bot` for any non-empty detail it is handed, so each rule only has to supply its own
detail string — the matched signature, `prefetch_header`, or `no_cookies_in_wp_plugin`. The debug
line in `hold_or_block_event()` (`class-woocommerce-hooks.php:1947`) then prints whichever one
fired, and the beacon carries the same value.

The alternative — a separate log line and a separate reporting decision per rule — is how the
`(BOT_UA)` literal came about in the first place: a message written for one rule that stopped
being accurate once a second existed. One mechanism also keeps the backend's `(reason, detail)`
breakdown meaningful, since every automation suppression appears there under its own name.

### One cause per suppression, in a fixed precedence

With three rules able to fire on the same request — a crawler following an `add-to-cart` link
matches the user-agent list and the cookieless rule at once — the reported cause has to be
decided rather than left to whichever branch runs first. The order is: matched user-agent
signature, then prefetch header, then the rule supplied by the calling path.

It runs most-specific first. A matched signature names an actual client; a prefetch header names
a browser behaviour; the cookieless rule is the weakest evidence, an inference from absence. If
the request tells us plainly what it is, that answer is better than our inference. Leaving the
order to code structure instead would tie the backend's `(reason, detail)` breakdown to call
order, so the same traffic could shift between buckets after an unrelated refactor.

**The consent state sits between them, and this is not a detail.** An inference from absence is
only sound while nothing else explains the absence — and a consent decision that is pending or
declined explains it exactly. `_pf_uid` and `_fbp` are both marketing cookies, withheld until
consent is granted (the functional set the plugin declares is `_pf_consent`,
`_pf_no_consent_decision`, `_pf_consent_source` and `_pf_held_woo_events`, none of which stores a
visitor identifier). So a real shopper who has not yet answered the banner and clicks a classic
`?add-to-cart=` link looks exactly like the crawler this rule targets.

Ranking the rule above the consent checks destroys that shopper's event: `bot` is returned before
the hold is ever tested, and `pixelflow_should_queue_held_event()` queues only `no_decision`, so
the event cannot be held and can never be replayed on a grant. It is also invisible — it lands in
the bot bucket, where a rising count reads as the junk filter working. That is the population
held events exist to serve, so the rule is ranked below the consent state instead.

The gate costs nothing against real automation. The consent cookies are written by the browser
script, so a client running no JavaScript carries none of them; the hold is therefore false for a
genuine crawler and the rule still fires. Only a request that proves a human is deciding gets the
benefit of the doubt.

`pixelflow_resolve_bot_detail()` holds this in one place and `post_event()` calls it, so the log
line and the beacon cannot disagree about which rule fired.

### The cookieless add-to-cart skip is reported as a bot suppression

The skip removes a material share of reported add-to-cart volume, so it needs to be visible
rather than silently absent — a drop that size looks like a plugin failure if nothing accounts
for it.

The reason enum is closed: `pixelflow_build_blocked_events_payload()`
(`includes/blocked-events.php:177`) drops any row whose `reason` is outside
`PIXELFLOW_BLOCKED_EVENT_REASONS` (`:14`) — `denied`, `no_decision`, `bot`. A fourth reason would
mean a plugin change, a delta spec and a backend release before the plugin could send anything. So
the row reuses `reason: bot`, which is what the rule actually asserts: no browser cookies on an
`add-to-cart` GET means no browser ran, which means no human. `detail` carries
`no_cookies_in_wp_plugin` to keep the two detection methods apart. That field is not allow-listed
on the plugin side — on a `bot` row it is trimmed and passed through as-is (`:186-191`) — so no
code change is needed to carry it.

The cost is that `consent-resolution` stops being untouched by this change. Its current text pins
a `bot` row's `detail` to the matched user-agent pattern, which was true while the user agent was
the only bot signal. A MODIFIED delta widens that to "the matched pattern, or a fixed identifier
naming the rule" and adds the scenario for this rule — a small, honest widening rather than a
contract break.

**Still to confirm with the backend before release:** whether the `/blocked-events` ingest
allow-lists `detail` on its side (the plugin does not), and whether bot volume is aggregated by
`(reason, detail)` rather than by `reason` alone — otherwise these suppressions merge into the
user-agent bot count and neither number means much.

### Bot signatures stay generic, with diagnosability as the price

`guzzle`, `httpx` and `aiohttp` are general-purpose HTTP libraries. On a storefront they are
overwhelmingly automation, but a store's own mobile app or a partner integration can legitimately
use one, and a misfire currently loses every event from that client with nothing in the site's
own log but the literal `BOT_UA`. `PIXELFLOW_BOT_PATTERNS` is otherwise made of unambiguous
automation markers (`crawler`, `spider`, `headless`, named crawler agents); the one broad entry,
`bot`, is broad only in a direction that cannot match a real browser. The three new library
signatures are the first that can. The signatures are kept because the field data supports them;
the mitigation is to name the matched signature in the debug log and to document the existing
`pixelflow_useragent_bot_patterns` filter where a site owner will find it.

The Meta crawlers are handled the other way round — by adding a catch-all rather than only exact
agents. Meta ships a family under the `meta-external` prefix (`meta-externalagent` was already in
the list, `meta-externalads` is new in the export), and listing only exact agents means a plugin
release every time Meta adds one. The prefix costs nothing in false positives: no real browser
carries it.

A prefix alone would cost resolution in the telemetry, because
`pixelflow_get_bot_detail_pattern()` reports the *pattern* that matched rather than the agent
string — so every Meta crawler would beacon `meta-external` and the backend could no longer
separate the ads crawler from the agent crawler. The list avoids that by carrying both: the two
agents seen in production are listed exactly and placed ahead of the prefix, so they keep reporting
themselves, and only an unrecognised family member falls through to the generic value. Known
traffic stays as legible as it is today; unknown traffic is caught instead of missed.

That ordering is the mechanism, not a hazard to be designed around. The resolver returns the first
matching pattern, so a list ordered specific-before-general is exactly how a hierarchy of
signatures is expressed. The existing list already depends on this, though not always to its
advantage: `bot` sits first, so `ahrefsbot`, `semrushbot`, `dotbot`, `rogerbot` and `linkupbot`
never appear as a reported cause — every one of them beacons `bot`. That predates this change and
is left alone here, but it is the same lesson, and it is why the Meta entries are ordered
deliberately rather than appended.

Reporting the matched agent substring instead of a pattern would keep every variant distinct
without any ordering at all, and is still rejected: it would put user-agent-derived text on a
channel whose contract is that it carries no visitor data beyond a closed vocabulary.

The anonymous telemetry needs no change here: `consent-resolution` already requires
`/blocked-events` to carry the matched pattern in `detail`, and
`pixelflow_get_bot_detail_pattern()` → `pixelflow_resolve_blocked_event_reason()` already
delivers it. The gap is confined to `hold_or_block_event()`
(`includes/woo/hooks/class-woocommerce-hooks.php:1947`), which logs the literal `(BOT_UA)` while
the resolved `$blocked['detail']` sits unused in the same scope.

### Resolve identity at the dispatch point, not in the customer-data builders

`external_id` is resolved in `post_event()` and written into `customerData` there, rather than
inside `build_customer_data_from_current_user()` or `build_customer_data_from_order()`.

The builders cannot do the job. `build_customer_data_from_current_user()`
(`includes/woo/hooks/class-woocommerce-hooks.php:1341`) takes no arguments and is shared by
AddToCart (`:228`, `:330`) and InitiateCheckout (`:576`), so it cannot tell which event it is
building for. And on the order path the builder runs at `:724` while `$context` is assembled at
`:745-752` — the context the `pixelflow_external_id` filter is contracted to receive does not exist
yet at that moment. Resolving in the builder and filtering with a real context are mutually
exclusive; the filter's contract decides where the resolution goes.

`post_event()` already holds what the resolver needs: `$context['order']` for the visitor id stored
on the order, and `$allow_live` for the buyer-trust gate. It is also the single point every event
type passes through, which is the same reason `pixelflow_resolve_bot_detail()` lives there.

This also disposes of a problem rather than solving it. `build_customer_data_from_current_user()`
returns `[]` for a guest — `get_current_user_id()` is 0, and removing that guard would not help
because `get_userdata(0)` returns `false` and the second guard fires anyway. An earlier draft split
the function so identity could be resolved ahead of both guards. None of that is needed now: the
guards stay exactly as they are, and `post_event()` adds `customerData` when the builder produced
none.

Within `post_event()` the resolution happens before `hold_or_block_event()` (`:1895`), so a held
event captures its identity rather than acquiring one later — and is skipped entirely while
`$this->flushing_held` is set (`:46`, already branched on at `:1900`), so a replay cannot overwrite
that capture. This is not a new idea: `external_id`
is already in `PIXELFLOW_HELD_CUSTOMER_KEYS` (`includes/held-events.php:29-39`), and
`pixelflow_held_event_recipe_from_payload()` already snapshots the whole `customerData` at hold
time next to `em`, `fn` and the rest. The recipe exists precisely to remember who the shopper was.

Re-resolving at flush would break that. `flush_held_events()`
(`includes/woo/hooks/trait-held-woo-events.php:124`) can run on a later request that is not the
same person's, so resolving there would attribute the held event to whoever happened to trigger the
flush — the same failure as the staff-cookie leak this change closes elsewhere. An event held with
no identity therefore stays without one; it does not pick one up on the way out, even if the
flushing request would have resolved one.

The guard is the existing `flushing_held` flag rather than a new `$context` key, because
`$context` became a public contract the moment the identity filter started receiving it, and a
replay flag is an internal detail with no business being in it.

### Hash the primary inputs without normalising them

`pixelflow_normalize_external_id()` (`includes/helpers.php:155`) trims and lowercases, and every
other `external_id`-shaped field in the plugin goes through it. The primary formula must not.
This was settled by reading the deployed browser script (`pfm.js`) rather than by analogy: its
`getOrCreateExternalId` concatenates the raw values and hashes the result directly —

```js
let vid = readVisitorId();            // _pf_uid cookie, else localStorage
if (!vid) vid = createVisitorId();    // generates and persists one
return sha256Hex(siteId + '_' + vid); // sha256 = TextEncoder + crypto.subtle, no trim/lowercase
```

The script's normalisers (`normalizeEmail`, `normalizeAlnumLower`, `normalizePostal`, …) are
applied only to the PII fields `em`, `ph`, `fn`, `ln`, `ct`, `st`, `zp`, `country`, never to the
identity input. Since byte-for-byte agreement with the script is the entire point of the primary
path, adding a normalisation step the script does not perform would be the one change guaranteed
to break it. Since the visitor id is now the only source, this one rule covers the whole resolver:
there is no second branch whose input could be normalised differently.

The guarantee is stated in terms of case, not whitespace, because whitespace is already gone by
the time identity sees the value: `pixelflow_resolve_attribution_visitor_id()`
(`includes/helpers.php:572`) runs every branch through `sanitize_text_field()`, which trims, and
this change reuses that resolver rather than replacing it. Nothing is lost — a cookie value cannot
carry raw whitespace under RFC 6265, and the id the script generates is digits and a dot. Claiming
byte-for-byte preservation of whitespace would make the spec assert something the code does not do;
the rule that actually matters is that no case-folding or `pixelflow_normalize_external_id()` call
is added to the hash input.

One transformation does survive into the hash input, and the guarantee is stated relative to it
rather than in spite of it: `pixelflow_resolve_attribution_visitor_id()` truncates the visitor id
to 64 characters on both of its branches (`includes/helpers.php:574`, `:588`). Reusing that
resolver rather than replacing it is deliberate — identity and attribution must agree on what the
visitor id *is* — so the cap is inherited, and "hashed as the existing resolver returns it" is the
honest way to state the formula. Today the cap never binds: the id the script generates is digits
and a dot, far short of 64. But nothing pins that, and if a future script emitted a longer id the
plugin would hash a truncated copy and the script the whole one — reintroducing the split identity
this change exists to close, silently and only for the visitors affected. Task 1.5 therefore pins
the boundary with a regression test rather than leaving it to inspection; raising or removing the
cap is out of scope here, because the same function feeds the attribution path.

### Every live-cookie read is gated on the request belonging to the buyer

The gate has to cover three reads, not one. `pixelflow_get_attribution_from_cookie()`
(`includes/helpers.php:602`) falls back to `$_COOKIE['_pf_attribution']` when given no override
(`:605`), and the `visitor_id` inside that payload is consulted *first* (`:619`) — ahead of the
order-meta override, so an ungated attribution cookie is worse than an ungated `_pf_uid`: it wins
on priority. Then `pixelflow_resolve_attribution_visitor_id()` (`:572`), which that function calls,
reads `$_COOKIE['_pf_uid']` on its own account whenever the override it was handed is empty
(`:580-583`). Gating the outer read alone leaves this inner one open, and it is reached on exactly
the case that matters: a non-buyer request for an order with nothing stored.

On an order path, a live cookie belongs to whoever made the request — which on a gateway callback,
a wp-admin status change or a cron run is not the shopper. Without a gate, a staff member marking
an order paid while carrying their own cookies from a visit to the storefront would have their
visitor id hashed into that buyer's Purchase, merging unrelated people under one identity: a
smaller instance of the exact defect this change exists to remove.

The convention exists in the file already: `build_customer_data_from_order()`,
`append_cookie_params_for_order()` and `append_attribution_for_order()` all take a
`$request_is_buyer` flag derived from `pixelflow_request_owns_order($order)`
(`class-woocommerce-hooks.php:712`). The identity resolver takes the same flag, so there is one
convention rather than two. The order-meta visitor id, captured at order creation, stays available
on those paths and is unaffected.

One of those call sites does not actually hold, and this change fixes it rather than copying it.
`append_attribution_for_order()` gates its own cookie read correctly (`:1279`), but then passes
`null` to `pixelflow_get_attribution_from_cookie()` whenever the resulting value is empty
(`:1288-1291`) — and inside that function `null` means "no override was given, read `$_COOKIE`"
(`includes/helpers.php:603-606`), not "the override is empty". So on a non-buyer request for an
order with no stored attribution, the staff member's live `_pf_attribution` is read after all, and
its embedded `visitor_id` outranks everything else. The fix is an explicit argument that disables
the cookie fallback, passed from both the new resolver and the existing call; an absent override
must never read as permission.

The same gate covers the prefetch header, for the same reason and by the same precedent. `ua` is
already read live only when the request is the buyer's (`class-woocommerce-hooks.php:1881-1883`),
under an explicit comment that a staff member or an automation client must not decide whether the
buyer looks like a bot. `Sec-Purpose` is a signal of exactly that class, so reading it
unconditionally would leave two identical signals governed by different rules in the same method,
with nothing in the spec explaining why. The realistic risk is small — a gateway's server-to-server
POST carries no browser fetch metadata, and browsers set `Sec-Purpose: prefetch` on speculative GET
navigations, not on the form POST or admin-ajax call behind a wp-admin status change — so this is
settled on consistency rather than on exposure. The cost is one ternary beside the existing one:
the resolver reads the header only when `$allow_live` is true, and on a replay path the rule
simply does not fire.

### The script's no-identity fallback is deliberately not mirrored

When the script cannot resolve a visitor id it does not omit the field: it emits the plaintext
`siteId + '_fallback_' + Date.now()`, unhashed and unique per call. The plugin does not copy this.
The value is not a hash, so it is not comparable with any other identity, and its millisecond
suffix makes every such event a distinct person — the same fragmentation this change exists to
fix, in a different form. The script also rarely reaches that branch, because it can *create* a
visitor id when none exists; the plugin, having no browser, cannot. Omitting the field, as the
"Nothing identifies the request" scenario requires, keeps the plugin's behaviour honest and
leaves the residue visible in the backend rather than hiding it behind unique junk identifiers.

### The filter runs on every event and receives the event context

`pixelflow_external_id` is applied whether or not an identifier resolved. Only the empty case makes
the escape hatch meaningful: the behaviour a site would want to restore — hashing a user id or an
email — applied exactly where the new resolver now yields nothing. A filter that skips the empty
case can replace and suppress but never supply, so the promise in the proposal would be words only.

The callback receives `post_event()`'s own `$context` array as its second argument, not just the
name of the source the value came from. That gives a site enough to vary its answer by event type
or by order, and it is what fixes where the resolution happens: the context only exists inside
`post_event()`, so the identity has to be resolved there too. The cost is worth naming: the shape
of `$context` stops being an internal detail. It is already being
extended this release with `bot_rule`, so the keys documented in `readme.txt` are the contract from
1.1.18 onward, and renaming one later breaks somebody's code. Document the keys deliberately rather
than describing the array as "whatever post_event() passes".

The key set is not uniform across event types, and the documentation has to say so rather than
promise one fixed shape: AddToCart supplies `product_id` and `variation_id`, Purchase supplies the
consent overrides and the order, and InitiateCheckout supplies nothing at all
(`class-woocommerce-hooks.php:581`). A callback therefore has to tolerate an absent key rather than
assume one.

An empty or null return still means "omit the field", so suppression keeps working and a callback
that returns the value unchanged is a no-op.

### An escape hatch as a PHP filter, not a setting

The identity change is not reversible from the Meta side once events are sent, so a site needs
some way back. A filter is enough: it is code-level, invisible to shop staff, and cannot be left
switched to the old behaviour by accident, which a checkbox in the settings screen can.

## Risks / Trade-offs

- **Events with no visitor id now carry no identity at all.** Dropping the user-id and `_fbp`
  fallbacks means a shopper whose browser blocked the script is unidentified server-side, where an
  earlier draft would have given them a weak, script-incompatible identity. → Accepted: that
  identity could never join to the same person's script events, so it bought reach without
  joinability. `em` and `fbp` still travel as their own fields.

- **The plugin's change and the backend's are visible to the customer as one change.** Events that
  today collapse onto `sha256(site_external_id)` will arrive with no `external_id` once both ship.
  → Coordinate the release order with the backend team and say so in the customer note; a long gap
  in either direction makes the numbers hard to read.

- **Prefetch and cookieless-add-to-cart suppressions land in the bot bucket.** All three
  automation rules share `reason: bot`. → `detail` separates them (`prefetch_header`,
  `no_cookies_in_wp_plugin`, or the matched signature), but only if the backend aggregates by
  `(reason, detail)`. Confirm before release.

- **Reported volume drops, and guest identity changes over in one step.** Meta
  audiences and attribution windows keyed on the old constant do not carry over. → Both effects
  are intended and both are visible to the customer; announce before release rather than
  explaining after.

- **A site's own HTTP client may be filtered as a bot.** → The matched signature is named in the
  diagnostic record and the removal filter is documented.

- **The `$context` array becomes a public contract.** Passing it to `pixelflow_external_id` means a
  later rename of any key is a breaking change for sites that wrote a callback. → Accepted
  deliberately for the flexibility it gives; mitigated by documenting the guaranteed keys in
  `readme.txt` rather than leaving them implicit.

- **The configuration gate silences a half-configured site completely.** A site that was
  producing events with an empty API key stops producing them. → That traffic was never accepted
  by the API, so nothing of value is lost, and the gate matches the condition the browser script
  already uses. The gate alone would only move the failure, though — from rejected-at-the-API to
  silent-at-the-source — so it ships with an admin notice naming the cause and linking to the
  settings screen. "Making a silent failure explicit" is only true if someone is told.

## Open Questions

**Answered by the backend/product team; the specs above reflect the answers.**

- *Is substituting `sha256(site_external_id)` for a missing `external_id` intentional?* Yes — a
  deliberate EMQ measure, now judged no longer worth it. The backend is removing it.
- *What should the plugin emit?* The script's formula, `sha256(site_external_id + '_' +
  visitor_id)` with `visitor_id` being `_pf_uid`; for a Purchase whose request carries no cookie,
  the visitor id saved on the order; with neither, no field at all. One format, no fallbacks.

**Still open, for the backend team. Neither blocks implementation.**

- Does the `/blocked-events` ingest allow-list `detail`, and is bot volume aggregated by
  `(reason, detail)` rather than by `reason` alone? If `detail` is filtered server-side, the
  `no_cookies_in_wp_plugin` rows will look like ordinary user-agent bot hits.
- What release order do the plugin and the backend substitution removal take, and how far apart?
- Would a namespaced separator be adopted across script, plugin and backend, so the primary
  formula stops depending on an unmarked concatenation? Not urgent now that only one format
  exists, but it is the one remaining place where the two producers agree by convention rather
  than by construction.
