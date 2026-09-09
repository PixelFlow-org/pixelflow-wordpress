/**
 * Events performed under an unanswered banner are held, then flushed on accept.
 *
 * This file is the only one that runs with the banner still standing, so it is
 * also where the banner baseline itself is asserted: if the CMP stopped
 * rendering, every other consent scenario would pass for the wrong reason.
 */
import { test, expect, withAdminPage } from '../fixtures';
import { PRODUCTS } from '../site';
import { applySettings, TRACK_EVERYTHING } from '../presets';
import {
  additionalData,
  contentIds,
  expectNoTrackedEvents,
  readEventRecords,
  sentRecords,
  waitForEvent,
  waitForHeld,
} from '../helpers/debug-log';

/** The storefront script's idle poll interval; a signal-driven flush must beat it. */
const POLL_INTERVAL_MS = 10_000;

test.use({ consent: 'undecided' });

test.beforeAll(async ({ browser }) => {
  await withAdminPage(browser, (page) => applySettings(page, TRACK_EVERYTHING));
});

test.describe('Consent hold and flush', () => {
  test('the banner is present and offers both choices', async ({ shop, consentBanner }) => {
    await shop.open();

    await consentBanner.waitUntilVisible();
    expect(await consentBanner.isVisible(), 'the consent banner is not visible').toBe(true);
    expect(
      await consentBanner.offersBothChoices(),
      'the banner does not offer both accept and deny — it is not in opt-in mode'
    ).toBe(true);

    // Nothing is decided until one of them is clicked.
    expect(await consentBanner.marketingConsent()).not.toBe('allow');
  });

  test('AddToCart under an unanswered banner logs nothing and is held', async ({
    shop,
    consentBanner,
  }) => {
    await shop.open();
    await shop.addToCart(PRODUCTS.tshirt);

    await waitForHeld('AddToCart', 1, { timeoutMs: 30_000 });
    await expectNoTrackedEvents();
    expect(
      await consentBanner.hasHeldEvents(),
      'the hold queue cookie was not written, so nothing is waiting for a decision'
    ).toBe(true);
  });

  test('InitiateCheckout under an unanswered banner logs nothing and is held', async ({
    shop,
    cart,
    consentBanner,
  }) => {
    await shop.open();
    await shop.addToCart(PRODUCTS.tshirt);
    await cart.open();
    await cart.proceedToCheckout();

    await waitForHeld('InitiateCheckout', 1, { timeoutMs: 30_000 });
    await expectNoTrackedEvents();
    expect(await consentBanner.hasHeldEvents()).toBe(true);
  });

  test('accepting the banner flushes both held events with what was captured at hold time', async ({
    shop,
    cart,
    consentBanner,
  }) => {
    const held = [PRODUCTS.tshirt, PRODUCTS.sale];

    await shop.open();
    for (const product of held) {
      await shop.addToCart(product);
    }
    await cart.open();
    await cart.proceedToCheckout();
    await expectNoTrackedEvents();

    await consentBanner.accept();

    const records = await waitForEvent('InitiateCheckout', 1, { timeoutMs: 30_000 });
    const addToCart = sentRecords(records, 'AddToCart');
    const initiateCheckout = sentRecords(records, 'InitiateCheckout');

    expect(addToCart, 'the held AddToCart events were not flushed').toHaveLength(held.length);
    expect(initiateCheckout, 'the held InitiateCheckout was not flushed').toHaveLength(1);

    const flushedIds = initiateCheckout.flatMap(contentIds);
    for (const product of held) {
      expect(flushedIds, `${product.sku} missing from the flushed InitiateCheckout`).toContain(
        String(product.id)
      );
    }
    expect(additionalData(initiateCheckout[0]).value).toBeGreaterThan(0);
  });

  test('a cart changed between the hold and the grant is reported as it stood when held', async ({
    shop,
    cart,
    consentBanner,
  }) => {
    await shop.open();
    await shop.addToCart(PRODUCTS.tshirt);
    await cart.open();
    await cart.proceedToCheckout();
    await expectNoTrackedEvents();

    // The shopper keeps shopping before answering the banner. The held event
    // must still describe the cart it was captured from.
    await shop.open();
    await shop.addToCart(PRODUCTS.sale);

    await consentBanner.accept();

    const records = await waitForEvent('InitiateCheckout', 1, { timeoutMs: 30_000 });
    const initiateCheckout = sentRecords(records, 'InitiateCheckout');
    expect(initiateCheckout).toHaveLength(1);

    const ids = contentIds(initiateCheckout[0]);
    expect(ids, 'the flushed InitiateCheckout lost the product it was captured with').toContain(
      String(PRODUCTS.tshirt.id)
    );
    expect(
      ids,
      'the flushed InitiateCheckout reports the changed cart instead of the cart at hold time'
    ).not.toContain(String(PRODUCTS.sale.id));
  });

  test('the flush happens on the consent signal, not on the idle poll', async ({
    shop,
    consentBanner,
  }) => {
    await shop.open();
    await shop.addToCart(PRODUCTS.tshirt);
    await expectNoTrackedEvents();

    const startedAt = Date.now();
    await consentBanner.accept();
    await waitForEvent('AddToCart', 1, { timeoutMs: 30_000, pollMs: 250 });
    const elapsed = Date.now() - startedAt;

    expect(
      elapsed,
      `the flush took ${elapsed}ms — at or beyond the ${POLL_INTERVAL_MS}ms poll interval, so it ` +
        'was the timer that acted, not the consent signal'
    ).toBeLessThan(POLL_INTERVAL_MS);

    expect(sentRecords(readEventRecords(), 'AddToCart')).toHaveLength(1);
  });
});
