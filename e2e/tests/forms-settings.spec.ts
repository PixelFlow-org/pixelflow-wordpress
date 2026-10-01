import { test, expect, type Locator, type Page } from '@playwright/test';
import { execFileSync } from 'node:child_process';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const __dirname = path.dirname(fileURLToPath(import.meta.url));

/**
 * Forms tab of the PixelFlow settings page against the operator's local WordPress, which has
 * Contact Form 7 active with its default "Contact form 1". Covers the settings surface only:
 * turn form tracking on, change a form's event, map an identifier to a field, reload and find
 * the same configuration; and a field whose key only contains a name word is flagged, left out
 * of what is sent, and sent once it is chosen. No form is submitted here — sending is covered by the PHP suite and
 * the live specs.
 */

const SETTINGS_URL = 'http://localhost/wp/wp-admin/options-general.php?page=pixelflow-settings';
const LOGIN_URL = 'http://localhost/wp/wp-login.php';
const CREDENTIALS = { username: 'admin', password: 'admin' };
const SCREENSHOTS_DIR = `${__dirname}/../screenshots`;
const FORM_TITLE = 'Contact form 1';
const WP_PATH = '/var/www/html/wp';
const UNCONFIRMED_TITLE = 'PF unconfirmed name';

/** Runs WP-CLI against the local WordPress and returns its trimmed output. */
function wp(...args: string[]): string {
  return execFileSync('wp', [`--path=${WP_PATH}`, ...args], {
    encoding: 'utf8',
    stdio: ['ignore', 'pipe', 'ignore'],
  }).trim();
}

async function login(page: Page): Promise<void> {
  await page.goto(LOGIN_URL);
  await page.fill('#user_login', CREDENTIALS.username);
  await page.fill('#user_pass', CREDENTIALS.password);
  await page.click('#wp-submit');
  await page.waitForLoadState('networkidle');
}

async function openFormsTab(page: Page, title: string = FORM_TITLE): Promise<void> {
  await page.goto(SETTINGS_URL);
  await page.waitForLoadState('networkidle');
  await page.locator('text=Form Settings').first().waitFor({ state: 'visible' });
  await page.locator('text=Form Settings').first().click();
  // The list is shown only while form tracking is on.
  const master = page.locator('#forms-enabled');
  if ((await master.getAttribute('aria-checked')) !== 'true') {
    await master.click();
    await expect(master).toHaveAttribute('aria-checked', 'true');
  }
  await page.getByRole('button', { name: `Event for ${title}` }).waitFor();
}

/** Opens one of the page's dropdowns and picks an option from its list. */
async function choose(page: Page, control: Locator, option: string | RegExp): Promise<void> {
  await control.click();
  await page.getByRole('menuitem', { name: option }).click();
}

/** Form key of the test form, read from its row. */
async function formKey(page: Page): Promise<string> {
  const row = page.locator('[data-testid^="form-row-cf7:"]', { hasText: FORM_TITLE }).first();
  const testId = (await row.getAttribute('data-testid')) ?? '';
  return testId.replace('form-row-', '');
}

/** Returns the form's record to computed state through the save route. */
async function resetForm(page: Page, key: string): Promise<void> {
  await page.evaluate(async (formKeyValue) => {
    const settings = (window as unknown as { pixelflowSettings: { nonce: string; ajax_url: string } })
      .pixelflowSettings;
    const body = new FormData();
    body.append('action', 'pixelflow_save_form_settings');
    body.append('nonce', settings.nonce);
    body.append(
      'forms',
      JSON.stringify({
        [formKeyValue]: {
          enabled: null,
          event: null,
          value: null,
          fields: { ph: null, fn: null, ln: null },
        },
      })
    );
    await fetch(settings.ajax_url, { method: 'POST', body });
  }, key);
}

