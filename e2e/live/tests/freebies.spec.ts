/**
 * The three freebie suppression flags, exercised one at a time so it is clear
 * each governs only its own event.
 *
 * Structured by settings preset rather than by product: applying a preset means
 * driving the settings panel, which is the slowest thing in the run, so the
 * outer loop is the preset and every product and persona is checked under it
 * before the next one is applied.
 */
import { test, expect, withAdminPage, CUSTOMER_STATE } from '../fixtures';
import { PRODUCTS, VARIATIONS, type Fixture } from '../site';
import { applySettings, freebiesDisabledFor, TRACK_EVERYTHING } from '../presets';
import type { EventKey } from '../pages/settings-page';
import type { CheckoutPage } from '../pages/checkout-page';
import type { ProductPage } from '../pages/product-page';
import { readEventRecords, recordsNamed, waitForRecords } from '../helpers/debug-log';

const EVENT_NAMES: Record<EventKey, string> = {
  add_to_cart: 'AddToCart',
  initiate_checkout: 'InitiateCheckout',
  purchase: 'Purchase',
};

const ALL_EVENTS = Object.values(EVENT_NAMES);

interface FreeItem {
  label: string;
  product: Fixture;
  variation?: string;
}

const FREE_ITEMS: FreeItem[] = [
  { label: 'the free simple product', product: PRODUCTS.free },
  { label: 'the free variation', product: PRODUCTS.variable, variation: VARIATIONS.small.option },
];

/**
 * Add → checkout → purchase with a single zero-price item, returning the events
 * that were logged.
 *
 * The cart page is skipped: InitiateCheckout fires on the checkout page load
 * too, and what is under test here is the flag, not the entry point.
 *
 * Rather than sleeping for a fixed settle window, this waits for the events
 * that are expected to fire — by which point a suppressed one would have fired
 * as well — and then gives it a short grace period before declaring it absent.
 */
async function runFreeFlow(
  item: FreeItem,
  pages: { product: ProductPage; checkout: CheckoutPage },
  expectedPresent: string[]
): Promise<Set<string>> {
  if (item.variation) {
    await pages.product.addVariation(item.product, item.variation);
  } else {
    await pages.product.addSimple(item.product);
  }
  await pages.checkout.open();
  await pages.checkout.completeOrder();

  if (expectedPresent.length > 0) {
    await waitForRecords(
      (records) => expectedPresent.every((name) => recordsNamed(records, name).length > 0),
      {
        description: `${expectedPresent.join(' + ')} for ${item.label}`,
        timeoutMs: 40_000,
      }
    );
  }
  // Grace period: a suppressed event would have been dispatched by now, so this
  // only guards against it landing a moment late.
  await new Promise((resolve) => setTimeout(resolve, 4_000));

  const records = readEventRecords();
  return new Set(ALL_EVENTS.filter((name) => recordsNamed(records, name).length > 0));
}

/** One settings preset, checked against every free item under both personas. */
function underPreset(
  title: string,
  preset: Parameters<typeof applySettings>[1],
  expectSuppressed: string | null
): void {
  test.describe(title, () => {
    test.beforeAll(async ({ browser }) => {
      await withAdminPage(browser, (page) => applySettings(page, preset));
    });

    for (const persona of ['guest', 'customer'] as const) {
      test.describe(persona, () => {
        if (persona === 'customer') {
          test.use({ storageState: CUSTOMER_STATE });
        }

        for (const item of FREE_ITEMS) {
          test(`${item.label} — ${persona}`, async ({ product, checkout }) => {
            const expectedPresent = ALL_EVENTS.filter((name) => name !== expectSuppressed);
            const seen = await runFreeFlow(item, { product, checkout }, expectedPresent);

            for (const name of expectedPresent) {
              expect(seen, `${name} should still fire for ${item.label}`).toContain(name);
            }
            if (expectSuppressed) {
              expect(
                seen,
                `${expectSuppressed} should have been suppressed for ${item.label}`
              ).not.toContain(expectSuppressed);
            }
          });
        }
      });
    }
  });
}

test.describe('Freebie suppression flags', () => {
  underPreset('all three events enabled for free products', TRACK_EVERYTHING, null);

  for (const [flag, suppressed] of Object.entries(EVENT_NAMES) as [EventKey, string][]) {
    underPreset(`only ${suppressed} disabled for free products`, freebiesDisabledFor(flag), suppressed);
  }

  test.afterAll(async ({ browser }) => {
    await withAdminPage(browser, (page) => applySettings(page, TRACK_EVERYTHING));
  });
});
