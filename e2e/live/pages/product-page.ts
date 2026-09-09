/** Single product pages — classic WooCommerce templates on this site. */
import { expect, type Page } from '@playwright/test';
import { URLS, type Fixture } from '../site';
import { cartItemCount, waitForCartCount } from './cart-state';

export class ProductPage {
  constructor(private readonly page: Page) {}

  async open(product: Fixture): Promise<void> {
    await this.page.goto(URLS.product(product.slug), { waitUntil: 'domcontentloaded' });
  }

  /**
   * Submits the cart form and waits until WooCommerce actually holds the items.
   * Some product types post and reload, others add over the Store API, so the
   * cart itself is the signal rather than a navigation.
   */
  private async submitCartForm(what: string, added = 1): Promise<void> {
    const before = await cartItemCount(this.page).catch(() => 0);
    await this.page.click('form.cart button.single_add_to_cart_button');
    await waitForCartCount(this.page, Math.max(before, 0) + added, what);
  }

  /** Simple products: one button submits the cart form. */
  async addSimple(product: Fixture): Promise<void> {
    await this.open(product);
    await this.submitCartForm(product.sku);
  }

  /**
   * Variable products: pick the variation, then add. Woo resolves the chosen
   * variation asynchronously and only then fills the hidden variation_id — a
   * click before that submits without a variation and adds nothing.
   */
  async addVariation(product: Fixture, option: string): Promise<void> {
    await this.open(product);
    await this.page.selectOption('form.variations_form select[name="attribute_size"]', option);

    const variationId = this.page.locator('form.variations_form input[name="variation_id"]');
    await expect
      .poll(() => variationId.inputValue(), {
        message: `Woo never resolved the "${option}" variation of ${product.sku}`,
        timeout: 20_000,
      })
      .not.toMatch(/^(0|)$/);

    await expect(this.page.locator('form.cart button.single_add_to_cart_button')).toBeEnabled();
    await this.submitCartForm(`${product.sku} (${option})`);
  }

  /** Grouped products: quantities are per child, keyed by child product id. */
  async addGroupedChildren(product: Fixture, childIds: readonly number[]): Promise<void> {
    await this.open(product);
    for (const childId of childIds) {
      await this.page.fill(`form.cart input[name="quantity[${childId}]"]`, '1');
    }
    await this.submitCartForm(`${product.sku} children`, childIds.length);
  }

  /**
   * External products offer a link off-site. The navigation is blocked so the
   * run stays on the test site while still exercising the click.
   */
  async activateExternal(product: Fixture): Promise<void> {
    await this.open(product);
    await this.page.route(/^(?!https:\/\/rift\.kskonovalov\.me).*$/, (route) => route.abort());
    await this.page
      .click('form.cart a.single_add_to_cart_button, form.cart button.single_add_to_cart_button')
      .catch(() => undefined);
    await this.page.waitForTimeout(1_000);
    await this.page.unroute(/^(?!https:\/\/rift\.kskonovalov\.me).*$/);
  }
}
