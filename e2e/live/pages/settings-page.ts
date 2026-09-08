/**
 * Driver for the plugin's React settings panel.
 *
 * Every setting the matrix depends on is set through the panel rather than
 * through WP-CLI, so a regression in the panel's persistence fails the run.
 *
 * Switch polarity is not uniform: `enableWoo` and the debug switch are checked
 * when their option is 1, while the event and freebie switches are worded
 * positively ("Enable … event") and are therefore checked when the underlying
 * `woo_disable_*` option is 0.
 */
import { expect, type Locator, type Page } from '@playwright/test';
import { URLS } from '../site';

const SAVE_TOAST = /Settings saved successfully/i;
const SAVE_ERROR_TOAST = /Failed to save settings/i;

export type EventKey = 'add_to_cart' | 'initiate_checkout' | 'purchase';

export class SettingsPage {
  constructor(private readonly page: Page) {}

  async open(): Promise<void> {
    await this.page.goto(URLS.settings);
    await this.page.waitForLoadState('networkidle');
    await this.wooTab().waitFor({ state: 'visible' });
  }

  private wooTab(): Locator {
    return this.page.getByText('WooCommerce Settings', { exact: true });
  }

  private advancedTab(): Locator {
    return this.page.getByText('Advanced Settings', { exact: true });
  }

  async openWooTab(): Promise<void> {
    await this.wooTab().click();
    await this.switchById('enableWoo').waitFor({ state: 'visible' });
  }

  async openAdvancedTab(): Promise<void> {
    await this.advancedTab().click();
  }

  private switchById(id: string): Locator {
    return this.page.locator(`#${id}`);
  }

  private async isChecked(id: string): Promise<boolean> {
    await this.switchById(id).waitFor({ state: 'visible' });
    const state = await this.switchById(id).getAttribute('data-state');
    if (state === 'checked' || state === 'unchecked') return state === 'checked';
    return (await this.switchById(id).getAttribute('aria-checked')) === 'true';
  }

  /**
   * Brings a switch to `desired` and waits for the panel's own save confirmation.
   * A switch already in the target state is left alone — the panel saves on
   * change only, so there would be no toast to wait for.
   */
  private async setSwitch(id: string, desired: boolean): Promise<void> {
    const control = this.switchById(id);
    // A save re-renders the panel and can drop it back to another tab, so
    // re-select the WooCommerce tab once before giving up on a control.
    try {
      await control.waitFor({ state: 'visible', timeout: 10_000 });
    } catch {
      await this.openWooTab();
      await control.waitFor({ state: 'visible', timeout: 10_000 });
    }
    if ((await this.isChecked(id)) === desired) return;

    await expect(control, `settings switch #${id} is disabled`).toBeEnabled();
    await control.click();
    await this.expectSaved(id);
    expect(await this.isChecked(id), `switch #${id} did not settle on ${desired}`).toBe(desired);
  }

  /** Fails with the panel's own message when the save did not succeed. */
  private async expectSaved(what: string): Promise<void> {
    const success = this.page.getByText(SAVE_TOAST).first();
    const failure = this.page.getByText(SAVE_ERROR_TOAST).first();

    await expect
      .poll(
        async () => {
          if (await failure.isVisible()) return 'error';
          if (await success.isVisible()) return 'saved';
          return 'pending';
        },
        { message: `settings panel never confirmed saving ${what}`, timeout: 20_000 }
      )
      .toBe('saved');

    // Let the toast clear so the next save waits on a fresh one.
    await success.waitFor({ state: 'hidden', timeout: 20_000 }).catch(() => undefined);
  }

  async setWooIntegration(enabled: boolean): Promise<void> {
    await this.setSwitch('enableWoo', enabled);
  }

  /** "Enable <event> event" master toggle. */
  async setEventEnabled(event: EventKey, enabled: boolean): Promise<void> {
    await this.setSwitch(`woo_disable_${event}`, enabled);
  }

  /**
   * "Enable <event> event for free products".
   * `enabled: false` is the freebie-suppression case (`woo_disable_*_freebies = 1`).
   */
  async setFreebiesEnabled(event: EventKey, enabled: boolean): Promise<void> {
    await this.setSwitch(`woo_disable_${event}_freebies`, enabled);
  }

  async setDebugLogging(enabled: boolean): Promise<void> {
    await this.openAdvancedTab();
    await this.setSwitch('woo-debug-enabled', enabled);
    await this.openWooTab();
  }

  private excludedSkuChip(sku: string): Locator {
    return this.page.getByRole('button', { name: `Remove SKU ${sku}` });
  }

  async addExcludedSku(sku: string): Promise<void> {
    if ((await this.excludedSkuChip(sku).count()) > 0) return;
    await this.page.fill('#skuInput', sku);
    await this.page.press('#skuInput', 'Enter');
    await this.expectSaved(`excluded SKU ${sku}`);
    await expect(this.excludedSkuChip(sku)).toBeVisible();
  }

  async removeExcludedSku(sku: string): Promise<void> {
    if ((await this.excludedSkuChip(sku).count()) === 0) return;
    await this.excludedSkuChip(sku).click();
    await this.expectSaved(`removal of excluded SKU ${sku}`);
    await expect(this.excludedSkuChip(sku)).toHaveCount(0);
  }

  async clearExcludedSkus(): Promise<void> {
    for (;;) {
      const chips = this.page.getByRole('button', { name: /^Remove SKU / });
      if ((await chips.count()) === 0) return;
      await chips.first().click();
      await this.expectSaved('removal of an excluded SKU');
    }
  }
}
