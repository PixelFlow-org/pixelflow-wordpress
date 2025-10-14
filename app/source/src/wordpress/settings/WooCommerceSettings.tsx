import * as UI from '@pixelflow-org/plugin-ui';
import { WooClassSection } from './WooClassSection';
import type { PixelFlowGeneralOptions, PixelFlowClasses } from './settings.types';
import { productClasses, cartClasses, checkoutClasses } from './classes.ts';

interface WooCommerceSettingsProps {
  generalOptions: PixelFlowGeneralOptions;
  classOptions: PixelFlowClasses;
  onUpdateGeneral: <K extends keyof PixelFlowGeneralOptions>(
    key: K,
    value: PixelFlowGeneralOptions[K]
  ) => void;
  onUpdateClass: <K extends keyof PixelFlowClasses>(key: K, value: PixelFlowClasses[K]) => void;
  isEnabled: boolean;
  isWooCommerceActive: boolean;
}

export function WooCommerceSettings({
  generalOptions,
  classOptions,
  onUpdateGeneral,
  onUpdateClass,
  isEnabled,
  isWooCommerceActive,
}: WooCommerceSettingsProps) {
  if (!isWooCommerceActive || !isEnabled) {
    return null;
  }

  return (
    <div className="space-y-6 mt-8 pt-8 border-t">
      <div className="flex items-center gap-3">
        <UI.Switch.Root
          checked={generalOptions.woo_enabled === 1}
          onCheckedChange={(checked) => onUpdateGeneral('woo_enabled', checked ? 1 : 0)}
          id="enableWoo"
        ></UI.Switch.Root>
        <UI.TooltipRoot>
          <UI.TooltipTrigger asChild>
            <UI.Label.Root className="cursor-pointer" htmlFor="enableWoo">
              Enable WooCommerce Integration
            </UI.Label.Root>
          </UI.TooltipTrigger>
          <UI.TooltipContent>
            Enable WooCommerce integration to track eCommerce events
          </UI.TooltipContent>
        </UI.TooltipRoot>
      </div>

      {generalOptions.woo_enabled === 1 && (
        <div className="space-y-6 mt-6">
          <div className="flex flex-wrap gap-8">
            <WooClassSection
              title="Product Classes"
              items={productClasses}
              values={classOptions}
              onUpdate={onUpdateClass}
              sectionKey="product"
            />

            <WooClassSection
              title="Cart Classes"
              items={cartClasses}
              values={classOptions}
              onUpdate={onUpdateClass}
              sectionKey="cart"
            />

            <WooClassSection
              title="Checkout Classes"
              items={checkoutClasses}
              values={classOptions}
              onUpdate={onUpdateClass}
              sectionKey="checkout"
            />
          </div>
        </div>
      )}
    </div>
  );
}
