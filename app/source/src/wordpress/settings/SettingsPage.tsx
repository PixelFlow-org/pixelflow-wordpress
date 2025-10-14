import { TooltipProvider } from '@pixelflow-org/plugin-ui';
import { useSettings } from './useSettings';
import { GeneralSettings } from './GeneralSettings';
import { WooCommerceSettings } from './WooCommerceSettings';
import { DebugSettings } from './DebugSettings';
import * as UI from '@pixelflow-org/plugin-ui';
import { useState } from 'react';

type SettingsPageProps = {
  onRegenerateScript: () => void;
};

export function SettingsPage(props: SettingsPageProps) {
  const { onRegenerateScript } = props;
  const {
    generalOptions,
    classOptions,
    debugOptions,
    scriptCode,
    isWooCommerceActive,
    isLoading,
    isSaving,
    error,
    updateGeneralOption,
    updateClassOption,
    updateDebugOption,
    saveSettings,
  } = useSettings();

  const handleSave = async () => {
    await saveSettings();
  };

  const [regenerateScriptLoading, setRegenerateScriptLoading] = useState(false);
  const onRegenerateScriptHandle = async () => {
    setRegenerateScriptLoading(true);
    await onRegenerateScript();
    setRegenerateScriptLoading(false);
  };

  if (isLoading) {
    return (
      <div className="p-8">
        <div className="animate-pulse">
          <div className="h-8 bg-gray-200 rounded w-1/4 mb-4"></div>
          <div className="h-4 bg-gray-200 rounded w-1/2 mb-8"></div>
          <div className="space-y-4">
            <div className="h-10 bg-gray-200 rounded"></div>
            <div className="h-32 bg-gray-200 rounded"></div>
          </div>
        </div>
      </div>
    );
  }

  return (
    <TooltipProvider>
      <div className="max-w-6xl mx-auto p-8">
        <div className=" rounded-lg shadow-sm border border-gray-200 p-6">
          {error && (
            <div className="mb-6 p-4 bg-red-50 border border-red-200 rounded-md">
              <p className="text-red-800 text-sm">{error}</p>
            </div>
          )}

          <div className="space-y-6">
            <GeneralSettings generalOptions={generalOptions} onUpdate={updateGeneralOption} />

            <WooCommerceSettings
              generalOptions={generalOptions}
              classOptions={classOptions}
              onUpdateGeneral={updateGeneralOption}
              onUpdateClass={updateClassOption}
              isEnabled={generalOptions.enabled === 1}
              isWooCommerceActive={isWooCommerceActive}
            />

            <DebugSettings
              generalOptions={generalOptions}
              debugOptions={debugOptions}
              onUpdateGeneral={updateGeneralOption}
              onUpdateDebug={updateDebugOption}
              isWooEnabled={generalOptions.enabled === 1 && generalOptions.woo_enabled === 1}
            />
          </div>

          <div className="mt-8 pt-6 border-t flex items-center justify-between">
            <p className="text-sm text-foreground">
              Changes will be saved and applied after you click the <b>'Save settings'</b> buutton
            </p>
            <button onClick={handleSave} disabled={isSaving} className="button button-primary">
              {isSaving ? 'Saving...' : 'Save Settings'}
            </button>
          </div>

          {generalOptions.enabled ? (
            <div className="space-y-2">
              <p className="text-sm text-foreground">
                Below is the integration script code that will be injected into the site head.
              </p>
              <UI.Input.Root>
                <UI.Input.Wrapper>
                  <textarea
                    id="script_code"
                    value={scriptCode}
                    rows={3}
                    readOnly={true}
                    className="w-full px-3 py-2 border border-gray-300 rounded-md font-mono text-sm !text-foreground resize-y focus:outline-none focus:ring-2 focus:ring-blue-500 placeholder-foreground"
                    placeholder="Integration script that will be injected into the site head"
                  />
                </UI.Input.Wrapper>
              </UI.Input.Root>

              <button
                onClick={onRegenerateScriptHandle}
                className="button button-primary"
                disabled={regenerateScriptLoading}
              >
                {regenerateScriptLoading ? 'Regenerating...' : 'Regenerate Script'}
              </button>
            </div>
          ) : null}
        </div>
      </div>
    </TooltipProvider>
  );
}
