/**
 * @fileoverview "Only the customer's first purchase" controls
 * @description The switch, the lookback window and the free-order control that sit under the
 * Purchase free-products toggle in the WooCommerce settings
 */

/** External libraries */
import React from 'react';

/** UI Components */
import * as UI from '@pixelflow-org/plugin-ui';
import { Dropdown } from '@pixelflow-org/plugin-ui';

/** Hooks */
import { useSettingsContext } from '@/features/settings/contexts/useSettingsContext.ts';
import { PixelFlowGeneralOptions } from '@/features/settings';

/** Utils */
import { parseWholeDays } from '@/features/settings/utils/first-purchase';

const DAYS_ERROR = 'Enter a whole number of days, 1 or more';

const LOOKBACK_OPTIONS = [
  { value: 'all', label: 'All time' },
  { value: 'days', label: 'The last N days' },
] as const;

/**
 * FirstPurchaseSettings component
 * @description Lets a merchant send Purchase only for a customer's first paid order
 * @returns FirstPurchaseSettings component
 */
export function FirstPurchaseSettings() {
  const { generalOptions, updateGeneralOption, saveSettings, isSaving } = useSettingsContext();

  const storedDays = String(generalOptions.woo_purchase_first_only_days);
  const [daysDraft, setDaysDraft] = React.useState(storedDays);
  const [daysInvalid, setDaysInvalid] = React.useState(false);

  React.useEffect(() => {
    setDaysDraft(storedDays);
    setDaysInvalid(false);
  }, [storedDays]);

  const purchaseDisabled = generalOptions.woo_disable_purchase === 1;
  const enabled = generalOptions.woo_purchase_first_only === 1;
  const subControlsDisabled = isSaving || purchaseDisabled || !enabled;

  const save = async (patch: Partial<PixelFlowGeneralOptions>) => {
    (Object.keys(patch) as Array<keyof PixelFlowGeneralOptions>).forEach((key) => {
      updateGeneralOption(key, patch[key] as never);
    });
    await saveSettings({ generalOptionsOverride: patch });
  };

  const toggle = (option: 'woo_purchase_first_only' | 'woo_purchase_first_only_ignore_free') =>
    save({ [option]: generalOptions[option] === 1 ? 0 : 1 });

  const commitDays = async () => {
    const days = parseWholeDays(daysDraft);
    if (days === null) {
      setDaysInvalid(true);
      return;
    }
    setDaysInvalid(false);
    if (String(days) === storedDays) {
      return;
    }
    await save({ woo_purchase_first_only_days: days });
  };

  const selected = LOOKBACK_OPTIONS.find(
    (o) => o.value === generalOptions.woo_purchase_first_only_lookback
  );

  return (
    <div
      className={`flex flex-col gap-3${purchaseDisabled ? ' opacity-40 pointer-events-none' : ''}`}
    >
      <div className="flex items-center gap-3">
        <UI.Switch.Root
          checked={enabled}
          onCheckedChange={() => toggle('woo_purchase_first_only')}
          id="woo_purchase_first_only"
          variant={'green'}
          disabled={isSaving || purchaseDisabled}
        ></UI.Switch.Root>
        <UI.TooltipRoot>
          <UI.TooltipTrigger asChild>
            <UI.Label.Root className="cursor-pointer" htmlFor="woo_purchase_first_only">
              <span>Only the customer&apos;s first purchase</span>
            </UI.Label.Root>
          </UI.TooltipTrigger>
          <UI.TooltipContent>
            When enabled, Purchase is sent only if this customer (same account or billing email) has
            no other paid order in the lookback window (or no other order at all, when &quot;Ignore
            previous free orders&quot; is off). Subscription renewals are such orders, so they stop
            sending — and so do repeat purchases of any product, including orders that contain only
            excluded or free products. Set the window longer than your longest billing period, or
            yearly renewals will send again. Orders already processed keep their decision when you
            change these settings.
          </UI.TooltipContent>
        </UI.TooltipRoot>
      </div>

      <div className={`flex flex-col gap-3 pl-12${!enabled ? ' opacity-40' : ''}`}>
        <div className="flex items-center gap-3 flex-wrap">
          <span className="text-sm">Count previous orders from</span>
          <Dropdown.Root>
            <Dropdown.Trigger asChild>
              <button
                type="button"
                id="woo_purchase_first_only_lookback"
                disabled={subControlsDisabled}
                className="inline-flex items-center justify-between gap-2 w-48 rounded-md border border-gray-300 bg-white px-3 py-2 text-sm shadow-sm hover:border-gray-400 disabled:opacity-60 disabled:cursor-not-allowed"
              >
                <span>{selected ? selected.label : 'All time'}</span>
                <span className="text-gray-400">▾</span>
              </button>
            </Dropdown.Trigger>
            <Dropdown.Content>
              {LOOKBACK_OPTIONS.map(({ value, label }) => (
                <Dropdown.Item
                  key={value}
                  onSelect={() => save({ woo_purchase_first_only_lookback: value })}
                >
                  {label}
                </Dropdown.Item>
              ))}
            </Dropdown.Content>
          </Dropdown.Root>
          {generalOptions.woo_purchase_first_only_lookback === 'days' && (
            <span className="flex items-center gap-2">
              <input
                type="text"
                inputMode="numeric"
                id="woo_purchase_first_only_days"
                aria-label="Number of days"
                aria-invalid={daysInvalid}
                aria-describedby={daysInvalid ? 'woo_purchase_first_only_days_error' : undefined}
                className={`w-24 rounded-md border px-3 py-2 text-sm shadow-sm ${daysInvalid ? 'border-red-500' : 'border-gray-300'}`}
                value={daysDraft}
                disabled={subControlsDisabled}
                onChange={(e) => setDaysDraft(e.target.value)}
                onBlur={commitDays}
                onKeyDown={(e) => {
                  if (e.key === 'Enter') {
                    e.preventDefault();
                    void commitDays();
                  }
                }}
              />
              <span className="text-sm">days</span>
            </span>
          )}
        </div>
        {daysInvalid && (
          <p id="woo_purchase_first_only_days_error" className="text-sm text-red-700 !my-0">
            {DAYS_ERROR}
          </p>
        )}

        <div className="flex items-center gap-3">
          <UI.Switch.Root
            checked={generalOptions.woo_purchase_first_only_ignore_free === 1}
            onCheckedChange={() => toggle('woo_purchase_first_only_ignore_free')}
            id="woo_purchase_first_only_ignore_free"
            variant={'green'}
            disabled={subControlsDisabled}
          ></UI.Switch.Root>
          <UI.TooltipRoot>
            <UI.TooltipTrigger asChild>
              <UI.Label.Root
                className="cursor-pointer"
                htmlFor="woo_purchase_first_only_ignore_free"
              >
                <span>Ignore previous free orders</span>
              </UI.Label.Root>
            </UI.TooltipTrigger>
            <UI.TooltipContent>
              When enabled, an earlier order with nothing paid (such as a free trial) does not
              count, so the first paid charge still sends Purchase.
            </UI.TooltipContent>
          </UI.TooltipRoot>
        </div>
      </div>
    </div>
  );
}
