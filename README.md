# PixelFlow WordPress Plugin

Facebook Conversions API for WooCommerce. One-click setup. Auto track WooCommerce events to Meta with 100% accuracy. Bypass iOS restrictions & ad blockers.

Stop losing 30-50% of your WooCommerce conversions to iOS restrictions and ad blockers. [PixelFlow](https://pixelflow.so) is the no-code solution for implementing Meta's Conversions API on WooCommerce - so your ads finally get the data they need to optimize.

- No more missing sales or conversions in Facebook ads manager
- See every single event and where it came from down to the campaign, adset and ad
- No for developers, Google Tags Manager or expensive solution

## Why PixelFlow?

Most server-side tracking solutions are built for developers or enterprise teams. They require complex setups, expensive consultants, or per-event pricing that spirals out of control.

## PixelFlow is different:

- **No developer needed** — install plugin, click enable and you're DONE.
- **Unlimited event tracking** — flat monthly pricing, no per-event fees or surprise charges
- **Set up in minutes** — not days or weeks

## What You Get

- **Recover lost conversions** that browser-based tracking misses
- **Improve Event Match Quality scores** for better ad targeting and lower CPAs
- **Track WooCommerce events automatically** — Add to Cart, Checkout, Purchase
- **Real-time event monitoring** — see exactly what's being sent to Meta
- **Works alongside your existing Pixel** — CAPI supplements browser tracking, it doesn't replace it. PixelFlow automatically loads both your pixel & CAPI for you for perfect event. deduplication and more coverage.

## Why Server-Side Tracking Matters

Since iOS 14, Meta's browser-based Pixel misses up to half your conversions. Ad blockers make it worse. When Meta doesn't receive your conversion data, it can't optimize your campaigns — so you pay more for worse results.
PixelFlow sends events directly from your server to Meta, bypassing ad blockers and privacy restrictions entirely. Your ads get complete data. Your ROAS improves.

## Built for WooCommerce

When WooCommerce is active, PixelFlow automatically tracks:

- **Add to Cart** — with product name, price, and quantity
- **Initiate Checkout** — captures cart totals
- **Purchase** — full order data including revenue

No manual setup. No custom code. Works instantly.

## Additional options:

- Exclude free products from tracking
- Exclude admins and specific user roles
- Control which events fire and when

**Not Using WooCommerce? We've Got You Covered.**

Running a WordPress site without an online store? PixelFlow works for you too without the need for a plugin. Learn more at [PixelFlow](https://pixelflow.so) .

## Installation

**Automatic Installation:**

1. Log in to your WordPress admin panel
2. Go to Plugins > Add New
3. Search for "PixelFlow"
4. Click "Install Now" and then "Activate"
5. Go to Settings > PixelFlow Settings to configure

**Manual Installation:**

1. Download the plugin zip file
2. Go to Plugins > Add New > Upload Plugin
3. Choose the downloaded file and click "Install Now"
4. Activate the plugin
5. Go to Settings > PixelFlow Settings to configure

**Configuration:**

Please follow this guide to set up your PixelFlow tracking code: [PixelFlow Setup Guide](https://docs.pixelflow.so/wordpress-setup)

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

PixelFlow is a WooCommerce plugin that implements Meta's Conversions API (CAPI) on your store without any coding. It sends your conversion data directly from your server to Meta, bypassing the iOS restrictions and ad blockers that cause the standard Meta Pixel to miss 30-50% of your sales.

### How is PixelFlow different from the standard Meta Pixel?

The Meta Pixel runs in your visitor's browser, which means it gets blocked by iOS privacy settings, ad blockers, and cookie restrictions. PixelFlow sends data server-to-server, so Meta receives your conversion data regardless of what's happening in the browser. You get more accurate tracking and better ad performance.

### How is PixelFlow different from competitors?

Other implementations are either too complex or have "proprietary" systems to make up for their higher pricing. PixelFlow just simplifies all of this - we offer perfect server side tracking for WooCommerce at an affordable price for our users. We're a small team and always available for support and video calls!

### How long does setup take?

About 2 minutes. Install the plugin, connect your Meta account, and click enable. PixelFlow handles everything else automatically.

### Will PixelFlow slow down my website?

No. PixelFlow sends data from your server after the page has loaded, so it has zero impact on your storefront speed or customer experience.

### Do I need to remove my existing Meta Pixel?

No. PixelFlow works alongside your existing Pixel and automatically handles deduplication so Meta doesn't count events twice. Running both gives you maximum coverage.

### Can I try PixelFlow for free?

Yes. PixelFlow offers a 7-day free trial with no credit card required. You can test everything and see events flowing before you commit.

### How do I know if events are tracking correctly?

PixelFlow includes a real-time event log in your dashboard. You can see every event sent to Meta, including the data payload, delivery status, and any errors. You can also verify in Meta Events Manager.

### What if I'm not using WooCommerce?

PixelFlow also works on regular WordPress sites and other platforms like Webflow, Framer, and Squarespace using a simple tracking script. Visit [pixelflow.so](https://pixelflow.so) to learn more.

### Is PixelFlow GDPR compliant?

PixelFlow is a data processor that sends conversion data to Meta on your behalf. You are responsible for obtaining appropriate user consent where required by law (GDPR, CCPA, etc.). PixelFlow works with popular consent plugins and can be configured to only fire events after consent is given.

### What support is available if I need help?

We offer documentation, video tutorials, and email support on all plans. Most users complete setup without any assistance, but we're here if you get stuck. You can [ask your questions right here](https://wordpress.org/support/plugin/pixelflow/) or visit [PixelFlow documentation](https://docs.pixelflow.so) or [Contact Support](https://pixelflow.so/contact).

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

- WordPress 5.0 or higher
- PHP 7.4 or higher
- WooCommerce 4.0+ (optional, for e-commerce features)

## Privacy Policy

PixelFlow integrates with Meta's Conversions API to track customer actions on your website (when configured). This may include:

* Page views
* Product interactions
* Purchase events
* Form submissions

All data is processed according to Meta's privacy policies and your local privacy regulations. Please ensure you have appropriate user consent mechanisms in place if required by law (e.g., GDPR, CCPA).

For more information:
* [PixelFlow Privacy Policy](https://pixelflow.so/privacy)
* [Meta Business Tools](https://www.facebook.com/business/tools)

## Changelog

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

