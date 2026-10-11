import { test, expect, type Page } from '@playwright/test';
import { execFileSync } from 'node:child_process';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const __dirname = path.dirname(fileURLToPath(import.meta.url));

/**
 * "Only the customer's first purchase" controls in the WooCommerce tab, against the operator's
 * local WordPress with WooCommerce active. Covers the settings surface only — where the controls
 * sit, when they are disabled, and that an invalid day count is refused and not saved. What the
 * setting does to Purchase is covered by the PHP suite and the live specs.
 *
 * The saved options are put back as they were after the run.
 */

const SETTINGS_URL = 'http://localhost/wp/wp-admin/options-general.php?page=pixelflow-settings';
const LOGIN_URL = 'http://localhost/wp/wp-login.php';
const CREDENTIALS = { username: 'admin', password: 'admin' };
const SCREENSHOTS_DIR = `${__dirname}/../screenshots`;
const WP_PATH = '/var/www/html/wp';
const DAYS_ERROR = 'Enter a whole number of days, 1 or more';

function wp(...args: string[]): string {
  return execFileSync('wp', [`--path=${WP_PATH}`, ...args], {
    encoding: 'utf8',
    stdio: ['ignore', 'pipe', 'ignore'],
  }).trim();
}

// Each test saves several times and reloads the panel; the default 30s leaves no margin.
test.describe.configure({ timeout: 90_000 });

let savedOptions = '';

test.beforeAll(() => {
  savedOptions = wp('option', 'get', 'pixelflow_general_options', '--format=json');
  const options = JSON.parse(savedOptions) as Record<string, unknown>;
  wp(
    'option',
    'update',
    'pixelflow_general_options',
    JSON.stringify({
      ...options,
      woo_enabled: 1,
      woo_disable_purchase: 0,
      woo_purchase_first_only: 0,
      woo_purchase_first_only_lookback: 'all',
      woo_purchase_first_only_days: 60,
      woo_purchase_first_only_ignore_free: 1,
    }),
    '--format=json'
  );
});

test.afterAll(() => {
  if (savedOptions) {
    wp('option', 'update', 'pixelflow_general_options', savedOptions, '--format=json');
  }
});

async function login(page: Page): Promise<void> {
  await page.goto(LOGIN_URL);
  await page.fill('#user_login', CREDENTIALS.username);
  await page.fill('#user_pass', CREDENTIALS.password);
  await page.click('#wp-submit');
  await page.waitForLoadState('networkidle');
}

async function openWooTab(page: Page): Promise<void> {
  await page.goto(SETTINGS_URL);
  await page.waitForLoadState('networkidle');
  await page.getByText('WooCommerce Settings', { exact: true }).click();
  await page.locator('#woo_purchase_first_only').waitFor({ state: 'visible' });
}

async function expectSaved(page: Page): Promise<void> {
  const toast = page.getByText(/Settings saved successfully/i).first();
  await expect(toast).toBeVisible();
  await toast.waitFor({ state: 'hidden', timeout: 20_000 }).catch(() => undefined);
}

test('first-purchase controls sit under the free-products toggle and follow the switches', async ({
  page,
}) => {
  await login(page);
  await openWooTab(page);

  // Placement: directly after "Enable Purchase event for free products".
  const freebies = page.locator('#woo_disable_purchase_freebies');
  const firstOnly = page.locator('#woo_purchase_first_only');
  const order = await page.evaluate(() => {
    const a = document.getElementById('woo_disable_purchase_freebies');
    const b = document.getElementById('woo_purchase_first_only');
    return a && b ? a.compareDocumentPosition(b) & Node.DOCUMENT_POSITION_FOLLOWING : 0;
  });
  expect(order, 'the switch follows the Purchase free-products toggle').toBeTruthy();
  await expect(freebies).toBeVisible();
  await expect(page.getByText("Only the customer's first purchase")).toBeVisible();

  // Off: the sub-controls are present but disabled.
  await expect(firstOnly).toHaveAttribute('aria-checked', 'false');
  await expect(page.locator('#woo_purchase_first_only_lookback')).toBeDisabled();
  await expect(page.locator('#woo_purchase_first_only_ignore_free')).toBeDisabled();

  // On: defaults are all time and ignore-free on.
  await firstOnly.click();
  await expectSaved(page);
  await expect(page.locator('#woo_purchase_first_only_lookback')).toBeEnabled();
  await expect(page.locator('#woo_purchase_first_only_lookback')).toContainText('All time');
  await expect(page.locator('#woo_purchase_first_only_ignore_free')).toHaveAttribute('aria-checked', 'true');

  await page.screenshot({ path: `${SCREENSHOTS_DIR}/first-purchase-settings-on.png`, fullPage: true });

  // Purchase disabled: the whole group is disabled, the switch keeps its value.
  await page.locator('#woo_disable_purchase').click();
  await expectSaved(page);
  await expect(firstOnly).toBeDisabled();
  await expect(firstOnly).toHaveAttribute('aria-checked', 'true');
  await page.locator('#woo_disable_purchase').click();
  await expectSaved(page);
  await expect(firstOnly).toBeEnabled();
});

test('an invalid day count shows the message and is not saved', async ({ page }) => {
  await login(page);
  await openWooTab(page);

  const firstOnly = page.locator('#woo_purchase_first_only');
  if ((await firstOnly.getAttribute('aria-checked')) !== 'true') {
    await firstOnly.click();
    await expectSaved(page);
  }

  await page.locator('#woo_purchase_first_only_lookback').click();
  await page.getByRole('menuitem', { name: 'The last N days' }).click();
  await expectSaved(page);

  const days = page.locator('#woo_purchase_first_only_days');
  await days.fill('90');
  await days.press('Enter');
  await expectSaved(page);

  for (const bad of ['0', '7.5', '1e3', '']) {
    await days.fill(bad);
    await days.press('Enter');
    await expect(page.getByText(DAYS_ERROR)).toBeVisible();
  }
  await page.screenshot({ path: `${SCREENSHOTS_DIR}/first-purchase-invalid-days.png`, fullPage: true });

  await page.reload();
  await openWooTab(page);
  await expect(page.locator('#woo_purchase_first_only_days')).toHaveValue('90');
  const stored = JSON.parse(wp('option', 'get', 'pixelflow_general_options', '--format=json')) as Record<
    string,
    unknown
  >;
  expect(stored.woo_purchase_first_only_lookback).toBe('days');
  expect(Number(stored.woo_purchase_first_only_days)).toBe(90);
});
