/**
 * Server-side cart state as the page's own session sees it.
 *
 * Add-to-cart is classic-form on some product types and Store-API-driven on
 * others, so "did the item land in the cart" is asked of WooCommerce directly
 * rather than inferred from a navigation or an optimistic label.
 */
import type { Page } from '@playwright/test';

export async function cartItemCount(page: Page): Promise<number> {
  return page.evaluate(async () => {
    const response = await fetch('/index.php?rest_route=/wc/store/v1/cart', {
      credentials: 'same-origin',
    });
    if (!response.ok) return -1;
    const cart = (await response.json()) as { items_count?: number };
    return cart.items_count ?? 0;
  });
}

/** Waits until the cart holds at least `expected` items. */
export async function waitForCartCount(
  page: Page,
  expected: number,
  what: string,
  timeoutMs = 30_000
): Promise<void> {
  const deadline = Date.now() + timeoutMs;
  for (;;) {
    const count = await cartItemCount(page).catch(() => -1);
    if (count >= expected) return;
    if (Date.now() >= deadline) {
      throw new Error(
        `${what} never reached the cart: WooCommerce reports ${count} item(s), expected at least ${expected}.`
      );
    }
    await page.waitForTimeout(500);
  }
}
