# PixelFlow WordPress Plugin

Facebook Pixel & TikTok Pixel with server-side Conversions API (CAPI) for WooCommerce & WordPress. Auto-tracks sales & forms. No code, no GTM.

## Description

**Your ads are only as good as the data you send them.** iOS privacy rules, ad blockers and cookie limits stop the browser pixel from firing, so Meta and TikTok miss a big share of your sales and leads, and their algorithms optimise on half the picture.

PixelFlow fixes that. It sends your WooCommerce and WordPress events **server-side** through the **Meta Conversions API** (Facebook & Instagram) and the **TikTok Events API**, alongside your browser pixels, with automatic deduplication so every sale is counted once.

Built for store owners and marketers, not developers. Install the plugin, connect your pixels, and events start flowing. No code, no Google Tag Manager, no server to manage.

**Free 7-day trial, no credit card required.** [Start at pixelflow.so](https://pixelflow.so)

### Why store owners choose PixelFlow

- **Meta and TikTok in one plugin.** Send the same clean, enriched events to Facebook, Instagram and TikTok ads.
- **WooCommerce tracked automatically.** Add to Cart, Initiate Checkout and Purchase fire on their own, with full order data.
- **Forms tracked automatically.** Contact Form 7, Elementor Pro, Fluent Forms, Gravity Forms, Ninja Forms and WPForms.
- **Point & click setup.** Tag any button or page from your dashboard. No GTM, no developer.
- **More than better data.** Attribution, conversion journeys and a full event log show what's actually driving your results.

### Automatic WooCommerce event tracking

PixelFlow tracks the events that matter for ecommerce as soon as WooCommerce is active:

- **Add to Cart** with product, price and quantity
- **Initiate Checkout** with cart totals
- **Purchase** with full order data and revenue

Every event is enriched with the data Meta and TikTok use to match the buyer to their ad click: hashed email, phone, name and address, click IDs (fbclid / ttclid), browser IDs, IP address and user agent. Better matching means higher Event Match Quality, more attributed sales and lower CPAs.

**Store controls:**

- **Product IDs for catalog ads.** Send product IDs with your events in the format your Meta catalog uses, for dynamic product ads and catalog reporting.
- **Exclude SKUs.** Stop specific products (gift cards, samples, wholesale items) from triggering events.
- **Free products.** Choose whether free products trigger Add to Cart, Checkout and Purchase events.
- **Pick your events.** Turn Add to Cart, Initiate Checkout or Purchase on or off individually.

### Automatic form tracking

Turn on form tracking and PixelFlow detects the forms on your site from the most popular form plugins:

- Contact Form 7
- Elementor Pro Forms
- Fluent Forms
- Gravity Forms
- Ninja Forms
- WPForms

Choose the event each form sends (Lead, CompleteRegistration and more) and which fields to use. Contact details are normalised and SHA-256 hashed before they leave your server, and message text is never sent, stored or logged. Only have one name field? Pick it for both first and last name and PixelFlow splits it automatically.

### Point & click setup, no Google Tag Manager

For anything else, use the PixelFlow dashboard:

- **Visual Tagger:** open your live site, click any button, link or form, and choose the event it should fire.
- **Page URL triggers:** fire an event when someone lands on a page, like /thank-you = Lead, with an optional value.

### Clean data your ads can trust

- **Bot blocking:** bots, crawlers and automation tools are filtered out before they reach your pixels.
- **Deduplication:** browser and server events share an event ID, so Meta and TikTok count each conversion once.
- **Blocking rules:** decide when an event should not fire: once per session, only with a click ID, or not again within a set window.
- **Exclude user roles:** keep administrators, shop managers and your own team out of your tracking.
- **Consent aware:** works with your cookie banner, honours Global Privacy Control and Do Not Track, and can hold events until consent is given (GDPR and CCPA friendly).

### See what's driving results

PixelFlow doesn't just send better data, it shows you what's working:

- **Attribution:** visitors, leads, purchases and revenue by traffic source, from Facebook and TikTok to Google, organic and direct.
- **Conversion journeys:** the full path behind every purchase and lead, from the ad they clicked to every page they visited.
- **Event log:** every event in real time, with the exact payload sent to Meta and TikTok and its delivery status.

### Also included

- Unlimited pixels across ad accounts
- Unlimited events on every plan, with no per-event fees
- Stripe and Calendly integrations
- Works alongside your existing pixel. No need to remove anything
- Zero impact on page speed: events are sent from your server
- Debug logs for WooCommerce and form events

### Not using WooCommerce?

PixelFlow works on any WordPress site. Track leads from your forms, or tag buttons and pages with point & click triggers. PixelFlow also works on Webflow, Framer, Squarespace and more at [pixelflow.so](https://pixelflow.so).

### Privacy

PixelFlow sends conversion events to the Meta Conversions API and the TikTok Events API on your behalf (when configured). This may include page views, product interactions, purchase events and form submissions.

Form submissions are sent only when form tracking is turned on in the Form Settings. A form event carries the event name, the form title, and the visitor's contact details (email, phone, name, city, state, postcode, country), normalised and SHA-256 hashed before they leave the server. Message bodies and other free-text fields are never sent, stored or logged.

All data is processed according to Meta's and TikTok's policies and your local privacy regulations. Please make sure you have appropriate consent mechanisms in place where required by law (e.g. GDPR, CCPA).

- [PixelFlow Privacy Policy](https://pixelflow.so/privacy)
- [Meta Business Tools Terms](https://www.facebook.com/legal/businesstech)
- [TikTok Business Products (Data) Terms](https://ads.tiktok.com/i18n/official/policy/business-products-terms)

### Links

- [PixelFlow website](https://pixelflow.so)
- [Documentation](https://docs.pixelflow.so)
- [Support](https://pixelflow.so/contact)
- [Source code on GitHub](https://github.com/PixelFlow-org/pixelflow-wordpress)

*PixelFlow is an independent product and is not affiliated with, endorsed by or sponsored by Meta Platforms, Inc., TikTok, Automattic or WooCommerce.*

## Installation

1. Install PixelFlow from **Plugins → Add New** (search for "PixelFlow") and click **Activate**.
2. Go to **Settings → PixelFlow Settings** and click **Go to Dashboard** to create your free account. No credit card needed.
3. Add your Meta pixel, your TikTok pixel, or both.
4. Switch on **Activate PixelFlow**. WooCommerce events start sending automatically.
5. Optional: turn on **Form Settings** to track your contact forms, and set up exclusions under **WooCommerce Settings** and **Advanced Settings**.

## WooCommerce Features

When WooCommerce integration is active, PixelFlow automatically:

* Tracks Add To Cart, Initiate Checkout, Purchase events
* Captures product names, prices, quantities, totals and the other required information
* Works with almost any WooCommerce theme out of the box
* Free products could be optionally excluded from tracking

**Tracked Events Include:**

* Purchase
* Add to Cart
* Initiate Checkout
* View Content
* Lead Events
* And more...

**Perfect for:**

* E-commerce stores using WooCommerce
* Businesses running Facebook/Meta advertising campaigns
* Marketers who need accurate conversion tracking
* Anyone looking to improve their Meta Pixel implementation

## Development

### Building the Plugin

The plugin includes a React-based admin interface that needs to be built before deployment.

```bash
sh build_plugin.sh
```

This will compile the frontend assets to `app/dist/`.

### Development Workflow

```bash
cd app/source
npm run dev  # Start development server with hot reload
```

### Testing core/features/ui changes with yalc

To try unpublished changes from `plugin-core` or `plugin-features` before publishing:

1. **One-time link** (if not already done):
   ```bash
   # From repo root: publish and link
   cd packages/pixelflow-plugin-core && pnpm run yalc:publish
   cd ../pixelflow-plugin-features && yalc add @pixelflow-org/plugin-core && pnpm install && pnpm run yalc:publish
   cd ../../platforms/pixelflow-wordpress/app/source && yalc add @pixelflow-org/plugin-core @pixelflow-org/plugin-features && pnpm install
   ```

2. **After every change** in core or features:
   - Push from the package you changed:
     ```bash
     cd packages/pixelflow-plugin-core && pnpm run yalc:push
     # and/or
     cd packages/pixelflow-plugin-features && pnpm run yalc:push
     ```
   - In the WordPress app: **clear Vite’s cache and restart** or changes won’t show:
     ```bash
     cd platforms/pixelflow-wordpress/app/source
     rm -rf node_modules/.vite
     pnpm dev
     ```

If you don’t see changes, you usually forgot a `yalc:push` in the package you edited or didn’t clear `node_modules/.vite` and restart the dev server.

### Adding New PixelFlow Classes

To add a new class (e.g., `info-chk-itm-ctnr-pf`), update the following files:

1. **`app/source/src/wordpress/settings/classes.ts`**
   - Add the class to the appropriate array (`productClasses`, `cartClasses`)
   ```typescript
   {
     key: 'woo_class_cart_products_container',
     className: 'info-chk-itm-ctnr-pf',
     description: 'Add this to the element which wraps all products',
   }
   ```

2. **`app/source/src/wordpress/settings/settings.types.ts`**
   - Add the key to the `PixelFlowClasses` interface
   ```typescript
   export interface PixelFlowClasses {
     // ... existing keys
     woo_class_cart_products_container: number;
   }
   ```

3. **`pixelflow.php`**
   - Add the default value to the class options and debug options arrays

4. **`includes/woo/hooks/`**
   - Add the hook implementation in the appropriate file:
     - `class-woocommerce-product-hooks.php` for product page classes
     - `class-woocommerce-cart-hooks.php` for cart page classes

After adding new classes, rebuild the frontend:
```bash
cd app/source
npm run build
```

## Continuous Integration

Every pull request to `main`, and every push to `main`, runs
`.github/workflows/ci.yml`. All seven checks below must pass before a pull request
can be merged; each one is reproducible locally with a single command.

The single required status check is `ci`, an aggregating job that fails unless
every job below succeeded. Individual results still show on the pull request.

| Check | Reproduce locally | Run from |
|---|---|---|
| Lint (ESLint, warnings are fatal) | `pnpm lint` | `app/source` |
| Types | `pnpm typecheck` | `app/source` |
| Formatting (Prettier) | `pnpm format:check` — `pnpm format` fixes it | `app/source` |
| Unit tests (vitest) | `pnpm test` | `app/source` |
| Production build + packaging | `./build_plugin.sh prod` | plugin root |
| Plugin Check (WordPress's own checker) | see note below | a WordPress install |
| PHP syntax + tests, on 7.4 / 8.1 / 8.3 | `php -l <file>` and `php tests/test-*.php` | plugin root |

Notes:

- Node is pinned to the version in `.nvmrc` (also `engines.node`); pnpm to
  `packageManager` in `app/source/package.json`. CI reads both, so it runs what
  you run.
- The `@pixelflow-org/*` packages are private. CI authenticates with the
  `GH_PACKAGES_TOKEN` secret; locally you need credentials for
  `npm.pkg.github.com` in your own `~/.npmrc`. A 404 on one of those packages is
  an authentication failure, not a missing package.
- There is no separate `pnpm build` step. `build_plugin.sh` runs the production
  build itself, and CI checks the zip it produces, so the build that ships is the
  build that is verified.
- Plugin Check runs against the **packaged** plugin — the unpacked
  `build/pixelflow.zip` — not against this repository. Most of the tree
  (`app/source/`, `e2e/`, `tests/`, `openspec/`, tooling, dotfiles) never ships,
  and checking it reports dozens of findings against files no user receives.
  Errors block the merge; warnings appear as annotations on the diff without
  blocking. Because a green checks box on the pull request says nothing about
  warnings, the action also posts the full report as a pull-request comment and
  updates that same comment on each push. The report is attached to every run as
  the `plugin-check-results` artifact as well.
- To approximate Plugin Check locally you need a WordPress install with the
  `plugin-check` plugin active and this repository as the plugin directory, then:

  ```bash
  wp plugin check pixelflow --ignore-codes=outdated_tested_upto_header \
    --exclude-directories=app/source,e2e,tests,openspec,svn,build,assets,.github \
    --exclude-files=build_plugin.sh,publish_plugin.sh,CLAUDE.md,.nvmrc,.gitignore,.wordpress-version-checker.json
  ```

  The exclusions stand in for what `build_plugin.sh` leaves out; CI needs no such
  list because it checks the real zip.

  **Keep your local `plugin-check` current** (`wp plugin update plugin-check`).
  CI installs the latest from wordpress.org on every run, so an older local copy
  reports fewer findings and will tell you a branch is clean when CI disagrees.
  The 2.x line, for one, added the check that compares API usage against the
  plugin's `Requires at least` version.
- `outdated_tested_upto_header` is ignored in CI on purpose. It compares
  `readme.txt` against the live WordPress release feed, so as a gating check a
  WordPress release would turn `main` red with no commit here. That gap is tracked
  by `.github/workflows/wordpress-version-check.yml`, which opens an issue instead.
- Playwright e2e (`e2e/`) does **not** run in CI — it needs a live WordPress.
  Run it locally.
- Branch protection settings and the exact required check names live in
  `.github/BRANCH_PROTECTION.md`.

## Deployment

### Quick Build (Recommended)

Use the automated build script to create a production-ready zip file:

```bash
./build_plugin.sh
```

This will:
1. Build the frontend assets
2. Create a timestamped zip file in `build/` directory
3. Include only production files (excluding `app/source`)

### Manual Deployment

When uploading the plugin to production manually:

1. Build the production assets (see above)
2. **Exclude the `app/source` directory**
3. **Upload only `app/dist`** and other plugin files
4. The production plugin should include:
   - `app/dist/` ✅
   - `includes/` ✅
   - `admin/` ✅
   - `pixelflow.php` ✅
   - `README.md` ✅
   - `app/source/` ❌ (exclude)

## WooCommerce Integration

The plugin automatically adds PixelFlow event tracking classes to WooCommerce elements for:

- Purchase events
- Add to Cart
- Initiate Checkout
- And more...

### Event Classes

All event classes follow the PixelFlow specification. For the complete list of available classes, see:
[PixelFlow Classes Documentation](https://docs.pixelflow.so/pixelflow-classes-document)

### Purchase Event Tracking

The Purchase Event will be sent from the Order Confirmed page automatically.

### Theme Compatibility

The plugin works with any WordPress themes. The WooCommerce integration works with most themes, but you can manually adjust class assignments if needed.

**If classes don't work with your theme or customizations:**

1. Disable the specific class auto-assignment in plugin settings
2. Manually add the class name to your theme template
3. Test to ensure events are tracking correctly

## Form Integration

For form tracking (Lead, Subscribe, Contact events), class names should be added manually to your form elements or their closest parents:

```html
<form class="info-frm-cntr-pf">
  <input type="text" class="info-cust-fn-pf" placeholder="First Name">
   <div class="info-cust-fn-pf">
      <input type="text" placeholder="Last Name">
   </div>
  <input type="email" class="info-cust-em-pf" placeholder="Email">
  <input type="tel" class="info-cust-ph-pf" placeholder="Phone">
  <button class="action-btn-lead-011-pf">Submit</button>
</form>
```

## Frequently Asked Questions

### What is PixelFlow?

PixelFlow is a server-side tracking plugin for WordPress and WooCommerce. It sends your sales, leads and other events to Meta (Facebook & Instagram) through the Conversions API and to TikTok through the Events API, so your ad platforms get the data the browser pixel misses.

### Does it work with TikTok as well as Facebook?

Yes. Connect a Meta pixel, a TikTok pixel or both, and PixelFlow sends your events to each one server-side.

### How is this different from the standard Meta Pixel or TikTok Pixel?

The browser pixel runs in your visitor's browser, where iOS privacy settings, ad blockers and cookie restrictions block it. PixelFlow also sends each event from your server, so Meta and TikTok receive it regardless of what happens in the browser. Both run together and are deduplicated automatically.

### Do I need to remove my existing pixel?

No. PixelFlow works alongside your existing pixel and handles deduplication, so each event is only counted once.

### Do I need Google Tag Manager or a developer?

No. WooCommerce and form events are tracked automatically, and anything else can be set up with point & click triggers in the PixelFlow dashboard.

### Which WooCommerce events are tracked?

Add to Cart, Initiate Checkout and Purchase, with product details, cart totals and full order data including revenue. You can turn each event on or off, include or exclude free products, and exclude specific SKUs.

### Which form plugins are supported?

Contact Form 7, Elementor Pro, Fluent Forms, Gravity Forms, Ninja Forms and WPForms. Forms built with other tools can be tracked with a page URL or Visual Tagger trigger from the PixelFlow dashboard.

### Can I send product IDs for catalog / dynamic product ads?

Yes. Turn on product IDs in WooCommerce Settings and choose the format that matches the Content ID in your Meta catalog. PixelFlow does not upload or sync your catalog. It tags each event with the ID so Meta can match it to products already in your catalog.

### Can I stop admins and staff being tracked?

Yes. Under Advanced Settings, choose any user roles (Administrator, Editor, Shop manager and more) that should not have the tracking script loaded.

### Is PixelFlow GDPR compliant?

PixelFlow acts as a data processor that sends conversion data to Meta and TikTok on your behalf. It works with popular consent plugins, honours Global Privacy Control and Do Not Track, and can hold events until consent is given. You remain responsible for collecting consent where the law requires it.

### Will PixelFlow slow down my website?

No. Events are sent from your server after the page has loaded, so there's no impact on your storefront speed.

### How do I know events are tracking correctly?

The PixelFlow dashboard has a real-time event log showing every event, the exact data sent to Meta and TikTok, and its delivery status. You can also check in Meta Events Manager or TikTok Events Manager.

### Can I try PixelFlow for free?

Yes. Every plan starts with a free 7-day trial, with no credit card required.

### What support is available?

Documentation, video tutorials and email support on all plans. Ask a question in the support forum, or contact us at [pixelflow.so](https://pixelflow.so).

## Troubleshooting

### Events not tracking?

1. Check that PixelFlow tracking code is properly inserted
2. Verify classes are being added to elements (inspect with browser DevTools)
3. Check Meta Events Manager for event data
4. Ensure ad blockers are disabled for testing

### Classes not being applied?

1. Check if your theme has custom WooCommerce templates
2. Disable auto-class assignment for specific elements
3. Manually add classes to your theme files
4. Clear WordPress and browser cache

## Requirements

- WordPress 6.5 or higher
- PHP 7.4 or higher
- WooCommerce 4.0+ (optional, for e-commerce features)

## Changelog

### 1.2.2
WooCommerce: optional setting to send Purchase only for a customer's first paid order, so subscription renewals and repeat orders stop counting as new purchases. Forms: events now include city, state, postcode and country from the visitor's location when the form has no such fields.

### 1.2.1
Refreshed the WordPress.org listing (description, screenshots, banner); listing images no longer ship inside the plugin zip.

### 1.2.0
Form submissions from Contact Form 7, Elementor Pro, Fluent Forms, Gravity Forms, Ninja Forms and WPForms can now be sent as Meta events from the server, off by default and configured per form.

### 1.1.20
WooCommerce AddToCart, InitiateCheckout and Purchase now include TikTok's ttp and ttclid when the shopper's cookies carry them.

### 1.1.19
AddToCart now reports product prices excluding tax, matching InitiateCheckout and Purchase, on stores that display prices including tax.

### 1.1.18
The plugin now derives external_id itself from the visitor id, using the same formula as the PixelFlow browser script, so guests are no longer collapsed onto a single site-wide identifier; added guzzle, httpx, aiohttp and meta-externalads to the bot signatures plus a meta-external catch-all for the rest of Meta's crawler family, stopped reporting speculative browser prefetch and anonymous cookieless add-to-cart URLs, named the matched rule in the debug log instead of BOT_UA, and a site missing its credentials now stops sending events.

### 1.1.17
Added GDPR consent gating: WooCommerce events now carry the visitor's consent state, resolved from the WP Consent API or the _pf_consent cookie. Raised the minimum supported WordPress version to 6.5, which the admin settings page requires. Added Refresh button to Debug Log popup in Advanced Settings, enabling log re-fetch without closing the modal.

### 1.1.16
Fixed a fatal error (HTTP 500) on WooCommerce add-to-cart when a third-party integration (e.g. CheckoutWC Order Bumps) calls the add-to-cart hook with null instead of an int/array for the variation arguments.

### 1.1.15
Initial setup fix

### 1.1.14
Added Product ID Format setting for WooCommerce events — choose between numeric ID, prefixed, SKU, legacy, or off; removed the redundant content_ids field from all event payloads.
Added support of the first and last touch attribution collection.

### 1.1.13
Add an option to exclude products with custom SKU from tracking
Custom filters introduced to disable certain e-commerce events programmatically: pixelflow_should_send_add_to_cart, pixelflow_should_send_initiate_checkout, pixelflow_should_send_purchase

### 1.1.12
Disable some WooCommerce events from tracking, add plugin version to logs

### 1.1.11
XStore theme false AddToCart event sending disabled

### 1.1.10
Woo events tracking hardened to prevent double or unnecessary events tracked

### 1.1.9
Improved Woo events tracking: blocked bots actions, cart updates now counts, include hashed customer details if the user is logged in, coupon in cart counts in products prices for InitiateCheckout and Purchase events

### 1.1.8
Fixed plugin authentication

### 1.1.7
Prevent sending events from bots to PixelFlow
Improved the way of finding the correct siteURL to send
Improved getting the UTM params

### 1.1.6
New logic for working with URL triggers (formerly known as tracking urls)

### 1.1.5
Added debug section to debug WooCommerce events

### 1.1.4
Track WooCommerce events, such as Add to Cart, Initiate Checkout, Purchase, using Woo hooks, so this is now working out of the box

### 1.1.3
Error handling and auth handling improved

### 1.1.2
Making e-mail field optional for register

### 1.1.1
Made the main plugin options available after setup even if not auth in PixelFlow

### 1.1.0
Simplified the analytics script, excluded dynamically loaded parameters, WordPress support up to 6.9

### 0.1.21
Replaced ob_ usage with javascript to add classes

### 0.1.20
Updated the way how the script is injected

### 0.1.19
Added button to quickly copy site ID (on the login screen)
Fixed Woo settings global variable name
Updated UI components

### 0.1.18
Main developer changed
Option name changed from pixelflow_script_code to pixelflow_code

### 0.1.17
Improved pixel id form field validation to prevent invalid values from submitting

### 0.1.16
* Added links to docs and PixelFlow Dashboard
* UX improved

### 0.1.15
* Initial public release
* Automatic PixelFlow tracking code insertion
* WooCommerce integration with auto-class assignment
* Settings page for configuration
* User role exclusion feature
* Debug mode for testing
* Support for all major PixelFlow event classes

## Support

For more information about PixelFlow and event tracking, visit:
- [PixelFlow Website](https://pixelflow.so)
- [PixelFlow Documentation](https://docs.pixelflow.so)
- [Event Classes Reference](https://docs.pixelflow.so/pixelflow-classes-document)
- [Support](https://pixelflow.so/contact)
- [GitHub Repository](https://github.com/PixelFlow-org/pixelflow-wordpress) - feel free to contribute or report issues

## License

GPLv2 or later
https://www.gnu.org/licenses/gpl-2.0.html

## Credits

Developed for [PixelFlow.so](https://pixelflow.so)

