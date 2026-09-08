/** InitiateCheckout, triggered only from the cart page. */
import { test, expect, forEachPersona, withAdminPage } from '../fixtures';
import { PRODUCTS } from '../site';
import { applySettings, TRACK_EVERYTHING } from '../presets';
import { contentIds, recordsNamed, waitForEvent } from '../helpers/debug-log';

test.beforeAll(async ({ browser }) => {
  await withAdminPage(browser, (page) => applySettings(page, TRACK_EVERYTHING));
});

forEachPersona('InitiateCheckout from the cart', () => {
  test('logs one InitiateCheckout listing every cart product', async ({ shop, cart }) => {
    const inCart = [PRODUCTS.tshirt, PRODUCTS.sale];

    await shop.open();
    for (const product of inCart) {
      await shop.addToCart(product);
    }
    await cart.open();
    expect(await cart.itemCount()).toBe(inCart.length);

    await cart.proceedToCheckout();

    const events = recordsNamed(await waitForEvent('InitiateCheckout'), 'InitiateCheckout');
    expect(events).toHaveLength(1);

    const ids = contentIds(events[0]);
    for (const product of inCart) {
      expect(ids, `${product.sku} missing from InitiateCheckout contents`).toContain(
        String(product.id)
      );
    }
  });
});
