/**
 * Named settings combinations. A scenario declares the state it needs; the
 * preset applies it through the settings panel in a safe order.
 *
 * Order matters: the freebie switches are disabled while their master event is
 * off, and turning a master event off forces its freebie option to "disabled"
 * as a side effect — so masters are set first and freebies always afterwards.
 */
import type { Page } from '@playwright/test';
import { SettingsPage, type EventKey } from './pages/settings-page';

const EVENTS: EventKey[] = ['add_to_cart', 'initiate_checkout', 'purchase'];

export interface SettingsState {
  wooIntegration: boolean;
  debugLogging: boolean;
  /** Master "Enable <event> event" toggles. Defaults to all enabled. */
  events?: Partial<Record<EventKey, boolean>>;
  /** "Enable <event> event for free products". Defaults to all enabled. */
  freebies?: Partial<Record<EventKey, boolean>>;
  excludedSkus?: string[];
  /** "Only the customer's first purchase". Off unless given, so every preset restores it. */
  firstPurchase?: {
    lookback?: 'all' | 'days';
    days?: number;
    ignoreFree?: boolean;
  };
}

export async function applySettings(page: Page, state: SettingsState): Promise<void> {
  const settings = new SettingsPage(page);
  await settings.open();
  await settings.openWooTab();

  await settings.setWooIntegration(state.wooIntegration);

  if (!state.wooIntegration) {
    // The rest of the panel is hidden while the integration is off.
    return;
  }

  await settings.setDebugLogging(state.debugLogging);

  for (const event of EVENTS) {
    await settings.setEventEnabled(event, state.events?.[event] ?? true);
  }
  for (const event of EVENTS) {
    if ((state.events?.[event] ?? true) === false) continue;
    await settings.setFreebiesEnabled(event, state.freebies?.[event] ?? true);
  }

  if (state.events?.purchase ?? true) {
    // The sub-controls are disabled until the switch is on, so it goes first.
    await settings.setFirstPurchaseOnly(state.firstPurchase !== undefined);
    if (state.firstPurchase) {
      await settings.setFirstPurchaseLookback(
        state.firstPurchase.lookback ?? 'all',
        state.firstPurchase.days
      );
      await settings.setIgnoreFreeOrders(state.firstPurchase.ignoreFree ?? true);
    }
  }

  await settings.clearExcludedSkus();
  for (const sku of state.excludedSkus ?? []) {
    await settings.addExcludedSku(sku);
  }
}

/** Everything on: the baseline the event matrix runs under. */
export const TRACK_EVERYTHING: SettingsState = {
  wooIntegration: true,
  debugLogging: true,
};

/** Integration off: nothing should be logged at all. */
export const INTEGRATION_OFF: SettingsState = {
  wooIntegration: false,
  debugLogging: true,
};

/** Baseline plus one freebie event suppressed. */
export function freebiesDisabledFor(event: EventKey): SettingsState {
  return {
    wooIntegration: true,
    debugLogging: true,
    freebies: { [event]: false },
  };
}

/** Baseline plus "Only the customer's first purchase" (all time, ignore free orders). */
export const FIRST_PURCHASE_ONLY: SettingsState = {
  wooIntegration: true,
  debugLogging: true,
  firstPurchase: {},
};

export function skusExcluded(...skus: string[]): SettingsState {
  return { wooIntegration: true, debugLogging: true, excludedSkus: skus };
}
