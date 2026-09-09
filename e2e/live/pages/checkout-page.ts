/** Block-based checkout, completed with an offline payment method. */
import { expect, type Page } from '@playwright/test';
import { CUSTOMER, URLS } from '../site';

export class CheckoutPage {
  constructor(private readonly page: Page) {}

  async open(): Promise<void> {
    await this.page.goto(URLS.checkout, { waitUntil: 'domcontentloaded' });
    await this.page
      .locator('.wc-block-checkout')
      .first()
      .waitFor({ state: 'visible', timeout: 30_000 });
  }

  /**
   * Fills whichever address block the checkout renders. Woo shows the shipping
   * block only when the cart needs shipping, so both prefixes are handled and
   * prefilled fields are left alone. Country and state are native selects, and
   * the state list only populates after the country is chosen.
   */
  async fillAddress(): Promise<void> {
    const b = CUSTOMER.billing;
    await this.setIfEmpty('#email', b.email);

    for (const prefix of ['shipping', 'billing']) {
      const first = this.page.locator(`#${prefix}-first_name`);
      if (!(await first.isVisible().catch(() => false))) continue;

      await this.selectIfEmpty(`#${prefix}-country`, b.country);
      await this.setIfEmpty(`#${prefix}-first_name`, b.firstName);
      await this.setIfEmpty(`#${prefix}-last_name`, b.lastName);
      await this.setIfEmpty(`#${prefix}-address_1`, b.address1);
      await this.setIfEmpty(`#${prefix}-city`, b.city);
      await this.setIfEmpty(`#${prefix}-postcode`, b.postcode);
      await this.selectIfEmpty(`#${prefix}-state`, b.state);
      await this.setIfEmpty(`#${prefix}-phone`, b.phone);
    }
  }

  private async setIfEmpty(selector: string, value: string): Promise<void> {
    const field = this.page.locator(selector);
    if (!(await field.isVisible().catch(() => false))) return;
    if ((await field.inputValue()) !== '') return;
    await field.fill(value);
  }

  private async selectIfEmpty(selector: string, value: string): Promise<void> {
    const field = this.page.locator(selector);
    if (!(await field.isVisible().catch(() => false))) return;
    if ((await field.inputValue().catch(() => '')) === value) return;
    await field.selectOption(value);
  }

  async choosePaymentMethod(id: 'cod' | 'cheque' | 'bacs' = 'cod'): Promise<void> {
    const option = this.page.locator(`#radio-control-wc-payment-method-options-${id}`);
    if (await option.isVisible().catch(() => false)) {
      await option.check();
    }
  }

  async placeOrder(): Promise<void> {
    await this.page.locator('.wc-block-components-checkout-place-order-button').click();
    await this.page.waitForURL(/order-received|order_received/, { timeout: 60_000 });
    await expect(this.page.locator('body')).toContainText(/thank you|order received/i);
  }

  /** The order id, read off the thank-you URL after `placeOrder()`. */
  orderId(): number {
    // Both shapes appear in the wild: the pretty permalink `/order-received/123/`
    // and the query form this site uses, `?page_id=11&order-received=123&key=…`.
    const match = /order-received[/=](\d+)/.exec(this.page.url());
    const id = match?.[1];
    if (!id) {
      throw new Error(`Not on a thank-you page — cannot read an order id from ${this.page.url()}.`);
    }
    return Number(id);
  }

  /** The thank-you URL of the current order, so another context can be pointed at it. */
  orderReceivedUrl(): string {
    return this.page.url();
  }

  /** Address → payment method → place order → thank-you page. */
  async completeOrder(): Promise<void> {
    // The checkout block hydrates after navigation; wait for the form itself.
    await this.page.locator('#email').waitFor({ state: 'visible', timeout: 30_000 });
    await this.fillAddress();
    await this.choosePaymentMethod();
    await this.placeOrder();
  }
}
