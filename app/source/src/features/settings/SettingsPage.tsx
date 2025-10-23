/**
 * @fileoverview Settings Page component
 * @description Main settings page container with provider wrapper
 */

/** External libraries */
import { useState } from 'react';

/** UI Components */
import * as UI from '@pixelflow-org/plugin-ui';

/** Components */
import { WooCommerceSettings } from '@/features/settings/components/WooCommerceSettings.tsx';

/** Hooks */
import { SettingsProvider, useSettings } from '@/features/settings/contexts/SettingsContext.tsx';

type SettingsPageProps = {
  onRegenerateScript: () => void;
};

/**
 * SettingsPageContent component
 * @description Inner content component that accesses settings context
 * @param props - Component props
 * @param props.onRegenerateScript - Callback to regenerate tracking script
 * @returns SettingsPageContent component
 */
function SettingsPageContent(props: SettingsPageProps) {
  const { onRegenerateScript } = props;
  const { generalOptions, scriptCode, isLoading, error } = useSettings();

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
    <div className="max-w-6xl py-3">
      <div className=" rounded-lg shadow-sm border border-gray-200 p-6">
        {error && (
          <div className="mb-6 p-4 bg-red-50 border border-red-200 rounded-md">
            <p className="text-red-800 text-sm">{error}</p>
          </div>
        )}
        <div className="space-y-6">
          <WooCommerceSettings />
        </div>

        <div className="mt-3 flex items-center justify-between">
          <p className="text-sm text-foreground">
            Changes will be saved and applied automatically.
          </p>
        </div>

        {generalOptions.enabled ? (
          <div className="space-y-2">
            <p className="text-sm text-foreground">
              Below is the integration script code that will be injected into the site head.
              <br />
              <b>You do not need to copy it anywhere, it will be added automatically.</b>
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
  );
}

/**
 * SettingsPage component
 * @description Wraps SettingsPageContent with SettingsProvider for context access
 * @param props - Component props
 * @param props.onRegenerateScript - Callback to regenerate tracking script
 * @returns SettingsPage component
 */
export function SettingsPage(props: SettingsPageProps) {
  return (
    <SettingsProvider>
      <SettingsPageContent {...props} />
    </SettingsProvider>
  );
}
