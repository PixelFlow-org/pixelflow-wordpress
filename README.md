# PixelFlow WordPress Plugin

WordPress plugin for [PixelFlow](https://pixelflow.so) - automatically inserts PixelFlow tracking code and provides WooCommerce integration with automatic event class assignment.

## Features

- 🚀 Automatic PixelFlow tracking code insertion
- 🛒 WooCommerce integration with auto-class assignment
- ⚙️ Settings page for configuration
- 🎨 Support for custom themes and templates
- 📊 Meta Pixel event tracking via classes

## Installation

1. Upload the plugin folder to `/wp-content/plugins/`
2. Activate the plugin through the 'Plugins' menu in WordPress
3. Configure your PixelFlow settings in the admin panel

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

To add a new class (e.g., `info-pdct-ctnr-list-pf`), update the following files:

1. **`app/source/src/wordpress/settings/classes.ts`**
   - Add the class to the appropriate array (`productClasses`, `cartClasses`, or `checkoutClasses`)
   ```typescript
   {
     key: 'woo_class_cart_products_container',
     className: 'info-pdct-ctnr-list-pf',
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
     - `class-woocommerce-checkout-hooks.php` for checkout page classes

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
- Add to Wishlist
- Initiate Checkout
- And more...

### Event Classes

All event classes follow the PixelFlow specification. For the complete list of available classes, see:
[PixelFlow Classes Documentation](https://docs.pixelflow.so/pixelflow-classes-document#purchase-events-classes-document)

### Purchase Event Classes

The following classes are automatically applied to WooCommerce checkout and order confirmation pages:

| Class | Applied To |
|-------|-----------|
| `info-chk-itm-ctnr-pf` | Main container with all checkout items |
| `info-chk-itm-pf` | Individual item container |
| `info-itm-name-pf` | Item name |
| `info-itm-prc-pf` | Item price |
| `info-itm-qnty-pf` | Item quantity |
| `info-totl-amt-pf` | Total amount |

### Theme Compatibility

The automatic class assignment works with the default WooCommerce template. 

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
- WooCommerce 4.0 or higher (for WooCommerce integration features)

## Support

For more information about PixelFlow and event tracking, visit:
- [PixelFlow Documentation](https://docs.pixelflow.so)
- [Event Classes Reference](https://docs.pixelflow.so/pixelflow-classes-document)

## License

Private - © PixelFlow Organization

This package is proprietary and confidential. Unauthorized copying or distribution is prohibited.

## Credits

Developed for [PixelFlow.so](https://pixelflow.so)

