/**
 * @fileoverview WooCommerce Settings component
 * @description Configuration interface for WooCommerce event tracking
 */

/** UI Components */
import * as UI from '@pixelflow-org/plugin-ui';

/** Components */
import { WooClassSection } from '@/features/settings/components/WooClassSection.tsx';
import { DebugSettings } from '@/features/settings';

/** Constants */
import { productClasses, cartClasses } from '@/features/settings/const/classes.ts';

/** Hooks */
import { useSettings } from '@/features/settings/contexts/SettingsContext.tsx';

/**
 * WooCommerceSettings component
 * @description Manages WooCommerce eCommerce event tracking configuration including
 * Add to Cart, Checkout, and Purchase event tracking
 * @returns WooCommerceSettings component
 */
export function WooCommerceSettings() {
  const {
    generalOptions,
    isWooCommerceActive,
    updateGeneralOption,
    saveSettings,
    error,
    isSaving,
  } = useSettings();

  if (!isWooCommerceActive) {
    return null;
  }

  const handleToggle = async (checked: boolean) => {
    const newValue = checked ? 1 : 0;
    updateGeneralOption('woo_enabled', newValue);

    // Save immediately
    await saveSettings({ generalOptionsOverride: { woo_enabled: newValue } });
  };

  const handlePurchaseTrackingToggle = async (checked: boolean) => {
    const newValue = checked ? 1 : 0;
    updateGeneralOption('woo_purchase_tracking', newValue);

    // Save immediately
    await saveSettings({ generalOptionsOverride: { woo_purchase_tracking: newValue } });
  };

  return (
    <div className="max-w-6xl py-3">
      <div className=" rounded-lg shadow-sm border border-gray-200 p-6">
        {error && (
          <div className="mb-6 p-4 bg-red-50 border border-red-200 rounded-md">
            <p className="text-red-800 text-sm">{error}</p>
          </div>
        )}
        <div className="flex items-center gap-3">
          <UI.Switch.Root
            checked={generalOptions.woo_enabled === 1}
            onCheckedChange={handleToggle}
            id="enableWoo"
            variant={'green'}
            disabled={isSaving}
          ></UI.Switch.Root>
          <UI.TooltipRoot>
            <UI.TooltipTrigger asChild>
              <UI.Label.Root className="cursor-pointer" htmlFor="enableWoo">
                Track WooCommerce eCommerce Events
              </UI.Label.Root>
            </UI.TooltipTrigger>
            <UI.TooltipContent>
              We will automatically track all add to cart, checkout and purchase events along with
              associated metadata where available
            </UI.TooltipContent>
          </UI.TooltipRoot>
        </div>
        <div>
          <p className="text-sm text-foreground ml-11">
            We will automatically track all add to cart, checkout and purchase events along with
            associated metadata where available
          </p>
          {/*<p className="text-sm text-foreground ml-11">*/}
          {/*  You can{' '}*/}
          {/*  <a*/}
          {/*    href="https://docs.pixelflow.so/pixelflow-classes-document#purchase-events-classes-document"*/}
          {/*    target="_blank"*/}
          {/*    className="text-primary underline"*/}
          {/*  >*/}
          {/*    read more about the classes here*/}
          {/*  </a>*/}
          {/*</p>*/}
        </div>

        {generalOptions.woo_enabled === 1 && (
          <div className="space-y-6 mt-6">
            <div className="flex gap-8 [@media(max-width:1100px)]:flex-wrap">
              <WooClassSection
                title={
                  <>
                    Enable <b>Add to Cart</b> tracking
                  </>
                }
                comment="Enable tracking for Add to Cart events on Product and Shop pages"
                items={productClasses}
                sectionKey="product"
              />

              <WooClassSection
                title={
                  <>
                    Enable <b>Initiate checkout</b> tracking
                  </>
                }
                comment="Enable tracking for Initiate Checkout events on the Cart page"
                items={cartClasses}
                sectionKey="cart"
              />

              <div>
                <div className="flex items-center gap-3 mb-3">
                  <UI.Switch.Root
                    checked={generalOptions.woo_purchase_tracking === 1}
                    onCheckedChange={handlePurchaseTrackingToggle}
                    id="enableWooPurchaseTracking"
                    variant={'green'}
                    disabled={isSaving}
                  ></UI.Switch.Root>
                  <UI.TooltipRoot>
                    <UI.TooltipTrigger asChild>
                      <UI.Label.Root className="cursor-pointer" htmlFor="enableWooPurchaseTracking">
                        <span>
                          Enable <b>Purchase Event</b> tracking
                        </span>
                      </UI.Label.Root>
                    </UI.TooltipTrigger>
                    <UI.TooltipContent>
                      Enable automatic tracking of WooCommerce purchase events on checkout
                    </UI.TooltipContent>
                  </UI.TooltipRoot>
                </div>
                <div>
                  <p className="text-sm text-foreground ml-11">
                    Adds a script which will track the WooCommerce purchase event on the order
                    received page after a successful checkout
                  </p>
                </div>
              </div>
            </div>
            <hr className="mt-6 opacity-30" />
            <DebugSettings />
          </div>
        )}
      </div>
    </div>
  );
}
