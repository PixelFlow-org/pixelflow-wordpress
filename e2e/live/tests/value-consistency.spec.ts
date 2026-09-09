/**
 * custom data consistency: value must equal the sum of the contents'
 * item_price, under a product-level discount and under a cart coupon.
 * In the logged payload the custom data is eventData.additionalData.
 */
import { test, expect, forEachPersona, withAdminPage } from '../fixtures';
import { COUPON, PRODUCTS } from '../site';
import { applySettings, TRACK_EVERYTHING } from '../presets';
import {
  additionalData,
  recordsNamed,
  waitForEvent,
  type EventRecord,
} from '../helpers/debug-log';

test.beforeAll(async ({ browser }) => {
  await withAdminPage(browser, (page) => applySettings(page, TRACK_EVERYTHING));
});

function sumOfContents(record: EventRecord): number {
  return (additionalData(record).contents ?? []).reduce(
    (total, item) => total + Number(item.item_price ?? 0) * Number(item.quantity ?? 1),
    0
  );
}

forEachPersona('Reported value', () => {
  test('a discounted product reports its sale price', async ({ shop, cart, checkout }) => {
    await shop.open();
    await shop.addToCart(PRODUCTS.sale);
    await cart.open();
    await cart.proceedToCheckout();

    await checkout.completeOrder();

    const purchase = recordsNamed(
      await waitForEvent('Purchase', 1, { timeoutMs: 30_000 }),
      'Purchase'
    )[0];
    const data = additionalData(purchase);

    expect(data.contents).toHaveLength(1);
    expect(data.contents![0].item_price).toBeCloseTo(PRODUCTS.sale.price, 2);
    expect(Number(data.value)).toBeCloseTo(sumOfContents(purchase), 2);
  });

  test(`several products under the ${COUPON.percent}% coupon sum to value`, async ({
    shop,
    cart,
    checkout,
  }) => {
    const inCart = [PRODUCTS.tshirt, PRODUCTS.sale, PRODUCTS.virtual];
    const undiscounted = inCart.reduce((total, product) => total + product.price, 0);

    await shop.open();
    for (const product of inCart) {
      await shop.addToCart(product);
    }
    await cart.open();
    await cart.applyCoupon();
    await cart.proceedToCheckout();

    await checkout.completeOrder();

    const purchase = recordsNamed(
      await waitForEvent('Purchase', 1, { timeoutMs: 30_000 }),
      'Purchase'
    )[0];
    const data = additionalData(purchase);

    expect(data.contents).toHaveLength(inCart.length);
    expect(Number(data.value)).toBeCloseTo(sumOfContents(purchase), 2);
    expect(
      Number(data.value),
      'value still reflects the pre-coupon total'
    ).toBeLessThan(undiscounted);
    expect(Number(data.value)).toBeCloseTo(undiscounted * (1 - COUPON.percent / 100), 1);
  });
});
