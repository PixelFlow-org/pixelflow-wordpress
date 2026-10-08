/**
 * "Only the customer's first purchase" with a subscription plugin's own renewal.
 *
 * Runs against the free "Subscriptions for WooCommerce" plugin on the test site, with its
 * subscription product PF-SUB-MONTHLY. That plugin requires an account for a subscription and,
 * on its own, accepts only card processors at checkout; a test-site mu-plugin lets it take cash
 * on delivery. The renewal is produced by the plugin's scheduler action after the next payment
 * date is moved into the past, and is then marked paid, as a merchant would for a manual payment.
 *
 * A fresh customer account per run, so the signup is the customer's first order.
 */
import { test, expect, withAdminPage } from '../fixtures';
import { URLS } from '../site';
import { applySettings, FIRST_PURCHASE_ONLY, TRACK_EVERYTHING } from '../presets';
import {
  firstPurchaseSkips,
  readEventRecords,
  recordsNamed,
  truncateDebugLog,
  waitForEvent,
  waitForRecords,
} from '../helpers/debug-log';
import { setOrderStatus } from '../helpers/order-meta';
import { wpEval } from '../helpers/ssh';

const SUBSCRIPTION_SKU = 'PF-SUB-MONTHLY';

test.describe('First purchase only — subscription renewal', () => {
  test.beforeAll(async ({ browser }) => {
    await withAdminPage(browser, (page) => applySettings(page, FIRST_PURCHASE_ONLY));
  });

  test.afterAll(async ({ browser }) => {
    await withAdminPage(browser, (page) => applySettings(page, TRACK_EVERYTHING));
  });

  test('the signup sends Purchase, its renewal is skipped against it', async ({
    page,
    cart,
    checkout,
  }) => {
    const productId = Number(wpEval(`echo (int) wc_get_product_id_by_sku("${SUBSCRIPTION_SKU}");`));
    expect(productId, `subscription product ${SUBSCRIPTION_SKU} is missing on the site`).toBeGreaterThan(0);

    const login = `sub${Date.now()}`;
    const password = `pw-${Math.random().toString(36).slice(2)}-${Date.now()}`;
    const userId = Number(
      wpEval(`
        $id = wp_create_user("${login}", "${password}", "${login}@example.test");
        if (is_wp_error($id)) { echo 0; return; }
        (new WP_User($id))->set_role("customer");
        echo $id;
      `)
    );
    expect(userId, 'could not create the customer account').toBeGreaterThan(0);

    await page.goto(URLS.login);
    await page.fill('#user_login', login);
    await page.fill('#user_pass', password);
    await page.click('#wp-submit');
    await page.waitForLoadState('domcontentloaded');

    await page.goto(URLS.classicAddToCart(productId), { waitUntil: 'domcontentloaded' });
    await cart.open();
    await cart.proceedToCheckout();
    await checkout.completeOrder();
    const parent = checkout.orderId();

    await waitForEvent('Purchase', 1, { timeoutMs: 30_000 });
    truncateDebugLog();

    const renewal = Number(
      wpEval(`
        $sub = (int) wc_get_order(${parent})->get_meta("wps_subscription_id", true);
        if (!$sub) { echo 0; return; }
        wps_sfw_update_meta_data($sub, "wps_next_payment_date", time() - 60);
        do_action("wps_sfw_create_renewal_order_schedule");
        echo (int) wps_sfw_get_meta_data($sub, "wps_renewal_subscription_order", true);
      `)
    );
    expect(renewal, 'the subscription plugin created no renewal order').toBeGreaterThan(0);
    expect(renewal).not.toBe(parent);

    const status = wpEval(`echo wc_get_order(${renewal})->get_status();`).trim();
    test.info().annotations.push({ type: 'renewal status as created', description: status });
    if (status !== 'processing' && status !== 'completed') {
      setOrderStatus(renewal, 'processing');
    }

    const records = await waitForRecords((r) => firstPurchaseSkips(r).length >= 1, {
      timeoutMs: 30_000,
      description: `a first-purchase skip for renewal ${renewal}`,
    });
    expect(firstPurchaseSkips(records)[0]).toEqual({ order_id: renewal, matched_order_id: parent });
    expect(recordsNamed(readEventRecords(), 'Purchase'), 'no Purchase for the renewal').toHaveLength(0);
  });
});
