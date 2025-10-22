import * as UI from '@pixelflow-org/plugin-ui';
import { WooClassSection } from './WooClassSection';
import type { PixelFlowGeneralOptions, PixelFlowClasses } from '../types/settings.types';
import { productClasses, cartClasses } from '../const/classes.ts';

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
      <div>
        <p>
          These options will add the classes to track your WooCommerce purchases automatically.
          <br />
          Please check your site thoroughly after enabling this option and saving the changes
        </p>
        <p>
          You can{' '}
          <a
            href="https://docs.pixelflow.so/pixelflow-classes-document#purchase-events-classes-document"
            target="_blank"
          >
            read more about the classes here
          </a>
        </p>
      </div>

      {generalOptions.woo_enabled === 1 && (
        <div className="space-y-6 mt-6">
          <div className="flex items-center gap-3">
            <UI.Switch.Root
              checked={generalOptions.woo_purchase_tracking === 1}
              onCheckedChange={(checked) =>
                onUpdateGeneral('woo_purchase_tracking', checked ? 1 : 0)
              }
              id="enableWooPurchaseTracking"
            ></UI.Switch.Root>
            <UI.TooltipRoot>
              <UI.TooltipTrigger asChild>
                <UI.Label.Root className="cursor-pointer" htmlFor="enableWooPurchaseTracking">
                  Track WooCommerce Purchase
                </UI.Label.Root>
              </UI.TooltipTrigger>
              <UI.TooltipContent>
                Enable automatic tracking of WooCommerce purchase events after checkout
              </UI.TooltipContent>
            </UI.TooltipRoot>
          </div>
          <div>
            <p>
              Adds a script which will track the WooCommerce purchase event on the order received
              page after a successful checkout
            </p>
          </div>

          <div className="flex gap-8 [@media(max-width:1100px)]:flex-wrap">
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
          </div>
        </div>
      )}
    </div>
  );
}
