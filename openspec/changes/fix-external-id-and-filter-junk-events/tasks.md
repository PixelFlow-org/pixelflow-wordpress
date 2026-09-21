## 1. Identity resolution

- [x] 1.1 Add a single identity resolver in `includes/helpers.php` that returns the hashed
      `external_id` for a request: `sha256($site_external_id . '_' . $visitor_id)` when a visitor
      id resolves, and `null` when none does. There are no other sources — no WordPress user id,
      no `_fbp`, no email, no order id — because the backend requires one format shared with the
      browser script, and the script emits only this one.
- [x] 1.2 Give the resolver an explicit visitor-id override argument so the order paths can pass
      the value stored on the order rather than relying on `$_COOKIE`, plus a `$request_is_buyer`
      flag that suppresses every live-cookie read when false — both `$_COOKIE['_pf_uid']` and the
      `_pf_attribution` fallback inside `pixelflow_get_attribution_from_cookie()`
      (`includes/helpers.php:605`), which supplies the attribution payload's `visitor_id` and is
      consulted ahead of the order-meta override (`:619`), and the bare `$_COOKIE['_pf_uid']` read
      inside `pixelflow_resolve_attribution_visitor_id()` itself (`:580-583`). The flag's value comes from
      `pixelflow_request_owns_order($order)` (`class-woocommerce-hooks.php:712`), as for
      `build_customer_data_from_order()`, `append_cookie_params_for_order()` and
      `append_attribution_for_order()`, each of which already gates its own cookie reads on it.
- [x] 1.2a Give both cookie-reading helpers an explicit argument that disables their live-cookie
      fallback, and pass it from the new resolver and from the existing
      `append_attribution_for_order()` call (`class-woocommerce-hooks.php:1288`). Two reads need
      it — closing all three ways a live cookie can reach the identity.
      `pixelflow_get_attribution_from_cookie()` (`includes/helpers.php:602`) falls back to
      `$_COOKIE['_pf_attribution']` (`:605`), whose embedded `visitor_id` is then consulted first
      of all (`:619`); and the `pixelflow_resolve_attribution_visitor_id()` it calls (`:572`) falls
      back to `$_COOKIE['_pf_uid']` on its own (`:580-583`) whenever the passed override is empty.
      Gating only the first leaves the others open, so a staff member's bare `_pf_uid` still
      reaches an order that has no stored visitor id of its own. That call passes `null` for the raw override whenever
      the order has no stored attribution — including when `$request_is_buyer` is false — and
      `null` means "no override given, read the cookie" (`:603-606`), not "the override is empty".
      The gate therefore does not hold on that path in shipped code: a staff member's own
      `_pf_attribution` supplies a `visitor_id` that wins over the order's own values. Fix the
      existing call in this change; a resolver copying the current pattern would inherit the hole.
- [x] 1.3 Apply the `pixelflow_external_id` filter inside `post_event()`, to the resolved value on
      every event, including when the resolver returned `null` — otherwise a site cannot supply an
      identifier where none resolved, which is what "restore the previous behaviour" means. Pass
      `$context` as the second argument; that is why the resolution lives in `post_event()` (task
      2.1) rather than in the customer-data builders, where no context exists yet. Treat an empty
      or null return as "omit the field".
- [x] 1.4 Unit-test the resolver: each visitor-id source, two sites with the same literal visitor
      id producing different hashes, the no-visitor case returning `null`, the filter's replace
      suppress and supply behaviour — the supply case being a callback that returns a value when
      the resolver found nothing — and that a live `_pf_uid` cookie is ignored when `$request_is_buyer`
      is false. Add negative tests pinning that a logged-in user with no visitor id, and a request
      with only `_fbp`, both resolve to `null`, and that a live `_pf_attribution` cookie carrying a
      visitor id is ignored when `$request_is_buyer` is false — including the case where the order
      has no stored attribution at all, which is the path that leaks today.
- [x] 1.4a Regression-test `append_attribution_for_order()` directly for both leaks: a request that
      is not the buyer's and an order with no stored values, once with a live `_pf_attribution`
      cookie present and once with only a bare `_pf_uid` cookie. Neither may reach the attribution
      or the identity.
