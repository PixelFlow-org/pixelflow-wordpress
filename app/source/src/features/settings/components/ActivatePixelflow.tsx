/**
 * @fileoverview Activate PixelFlow component
 * @description Main toggle to enable/disable PixelFlow integration
 */

/** External libraries */
import { useState } from 'react';

/** UI Components */
import * as UI from '@pixelflow-org/plugin-ui';
import { Button } from '@pixelflow-org/plugin-ui';

/** Hooks */
import { useSettings } from '@/features/settings/contexts/SettingsContext.tsx';

interface ActivatePixelflowProps {
  onRegenerateScript: () => void;
}

/**
 * ActivatePixelflow component
 * @description Provides main toggle to activate/deactivate PixelFlow and regenerate script
 * @param props - Component props
 * @param props.onRegenerateScript - Callback to regenerate the tracking script
 * @returns ActivatePixelflow component
 */
export function ActivatePixelflow(props: ActivatePixelflowProps) {
  const { onRegenerateScript } = props;
  const { generalOptions, updateGeneralOption, saveSettings, isSaving } = useSettings();

  const [regenerateScriptLoading, setRegenerateScriptLoading] = useState(false);
  const onRegenerateScriptHandle = async () => {
    setRegenerateScriptLoading(true);
    await onRegenerateScript();
    setRegenerateScriptLoading(false);
  };

  const handleToggle = async (checked: boolean) => {
    const newValue = checked ? 1 : 0;
    updateGeneralOption('enabled', newValue);
    // Save immediately
    await saveSettings({ generalOptionsOverride: { enabled: newValue } });
  };

  return (
    <div className="space-y-6 pf-layout-main pf-module-home bg-background text-foreground min-h-full !p-[12px] flex flex-wrap justify-between content-center">
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
      <div>
        <Button.Root
          size="xsmall"
          onClick={onRegenerateScriptHandle}
          disabled={regenerateScriptLoading}
        >
          {regenerateScriptLoading ? 'Updating...' : 'Update Script'}
        </Button.Root>
      </div>
    </div>
  );
}
