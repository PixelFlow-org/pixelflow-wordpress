/**
 * A declined shopper: no real event leaves the site, and exactly one anonymous
 * blocked-events row reports each suppression.
 *
 * The purchase scenarios also cover the deferred report. A live run cannot wait
 * out the 30-minute window, so the marker's due time is rewritten to the past
 * over wp-cli and the plugin is then asked what it does with an overdue marker —
 * which exercises the same overdue path a site with no working cron relies on.
 */
import { test, expect, withAdminPage } from '../fixtures';
import { PRODUCTS, URLS } from '../site';
import { applySettings, TRACK_EVERYTHING } from '../presets';
import {
  blockedEntries,
  blockedRecords,
  expectNoIdentifiers,
  expectNoTrackedEvents,
  readEventRecords,
  sentRecords,
  truncateDebugLog,
  waitForBlocked,
} from '../helpers/debug-log';
import {
  expireBlockedReport,
  isolateScheduledReport,
  hasScheduledReport,
  readOrderMeta,
  runCronEvent,
} from '../helpers/order-meta';

/**
 * Moves an order to a new status through wp-admin.
 *
 * The purchase hooks are not attached in a WP-CLI request, so a wp-cli status change runs no
 * plugin logic at all — see the note on `setOrderStatus`. These scenarios need a real HTTP
 * request, which is what the admin UI gives them.
 */
async function setOrderStatusInAdmin(
  browser: import('@playwright/test').Browser,
  orderId: number,
  status: string
): Promise<void> {
  await withAdminPage(browser, async (page) => {
    await page.goto(URLS.adminOrder(orderId), { waitUntil: 'domcontentloaded' });
    await page.selectOption('#order_status', `wc-${status}`);
    await page.locator('button.save_order').first().click();
    await page.waitForLoadState('domcontentloaded');
  });
}

const REPORT_HOOK = 'pixelflow_report_blocked_purchase';

test.use({ consent: 'declined' });

test.beforeAll(async ({ browser }) => {
  await withAdminPage(browser, (page) => applySettings(page, TRACK_EVERYTHING));
});

test.describe('Events under a declined banner', () => {
  test('AddToCart logs no event and exactly one blocked row', async ({ shop }) => {
    await shop.open();
    await shop.addToCart(PRODUCTS.tshirt);

    const records = await waitForBlocked('AddToCart', 1, { timeoutMs: 30_000 });
    expect(blockedRecords(records, 'AddToCart')).toHaveLength(1);
    expect(sentRecords(records, 'AddToCart'), 'a real AddToCart was sent under a decline').toHaveLength(0);

    const entries = blockedEntries(blockedRecords(records, 'AddToCart')[0]);
    expect(entries).toHaveLength(1);
    expect(entries[0].eventType).toBe('AddToCart');
    expect(entries[0].reason).toBe('denied');
  });

  test('InitiateCheckout is blocked, and the row carries no visitor identity', async ({
    shop,
    cart,
  }) => {
    await shop.open();
    await shop.addToCart(PRODUCTS.tshirt);
    await cart.open();
    await cart.proceedToCheckout();

    const records = await waitForBlocked('InitiateCheckout', 1, { timeoutMs: 30_000 });
    const blocked = blockedRecords(records, 'InitiateCheckout');
    expect(blocked).toHaveLength(1);
    expect(sentRecords(records, 'InitiateCheckout')).toHaveLength(0);

    expectNoIdentifiers(blocked[0]);
  });

  test('repeated actions produce one blocked row each and no real event', async ({ shop }) => {
    const products = [PRODUCTS.tshirt, PRODUCTS.sale, PRODUCTS.virtual];

    await shop.open();
    for (const product of products) {
      await shop.addToCart(product);
    }

    const records = await waitForBlocked('AddToCart', products.length, { timeoutMs: 40_000 });
    expect(
      blockedRecords(records, 'AddToCart'),
      'each suppressed action must report exactly one blocked row'
    ).toHaveLength(products.length);
    expect(sentRecords(records, 'AddToCart')).toHaveLength(0);
  });
});

test.describe('A purchase placed under a decline', () => {
  test('sends nothing at checkout and defers its report', async ({ shop, cart, checkout }) => {
    await shop.open();
    await shop.addToCart(PRODUCTS.tshirt);
    await cart.open();
    await cart.proceedToCheckout();
    await checkout.completeOrder();

    const orderId = checkout.orderId();
    await expectNoTrackedEvents();

    expect(
      blockedRecords(readEventRecords(), 'Purchase'),
      'the blocked purchase was reported at the moment of the skip instead of being deferred'
    ).toHaveLength(0);

    const meta = readOrderMeta(orderId);
    expect(meta.sent, 'a purchase was delivered for a declined order').toBe(false);
    expect(meta.blockedReported).toBe(false);
    expect(meta.blocked, 'the blocked marker is missing from the order').not.toBeNull();
    expect(
      meta.blocked?.due ?? 0,
      'the report is due immediately instead of after the reporting window'
    ).toBeGreaterThan(Math.floor(Date.now() / 1000));
  });

  test('an overdue marker is reported once by a later status change', async ({
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

    expireBlockedReport(orderId);
    truncateDebugLog();

    // `completed`, not `processing`: an offline-payment checkout already leaves the order in
    // `processing`, and asking for the status it already has fires no transition at all — so
    // no purchase hook runs and the scenario reads as "the plugin did nothing".
    await setOrderStatusInAdmin(browser, orderId, 'completed');

    const records = await waitForBlocked('Purchase', 1, { timeoutMs: 30_000 });
    expect(blockedRecords(records, 'Purchase')).toHaveLength(1);
    expect(sentRecords(records, 'Purchase')).toHaveLength(0);
    expectNoIdentifiers(blockedRecords(records, 'Purchase')[0]);

    const afterReport = readOrderMeta(orderId);
    expect(afterReport.blockedReported, 'the order was not closed to further traffic').toBe(true);
    expect(afterReport.blocked, 'the spent marker was left behind').toBeNull();

    // A further status change must cost nothing at all.
    truncateDebugLog();
    await setOrderStatusInAdmin(browser, orderId, 'processing');
    await expectNoTrackedEvents();
    expect(
      blockedRecords(readEventRecords()),
      'a closed order reported a second blocked row'
    ).toHaveLength(0);
  });

  test('an overdue marker is reported once by the scheduled event', async ({
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
    await expectNoTrackedEvents();

    expect(
      hasScheduledReport(orderId),
      'no report was scheduled for the blocked purchase'
    ).toBe(true);

    // The marker is brought forward so the report is overdue; the scheduled event is left as
    // the plugin created it, and only other orders' pending reports are cleared so this run
    // reports this order alone.
    expireBlockedReport(orderId);
    isolateScheduledReport(orderId);
    truncateDebugLog();

    runCronEvent(REPORT_HOOK);

    const records = await waitForBlocked('Purchase', 1, { timeoutMs: 30_000 });
    expect(blockedRecords(records, 'Purchase')).toHaveLength(1);
    expect(readOrderMeta(orderId).blockedReported).toBe(true);
  });
});
