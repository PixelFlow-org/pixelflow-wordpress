=== PixelFlow ===
Contributors: dependencyinjection
Tags: facebook pixel, conversions api, meta pixel, woocommerce tracking, ecommerce
Requires at least: 5.0
Tested up to: 6.7
Requires PHP: 7.4
Stable tag: 0.1.5
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Easily install Meta's Conversions API on your WordPress website with automatic WooCommerce integration and event tracking.

== Description ==

PixelFlow is the official WordPress plugin for [PixelFlow](https://pixelflow.so) - a powerful solution for implementing Meta's Conversions API on your website. 

**Why PixelFlow?**

Meta's Conversions API helps you track customer actions beyond the limitations of browser-based tracking, ensuring accurate data collection for your advertising campaigns. PixelFlow makes it simple to set up and manage without technical expertise.

**Key Features:**

* 🚀 **Easy Installation** - Add your PixelFlow tracking code with just a few clicks
* 🛒 **WooCommerce Integration** - Automatic event tracking for your online store
* 📊 **Complete Event Tracking** - Track purchases, add to cart, checkout, and more
* ⚙️ **Flexible Configuration** - Control which events and elements to track
* 🎨 **Theme Compatible** - Works with any WordPress theme
* 🔒 **User Role Exclusion** - Exclude admins or specific user roles from tracking
* 🐛 **Debug Mode** - Visual debugging tools for testing and validation
* 🎯 **Custom Class Assignment** - Automatically adds tracking classes to WooCommerce elements

**WooCommerce Features:**

When WooCommerce is active, PixelFlow automatically:

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

== Installation ==

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

Please follow this guide to set up your PixelFlow tracking code: [PixelFlow Setup Guide](https://docs.pixelflow.so/getting-started)

== Frequently Asked Questions ==

= What is PixelFlow? =

PixelFlow is a service that helps you implement Meta's Conversions API on your website, providing accurate tracking of customer actions for your Facebook and Instagram advertising campaigns.

= Do I need a PixelFlow account? =

Yes, you need to sign up for a PixelFlow account at [pixelflow.so](https://pixelflow.so) to get your tracking code. They offer a free plan to get started.

= Does this work without WooCommerce? =

Yes! The plugin works on any WordPress site. WooCommerce integration is an optional feature that provides automatic e-commerce event tracking.

= Will this slow down my website? =

No. The PixelFlow tracking code is lightweight and loads asynchronously, so it won't affect your website's performance.

= Can I exclude certain users from tracking? =

Yes. You can exclude specific user roles (like administrators) from being tracked in the plugin settings.

= Is this compatible with my theme? =

The plugin works with any WordPress theme. The WooCommerce integration works with most themes, but you can manually adjust class assignments if needed.

= How do I test if events are tracking correctly? =

1. Enable Debug Mode in the plugin settings
2. Visit your site while logged in
3. You'll see visual indicators on tracked elements
4. Check Meta Events Manager for event data

= Can I track custom events? =

Yes! You can manually add PixelFlow class names to any element on your site to track custom events. See the [PixelFlow documentation](https://docs.pixelflow.so) for details.

= Is my data secure? =

Yes. PixelFlow follows industry best practices for data security and privacy. Your tracking data is sent directly to Meta's servers.

= What if I need help? =

Visit [PixelFlow documentation](https://docs.pixelflow.so) or contact support through your PixelFlow dashboard.

== Screenshots ==

1. Main settings page with PixelFlow configuration
2. WooCommerce integration settings
3. Class assignment controls for custom tracking
4. Debug mode visualization
5. General settings and user role exclusion

== Changelog ==

= 0.1.5 =
* Initial public release
* Automatic PixelFlow tracking code insertion
* WooCommerce integration with auto-class assignment
* Settings page for configuration
* User role exclusion feature
* Debug mode for testing
* Support for all major PixelFlow event classes

== Upgrade Notice ==

= 0.1.5 =
Initial release. Install to start using Meta's Conversions API on your WordPress site.

== Privacy Policy ==

PixelFlow integrates with Meta's Conversions API to track customer actions on your website. This includes:

* Page views
* Product interactions
* Purchase events
* Form submissions (when configured)

All data is processed according to Meta's privacy policies and your local privacy regulations. Please ensure you have appropriate user consent mechanisms in place if required by law (e.g., GDPR, CCPA).

For more information:
* [PixelFlow Privacy Policy](https://pixelflow.so/privacy)
* [Meta Business Tools](https://www.facebook.com/business/tools)

== Additional Information ==

**Links:**
* [PixelFlow Website](https://pixelflow.so)
* [Documentation](https://docs.pixelflow.so)
* [Event Classes Reference](https://docs.pixelflow.so/pixelflow-classes-document)
* [Support](https://pixelflow.so/support)

**Requirements:**
* WordPress 5.0 or higher
* PHP 7.4 or higher
* WooCommerce 4.0+ (optional, for e-commerce features)

**Developer Resources:**
* The plugin is developer-friendly with filters and hooks
* Custom event tracking can be implemented using PixelFlow classes
* Compatible with custom WooCommerce themes


