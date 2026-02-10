/**
 * @fileoverview WooCommerce Settings component
 * @description Configuration interface for WooCommerce event tracking
 */

/** UI Components */
import * as UI from '@pixelflow-org/plugin-ui';

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
        </div>
      </div>
    </div>
  );
}