- [x] 1.5 Add a regression test pinning the primary formula to
      `sha256(site_external_id . '_' . $visitor_id)` with a fixture pair taken from the export,
      so a future refactor cannot silently diverge from the browser script. Include a fixture
      whose inputs carry uppercase characters, pinning that the case is not folded — the script
      normalises only the customer-data fields, never the identity input. Whitespace is not
      pinned: the existing visitor-id resolver runs `sanitize_text_field()`, which trims, and
      cookie values cannot carry raw whitespace anyway. Pin the 64-character boundary too: a
      visitor id longer than 64 characters hashes the truncated value, because the identity path
      reuses `pixelflow_resolve_attribution_visitor_id()` (`includes/helpers.php:574`, `:588`) and
      inherits its cap. The cap does not bind for ids the current script generates; the test exists
      so that a future longer id fails here instead of silently splitting one shopper across the
      plugin and the script.

## 2. Wire identity into the event paths

- [x] 2.1 Resolve `external_id` in `post_event()`
      (`includes/woo/hooks/class-woocommerce-hooks.php:1868`), after `$context` is unpacked, and
      write it into `$payload['eventData']['customerData']` — creating that key when the builders
      produced nothing, as they do for a guest. `post_event()` already holds everything the
      resolver needs: `$context['order']` for the stored visitor id and `$allow_live` as the
      `$request_is_buyer` flag. The customer-data builders must not resolve identity themselves:
      they run before `$context` exists (`:724` versus `:745-752` on the order path), they take no
      event argument, and `build_customer_data_from_current_user()` is shared by AddToCart and
      InitiateCheckout so it cannot tell which event it is building for. Resolve before
      `hold_or_block_event()` (`:1895`), so a held event's recipe captures the identity of the
      shopper who triggered it — see 2.5. Skip the resolver entirely when
      `$this->flushing_held` is true (`:46`, already branched on at `:1900`): a flush replays a
      recipe whose identity is already decided, and re-resolving there would overwrite the
      snapshot with whoever is making the flush request. The `pixelflow_external_id` filter still
      runs on the replayed value: read it from
      `$payload['eventData']['customerData']['external_id']`, which `apply_held_customer_data()`
      has already restored from the recipe by this point, and pass that to the filter in place of a
      resolver result.
- [x] 2.2 Delete the `external_id` derivation from `build_customer_data_from_current_user()`
      (`:1379`) and from `build_customer_data_from_order()` (`:1417`). Leave both methods otherwise
      untouched — including the two guards in the first one, which no longer matter for identity,
      and the hashed email in `em`.
- [x] 2.3 Make sure an unresolved identity leaves no key behind. `build_customer_data_from_order()`
      strips both `''` and `null` (`:1461-1465`), but `build_customer_data_from_current_user()`
      closes with `array_filter($out, fn($v) => $v !== '')` (`:1382`), which keeps `null`. Since
      the field is now added in `post_event()`, simply do not set it when the resolver and the
      filter both come back empty, so nothing can serialise as `"external_id": null`. On a held
      replay the resolver does not run at all (2.1), so the recipe's value — present or absent —
      is what the event carries, still subject to the filter (1.3).
- [x] 2.4 Verify AddToCart, InitiateCheckout and Purchase all emit `external_id` through this one
      path, and that no code still hashes a user id, email, `_fbp` or order id into that field.
      Note that InitiateCheckout calls `post_event($payload)` with no context at all (`:581`); it
      must still resolve identity from the live cookies.
- [x] 2.5 Leave the held-event path working the way it already does, and test it. `external_id` is
      already listed in `PIXELFLOW_HELD_CUSTOMER_KEYS` (`includes/held-events.php:29-39`) and
      `pixelflow_held_event_recipe_from_payload()` (`:87-103`) snapshots the whole `customerData`
      at hold time, alongside `em`, `fn` and the rest. Because 2.1 resolves before
      `hold_or_block_event()`, the recipe captures the identity of the shopper who actually
      triggered the event, and `apply_held_customer_data()`
      (`includes/woo/hooks/trait-held-woo-events.php:191-200`) replays it unchanged. Do not
      re-resolve on flush: `flush_held_events()` (`:124`) may run on a different request, and
      re-resolving there would attribute the event to whoever made that request. Test that a held
      event flushed on a request carrying a different `_pf_uid` still reports the identity captured
      at hold time, and that one held with no identity is still sent without the field even when
      the flushing request would have resolved one. Also pass the recipe's `product_id` and
      `variation_id` into the replay's `post_event()` call
      (`includes/woo/hooks/trait-held-woo-events.php:124`), which currently passes no context at
      all, so a `pixelflow_external_id` callback sees the same context on a replay as on the live
      AddToCart that produced it. Both values are already on the recipe
      (`includes/held-events.php:87-103`); the recipe structure does not change. That second case is what the `flushing_held`
      skip in 2.1 protects: without it, an event held before the visitor cookie existed would
      quietly acquire an identity on the way out.
