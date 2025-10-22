import * as UI from '@pixelflow-org/plugin-ui';
import { WooClassSection } from './WooClassSection';
import type { PixelFlowGeneralOptions, PixelFlowClasses } from '../types/settings.types';
import { productClasses, cartClasses } from '../const/classes.ts';

interface DebugSettingsProps {
  generalOptions: PixelFlowGeneralOptions;
  debugOptions: PixelFlowClasses;
  onUpdateGeneral: <K extends keyof PixelFlowGeneralOptions>(
    key: K,
    value: PixelFlowGeneralOptions[K]
  ) => void;
  onUpdateDebug: <K extends keyof PixelFlowClasses>(key: K, value: PixelFlowClasses[K]) => void;
  isWooCommerceActive: boolean;
}

export function DebugSettings({
  generalOptions,
  debugOptions,
  onUpdateGeneral,
  onUpdateDebug,
  isWooCommerceActive,
}: DebugSettingsProps) {
  if (!isWooCommerceActive) {
    return null;
  }

  return (
    <div className="space-y-6 mt-8 pt-8 border-t">
      <div className="space-y-3">
        <div className="flex items-center gap-3">
          <UI.Switch.Root
            checked={generalOptions.debug_enabled === 1}
            onCheckedChange={(checked) => onUpdateGeneral('debug_enabled', checked ? 1 : 0)}
            id="enableWooDebug"
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
              values={debugOptions}
              onUpdate={onUpdateDebug}
              sectionKey="debug-product"
              isDebugMode={true}
            />

            <WooClassSection
              title="DEBUG Cart Classes"
              items={cartClasses}
              values={debugOptions}
              onUpdate={onUpdateDebug}
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
