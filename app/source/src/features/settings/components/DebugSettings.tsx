import * as UI from '@pixelflow-org/plugin-ui';
import { WooClassSection } from '@/features/settings/components/WooClassSection.tsx';
import { productClasses, cartClasses } from '@/features/settings/const/classes.ts';
import { useSettings } from '@/features/settings/contexts/SettingsContext.tsx';

export function DebugSettings() {
  const { generalOptions, isWooCommerceActive, updateGeneralOption, saveSettings, isSaving } =
    useSettings();

  if (!isWooCommerceActive) {
    return null;
  }

  const handleToggle = async (checked: boolean) => {
    const newValue = checked ? 1 : 0;
    updateGeneralOption('debug_enabled', newValue);

    // Save immediately
    await saveSettings({ generalOptionsOverride: { debug_enabled: newValue } });
  };

  return (
    <div className="space-y-6 mt-6">
      <div className="space-y-3">
        <div className="flex items-center gap-3">
          <UI.Switch.Root
            checked={generalOptions.debug_enabled === 1}
            onCheckedChange={handleToggle}
            id="enableWooDebug"
            variant={'green'}
            disabled={isSaving}
          ></UI.Switch.Root>
          <UI.TooltipRoot>
            <UI.TooltipTrigger asChild>
              <UI.Label.Root className="cursor-pointer font-semibold" htmlFor="enableWooDebug">
                Debug WooCommerce Integration
              </UI.Label.Root>
            </UI.TooltipTrigger>
            <UI.TooltipContent>
              Enable debug mode to visually highlight which WooCommerce classes are being added
            </UI.TooltipContent>
          </UI.TooltipRoot>
        </div>
        <p className="text-sm text-foreground ml-11">
          Enable debug mode to track which WooCommerce classes are being added. Turning this ON adds
          colored borders and backgrounds to the Woo elements making added the classes visible (for
          admin only).
        </p>
        <p className="text-sm text-foreground ml-11">
          When debug mode is enabled, the <b>Track WooCommerce Purchase</b> option will be also
          debugged.
        </p>
        <p className="text-sm text-foreground ml-11">
          <b>Track WooCommerce Purchase</b> event usually run only once on the "Thank you" page
          after the order was completed. <br />
          When debug mode is enabled, the event will be triggered <b>EVERY TIME</b> you open or
          refresh the "Thank you" page.
        </p>
      </div>

      {generalOptions.debug_enabled === 1 && (
        <div className="space-y-6 mt-6">
          <div className="flex gap-8 [@media(max-width:1100px)]:flex-wrap">
            <WooClassSection
              title="DEBUG Product Classes"
              items={productClasses}
              sectionKey="debug-product"
              isDebugMode={true}
            />

            <WooClassSection
              title="DEBUG Cart Classes"
              items={cartClasses}
              sectionKey="debug-cart"
              isDebugMode={true}
            />
          </div>

          <div className="bg-blue-50 border border-blue-200 rounded-md p-4 text-sm">
            <p className="text-blue-900">
              <strong>Note:</strong> Some classes could appear in different locations (like for
              example <code className="bg-blue-100 px-1 rounded">info-itm-name-pf</code> exists on
              the <strong>Product page</strong>). Therefore it will be highlighted on the{' '}
              <strong>Cart page</strong> even if it was enabled for debug only in{' '}
              <strong>Product page</strong>, and vice versa.
            </p>
          </div>
        </div>
      )}
    </div>
  );
}
