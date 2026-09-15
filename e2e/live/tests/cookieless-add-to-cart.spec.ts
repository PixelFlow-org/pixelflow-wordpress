/**
 * The cookieless add-to-cart rule, from both sides.
 *
 * WooCommerce adds to the cart on any GET carrying an `add-to-cart` parameter, so crawlers that
 * follow such a link produce cart activity with no shopper behind it. The plugin withholds those
 * events when the request carries neither the visitor cookie nor the Facebook browser cookie.
 *
 * The rule is an inference from an *absence*, which is why it needs a test on both sides. A
 * shopper who has not answered the consent banner yet has neither cookie either — both are
 * marketing cookies, withheld until consent is granted — so they look exactly like the crawler.
 * Ranking the consent state above the rule is what keeps their event held and replayable; an
 * earlier build dropped it as automation, permanently and invisibly, because a `bot` row can
 * never be held. These scenarios pin both outcomes so that cannot regress silently.
 *
 * Nothing else in the suite exercises the classic link: every other add goes through the Store
 * API, which is a POST and outside the rule by construction.
 */
import { test, expect, withAdminPage, withCrawlerPage } from '../fixtures';
import { PRODUCTS, URLS } from '../site';
import { applySettings, TRACK_EVERYTHING } from '../presets';
import {
  blockedEntries,
  blockedRecords,
  expectNoTrackedEvents,
  readEventRecords,
  sentRecords,
  waitForBlocked,
  waitForEvent,
  waitForHeld,
} from '../helpers/debug-log';

test.beforeAll(async ({ browser }) => {
  await withAdminPage(browser, (page) => applySettings(page, TRACK_EVERYTHING));
});

/** Every `bot` row the log holds, whatever event it was reported for. */
function botEntries() {
  return blockedRecords(readEventRecords())
    .flatMap(blockedEntries)
    .filter((entry) => entry.reason === 'bot');
}

test.describe('Cookieless add-to-cart — an undecided shopper', () => {
  test.use({ consent: 'undecided' });

  test('a classic add-to-cart link is held for the decision, not dropped as automation', async ({
    page,
  }) => {
    await page.goto(URLS.classicAddToCart(PRODUCTS.tshirt.id), {
      waitUntil: 'domcontentloaded',
    });

    await waitForHeld('AddToCart', 1, { timeoutMs: 30_000 });
    await expectNoTrackedEvents();

    const bots = botEntries();
    expect(
      bots,
      'the undecided shopper was classified as automation — a bot row can never be held, so ' +
        `their AddToCart is lost instead of replayed on a grant: ${JSON.stringify(bots)}`
    ).toHaveLength(0);
  });

  test('accepting afterwards flushes the held event', async ({ page, consentBanner }) => {
    await page.goto(URLS.classicAddToCart(PRODUCTS.tshirt.id), {
      waitUntil: 'domcontentloaded',
    });

    await waitForHeld('AddToCart', 1, { timeoutMs: 30_000 });
    await expectNoTrackedEvents();

    await consentBanner.accept();

    const records = await waitForEvent('AddToCart', 1, { timeoutMs: 30_000 });
    expect(
      sentRecords(records, 'AddToCart'),
      'the held AddToCart from the classic link was never flushed'
    ).toHaveLength(1);
  });
});

test.describe('Cookieless add-to-cart — a shopper who has granted consent', () => {
  test('a classic add-to-cart link is reported normally', async ({ page }) => {
    // Consent is granted by the default fixture, so the visitor cookie exists and the rule's
    // premise — that neither cookie is present — does not hold.
    await page.goto(URLS.classicAddToCart(PRODUCTS.tshirt.id), {
      waitUntil: 'domcontentloaded',
    });

    const records = await waitForEvent('AddToCart', 1, { timeoutMs: 30_000 });
    expect(
      sentRecords(records, 'AddToCart'),
      'a consenting shopper using the classic link was not reported'
    ).toHaveLength(1);

    expect(botEntries(), 'a consenting shopper was filtered as automation').toHaveLength(0);
  });
});

test.describe('Cookieless add-to-cart — a crawler', () => {
  test('a JavaScript-less request on the classic link is withheld and reported under the rule', async ({
    browser,
  }) => {
    await withCrawlerPage(browser, async (page) => {
      await page.goto(URLS.classicAddToCart(PRODUCTS.tshirt.id), {
        waitUntil: 'domcontentloaded',
      });
    });

    const records = await waitForBlocked('AddToCart', 1, { timeoutMs: 30_000 });
    const entries = records.flatMap(blockedEntries);

    expect(
      entries.some(
        (entry) => entry.reason === 'bot' && entry.detail === 'no_cookies_in_wp_plugin'
      ),
      `the rule did not fire for a cookieless crawler: ${JSON.stringify(entries)}`
    ).toBe(true);

    expect(
      sentRecords(readEventRecords(), 'AddToCart'),
      "the crawler's AddToCart was reported as shopper activity"
    ).toHaveLength(0);
  });
});
