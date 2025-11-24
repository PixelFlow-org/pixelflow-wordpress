# PixelFlow WordPress Plugin

PixelFlow official WordPress plugin. Easily install Meta's Conversions API on your website with WooCommerce integration and event tracking.

PixelFlow is the official WordPress plugin for [PixelFlow](https://pixelflow.so) - a powerful solution for implementing Meta's Conversions API on your website.

## Why PixelFlow?

Meta's Conversions API helps you track customer actions beyond the limitations of browser-based tracking, ensuring accurate data collection for your advertising campaigns. PixelFlow makes it simple to set up and manage without technical expertise.

## Features

- 🚀 **Easy Installation** - Add your PixelFlow tracking code with just a few clicks
- 🛒 **WooCommerce Integration** - Automatic event tracking for your online store
- 📊 **Complete Event Tracking** - Track purchases, add to cart, checkout, and more
- ⚙️ **Flexible Configuration** - Control which events and elements to track
- 🎨 **Theme Compatible** - Works with most of WordPress themes
- 🔒 **User Role Exclusion** - Exclude admins or specific user roles from tracking
- 🐛 **Debug Mode** - Visual debugging tools for testing and validation
- 🎯 **Custom Class Assignment** - Automatically adds tracking classes to WooCommerce elements

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

* Tracks purchase events with complete order details
* Adds event tracking classes to product, cart, and checkout pages
* Captures product names, prices, quantities, and totals
* Works with most WooCommerce themes out of the box

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

PixelFlow is a service that helps you implement Meta's Conversions API on your website, providing accurate tracking of customer actions for your Facebook/Meta and Instagram advertising campaigns.

### How is this different to just using the Meta Pixel on my website?

Server-side tracking (also called the Conversion API) is better than using the standard "Facebook/Meta Pixel" implementation because it works even if visitors have ad blockers, tracking prevention, or cookie restrictions enabled meaning you get more complete, accurate data to measure results and improve your ads. It tracks events on your website before they can be blocked by a user's browser meaning you can get more data sent to Meta events manager and in turn reduce your costs because your data is better and more accurate.

### What are the benefits of PixelFlow?

PixelFlow tracks all events that users take on your website and send those events to Meta before the tracking can be blocked by the browser, adblocker, iPhone etc. This means you get far more accurate reporting data in your Meta events manager leading to reduced costs and increased conversions. Essentially you'll spend less on Meta ads and get a better return as you'll have more accurate data.

### How much more accurate is PixelFlow vs just the Meta pixel on my website?

PixelFlow picks up 30–40% more conversions that Meta Pixel misses - especially on iOS, Safari, or when ad blockers are active. While Meta relies on browser-side data (which gets blocked), PixelFlow uses server-side tracking to ensure you see the full picture - every click, sale, and signup, even the hidden ones.

### I'm not technical, can you just set it up for me?

We've actually designed PixelFlow as an alternative to the more complex platforms because we totally understand these things can seem complicated. With this in mind, the setup is super simple - you just follow the video and steps and you're all done. However, if you need any help we're happy to jump on a video call with you and get it all setup!

### How is GDPR handled?

For EU customers, you can ensure the PixelFlow script only loads after they have accepted whatever consent banner you choose to implement.

### Do I need a PixelFlow account?

Yes, you need to sign up for a PixelFlow account at [pixelflow.so](https://pixelflow.so) to get your tracking code. We offer a free trial to get started.

### Does this work without WooCommerce?

Yes! The plugin works on any WordPress site. WooCommerce integration is an optional feature that provides automatic e-commerce event tracking.

### Will this slow down my website?

No. The PixelFlow tracking code is lightweight and loads asynchronously, so it won't affect your website's performance.

### Can I exclude certain users from tracking?

Yes. You can exclude specific user roles (like administrators) from being tracked in the plugin settings.

### Is this compatible with my theme?

The plugin works with any WordPress themes. The WooCommerce integration works with most themes, but you can manually adjust class assignments if needed.

### How do I test if events are tracking correctly?

1. Enable Debug Mode in the plugin settings
2. Visit your site while logged in
3. You'll see visual indicators on tracked elements
4. Check Meta Events Manager for event data

### Can I track custom events?

Yes! You can manually add PixelFlow class names to any element on your site to track custom events. See the [PixelFlow documentation](https://docs.pixelflow.so) for details.

### Is my data secure?

Yes. PixelFlow follows industry best practices for data security and privacy. Your tracking data is sent directly to Meta's servers.

### What if I need help?

Visit [PixelFlow documentation](https://docs.pixelflow.so) or [Contact Support](https://pixelflow.so/contact).

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

