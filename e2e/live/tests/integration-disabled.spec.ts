/** With the WooCommerce integration switched off, nothing may be logged. */
import { test, forEachPersona, withAdminPage } from '../fixtures';
import { PRODUCTS } from '../site';
import { applySettings, INTEGRATION_OFF, TRACK_EVERYTHING } from '../presets';
import { expectNoEvents } from '../helpers/debug-log';

test.beforeAll(async ({ browser }) => {
  await withAdminPage(browser, (page) => applySettings(page, INTEGRATION_OFF));
});

test.afterAll(async ({ browser }) => {
  await withAdminPage(browser, (page) => applySettings(page, TRACK_EVERYTHING));
});

forEachPersona('WooCommerce integration disabled', () => {
  test('the full flow produces no events', async ({ shop, cart, checkout }) => {
    await shop.open();
    await shop.addToCart(PRODUCTS.tshirt);
    await cart.open();
    await cart.proceedToCheckout();
    await checkout.completeOrder();

    await expectNoEvents();
  });
});
