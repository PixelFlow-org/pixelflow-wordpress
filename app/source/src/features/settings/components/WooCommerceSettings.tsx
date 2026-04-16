/**
 * @fileoverview WooCommerce Settings component
 * @description Configuration interface for WooCommerce event tracking
 */

/** UI Components */
import * as UI from '@pixelflow-org/plugin-ui';

/** Hooks */
import { useSettings } from '@/features/settings/contexts/SettingsContext.tsx';
import { PixelFlowGeneralOptions } from '@/features/settings';

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
  console.log('WooCommerceSettings', generalOptions);

  const handleToggleOption = async (option: keyof PixelFlowGeneralOptions) => {
    const newValue = generalOptions[option] === 1 ? 0 : 1;
    updateGeneralOption(option, newValue);

    // Save immediately
    await saveSettings({ generalOptionsOverride: { [option]: newValue } });
  };

  // Master event toggles: when disabling, also force the freebies sub-option off
  const masterFreebiesMap: Partial<
    Record<keyof PixelFlowGeneralOptions, keyof PixelFlowGeneralOptions>
  > = {
    woo_disable_add_to_cart: 'woo_disable_add_to_cart_freebies',
    woo_disable_initiate_checkout: 'woo_disable_initiate_checkout_freebies',
    woo_disable_purchase: 'woo_disable_purchase_freebies',
  };

  const handleToggleMasterEvent = async (masterOption: keyof PixelFlowGeneralOptions) => {
    const newValue = generalOptions[masterOption] === 1 ? 0 : 1;
    updateGeneralOption(masterOption, newValue);

    const freebiesKey = masterFreebiesMap[masterOption];
    const cascade = newValue === 1 && freebiesKey;
    if (cascade) {
      updateGeneralOption(freebiesKey!, 1);
    }

    await saveSettings({
      generalOptionsOverride: {
        [masterOption]: newValue,
        ...(cascade ? { [freebiesKey!]: 1 } : {}),
      },
    });
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
            onCheckedChange={() => handleToggleOption('woo_enabled')}
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
        </div>
        {generalOptions.woo_enabled === 1 && (
          <div className="space-y-6 mt-6">
            <div className="flex gap-3 [@media(max-width:1100px)]:flex-wrap flex-col">
              <h4 className="font-semibold !mb-0 !text-foreground !text-lg">eCommerce Events</h4>
              <div className="flex items-center gap-3">
                <UI.Switch.Root
                  checked={generalOptions.woo_disable_add_to_cart === 0}
                  onCheckedChange={() => handleToggleMasterEvent('woo_disable_add_to_cart')}
                  id="woo_disable_add_to_cart"
                  variant={'green'}
                  disabled={isSaving}
                ></UI.Switch.Root>
                <UI.TooltipRoot>
                  <UI.TooltipTrigger asChild>
                    <UI.Label.Root className="cursor-pointer" htmlFor="woo_disable_add_to_cart">
                      <span>
                        Enable <b>Add to Cart</b> event
                      </span>
                    </UI.Label.Root>
                  </UI.TooltipTrigger>
                  <UI.TooltipContent>
                    When disabled, Add to Cart events will not be sent for any product.
                  </UI.TooltipContent>
                </UI.TooltipRoot>
              </div>
              <div className="flex items-center gap-3">
                <UI.Switch.Root
                  checked={generalOptions.woo_disable_initiate_checkout === 0}
                  onCheckedChange={() => handleToggleMasterEvent('woo_disable_initiate_checkout')}
                  id="woo_disable_initiate_checkout"
                  variant={'green'}
                  disabled={isSaving}
                ></UI.Switch.Root>
                <UI.TooltipRoot>
                  <UI.TooltipTrigger asChild>
                    <UI.Label.Root
                      className="cursor-pointer"
                      htmlFor="woo_disable_initiate_checkout"
                    >
                      <span>
                        Enable <b>Initiate Checkout</b> event
                      </span>
                    </UI.Label.Root>
                  </UI.TooltipTrigger>
                  <UI.TooltipContent>
                    When disabled, Initiate Checkout events will not be sent regardless of cart
                    contents.
                  </UI.TooltipContent>
                </UI.TooltipRoot>
              </div>
              <div className="flex items-center gap-3">
                <UI.Switch.Root
                  checked={generalOptions.woo_disable_purchase === 0}
                  onCheckedChange={() => handleToggleMasterEvent('woo_disable_purchase')}
                  id="woo_disable_purchase"
                  variant={'green'}
                  disabled={isSaving}
                ></UI.Switch.Root>
                <UI.TooltipRoot>
                  <UI.TooltipTrigger asChild>
                    <UI.Label.Root className="cursor-pointer" htmlFor="woo_disable_purchase">
                      <span>
                        Enable <b>Purchase</b> event
                      </span>
                    </UI.Label.Root>
                  </UI.TooltipTrigger>
                  <UI.TooltipContent>
                    When disabled, Purchase events will not be sent for any order.
                  </UI.TooltipContent>
                </UI.TooltipRoot>
              </div>
            </div>
            <div className="flex gap-3 [@media(max-width:1100px)]:flex-wrap flex-col">
              <h4 className="font-semibold !mb-0 !text-foreground !text-lg">Additional options</h4>
              <div
                className={`flex items-center gap-3${generalOptions.woo_disable_add_to_cart === 1 ? ' opacity-40 pointer-events-none' : ''}`}
              >
                <UI.Switch.Root
                  checked={generalOptions.woo_disable_add_to_cart_freebies === 0}
                  onCheckedChange={() => handleToggleOption('woo_disable_add_to_cart_freebies')}
                  id="woo_disable_add_to_cart_freebies"
                  variant={'green'}
                  disabled={isSaving || generalOptions.woo_disable_add_to_cart === 1}
                ></UI.Switch.Root>
                <UI.TooltipRoot>
                  <UI.TooltipTrigger asChild>
                    <UI.Label.Root
                      className="cursor-pointer"
                      htmlFor="woo_disable_add_to_cart_freebies"
                    >
                      <span>
                        Enable <b>Add to Cart</b> event for <b>free products</b>
                      </span>
                    </UI.Label.Root>
                  </UI.TooltipTrigger>
                  <UI.TooltipContent>
                    When this option is disabled, products with a price of zero will not trigger Add
                    to Cart events.
                  </UI.TooltipContent>
                </UI.TooltipRoot>
              </div>
              <div
                className={`flex items-center gap-3${generalOptions.woo_disable_initiate_checkout === 1 ? ' opacity-40 pointer-events-none' : ''}`}
              >
                <UI.Switch.Root
                  checked={generalOptions.woo_disable_initiate_checkout_freebies === 0}
                  onCheckedChange={() =>
                    handleToggleOption('woo_disable_initiate_checkout_freebies')
                  }
                  id="woo_disable_initiate_checkout_freebies"
                  variant={'green'}
                  disabled={isSaving || generalOptions.woo_disable_initiate_checkout === 1}
                ></UI.Switch.Root>
                <UI.TooltipRoot>
                  <UI.TooltipTrigger asChild>
                    <UI.Label.Root
                      className="cursor-pointer"
                      htmlFor="woo_disable_initiate_checkout_freebies"
                    >
                      <span>
                        Enable <b>Initiate Checkout</b> event for <b>free products</b>
                      </span>
                    </UI.Label.Root>
                  </UI.TooltipTrigger>
                  <UI.TooltipContent>
                    When this option is disabled, if current cart contains only free products, the
                    Initiate Checkout event will not be triggered. It does not interfere the
                    Checkout process itself
                  </UI.TooltipContent>
                </UI.TooltipRoot>
              </div>
              <div
                className={`flex items-center gap-3${generalOptions.woo_disable_purchase === 1 ? ' opacity-40 pointer-events-none' : ''}`}
              >
                <UI.Switch.Root
                  checked={generalOptions.woo_disable_purchase_freebies === 0}
                  onCheckedChange={() => handleToggleOption('woo_disable_purchase_freebies')}
                  id="woo_disable_purchase_freebies"
                  variant={'green'}
                  disabled={isSaving || generalOptions.woo_disable_purchase === 1}
                ></UI.Switch.Root>
                <UI.TooltipRoot>
                  <UI.TooltipTrigger asChild>
                    <UI.Label.Root
                      className="cursor-pointer"
                      htmlFor="woo_disable_purchase_freebies"
                    >
                      <span>
                        Enable <b>Purchase</b> event for <b>free products</b>
                      </span>
                    </UI.Label.Root>
                  </UI.TooltipTrigger>
                  <UI.TooltipContent>
                    When this option is disabled, if current cart contains only free products, the
                    Purchase event will not be triggered. It does not interfere the Purchase process
                    itself
                  </UI.TooltipContent>
                </UI.TooltipRoot>
              </div>
            </div>
          </div>
        )}
      </div>
    </div>
  );
}
