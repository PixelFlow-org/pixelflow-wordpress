/**
 * The excluded-roles setting on the storefront.
 *
 * A signed-in administrator with the role excluded gets none of the plugin's scripts, and their own
 * requests send nothing. The setting judges the current request only, so a Purchase skipped in the
 * administrator's own request is sent when a later request that is not theirs moves the order, and
 * an excluded staff member completing a real customer's order still sends it.
 *
 * The test site's cart and checkout are blocks, so these scenarios also prove that WordPress knows
 * the signed-in user during Store API requests. If it did not, the events would be sent.
 */
import {
  test,
  expect,
  forEachPersona,
  withAdminPage,
  ADMIN_STATE,
  CUSTOMER_STATE,
} from '../fixtures';
import { CUSTOMER, PRODUCTS, URLS } from '../site';
import { applySettings, rolesExcluded, TRACK_EVERYTHING } from '../presets';
import {
  blockedRecords,
  excludedRoleRecords,
  expectNoTrackedEvents,
  readEventRecords,
  sentRecords,
  truncateDebugLog,
  waitForEvent,
  waitForRecords,
} from '../helpers/debug-log';
import {
  createPendingOrder,
  orderPayUrl,
  readOrderMeta,
  setOrderStatus,
} from '../helpers/order-meta';

const TRACKING_SCRIPT = '#pixelflow-tracking-js-before';
const HELD_EVENTS_SCRIPT = '#pixelflow-held-events-js';

test.beforeAll(async ({ browser }) => {
  await withAdminPage(browser, (page) => applySettings(page, rolesExcluded('administrator')));
});

test.afterAll(async ({ browser }) => {
  await withAdminPage(browser, (page) => applySettings(page, TRACK_EVERYTHING));
});

test.describe('A signed-in administrator with the role excluded', () => {
  test.use({ storageState: ADMIN_STATE });

  test('gets none of the plugin scripts', async ({ page }) => {
    await page.goto(URLS.fixtureListing, { waitUntil: 'domcontentloaded' });

    await expect(page.locator('#wpadminbar'), 'the page was not served signed in').toHaveCount(1);
    await expect(page.locator(TRACKING_SCRIPT)).toHaveCount(0);
    await expect(page.locator(HELD_EVENTS_SCRIPT)).toHaveCount(0);
  });

  test('sends no AddToCart or InitiateCheckout', async ({ shop, cart }) => {
    await shop.open();
    await shop.addToCart(PRODUCTS.tshirt);
    await cart.open();
    await cart.proceedToCheckout();

    const records = await waitForRecords(
      (seen) =>
        excludedRoleRecords(seen, 'AddToCart').length >= 1 &&
        excludedRoleRecords(seen, 'InitiateCheckout').length >= 1,
      { description: 'AddToCart and InitiateCheckout skipped for the excluded role' }
    );

    await expectNoTrackedEvents();
    expect(blockedRecords(records), 'a skip must not send a blocked report').toHaveLength(0);
  });

  test('sends no Purchase from their own checkout, and a later request that is not theirs does', async ({
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
    await waitForRecords((seen) => excludedRoleRecords(seen, 'Purchase').length >= 1, {
      description: 'Purchase skipped for the excluded role',
    });
    await expectNoTrackedEvents();
    expect(blockedRecords(readEventRecords()), 'a skip must not send a blocked report').toHaveLength(0);
    expect(readOrderMeta(orderId).sent, 'a skipped order was recorded as delivered').toBe(false);

    // Automation over WP-CLI stands in for a gateway webhook: no signed-in user and none of the
    // buyer's cookies. This is the accepted limit of the setting, pinned so it stays deliberate.
    truncateDebugLog();
    setOrderStatus(orderId, 'completed');

    const records = await waitForEvent('Purchase', 1, { timeoutMs: 40_000 });
    expect(sentRecords(records, 'Purchase')).toHaveLength(1);
    expect(readOrderMeta(orderId).sent).toBe(true);
  });
});

forEachPersona('With the administrator role excluded, a visitor outside it', () => {
  test('still gets the tracking script and sends AddToCart', async ({ page, shop }) => {
    await page.goto(URLS.fixtureListing, { waitUntil: 'domcontentloaded' });
    await expect(page.locator(TRACKING_SCRIPT)).toHaveCount(1);

    await shop.open();
    await shop.addToCart(PRODUCTS.tshirt);

    const records = await waitForEvent('AddToCart');
    expect(sentRecords(records, 'AddToCart')).toHaveLength(1);
    expect(excludedRoleRecords(records, 'AddToCart')).toHaveLength(0);
  });
});

test.describe('A real customer whose order an excluded administrator completes', () => {
  test.use({ storageState: CUSTOMER_STATE });

  test('still has the Purchase sent', async ({ page, browser }) => {
    // An order the customer has yet to pay for: a completed checkout would already have sent its
    // Purchase at the thank-you page. Opening the pay page records their granted consent on it.
    const orderId = createPendingOrder(CUSTOMER.username, PRODUCTS.tshirt.id);
    await page.goto(orderPayUrl(orderId), { waitUntil: 'domcontentloaded' });
    truncateDebugLog();

    await withAdminPage(browser, async (adminPage) => {
      await adminPage.goto(URLS.adminOrder(orderId), { waitUntil: 'domcontentloaded' });
      await adminPage.selectOption('#order_status', 'wc-processing');
      await adminPage.locator('button.save_order').first().click();
      await adminPage.waitForLoadState('domcontentloaded');
    });

    const records = await waitForEvent('Purchase', 1, { timeoutMs: 40_000 });
    expect(sentRecords(records, 'Purchase')).toHaveLength(1);
    expect(excludedRoleRecords(records, 'Purchase')).toHaveLength(0);
    expect(readOrderMeta(orderId).sent).toBe(true);
  });
});
