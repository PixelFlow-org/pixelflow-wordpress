/**
 * The fixture category listing — the storefront listing the matrix uses.
 *
 * Only products WooCommerce considers directly purchasable render an
 * add-to-cart button here; variable, grouped and external products render a
 * link that navigates instead.
 */
import { expect, type Locator, type Page } from '@playwright/test';
import { URLS, type Fixture } from '../site';

export class ShopPage {
  constructor(private readonly page: Page) {}

  async open(): Promise<void> {
    await this.page.goto(URLS.fixtureListing, { waitUntil: 'domcontentloaded' });
    await this.page.locator('li.wc-block-product').first().waitFor({ state: 'visible' });
  }

  card(product: Fixture): Locator {
    return this.page.locator(`li.wc-block-product.post-${product.id}`);
  }

  addToCartButton(product: Fixture): Locator {
    return this.page.locator(`button[data-product_id="${product.id}"]`);
  }

  /** The control a non-purchasable product renders instead of an add-to-cart button. */
  navigatingControl(product: Fixture): Locator {
    return this.card(product).locator('a.wc-block-components-product-button__button');
  }

  /**
   * The block button flips to "N in cart" optimistically, before the Store API
   * request completes — navigating on that label aborts the request and the
   * item never reaches the session. So wait for the server's response instead.
   */
  async addToCart(product: Fixture): Promise<void> {
    const button = this.addToCartButton(product);
    await expect(
      button,
      `${product.sku} has no add-to-cart button on the listing`
    ).toBeVisible();

    const stored = this.page.waitForResponse(
      (response) =>
        /store\/v1\/(batch|cart)/.test(response.url()) &&
        response.request().method() === 'POST' &&
        response.status() < 400,
      { timeout: 30_000 }
    );
    await button.click();
    await stored;
    await expect(this.card(product)).toContainText(/in cart/i, { timeout: 20_000 });
  }

  /**
   * Activates a listing control that navigates rather than adding to the cart.
   * External destinations are blocked so the run never leaves the test site.
   */
  async activateNavigatingControl(product: Fixture): Promise<void> {
    const control = this.navigatingControl(product);
    await expect(
      control,
      `${product.sku} unexpectedly renders an add-to-cart button on the listing`
    ).toBeVisible();

    await this.page.route(/^(?!https:\/\/rift\.kskonovalov\.me).*$/, (route) => route.abort());
    await control.click().catch(() => undefined);
    await this.page.waitForTimeout(1_000);
    await this.page.unroute(/^(?!https:\/\/rift\.kskonovalov\.me).*$/);
  }
}
