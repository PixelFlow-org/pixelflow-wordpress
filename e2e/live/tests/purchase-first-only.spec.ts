/**
 * "Only the customer's first purchase" through the storefront.
 *
 * Guest checkouts with an email of their own: the suite's personas share one billing email
 * with a long order history on the site, so with the setting on none of their orders would
 * ever be a first purchase.
 */
import type { Page } from '@playwright/test';
import { test, expect, withAdminPage } from '../fixtures';
import { PRODUCTS } from '../site';
import { applySettings, FIRST_PURCHASE_ONLY, TRACK_EVERYTHING } from '../presets';
import type { CartPage } from '../pages/cart-page';
import type { CheckoutPage } from '../pages/checkout-page';
import type { ShopPage } from '../pages/shop-page';
import {
  firstPurchaseSkips,
  readEventRecords,
  recordsNamed,
  sentRecords,
  truncateDebugLog,
  waitForEvent,
  waitForRecords,
} from '../helpers/debug-log';
import { setOrderStatus } from '../helpers/order-meta';

function uniqueEmail(): string {
  return `first-${Date.now()}-${Math.floor(Math.random() * 1e6)}@example.test`;
}

/** Places one order for PF-VIRTUAL as a guest with the given email; returns its id. */
async function guestOrder(
  page: Page,
  shop: ShopPage,
  cart: CartPage,
  checkout: CheckoutPage,
  email: string
): Promise<number> {
  await shop.open();
  await shop.addToCart(PRODUCTS.virtual);
  await cart.open();
  await cart.proceedToCheckout();
  const field = page.locator('#email');
  await field.waitFor({ state: 'visible', timeout: 30_000 });
  await field.fill(email);
  await checkout.completeOrder();
  return checkout.orderId();
}

test.describe('First purchase only — guest, setting on', () => {
  test.beforeAll(async ({ browser }) => {
    await withAdminPage(browser, (page) => applySettings(page, FIRST_PURCHASE_ONLY));
  });

  test.afterAll(async ({ browser }) => {
    await withAdminPage(browser, (page) => applySettings(page, TRACK_EVERYTHING));
  });

  test('the first order sends Purchase, a second order with the same email is skipped', async ({
    page,
    shop,
    cart,
    checkout,
  }) => {
    const email = uniqueEmail();

    const first = await guestOrder(page, shop, cart, checkout, email);
    const firstRecords = await waitForEvent('Purchase', 1, { timeoutMs: 30_000 });
    expect(sentRecords(firstRecords, 'Purchase')).toHaveLength(1);
    expect(firstPurchaseSkips(firstRecords)).toHaveLength(0);

    // The checkout pays by bank transfer, which leaves the order on-hold, and an unpaid order does not
    // count as a previous purchase. The merchant confirming the payment is what makes it one.
    setOrderStatus(first, 'processing');
    truncateDebugLog();

    const second = await guestOrder(page, shop, cart, checkout, email);
    const records = await waitForRecords((r) => firstPurchaseSkips(r).length === 1, {
      timeoutMs: 30_000,
      description: `a first-purchase skip for order ${second}`,
    });

    expect(firstPurchaseSkips(records)[0]).toEqual({ order_id: second, matched_order_id: first });
    // The skip is written where the delivery would have been: give a late write a moment.
    await page.waitForTimeout(3_000);
    const settled = readEventRecords();
    expect(recordsNamed(settled, 'Purchase'), 'no Purchase may be logged for the repeat order').toHaveLength(0);
    expect(recordsNamed(settled, 'AddToCart').length, 'AddToCart is unaffected').toBeGreaterThan(0);
    expect(recordsNamed(settled, 'InitiateCheckout').length, 'InitiateCheckout is unaffected').toBeGreaterThan(0);
  });
});

test.describe('First purchase only — guest, setting off', () => {
  test.beforeAll(async ({ browser }) => {
    await withAdminPage(browser, (page) => applySettings(page, TRACK_EVERYTHING));
  });

  test('both orders with the same email send Purchase', async ({ page, shop, cart, checkout }) => {
    const email = uniqueEmail();

    await guestOrder(page, shop, cart, checkout, email);
    await waitForEvent('Purchase', 1, { timeoutMs: 30_000 });

    truncateDebugLog();

    await guestOrder(page, shop, cart, checkout, email);
    const records = await waitForEvent('Purchase', 1, { timeoutMs: 30_000 });
    expect(sentRecords(records, 'Purchase')).toHaveLength(1);
    expect(firstPurchaseSkips(records)).toHaveLength(0);
  });
});
