/**
 * Test fixtures for the live suite.
 *
 * Each test starts from a known state: carts cleared server-side, then the
 * debug log truncated, so every record read afterwards belongs to that test.
 */
import { test as base, type Page } from '@playwright/test';
import path from 'node:path';
import { ARTIFACTS_DIR } from './playwright.config';
import { CartPage } from './pages/cart-page';
import { ConsentBanner } from './pages/consent-banner';
import { CheckoutPage } from './pages/checkout-page';
import { ProductPage } from './pages/product-page';
import { ShopPage } from './pages/shop-page';
import { resetCarts } from './helpers/cart-reset';
import { truncateDebugLog } from './helpers/debug-log';
import { URLS } from './site';

/**
 * The consent state a scenario starts from.
 *
 * `granted` is the default because the site now shows an opt-in banner, and
 * every scenario that is not about consent would otherwise hold its events and
 * assert nothing. `declined` is a starting state for whole spec files rather
 * than a step repeated in each body; `undecided` leaves the banner standing for
 * the hold scenarios. A withdrawal is a transition out of `granted` and stays in
 * the test body, where the one scenario that needs it can see it happen.
 */
export type ConsentState = 'granted' | 'undecided' | 'declined';

interface LiveFixtures {
  shop: ShopPage;
  product: ProductPage;
  cart: CartPage;
  checkout: CheckoutPage;
  consentBanner: ConsentBanner;
  consent: ConsentState;
  consentBaseline: void;
  cleanSlate: void;
}

export const test = base.extend<LiveFixtures>({
  cleanSlate: [
    async ({}, use) => {
      resetCarts();
      truncateDebugLog();
      await use();
    },
    { auto: true },
  ],
  consent: ['granted', { option: true }],

  /**
   * Answers the banner in the test's own browser context before the body runs.
   *
   * This has to be a fixture rather than a `beforeAll`: the consent state lives
   * in the browser context and Playwright builds a fresh one per test, so a
   * `beforeAll` acceptance would be discarded before the first body ran.
   */
  consentBaseline: [
    async ({ page, consent, cleanSlate }, use) => {
      void cleanSlate;

      const banner = new ConsentBanner(page);
      await page.goto(URLS.fixtureListing, { waitUntil: 'domcontentloaded' });

      if (consent === 'granted') {
        await banner.accept();
      } else if (consent === 'declined') {
        await banner.deny();
      } else {
        // Leave the banner standing, but wait until the plugin knows there is no
        // decision yet. The tracking script writes that cookie asynchronously,
        // and a scenario acting before it lands would exercise the gap rather
        // than the hold it means to assert.
        await banner.waitForUndecidedState();
      }

      // Establishing the baseline is itself traffic the plugin may record.
      // Truncate again so the log the test reads holds only what the test did.
      truncateDebugLog();

      await use();
    },
    { auto: true },
  ],

  consentBanner: async ({ page }, use) => use(new ConsentBanner(page)),
  shop: async ({ page }, use) => use(new ShopPage(page)),
  product: async ({ page }, use) => use(new ProductPage(page)),
  cart: async ({ page }, use) => use(new CartPage(page)),
  checkout: async ({ page }, use) => use(new CheckoutPage(page)),
});

export const expect = test.expect;

export const ADMIN_STATE = path.join(ARTIFACTS_DIR, 'admin-state.json');
export const CUSTOMER_STATE = path.join(ARTIFACTS_DIR, 'customer-state.json');

export type Persona = 'guest' | 'customer';

/**
 * Runs a block of scenarios twice — once anonymously, once signed in.
 * Guest contexts start empty; the customer context carries the stored login.
 */
export function forEachPersona(
  title: string,
  body: (persona: Persona) => void
): void {
  for (const persona of ['guest', 'customer'] as Persona[]) {
    test.describe(`${title} — ${persona}`, () => {
      if (persona === 'customer') {
        test.use({ storageState: CUSTOMER_STATE });
      }
      body(persona);
    });
  }
}

/**
 * Runs a block in the context of a second visitor who has accepted the banner
 * and shares nothing with the buyer.
 *
 * The ownership predicate is a union of cookie- and session-derived signals, so
 * the only honest way to be "not the buyer" is to be a genuinely different
 * browser. The stranger answers the banner themselves, because a stranger with
 * no consent decision at all would satisfy the requirement for the wrong reason.
 */
export async function withStrangerPage(
  browser: import('@playwright/test').Browser,
  fn: (page: Page) => Promise<void>
): Promise<void> {
  const context = await browser.newContext({ ignoreHTTPSErrors: true });
  const page = await context.newPage();
  try {
    await page.goto(URLS.fixtureListing, { waitUntil: 'domcontentloaded' });
    await new ConsentBanner(page).accept();
    await fn(page);
  } finally {
    await context.close();
  }
}

/** Opens the settings panel in an admin context that is independent of the storefront persona. */
export async function withAdminPage(
  browser: import('@playwright/test').Browser,
  fn: (page: Page) => Promise<void>
): Promise<void> {
  const context = await browser.newContext({ storageState: ADMIN_STATE, ignoreHTTPSErrors: true });
  const page = await context.newPage();
  try {
    await fn(page);
  } finally {
    await context.close();
  }
}
