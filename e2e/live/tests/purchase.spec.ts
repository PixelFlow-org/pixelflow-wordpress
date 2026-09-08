/** Purchase, logged once the order reaches the thank-you page. */
import { test, expect, forEachPersona, withAdminPage } from '../fixtures';
import { PRODUCTS } from '../site';
import { applySettings, TRACK_EVERYTHING } from '../presets';
import { contentIds, recordsNamed, waitForEvent } from '../helpers/debug-log';

test.beforeAll(async ({ browser }) => {
  await withAdminPage(browser, (page) => applySettings(page, TRACK_EVERYTHING));
});

forEachPersona('Purchase after checkout', () => {
  test('logs one Purchase for the placed order', async ({ shop, cart, checkout }) => {
    await shop.open();
    await shop.addToCart(PRODUCTS.tshirt);
    await cart.open();
    await cart.proceedToCheckout();

    await checkout.completeOrder();

    const events = recordsNamed(await waitForEvent('Purchase', 1, { timeoutMs: 30_000 }), 'Purchase');
    expect(events).toHaveLength(1);
    expect(contentIds(events[0])).toContain(String(PRODUCTS.tshirt.id));
  });
});