- [x] 2.6 Integration-test the three event types for: guest with a visitor cookie, logged-in user
      without one, guest with only `_fbp`, and an order transitioned from wp-admin with no cookies
      on the request. Assert on the built payload, not only on the resolver. The negative cases —
      a logged-in user with no visitor id, a request with only `_fbp` — must show the
      `external_id` key absent, not present and null. Add two staff cases: an order with no stored
      visitor id, transitioned by a request carrying someone else's `_pf_uid`, and the same with
      someone else's `_pf_attribution` — neither may be borrowed.


## 3. Automation and prefetch filtering

- [x] 3.1 Add `guzzle`, `httpx` and `aiohttp` to `PIXELFLOW_BOT_PATTERNS` in
      `includes/helpers.php`, add `meta-externalads` beside the existing `meta-externalagent`, and
      add a `meta-external` prefix entry immediately after both as the catch-all for the rest of
      Meta's crawler family. Keep that order: `pixelflow_get_bot_detail_pattern()` returns the
      first pattern that matches, so the two exact agents must precede the prefix or they would be
      reported as `meta-external` instead of themselves.
- [x] 3.2 Reuse the existing `pixelflow_get_bot_detail_pattern()`
      (`includes/blocked-events.php:42`), which already returns the matched signature and is
      already wired through `pixelflow_resolve_blocked_event_reason()` into `post_event()`.
      No new function, and no change to the `pixelflow_useragent_bot_patterns` filter contract.
- [x] 3.3 Classify a request carrying `Sec-Purpose` containing `prefetch`, `Purpose: prefetch`, or
      Safari's legacy `X-Purpose`, as automated regardless of user agent. Read the header from `$_SERVER` only when the request
      is the buyer's, gating it on the same `$allow_live` flag that already guards the live
      user-agent read (`class-woocommerce-hooks.php:1881-1883`): on a gateway callback, a wp-admin
      status change or a cron flush the headers belong to someone other than the shopper, and the
      rule must not fire. Feed it through the same path as a user-agent match —
      `pixelflow_resolve_blocked_event_reason()` (`includes/blocked-events.php:75`) already returns
      `reason` `bot` for any non-empty detail — so the skip is beaconed with `detail`
      `prefetch_header` and named in the debug log without a second mechanism.
- [x] 3.4 In `hold_or_block_event()` (`includes/woo/hooks/class-woocommerce-hooks.php:1947`),
      replace both the `(BOT_UA)` literal and the hard-coded `$message` text, which currently
      reads `EVENT SENDING SKIPPED BECAUSE USER AGENT MATCHED BOT SIGNATURE` and is used twice.
      Leaving it would make a prefetch skip log `USER AGENT MATCHED BOT SIGNATURE prefetch_header`,
      naming a cause that did not fire. Use a message that states the request was classified as
      automated and append the resolved `$blocked['detail']`, or branch to user-agent wording only
      when the detail came from the signature list. The `/blocked-events` row already carries the
      detail; only the local log does not.
- [x] 3.4a Do the same for the blocked-Purchase branch of the same method
      (`class-woocommerce-hooks.php:1924-1930`), which logs only
      `EVENT SENDING SKIPPED (<reason>); BLOCKED ROW <disposition>` and never touches
      `$blocked['detail']`, even though it is in scope there. Without this, a Purchase blocked as a
      bot — exactly what the new `guzzle`/`httpx`/`aiohttp` signatures are for — leaves the site
      owner unable to see which rule fired. The beacon is unaffected:
      `defer_blocked_purchase_report()` stores the whole `$blocked` row and reports `detail` later.
