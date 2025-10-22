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
  isWooCommerceActive,
}: WooCommerceSettingsProps) {
  if (!isWooCommerceActive) {
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
