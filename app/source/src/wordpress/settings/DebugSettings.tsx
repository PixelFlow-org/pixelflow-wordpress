import * as UI from '@pixelflow-org/plugin-ui';
import { WooClassSection } from './WooClassSection';
import type { PixelFlowGeneralOptions, PixelFlowClasses } from './settings.types';
import { productClasses, cartClasses, checkoutClasses } from './classes.ts';

interface DebugSettingsProps {
  generalOptions: PixelFlowGeneralOptions;
  debugOptions: PixelFlowClasses;
  onUpdateGeneral: <K extends keyof PixelFlowGeneralOptions>(
    key: K,
    value: PixelFlowGeneralOptions[K]
  ) => void;
  onUpdateDebug: <K extends keyof PixelFlowClasses>(key: K, value: PixelFlowClasses[K]) => void;
  isWooEnabled: boolean;
}

export function DebugSettings({
  generalOptions,
  debugOptions,
  onUpdateGeneral,
  onUpdateDebug,
  isWooEnabled,
}: DebugSettingsProps) {
  if (!isWooEnabled) {
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
        <p className="text-sm text-foreground ml-12">
          Enable debug mode to track which WooCommerce classes are being added. Turning this ON adds
          colored borders and backgrounds to the Woo elements making added the classes visible (for
          admin only).
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

            <WooClassSection
              title="DEBUG Checkout Classes"
              items={checkoutClasses}
              values={debugOptions}
              onUpdate={onUpdateDebug}
              sectionKey="debug-checkout"
              isDebugMode={true}
            />
          </div>

          <div className="bg-blue-50 border border-blue-200 rounded-md p-4 text-sm">
            <p className="text-blue-900">
              <strong>Note:</strong> Some classes could appear in different locations (like for
              example <code className="bg-blue-100 px-1 rounded">info-itm-name-pf</code> exists on
              the <strong>Product page</strong> and <strong>Checkout page</strong>). Therefore it
              will be highlighted on the <strong>Checkout page</strong> even if it was enabled for
              debug only in <strong>Product page</strong>, and vice versa.
            </p>
          </div>
        </div>
      )}
    </div>
  );
}
