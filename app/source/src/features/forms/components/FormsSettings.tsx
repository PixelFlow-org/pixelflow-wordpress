/**
 * @fileoverview Forms settings
 * @description Master toggle, detected form plugins and the form list
 */

/** External libraries */
import { useCallback, useEffect, useState } from 'react';
import { toast } from 'react-toastify';

/** UI Components */
import * as UI from '@pixelflow-org/plugin-ui';

/** Hooks */
import { useSettingsContext } from '@/features/settings/contexts/useSettingsContext.ts';

/** API */
import { useLazyGetFormsQuery, useSaveFormSettingsMutation } from '@/features/forms/api';

/** Components */
import { FormRowItem } from '@/features/forms/components/FormRowItem';
import { NameSplitHint } from '@/features/forms/components/NameSplitHint';
import Notification from '@/shared/components/Notification/Notification.tsx';

/** Types */
import type { FormPatch, FormsListing } from '@/features/forms/types';

const DASHBOARD_URL = 'https://dashboard.pixelflow.so/dashboard/overview';

/**
 * FormsSettings component
 * @returns Forms tab content
 */
export function FormsSettings() {
  const { generalOptions, updateGeneralOption, saveSettings, isSaving } = useSettingsContext();

  const [loadForms] = useLazyGetFormsQuery();
  const [saveFormSettings] = useSaveFormSettingsMutation();
  const [listing, setListing] = useState<FormsListing | null>(null);
  const [loadError, setLoadError] = useState<string | null>(null);
  const [savingForm, setSavingForm] = useState(false);
  const [openKey, setOpenKey] = useState<string | null>(null);

  const trackingOn = generalOptions.forms_enabled === 1;

  const refresh = useCallback(async () => {
    try {
      setListing(await loadForms(undefined, false).unwrap());
      setLoadError(null);
    } catch {
      setLoadError('Failed to load forms');
    }
  }, [loadForms]);

  useEffect(() => {
    void refresh();
  }, [refresh]);

  const toggleTracking = async (checked: boolean) => {
    const value = checked ? 1 : 0;
    updateGeneralOption('forms_enabled', value);
    await saveSettings({ generalOptionsOverride: { forms_enabled: value } });
  };

  const saveForm = async (key: string, title: string, patch: FormPatch) => {
    setSavingForm(true);
    try {
      setListing(await saveFormSettings({ [key]: { title, ...patch } }).unwrap());
    } catch {
      toast('Failed to save form settings', { type: 'error' });
    } finally {
      setSavingForm(false);
    }
  };

  const active = listing?.plugins.filter((p) => p.active) ?? [];
  const inactive = listing?.plugins.filter((p) => !p.active) ?? [];

  return (
    <div className="max-w-6xl py-3">
      <div className="rounded-lg shadow-sm border border-gray-200 p-6 space-y-6">
        <section>
          <div className="flex items-center gap-3">
            <UI.Switch.Root
              id="forms-enabled"
              checked={trackingOn}
              disabled={isSaving}
              onCheckedChange={toggleTracking}
              variant={'green'}
            />
            <UI.Label.Root htmlFor="forms-enabled" className="cursor-pointer">
              <span className="text-sm font-semibold">Track form submissions</span>
            </UI.Label.Root>
          </div>
          <p className="text-sm text-foreground ml-12 mt-1">
            Sends a Meta event from your server when a visitor completes a form, with hashed contact
            details and the form title. Message text is never sent.
          </p>
        </section>

        {/* As with WooCommerce tracking: everything below belongs to tracking that is on */}
        {trackingOn && (
          <>
            {loadError && <p className="text-sm text-red-800">{loadError}</p>}

            {!listing && !loadError && (
              <div data-testid="forms-loading">
                <UI.LoadingScreen />
              </div>
            )}

            {listing && (
              <section>
                {active.length === 0 ? (
                  <p className="text-sm" data-testid="forms-no-plugin">
                    No supported form plugin was detected. Supported:{' '}
                    {listing.plugins.map((p) => p.label).join(', ')}.
                  </p>
                ) : (
                  <p className="text-sm" data-testid="forms-plugin-status">
                    Active form plugins: {active.map((p) => p.label).join(', ')}.
                    {inactive.length > 0 && (
                      <> Supported but not active: {inactive.map((p) => p.label).join(', ')}.</>
                    )}
                  </p>
                )}
              </section>
            )}

            {listing && active.length > 0 && (
              <section>
                <div className="mb-4" data-testid="forms-double-count-warning">
                  <Notification
                    type="warning"
                    message={
                      <>
                        If the PixelFlow dashboard also tracks one of these forms with a submit
                        trigger or a thank-you URL trigger, each submission is counted twice. Remove
                        that trigger, or leave the form off here. A form this list does not show can
                        be tracked from the{' '}
                        <a
                          href={DASHBOARD_URL}
                          target="_blank"
                          rel="noreferrer"
                          className="underline"
                        >
                          PixelFlow dashboard
                        </a>
                        .
                      </>
                    }
                  />
                </div>

                <div className="mb-4" data-testid="forms-name-split-hint">
                  <NameSplitHint />
                </div>

                {listing.forms.length === 0 ? (
                  <p className="text-sm">No forms found yet.</p>
                ) : (
                  <ul>
                    {listing.forms.map((row) => (
                      <FormRowItem
                        key={row.key}
                        row={row}
                        events={listing.events}
                        identifiers={listing.identifiers}
                        isSaving={savingForm}
                        open={openKey === row.key}
                        onToggleOpen={() => setOpenKey(openKey === row.key ? null : row.key)}
                        onSave={(patch) => saveForm(row.key, row.title, patch)}
                      />
                    ))}
                  </ul>
                )}
              </section>
            )}
          </>
        )}
      </div>
    </div>
  );
}
