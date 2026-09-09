/**
 * Location on events.
 *
 * An anonymous visitor's location comes from the pf_loc cookie. A signed-in
 * customer's own stored address takes precedence over the cookie, and its
 * values are hashed. In the logged payload these are the ct/st/zp/country keys
 * of customerData.
 */
import { createHash } from 'node:crypto';
import { test, expect, forEachPersona, withAdminPage, CUSTOMER_STATE } from '../fixtures';
import { CUSTOMER, PRODUCTS, URLS } from '../site';
import { applySettings, TRACK_EVERYTHING } from '../presets';
import { customerData, recordsNamed, waitForEvent } from '../helpers/debug-log';

test.beforeAll(async ({ browser }) => {
  await withAdminPage(browser, (page) => applySettings(page, TRACK_EVERYTHING));
});

/** How the plugin hashes a signed-in customer's personal fields. */
function hashed(value: string): string {
  return createHash('sha256').update(value.toLowerCase()).digest('hex');
}

/**
 * The plugin resolves the visitor's location asynchronously and only then
 * writes pf_loc, so the cookie has to be waited for rather than read straight
 * away.
 */
async function waitForLocationCookie(
  context: import('@playwright/test').BrowserContext
): Promise<{ name: string; value: string }> {
  await expect
    .poll(async () => (await context.cookies()).some((cookie) => cookie.name === 'pf_loc'), {
      message: 'pf_loc cookie was never set on the storefront',
      timeout: 30_000,
    })
    .toBe(true);

  return (await context.cookies()).find((cookie) => cookie.name === 'pf_loc')!;
}

async function addAndReadLocation(shop: import('../pages/shop-page').ShopPage) {
  await shop.addToCart(PRODUCTS.tshirt);
  const events = recordsNamed(await waitForEvent('AddToCart'), 'AddToCart');
  return customerData(events[0]);
}

forEachPersona('Location data', () => {
  test('the storefront sets a pf_loc cookie', async ({ page, context }) => {
    await page.goto(URLS.fixtureListing, { waitUntil: 'domcontentloaded' });
    await waitForLocationCookie(context);
  });
});

test.describe('Location data — guest', () => {
  test('an anonymous visitor takes location from the pf_loc cookie', async ({ shop, context }) => {
    await shop.open();
    const cookie = await waitForLocationCookie(context);
    const decoded = JSON.parse(decodeURIComponent(cookie.value)) as Record<string, string>;

    const location = await addAndReadLocation(shop);

    for (const key of ['ct', 'st', 'zp', 'country'] as const) {
      if (!decoded[key]) continue;
      expect(location[key], `customerData.${key} does not match the pf_loc cookie`).toBe(
        decoded[key]
      );
    }
    expect(
      Object.values(location).filter(Boolean).length,
      'customerData carried no location at all'
    ).toBeGreaterThan(0);
  });
});

test.describe('Location data — customer', () => {
  test.use({ storageState: CUSTOMER_STATE });

  test('the account address wins over the pf_loc cookie', async ({ shop, context }) => {
    await shop.open();
    await waitForLocationCookie(context);

    const location = await addAndReadLocation(shop);

    expect(location.ct, 'city did not come from the account').toBe(hashed(CUSTOMER.billing.city));
    expect(location.zp, 'postcode did not come from the account').toBe(
      hashed(CUSTOMER.billing.postcode)
    );
    expect(location.country, 'country did not come from the account').toBe(
      hashed(CUSTOMER.billing.country)
    );
  });

  test('location survives the pf_loc cookie being removed', async ({ page, context, shop }) => {
    await shop.open();
    await context.clearCookies({ name: 'pf_loc' });
    expect((await context.cookies()).some((c) => c.name === 'pf_loc')).toBe(false);

    // A full page load, so the cookie stays absent for the request that carries
    // the event.
    await page.goto(URLS.product(PRODUCTS.tshirt.slug), { waitUntil: 'domcontentloaded' });
    await page.click('form.cart button.single_add_to_cart_button');

    const events = recordsNamed(await waitForEvent('AddToCart'), 'AddToCart');
    const location = customerData(events[0]);

    expect(location.ct, 'city missing without the pf_loc cookie').toBe(
      hashed(CUSTOMER.billing.city)
    );
    expect(location.zp, 'postcode missing without the pf_loc cookie').toBe(
      hashed(CUSTOMER.billing.postcode)
    );
    expect(location.country, 'country missing without the pf_loc cookie').toBe(
      hashed(CUSTOMER.billing.country)
    );
  });
});
