/** Produces the storage states the matrix runs under: WordPress admin and WooCommerce customer. */
import { test as setup, expect } from '@playwright/test';
import fs from 'node:fs';
import path from 'node:path';
import { ADMIN, CUSTOMER, URLS } from '../site';
import { ARTIFACTS_DIR } from '../playwright.config';

export const ADMIN_STATE = path.join(ARTIFACTS_DIR, 'admin-state.json');
export const CUSTOMER_STATE = path.join(ARTIFACTS_DIR, 'customer-state.json');

async function login(page: import('@playwright/test').Page, user: string, pass: string) {
  if (!pass) {
    throw new Error(
      `No password configured for "${user}". Fill e2e/live/.env from .env.example before running.`
    );
  }
  await page.goto(URLS.login);
  await page.fill('#user_login', user);
  await page.fill('#user_pass', pass);
  await page.click('#wp-submit');
  await page.waitForLoadState('networkidle');
  await expect(page.locator('#login_error'), 'WordPress rejected the login').toHaveCount(0);
}

setup('authenticate as administrator', async ({ page }) => {
  fs.mkdirSync(ARTIFACTS_DIR, { recursive: true });
  await login(page, ADMIN.username, ADMIN.password);
  await page.goto(URLS.settings);
  await expect(page.locator('#wpbody')).toContainText(/WooCommerce Settings|Advanced Settings/);
  await page.context().storageState({ path: ADMIN_STATE });
});

setup('authenticate as customer', async ({ page }) => {
  await login(page, CUSTOMER.username, CUSTOMER.password);
  await page.context().storageState({ path: CUSTOMER_STATE });
});
