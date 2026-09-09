/** AddToCart from the storefront listing, per product type. */
import { test, expect, forEachPersona, withAdminPage } from '../fixtures';
import { PRODUCTS } from '../site';
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

const ADDABLE = [PRODUCTS.tshirt, PRODUCTS.free, PRODUCTS.sale, PRODUCTS.virtual] as const;
const NAVIGATING = [PRODUCTS.variable, PRODUCTS.grouped, PRODUCTS.external] as const;

forEachPersona('AddToCart from the shop listing', () => {
  for (const product of ADDABLE) {
    test(`logs one AddToCart for ${product.sku}`, async ({ shop }) => {
      await shop.open();
      await shop.addToCart(product);

      const records = await waitForEvent('AddToCart');
      const events = recordsNamed(records, 'AddToCart');
      expect(events, `expected exactly one AddToCart for ${product.sku}`).toHaveLength(1);

      const data = additionalData(events[0]);
      expect(contentIds(events[0])).toContain(String(product.id));
      expect(data.contents?.[0]?.item_price).toBeCloseTo(product.price, 2);
    });
  }

  for (const product of NAVIGATING) {
    test(`logs nothing when ${product.sku} navigates from the listing`, async ({ shop }) => {
      await shop.open();
      await shop.activateNavigatingControl(product);
      await expectNoEvents();
    });
  }
});
