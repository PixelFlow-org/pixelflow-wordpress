/** Block-based cart page. */
import { expect, type Page } from '@playwright/test';
import { COUPON, URLS } from '../site';

export class CartPage {
  constructor(private readonly page: Page) {}

  /**
   * Opens the cart and waits for the block to hydrate. The block renders either
   * the item rows or an explicit empty-cart heading, so an empty cart is
   * reported as such instead of timing out on a missing row.
   */
  async open(): Promise<void> {
    await this.page.goto(URLS.cart, { waitUntil: 'domcontentloaded' });

    const rows = this.page.locator('.wc-block-cart-items__row');
    const emptyHeading = this.page.getByRole('heading', { name: /cart is currently empty/i });

    await expect
      .poll(
        async () => {
          if ((await rows.count()) > 0) return 'filled';
          if (await emptyHeading.isVisible().catch(() => false)) return 'empty';
          return 'loading';
        },
        { message: 'the cart block never finished loading', timeout: 30_000 }
      )
      .not.toBe('loading');

    if ((await rows.count()) === 0) {
      throw new Error('The cart is empty — the items added earlier did not reach the session.');
    }
  }

  async itemCount(): Promise<number> {
    return this.page.locator('.wc-block-cart-items__row').count();
  }

  async applyCoupon(code: string = COUPON.code): Promise<void> {
    const totalsBefore = await this.orderTotalText();

    const opener = this.page.getByRole('button', { name: /add (a )?coupon/i });
    if (await opener.isVisible().catch(() => false)) {
      await opener.click();
    }
    await this.page.fill('.wc-block-components-totals-coupon input[type="text"]', code);
    await this.page.getByRole('button', { name: /^apply$/i }).click();

    await expect(
      this.page.locator('.wc-block-components-totals-discount'),
      `coupon "${code}" was not accepted`
    ).toBeVisible({ timeout: 30_000 });
    await expect
      .poll(() => this.orderTotalText(), { message: 'cart total did not change after the coupon' })
      .not.toBe(totalsBefore);
  }

  private async orderTotalText(): Promise<string> {
    return (
      (await this.page
        .locator('.wc-block-components-totals-footer-item .wc-block-formatted-money-amount')
        .first()
        .textContent()
        .catch(() => null)) ?? ''
    );
  }

  async proceedToCheckout(): Promise<void> {
    await this.page.locator('.wc-block-cart__submit-button').click();
    await this.page.waitForURL(/page_id=11|checkout/, { timeout: 30_000 });
  }

  async empty(): Promise<void> {
    await this.page.goto(URLS.cart, { waitUntil: 'domcontentloaded' });
    for (;;) {
      const remove = this.page.getByRole('button', { name: /remove item/i });
      if ((await remove.count()) === 0) return;
      await remove.first().click();
      await this.page.waitForTimeout(500);
    }
  }
}
