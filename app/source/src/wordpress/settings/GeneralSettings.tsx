import * as UI from '@pixelflow-org/plugin-ui';
import type { PixelFlowGeneralOptions } from './settings.types';

interface GeneralSettingsProps {
  generalOptions: PixelFlowGeneralOptions;
  onUpdate: <K extends keyof PixelFlowGeneralOptions>(
    key: K,
    value: PixelFlowGeneralOptions[K]
  ) => void;
}

export function GeneralSettings({ generalOptions, onUpdate }: GeneralSettingsProps) {
  return (
    <div className="space-y-6 pf-layout-main pf-module-home bg-background text-foreground min-h-full !p-[12px]">
      <div>
        <h2 className="text-lg font-semibold mb-4 !text-foreground">Integration Settings</h2>
        <p className="text-sm mb-6 text-foreground">
          Configure your PixelFlow integration settings below.
        </p>
      </div>

      <div className="flex items-center gap-3">
        <UI.Switch.Root
          checked={generalOptions.enabled === 1}
          onCheckedChange={(checked) => onUpdate('enabled', checked ? 1 : 0)}
          id="enablePixelflow"
        ></UI.Switch.Root>
        <UI.TooltipRoot>
          <UI.TooltipTrigger asChild>
            <UI.Label.Root className="cursor-pointer" htmlFor="enablePixelflow">
              Enable PixelFlow Integration
            </UI.Label.Root>
          </UI.TooltipTrigger>
          <UI.TooltipContent>
            Enable or disable the PixelFlow integration on your site
          </UI.TooltipContent>
        </UI.TooltipRoot>
      </div>
    </div>
  );
}
