/**
 * SKU exclusion.
 *
 * An excluded SKU never produces an AddToCart of its own. The cart-level events
 * are skipped only when every item in the cart is excluded — with a tracked item
 * present they fire normally and report the whole cart.
 */
import { test, expect, forEachPersona, withAdminPage } from '../fixtures';
import { PRODUCTS } from '../site';
import { applySettings, skusExcluded, TRACK_EVERYTHING } from '../presets';
import {
  contentIds,
  mentionsProduct,
  readEventRecords,
  recordsNamed,
  waitForEvent,
} from '../helpers/debug-log';

test.beforeAll(async ({ browser }) => {
  await withAdminPage(browser, (page) =>
    applySettings(page, skusExcluded(PRODUCTS.excluded.sku))
  );
});

test.afterAll(async ({ browser }) => {
  await withAdminPage(browser, (page) => applySettings(page, TRACK_EVERYTHING));
});

forEachPersona('Excluded SKU', () => {
  test('an entirely excluded cart produces no events at all', async ({
    shop,
    cart,
    checkout,
  }) => {
    await shop.open();
    await shop.addToCart(PRODUCTS.excluded);
    await cart.open();
    await cart.proceedToCheckout();
    await checkout.completeOrder();

    await new Promise((resolve) => setTimeout(resolve, 8_000));
    const records = readEventRecords();
    expect(
      records.map((record) => `${record.hook}`),
      'an entirely excluded cart still produced events'
    ).toEqual([]);
  });

  test('a tracked product in the same cart still reports', async ({ shop, cart, checkout }) => {
    await shop.open();
    await shop.addToCart(PRODUCTS.excluded);
    await shop.addToCart(PRODUCTS.tshirt);

    // Item-level: only the tracked product gets its own AddToCart.
    const addToCarts = recordsNamed(await waitForEvent('AddToCart'), 'AddToCart');
    expect(addToCarts).toHaveLength(1);
    expect(contentIds(addToCarts[0])).toContain(String(PRODUCTS.tshirt.id));
    expect(
      addToCarts.some((record) => mentionsProduct(record, PRODUCTS.excluded.id)),
      'the excluded product produced an AddToCart of its own'
    ).toBe(false);

    // Cart-level: the cart is not entirely excluded, so the events still fire — but the
    // excluded line is dropped from what they report. `fix-consent-gating-review` made
    // exclusion filter the contents rather than only suppress an entirely excluded cart.
    await cart.open();
    await cart.proceedToCheckout();

    const checkoutIds = contentIds(
      recordsNamed(await waitForEvent('InitiateCheckout'), 'InitiateCheckout')[0]
    );
    expect(checkoutIds).toContain(String(PRODUCTS.tshirt.id));
    expect(
      checkoutIds,
      'the excluded SKU must not contribute to the checkout contents'
    ).not.toContain(String(PRODUCTS.excluded.id));

    await checkout.completeOrder();

    const purchaseIds = contentIds(
      recordsNamed(await waitForEvent('Purchase', 1, { timeoutMs: 30_000 }), 'Purchase')[0]
    );
    expect(purchaseIds).toContain(String(PRODUCTS.tshirt.id));
    expect(
      purchaseIds,
      'the excluded SKU must not contribute to the purchase contents'
    ).not.toContain(String(PRODUCTS.excluded.id));
  });
});
