/**
 * TikTok's `ttp` (browser id, from the `_ttp` cookie) and `ttclid` (ad-click id, the `ttclid`
 * key of the `_pf_click_ids` query-string cookie) on AddToCart, InitiateCheckout and Purchase.
 *
 * The shopper's browser carries these two cookies the way the TikTok pixel and PixelFlow's own
 * click-id capture would write them; the suite sets them directly with `context.addCookies()`
 * rather than driving a real TikTok pixel, which this test site does not load.
 */
import { test, expect, forEachPersona, withAdminPage } from '../fixtures';
import { PRODUCTS, SITE } from '../site';
import { applySettings, TRACK_EVERYTHING } from '../presets';
import {
  expectNoTrackedEvents,
  recordsNamed,
  sentRecords,
  waitForEvent,
  waitForHeld,
} from '../helpers/debug-log';

/** Fictional values — distinct from anything a real cookie would carry. */
const TTP_VALUE = 'ttp-fake-browser-id-test';
const TTCLID_VALUE = 'E_C_P_live_test';
const CLICK_IDS_VALUE = `ttclid=${TTCLID_VALUE}&gclid=other-network-id`;

test.beforeAll(async ({ browser }) => {
  await withAdminPage(browser, (page) => applySettings(page, TRACK_EVERYTHING));
});

/**
 * Sets the TikTok cookies on the browser context for the site's own domain, derived from the
 * configured base URL rather than a hardcoded host.
 */
async function setTikTokCookies(
  context: import('@playwright/test').BrowserContext,
  cookies: { ttp?: string; clickIds?: string }
): Promise<void> {
  const domain = new URL(SITE.baseURL).hostname;
  const toAdd: Array<{ name: string; value: string; domain: string; path: string }> = [];

  if (cookies.ttp !== undefined) {
    toAdd.push({ name: '_ttp', value: cookies.ttp, domain, path: '/' });
  }
  if (cookies.clickIds !== undefined) {
    toAdd.push({ name: '_pf_click_ids', value: cookies.clickIds, domain, path: '/' });
  }
  if (toAdd.length > 0) {
    await context.addCookies(toAdd);
  }
}

forEachPersona('TikTok ids on storefront events', () => {
  test("AddToCart carries ttp and ttclid from the shopper's cookies", async ({ shop, context }) => {
    await setTikTokCookies(context, { ttp: TTP_VALUE, clickIds: CLICK_IDS_VALUE });
    await shop.open();
    await shop.addToCart(PRODUCTS.tshirt);

    const events = recordsNamed(await waitForEvent('AddToCart'), 'AddToCart');
    const data = events[0].payload.eventData ?? {};
    expect(data.ttp).toBe(TTP_VALUE);
    expect(data.ttclid).toBe(TTCLID_VALUE);
    expect(data.gclid).toBeUndefined();
  });

  test("InitiateCheckout carries ttp and ttclid from the shopper's cookies", async ({
    shop,
    cart,
    context,
  }) => {
    await setTikTokCookies(context, { ttp: TTP_VALUE, clickIds: CLICK_IDS_VALUE });
    await shop.open();
    await shop.addToCart(PRODUCTS.tshirt);
    await cart.open();
    await cart.proceedToCheckout();

    const events = recordsNamed(await waitForEvent('InitiateCheckout'), 'InitiateCheckout');
    const data = events[0].payload.eventData ?? {};
    expect(data.ttp).toBe(TTP_VALUE);
    expect(data.ttclid).toBe(TTCLID_VALUE);
    expect(data.gclid).toBeUndefined();
  });

  test("Purchase carries ttp and ttclid from the buyer's cookies", async ({
    shop,
    cart,
    checkout,
    context,
  }) => {
    await setTikTokCookies(context, { ttp: TTP_VALUE, clickIds: CLICK_IDS_VALUE });
    await shop.open();
    await shop.addToCart(PRODUCTS.tshirt);
    await cart.open();
    await cart.proceedToCheckout();
    await checkout.completeOrder();

    const events = recordsNamed(
      await waitForEvent('Purchase', 1, { timeoutMs: 30_000 }),
      'Purchase'
    );
    const data = events[0].payload.eventData ?? {};
    expect(data.ttp).toBe(TTP_VALUE);
    expect(data.ttclid).toBe(TTCLID_VALUE);
    expect(data.gclid).toBeUndefined();
  });

  test('AddToCart carries neither field when neither cookie is set', async ({ shop }) => {
    await shop.open();
    await shop.addToCart(PRODUCTS.tshirt);

    const events = recordsNamed(await waitForEvent('AddToCart'), 'AddToCart');
    const data = events[0].payload.eventData ?? {};
    expect(data.ttp).toBeUndefined();
    expect(data.ttclid).toBeUndefined();
  });

  test('only _ttp set: ttp is present and ttclid is absent', async ({ shop, context }) => {
    await setTikTokCookies(context, { ttp: TTP_VALUE });
    await shop.open();
    await shop.addToCart(PRODUCTS.tshirt);

    const events = recordsNamed(await waitForEvent('AddToCart'), 'AddToCart');
    const data = events[0].payload.eventData ?? {};
    expect(data.ttp).toBe(TTP_VALUE);
    expect(data.ttclid).toBeUndefined();
  });

  test('the debug log lists the TikTok cookies of the request', async ({ shop, context }) => {
    await setTikTokCookies(context, { ttp: TTP_VALUE, clickIds: CLICK_IDS_VALUE });
    await shop.open();
    await shop.addToCart(PRODUCTS.tshirt);

    const events = recordsNamed(await waitForEvent('AddToCart'), 'AddToCart');
    const cookieNames = Object.keys(events[0].cookies ?? {});
    expect(cookieNames).toContain('_ttp');
    expect(cookieNames).toContain('_pf_click_ids');
  });
});

test.describe('TikTok ids on a held-then-flushed event', () => {
  test.use({ consent: 'undecided' });

  test("a held AddToCart carries ttp and ttclid from the request that flushes it", async ({
    shop,
    consentBanner,
    context,
  }) => {
    await setTikTokCookies(context, { ttp: TTP_VALUE, clickIds: CLICK_IDS_VALUE });
    await shop.open();
    await shop.addToCart(PRODUCTS.tshirt);

    await waitForHeld('AddToCart', 1, { timeoutMs: 30_000 });
    await expectNoTrackedEvents();

    await consentBanner.accept();

    const records = await waitForEvent('AddToCart', 1, { timeoutMs: 30_000 });
    const flushed = sentRecords(records, 'AddToCart');
    expect(flushed).toHaveLength(1);

    const data = flushed[0].payload.eventData ?? {};
    expect(data.ttp).toBe(TTP_VALUE);
    expect(data.ttclid).toBe(TTCLID_VALUE);
  });
});
