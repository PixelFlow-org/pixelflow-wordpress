/**
 * Only the buyer's own request changes what an order sends.
 *
 * The ownership predicate is a union of cookie- and session-derived signals, so
 * the only honest way to be "not the buyer" is to be a genuinely different
 * browser. Each scenario acts in one context and then asserts on the debug log.
 *
 * The file starts from a decline, because the stranger, staff and
 * grant-after-decline scenarios all begin with a buyer who said no. The
 * withdrawal scenario is a transition out of a granted state and keeps its own
 * block.
 */
import { test, expect, CUSTOMER_STATE, withAdminPage, withStrangerPage } from '../fixtures';
import { ConsentBanner } from '../pages/consent-banner';
import { CUSTOMER, PRODUCTS, URLS } from '../site';
import { applySettings, TRACK_EVERYTHING } from '../presets';
import {
  blockedRecords,
  expectNoTrackedEvents,
  readEventRecords,
  sentRecords,
  truncateDebugLog,
  waitForEvent,
} from '../helpers/debug-log';
import {
  createPendingOrder,
  orderPayUrl,
  readOrderMeta,
  setOrderStatus,
} from '../helpers/order-meta';

test.beforeAll(async ({ browser }) => {
  await withAdminPage(browser, (page) => applySettings(page, TRACK_EVERYTHING));
});

test.describe('An order declined by its buyer', () => {
  test.use({ consent: 'declined' });

  test('is unaffected by a stranger opening its order-received URL', async ({
    shop,
    cart,
    checkout,
    browser,
  }) => {
    await shop.open();
    await shop.addToCart(PRODUCTS.tshirt);
    await cart.open();
    await cart.proceedToCheckout();
    await checkout.completeOrder();

    const orderId = checkout.orderId();
    const thankYouUrl = checkout.orderReceivedUrl();
    await expectNoTrackedEvents();
    truncateDebugLog();

    await withStrangerPage(browser, async (page) => {
      await page.goto(thankYouUrl, { waitUntil: 'domcontentloaded' });
      await page.waitForTimeout(2_000);
    });

    await expectNoTrackedEvents();
    expect(
      readOrderMeta(orderId).sent,
      "a stranger's granted consent delivered the buyer's declined purchase"
    ).toBe(false);
  });

  test('is unaffected by a staff status change made from the wp-admin session', async ({
    shop,
    cart,
    checkout,
    browser,
  }) => {
    await shop.open();
    await shop.addToCart(PRODUCTS.tshirt);
    await cart.open();
    await cart.proceedToCheckout();
    await checkout.completeOrder();

    const orderId = checkout.orderId();
    await expectNoTrackedEvents();
    truncateDebugLog();

    await withAdminPage(browser, async (page) => {
      // The admin session is captured in wp-admin, where the banner never
      // renders, so its stored state carries no consent cookies at all. A
      // request with no cookies would be rejected for the trivial reason that
      // nothing is present; the requirement is about an administrator whose own
      // consent is granted, so the banner is answered here first.
      await page.goto(URLS.fixtureListing, { waitUntil: 'domcontentloaded' });
      await new ConsentBanner(page).accept();

      await page.goto(URLS.adminOrder(orderId), { waitUntil: 'domcontentloaded' });
      await page.selectOption('#order_status', 'wc-completed');
      await page.locator('button.save_order').first().click();
      await page.waitForLoadState('domcontentloaded');
    });

    await expectNoTrackedEvents();
    expect(
      readOrderMeta(orderId).sent,
      "an administrator's own consent delivered a purchase the buyer declined"
    ).toBe(false);
  });

  test('is delivered when the buyer grants on their own order-received page', async ({
    shop,
    cart,
    checkout,
    page,
    consentBanner,
  }) => {
    await shop.open();
    await shop.addToCart(PRODUCTS.tshirt);
    await cart.open();
    await cart.proceedToCheckout();
    await checkout.completeOrder();

    const orderId = checkout.orderId();
    await expectNoTrackedEvents();
    truncateDebugLog();

    // Same browser, same order-received page: the buyer changes their mind.
    await consentBanner.regrant();

    // The grant only changes cookies in the browser. The purchase hook runs on a
    // request, so the buyer's own reload of the order-received page is what gives
    // the plugin the chance to act on the decision it now has.
    await page.reload({ waitUntil: 'domcontentloaded' });

    const records = await waitForEvent('Purchase', 1, { timeoutMs: 40_000 });
    expect(sentRecords(records, 'Purchase')).toHaveLength(1);
    expect(
      blockedRecords(records, 'Purchase'),
      'a delivered order must not also report a blocked row'
    ).toHaveLength(0);
    expect(readOrderMeta(orderId).sent).toBe(true);
  });

  test('is delivered when the buyer grants away from the order-received page', async ({
    shop,
    cart,
    checkout,
    page,
    consentBanner,
  }) => {
    await shop.open();
    await shop.addToCart(PRODUCTS.tshirt);
    await cart.open();
    await cart.proceedToCheckout();
    await checkout.completeOrder();

    const orderId = checkout.orderId();
    await expectNoTrackedEvents();
    truncateDebugLog();

    // The order-received page is the one storefront request that runs a purchase
    // hook, which makes it the easy case. A buyer who reconsiders anywhere else —
    // here, back on the shop listing — has made the same decision, and the order
    // still has an undelivered purchase waiting on it.
    await shop.open();
    await consentBanner.regrant();
    await page.reload({ waitUntil: 'domcontentloaded' });

    // Staff move the order on afterwards, as they would for an offline payment.
    setOrderStatus(orderId, 'completed');

    const records = await waitForEvent('Purchase', 1, { timeoutMs: 40_000 });
    expect(
      sentRecords(records, 'Purchase'),
      'a grant made away from the order-received page never reached the order'
    ).toHaveLength(1);
    expect(
      blockedRecords(records, 'Purchase'),
      'a delivered order must not also report a blocked row'
    ).toHaveLength(0);
    expect(readOrderMeta(orderId).sent).toBe(true);
  });
});

test.describe('An order whose buyer withdraws consent', () => {
  test.use({ consent: 'granted', storageState: CUSTOMER_STATE });

  test('sends no purchase on a later staff status change', async ({ page, consentBanner }) => {
    // An order the buyer has yet to pay for: nothing has been delivered for it,
    // so the withdrawal has something left to stop. A checkout completed under a
    // granted consent would already have sent its purchase at the thank-you
    // page, and withdrawing afterwards would prove nothing.
    const orderId = createPendingOrder(CUSTOMER.username, PRODUCTS.tshirt.id);

    await page.goto(orderPayUrl(orderId), { waitUntil: 'domcontentloaded' });
    await consentBanner.withdraw();
    truncateDebugLog();

    setOrderStatus(orderId, 'completed');

    await expectNoTrackedEvents();
    expect(
      sentRecords(readEventRecords(), 'Purchase'),
      'a purchase was sent for an order whose buyer had withdrawn consent'
    ).toHaveLength(0);
    expect(readOrderMeta(orderId).sent).toBe(false);
  });
});