- [x] 3.5 Tests: each new signature matches, each prefetch header suppresses, a mainstream
      browser user agent does not, and the debug log names the cause in each case — the matched
      signature for a user-agent hit, `prefetch_header` for a prefetch hit. Assert the prefetch
      skip is beaconed with `reason` `bot` and `detail` `prefetch_header`, and assert its log entry
      does NOT claim a user-agent match: a test on the presence of `prefetch_header` alone would
      pass while the line still said the user agent matched. Cover both branches of
      `hold_or_block_event()` — a blocked AddToCart and a blocked Purchase — since they log through
      separate statements. Add the gate case: a Purchase sent from a request that is not the
      buyer's, carrying a prefetch header, is still sent and produces no prefetch suppression.
- [x] 3.6a Rank a caller-supplied rule below the consent checks in
      `pixelflow_resolve_blocked_event_reason()`, passing it as its own argument rather than
      folding it into `$bot_detail`. Such a rule infers automation from an absence of cookies, and
      a pending or declined decision explains that absence: `_pf_uid` and `_fbp` are marketing
      cookies withheld until consent is granted, so an undecided shopper on a classic
      `?add-to-cart=` link is indistinguishable from the crawler the rule targets. With the rule
      ranked first, `bot` is returned before the hold is tested and
      `pixelflow_should_queue_held_event()` — which queues only `no_decision` — can never hold the
      event, so it is lost instead of replayed on a grant. A user-agent match and a prefetch header
      stay above the consent state: those are evidence about the request, not inferences from
      absence. Test all four orderings, and test that a client carrying no cookies at all —
      consent cookies included, as a no-JavaScript crawler produces — is still filtered by the
      rule.
- [x] 3.6 Add `pixelflow_resolve_bot_detail()` to `includes/blocked-events.php` as the single place
      that decides the automation detail from the request's own evidence, in this precedence: a
      matched user-agent signature, then a prefetch header. A caller-supplied rule identifier
      passed as `$context['bot_rule']`
      — `no_cookies_in_wp_plugin` for the only current caller. Add `bot_rule` to the `$context`
      keys documented in the `post_event()` docblock (`class-woocommerce-hooks.php:1863-1867`).
      Call it from `post_event()` (`includes/woo/hooks/class-woocommerce-hooks.php:1890`) in place
      of the bare `pixelflow_get_bot_detail_pattern($ua)`, taking the caller-supplied rule from
      `$context`, so every event type resolves the detail the same way. Pass the prefetch signal in
      as a resolved boolean rather than letting the function read `$_SERVER` itself, so the
      `$allow_live` gate from 3.3 lives at the one call site that knows whether the request is the
      buyer's and cannot be bypassed by a second caller. Test each precedence
      pair: signature over prefetch, signature over caller-supplied rule, and prefetch over
      caller-supplied rule — the last is the one no other task exercises.

## 4. Cookieless add-to-cart filtering

- [x] 4.1 In `pf_add_to_cart_hook()` (`includes/woo/hooks/class-woocommerce-hooks.php:152`),
      skip the event when `$_GET` carries an `add-to-cart` key and the request has neither
      `_pf_uid` nor `_fbp`. Do not test `$_SERVER['REQUEST_METHOD']`: WooCommerce's own handler
      runs on `wp_loaded` and reads `$_REQUEST['add-to-cart']`
      (`woocommerce/includes/class-wc-form-handler.php:33`, `:823`), so `HEAD` and a `POST` whose
      URL carries the parameter both add to the cart and would both escape a `GET`-only rule.
      `$_GET` is the parsed query string whatever the method, which is the scope this rule wants —
      and a genuine add-to-cart form stays outside it, because a form submits in the body. Record the skip in the
      site's debug log, and report it on the blocked-events channel with `reason` `bot` and
      `detail` `no_cookies_in_wp_plugin` so the withheld volume stays visible in the PixelFlow UI.
      Do NOT return early with a direct POST: pass `$context['bot_rule'] = 'no_cookies_in_wp_plugin'`
      into the normal `post_event()` call, so `pixelflow_resolve_bot_detail()` can apply
      the precedence and a crawler that also matches a user-agent signature is reported under that
      signature, not under this rule. Logging and beaconing then run through
      `hold_or_block_event()` for all three rules alike.
      `pixelflow_build_blocked_events_payload()` (`includes/blocked-events.php:186`) already passes
      `detail` through untouched on a `bot` row, so nothing changes there.
