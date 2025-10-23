import * as UI from '@pixelflow-org/plugin-ui';
import { useSettings } from '../hooks/useSettings';

export function ActivatePixelflow() {
  const { generalOptions, updateGeneralOption, saveSettings, isSaving } = useSettings();

  const handleToggle = async (checked: boolean) => {
    const newValue = checked ? 1 : 0;
    updateGeneralOption('enabled', newValue);
    // Save immediately
    await saveSettings({ generalOptionsOverride: { enabled: newValue } });
  };

  return (
    <div className="space-y-6 pf-layout-main pf-module-home bg-background text-foreground min-h-full !p-[12px]">
      <div className="flex items-center gap-3">
        <UI.TooltipRoot>
          <UI.TooltipTrigger asChild>
            <UI.Label.Root className="cursor-pointer" htmlFor="enablePixelflow">
              Activate PixelFlow
            </UI.Label.Root>
          </UI.TooltipTrigger>
          <UI.TooltipContent>
            Enable or disable the PixelFlow integration on your site
          </UI.TooltipContent>
        </UI.TooltipRoot>
        <UI.Switch.Root
          checked={generalOptions.enabled === 1}
          onCheckedChange={handleToggle}
          disabled={isSaving}
          id="enablePixelflow"
          variant={'green'}
        ></UI.Switch.Root>
      </div>
    </div>
  );
}
