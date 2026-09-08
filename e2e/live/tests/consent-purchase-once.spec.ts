/**
 * A purchase reaches the log once per order, however many hooks run for it.
 *
 * This is the reachable half of the delivery guarantee. Two hooks racing on the
 * same order cannot be provoked from a browser — the thank-you page and a status
 * change are seconds apart while the claim window is under a second — so the
 * concurrent case is proven in the PHP layer and this file covers the sequence a
 * real order actually goes through.
 */
import { test, expect, withAdminPage } from '../fixtures';
import { PRODUCTS } from '../site';
import { applySettings, TRACK_EVERYTHING } from '../presets';
import { sentRecords, waitForEvent } from '../helpers/debug-log';
import { setOrderStatus } from '../helpers/order-meta';

test.beforeAll(async ({ browser }) => {
  await withAdminPage(browser, (page) => applySettings(page, TRACK_EVERYTHING));
});

test.describe('Purchase delivered once', () => {
  test('one purchase across the thank-you page and two status changes', async ({
    shop,
    cart,
    checkout,
  }) => {
    await shop.open();
    await shop.addToCart(PRODUCTS.tshirt);
    await cart.open();
    await cart.proceedToCheckout();
    await checkout.completeOrder();

    const orderId = checkout.orderId();
    await waitForEvent('Purchase', 1, { timeoutMs: 40_000 });

    setOrderStatus(orderId, 'processing');
    setOrderStatus(orderId, 'completed');

    // Give the later hooks the same dispatch window a real duplicate would need.
    await new Promise((resolve) => setTimeout(resolve, 8_000));

    const purchases = sentRecords(
      await waitForEvent('Purchase', 1, { timeoutMs: 10_000 }),
      'Purchase'
    );
    expect(
      purchases,
      `order ${orderId} produced ${purchases.length} purchase records across three hooks`
    ).toHaveLength(1);
  });

  test('the event identifier is derived from the order and the event name', async ({
    shop,
    cart,
    checkout,
  }) => {
    await shop.open();
    await shop.addToCart(PRODUCTS.tshirt);
    await cart.open();
    await cart.proceedToCheckout();
    await checkout.completeOrder();

    const orderId = checkout.orderId();
    const purchases = sentRecords(
      await waitForEvent('Purchase', 1, { timeoutMs: 40_000 }),
      'Purchase'
    );
    expect(purchases).toHaveLength(1);

    const eventId = purchases[0].payload?.eventData?.event_id;
    expect(
      eventId,
      'the purchase event id is not derived from the order — a duplicate could not be recognised'
    ).toBe(`pf-order-${orderId}-purchase`);
  });
});