- [x] 4.1a Set the same `bot_rule` in `pf_cart_item_quantity_update_hook()`
      (`class-woocommerce-hooks.php:369`), the other AddToCart producer. For a product already in
      the cart WooCommerce calls `set_quantity()` (`class-wc-cart.php:1318`) — firing
      `woocommerce_after_cart_item_quantity_update` (`:1421`) — before
      `do_action('woocommerce_add_to_cart')` (`:1343`), so that hook wins the shared
      `add_to_cart:<key>` dedupe and would otherwise emit the event with no rule attached, making
      the outcome depend on cart state rather than on the shape of the request.
- [x] 4.1b Require a missing browser signal as well as missing cookies before the rule fires.
      `_pf_uid` and `_fbp` are both written by JavaScript, so an ad-blocked shopper carries
      neither and is indistinguishable from a crawler on cookies alone — and that shopper is
      precisely who server-side events exist to recover. Add
      `request_looks_like_a_browser_navigation()`: `Sec-Fetch-Mode: navigate`, or a non-empty
      `Accept-Language`. Either is enough, because `Sec-Fetch-*` is not universal and demanding
      both would put old browsers in the crawler bucket. Test that each header alone spares the
      request, that a non-navigation `Sec-Fetch-Mode` does not, and that a client sending neither
      is still filtered.
- [x] 4.2 Keep the scope to that one condition: the AJAX endpoint, the Store API and POST-form
      adds are excluded by construction, because none of them sets `$_GET['add-to-cart']`. Do
      not add a `wp_doing_ajax()` or `REST_REQUEST` probe — it would widen the rule without
      narrowing the traffic it targets.
- [x] 4.3a Live e2e coverage for the rule, in `e2e/live/tests/cookieless-add-to-cart.spec.ts`. The
      classic `?add-to-cart=` GET is the only add the live suite never exercised — every other one
      goes through the Store API, which is a POST and outside the rule by construction — so the
      rule and its interaction with consent were invisible to it. Four scenarios: an undecided
      shopper's classic link is held rather than reported as automation; accepting afterwards
      flushes it; a consenting shopper's classic link is reported normally; and a JavaScript-less
      context (`withCrawlerPage()`, which acquires no cookies at all because the script never
      runs) is withheld and reported under the rule. `URLS.classicAddToCart()` is added to
      `site.ts` for the first three.
- [x] 4.3 Tests: GET with neither cookie is skipped, logged, and beaconed with `reason` `bot` and
      `detail` `no_cookies_in_wp_plugin`; GET with either cookie is sent and not beaconed; an AJAX
      add (`?wc-ajax=add_to_cart` with a POST body) and a Store API add are unaffected even with
      no cookies at all; and a cookieless GET whose user agent also matches a signature is reported
      under that signature, proving the rule did not bypass the precedence.

## 5. Configuration gate and dead code

