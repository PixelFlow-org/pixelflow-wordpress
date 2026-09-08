/** AddToCart from the product page, per product type. */
import { test, expect, forEachPersona, withAdminPage } from '../fixtures';
import { GROUPED_CHILDREN, PRODUCTS, VARIATIONS } from '../site';
import { applySettings, TRACK_EVERYTHING } from '../presets';
import {
  additionalData,
  contentIds,
  expectNoEvents,
  recordsNamed,
  waitForEvent,
} from '../helpers/debug-log';

test.beforeAll(async ({ browser }) => {
  await withAdminPage(browser, (page) => applySettings(page, TRACK_EVERYTHING));
});

forEachPersona('AddToCart from the product page', () => {
  for (const product of [PRODUCTS.tshirt, PRODUCTS.free, PRODUCTS.sale, PRODUCTS.download] as const) {
    test(`logs one AddToCart for simple product ${product.sku}`, async ({ product: page }) => {
      await page.addSimple(product);

      const events = recordsNamed(await waitForEvent('AddToCart'), 'AddToCart');
      expect(events).toHaveLength(1);
      expect(contentIds(events[0])).toContain(String(product.id));
    });
  }

  test('logs the selected variation for a variable product', async ({ product: page }) => {
    await page.addVariation(PRODUCTS.variable, VARIATIONS.large.option);

    const events = recordsNamed(await waitForEvent('AddToCart'), 'AddToCart');
    expect(events).toHaveLength(1);

    const data = additionalData(events[0]);
    const ids = contentIds(events[0]);
    expect(
      ids.includes(String(VARIATIONS.large.id)) || ids.includes(String(PRODUCTS.variable.id)),
      `AddToCart identified neither the variation nor its parent; got ${ids.join(', ')}`
    ).toBe(true);
    expect(data.contents?.[0]?.item_price).toBeCloseTo(VARIATIONS.large.price, 2);
  });

  test('logs an AddToCart for each grouped child added', async ({ product: page }) => {
    await page.addGroupedChildren(PRODUCTS.grouped, GROUPED_CHILDREN);

    const records = await waitForEvent('AddToCart', GROUPED_CHILDREN.length);
    const events = recordsNamed(records, 'AddToCart');
    expect(events).toHaveLength(GROUPED_CHILDREN.length);

    const logged = events.flatMap((event) =>
      contentIds(event)
    );
    for (const childId of GROUPED_CHILDREN) {
      expect(logged).toContain(String(childId));
    }
  });

  test('logs nothing for an external product', async ({ product: page }) => {
    await page.activateExternal(PRODUCTS.external);
    await expectNoEvents();
  });
});