test.describe('Forms settings tab', () => {
  test.beforeEach(async ({ page }) => {
    await login(page);
  });

  test('enable tracking, change an event, map a field, and the configuration survives a reload', async ({
    page,
  }) => {
    await openFormsTab(page);
    const key = await formKey(page);
    expect(key).toMatch(/^cf7:\d+$/);
    await resetForm(page, key);
    await openFormsTab(page);

    const master = page.locator('#forms-enabled');
    if ((await master.getAttribute('aria-checked')) !== 'true') {
      await master.click();
      await expect(master).toHaveAttribute('aria-checked', 'true');
    }

    const event = page.getByRole('button', { name: `Event for ${FORM_TITLE}` });
    await choose(page, event, 'Contact');
    await expect(event).toHaveText('Contact');

    await page.getByRole('button', { name: `Configure ${FORM_TITLE}` }).click();
    const panel = page.getByTestId(`form-panel-${key}`);
    await choose(page, panel.getByRole('button', { name: 'Phone' }), /your-subject/);
    await expect(panel.getByRole('button', { name: 'Phone' })).toHaveText('your-subject');
    await page.screenshot({ path: `${SCREENSHOTS_DIR}/forms-configured.png`, fullPage: true });

    await page.reload();
    await openFormsTab(page);

    await expect(page.locator('#forms-enabled')).toHaveAttribute('aria-checked', 'true');
    await expect(page.getByRole('button', { name: `Event for ${FORM_TITLE}` })).toHaveText(
      'Contact'
    );
    await page.getByRole('button', { name: `Configure ${FORM_TITLE}` }).click();
    await expect(
      page.getByTestId(`form-panel-${key}`).getByRole('button', { name: 'Phone' })
    ).toHaveText('your-subject');

    await resetForm(page, key);
  });
  test('a field that only contains a name word is flagged, not sent, and sent once chosen', async ({
    page,
  }) => {
    // Contact Form 7 has no labels, so the key alone decides: `company-name` contains a name
    // word without being one.
    const id = wp(
      'post',
      'create',
      '--post_type=wpcf7_contact_form',
      '--post_status=publish',
      `--post_title=${UNCONFIRMED_TITLE}`,
      '--porcelain'
    );
    wp('post', 'meta', 'update', id, '_form', '[email* your-email] [text company-name] [submit "Send"]');
    const key = `cf7:${id}`;

    try {
      await openFormsTab(page, UNCONFIRMED_TITLE);
      const row = page.getByTestId(`form-row-${key}`);

      await expect(row.getByTestId(`form-unconfirmed-${key}`)).toContainText(
        'Not sent until you choose the field: First name, Last name.'
      );
      await expect(row.getByTestId(`form-summary-${key}`)).toHaveText('Sends: Email');

      await page.getByRole('button', { name: `Configure ${UNCONFIRMED_TITLE}` }).click();
      const panel = page.getByTestId(`form-panel-${key}`);
      await expect(panel.getByTestId('state-fn')).toHaveText('Needs your choice');
      await expect(panel.getByTestId('state-ln')).toHaveText('Needs your choice');
      await page.screenshot({ path: `${SCREENSHOTS_DIR}/forms-unconfirmed.png`, fullPage: true });

      await panel.getByRole('button', { name: 'Use this field' }).first().click();
      await expect(panel.getByRole('button', { name: 'First name' })).toHaveText('company-name');
      await expect(panel.getByRole('button', { name: 'Last name' })).toHaveText('company-name');
      await expect(panel.getByTestId(`form-name-split-${key}`)).toBeVisible();
      await expect(row.getByTestId(`form-unconfirmed-${key}`)).toHaveCount(0);

      await page.reload();
      await openFormsTab(page, UNCONFIRMED_TITLE);
      await expect(page.getByTestId(`form-summary-${key}`)).toHaveText(
        'Sends: Email, First name, Last name'
      );
      await expect(page.getByTestId(`form-unconfirmed-${key}`)).toHaveCount(0);
    } finally {
      // The record first: one left behind would list the deleted form as missing. The form
      // goes whatever happens, or the next run finds two with this title.
      try {
        await resetForm(page, key);
      } finally {
        wp('post', 'delete', id, '--force');
      }
    }
  });
});