- [x] 5.1 Gate the *sending* on both `siteExternalId` and `apiKey` being non-empty, reusing the
      same condition as the browser-script injection gate in `pixelflow.php:280`. Apply it at the
      one place that performs the outbound request, covering both the event POST and the
      blocked-events beacon. Do NOT gate hook registration in `load_hooks()`
      (`includes/woo/class-woocommerce-integration.php:52`): `init_hooks()` also registers the
      writers whose data exists only during the buyer's own request —
      `pf_save_tracking_cookies_to_order` (`class-woocommerce-hooks.php:126`, "written once and
      never rewritten"), `record_consent_decision_on_open_orders` (`:135`),
      `resolve_held_events_on_page_view` (`:136`) and the two `wc_ajax` handlers (`:137-138`) —
      so an empty credential would turn a rotated key or a half-finished migration into a
      permanent loss of attribution and consent for every order created in that window.
- [x] 5.2 Remove `pf_clkid`, `clkId` and the PHP reads of `pf_fbc` from all four readers — the
      default cookie map in `pixelflow_append_cookie_params()` (`includes/helpers.php:679`), the
      order-meta cookie list (`class-woocommerce-hooks.php:628`), the second cookie map
      (`:1230`) and the debug-log key list (`:1803`); read `_fbc` into `fbc` directly, as `_fbp`
      already is.
- [x] 5.3 *(Shipped off by default — see 7.12.)* Add an `admin_notices` callback that renders a `notice-error` when either
      `siteExternalId` or `apiKey` is empty, saying PixelFlow is not fully configured and no events
      are being sent, with a link to `options-general.php?page=pixelflow-settings`. Follow the
      existing `display_debug_notice()` pattern (`pixelflow.php:69` registers it, `:573` renders
      it) and reuse the gate's own condition so the notice and the gate cannot disagree. PHP only —
      the React settings app is not touched.
- [x] 5.4 Delete `pixelflow_normalize_external_id()` (`includes/helpers.php:155`). Its only two
      callers are the `external_id` derivations this change replaces
      (`class-woocommerce-hooks.php:1379`, `:1417`); nothing else in the plugin, the tests or the
      documented filter surface refers to it, so this change is what orphans it.
- [x] 5.5 Tests: no event and no beacon leaves the site when either setting is empty, while the
      cookie-to-order-meta writer, the consent recorder and the held-event flush all still run;
      the notice renders in that
      case and not when both settings are set, and renders on an empty credential regardless of the
      plugin's enable toggles and of whether WooCommerce is active — the notice is deliberately
      conditioned on the credentials alone, matching the browser-script gate; a stale retired cookie reaches neither the payload
      nor the debug log.

## 6. Release

- [x] 6.1 Document the `pixelflow_useragent_bot_patterns` and `pixelflow_external_id` filters in
      `readme.txt` with copy-pasteable snippets. For `pixelflow_external_id`, list the context keys
      the callback receives and show the supply case — returning a value when the plugin resolved
      none — since that is the documented way back to the previous identity. Say which keys each
      event type actually supplies: the set is not uniform. AddToCart passes `product_id` and
      `variation_id`, Purchase passes the consent overrides and the order, InitiateCheckout passes
      nothing at all (`class-woocommerce-hooks.php:581`), and a held replay passes what 2.5 adds.
      A callback must tolerate a missing key rather than assume one.
- [ ] 6.1a Before archiving this change, archive `fix-consent-gating-review` first, then confirm
      this delta's `Skipped sends report anonymous blocked events` still reads as the union of both
      — the reporting-window Purchase condition and `client_ip_address` from that change, plus the
      prefetch and cookieless producers and the `bot` `detail` rule from this one. The delta text
      here is already written as that union, so the check is a diff, not a rewrite. Check the
      scenario wording as well as the requirement paragraph — `Visitor declined` must keep its
      `storefront` qualifier, because a denied Purchase reports through the settling delay of
      `A blocked purchase is reported once, after a settling delay`, not immediately.
      `openspec validate --strict` validates each change against the baseline in isolation and will
      not catch it if the two drift apart again.
- [x] 6.2 Bump the version to 1.1.18 in all five places (`pixelflow.php:5`, `pixelflow.php:22`,
      `readme.txt` stable tag, `readme.txt` changelog, `README.md` changelog).
- [x] 6.3 Run the full PHP test suite and the frontend suite; confirm green.
- [x] 6.4 Write the customer-facing note: reported AddToCart and InitiateCheckout volume drops by
      by an amount the export cannot predict, and guest identity changes over in one step, so
      audiences and attribution
      windows keyed on the old identifier do not carry over. Note that a site missing either
      setting now stops sending events entirely (the admin notice is deferred, see 7.12). Say
      plainly that
      events with no visitor id now arrive with no `external_id` at all, because the backend is removing the
      site-constant substitution in the same window — coordinate the release order with that team
      so the two changes do not land far apart.

## 7. Review fixes

Found by the review round on the implemented branch. Each is a defect in this change's own work,
not new scope; the operator approved every spec edit they required.

- [x] 7.1 Stop an automation suppression from consuming the dedupe and guard state the shopper's
      own request needs. `should_send_event()` (`class-woocommerce-hooks.php:1394-1419`) writes the
      session timestamp at `:1416` before `post_event()` classifies the request, and
      `maybe_send_initiate_checkout()` sets the per-cart guard at `:637-641` before its
      `post_event()` at `:668`. A prefetch of the checkout page therefore drops the event as `bot`
      — never held, never replayed — and the shopper's real click returns at `:625-629` on the
      guard the prefetch set, so InitiateCheckout is never sent for that cart. The same shape burns
      the 5-second AddToCart window at `:171`. Keep the in-request flag (`:1396-1400`) committed at
      check time, and keep a consent suppression consuming the state exactly as it does today.
- [x] 7.2 Key the cookieless rule on the query string rather than the request method — see 4.1.
- [x] 7.3 Move the credential gate from hook registration to the send — see 5.1.
- [x] 7.4 Exempt Purchase from the three generic HTTP-library signatures, keeping every other
      signature, the vendor prefix family and the prefetch rule applying to it as now. A Purchase
      suppressed as `bot` is deferred and then closed for good by `_pf_purchase_blocked_reported`
      (`:988`, enforced at `:791`), and the live user agent is read only when the request owns the
      order (`includes/consent.php:427`) — that is, when it carries the buyer's own cookies. For a
      headless storefront or a mobile app whose client is one of those libraries, that is every
      real order. Keep the three names in one list beside `PIXELFLOW_BOT_PATTERNS`; a signature a
      site adds through the filter is not exempt.
- [x] 7.5 Add `scrapy` to `PIXELFLOW_BOT_PATTERNS`. It keeps cookies and sends
      `Accept-Language` by default, so `request_looks_like_a_browser_navigation()` (`:297-313`)
      spares it and no existing signature matches its default agent.
- [x] 7.6 Set `customerData` on Purchase only when it is non-empty (`:822`), as the other two
      producers already do (`:229-231`, `:663-666`). The key used to be guaranteed non-empty by the
      order-id fallback this change removed, and `wp_json_encode([])` emits `[]`, not `{}`.
- [x] 7.7 Remove the `fbc` fallback block in `append_cookie_params_for_order()` (`:1333-1342`):
      now that the map above reads `_pf_cookie__fbc` (`:1317-1331`), it reads the same meta key and
      the same live cookie. It is a duplicate this change created.
- [x] 7.8 Re-run the full PHP and frontend suites after the fixes, then the live storefront suite.
      PHP 21 files green, frontend 16 tests green, and the live matrix 89 of 89 green in one run
      against the test site. Three earlier live runs failed only on the harness and on the stand:
      a crawler fixture that modelled an HTTP client as a browser, a site whose consent state had
      drifted, SSH connections throttled by the host, and a deferred blocked-purchase report
      landing in another scenario's window. No plugin defect surfaced live.
- [x] 7.9 Stop a send that never happened from closing durable state. The credential gate added in
      7.3 made `dispatch_event_post()` return `skipped` (`class-woocommerce-hooks.php:2235`) and
      `post_blocked_event()` return without sending, while several call sites closed state right
      after calling them. `send_settled_the_event()` (`:1484-1498`) is now the single rule: state
      closes on `sent`, on `held`, or on a `blocked` whose reason is the shopper's own consent
      decision, and on nothing else. The per-cart InitiateCheckout guard is the case that mattered
      — keyed on the cart fingerprint and never reopened, so a guard closed by a send that never
      left the site silenced that cart for good.
- [x] 7.10 Make it impossible to mark a blocked-purchase report delivered when it was not.
      `post_blocked_event()` reports whether the row left the site, and
      `send_blocked_purchase_report()` (`:1019-1045`) sets `_pf_purchase_blocked_reported` — which
      closes the order permanently (`:791`) — only after a row that did. When it did not, the
      marker and the order stay open and exactly one retry is re-armed, guarded by
      `wp_next_scheduled`. If the credentials never return, that retry re-arms and nothing is sent;
      the alternative would be an order closed by a report nobody received.
- [x] 7.11 Sweep every call site of the three sending functions and record a verdict for each,
      including those left unchanged: `class-woocommerce-hooks.php:255`, `:438`, `:680`, `:874`,
      `:1029`, `:2235`, and `trait-held-woo-events.php:134`, `:162`. `ajax_resolve_held_events()`
      and `resolve_held_events_on_page_view()` both reach the flush only through
      `resolve_held_events()`, so its credential guard (`:99`) is the single chokepoint.
- [x] 7.12 Ship the unconfigured-site notice switched off. Its wording and placement are being
      reworked, so `display_unconfigured_notice()` stays registered but renders only when the
      `pixelflow_show_unconfigured_notice` filter returns true, defaulting to false; a test pins the
      default, and the existing render tests opt in. The requirement "An
      unconfigured site is told why it is silent" is removed from this change's delta, to return
      with the rework.
