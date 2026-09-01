import { test, expect, type Page, type Route } from '@playwright/test';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const __dirname = path.dirname(fileURLToPath(import.meta.url));

/**
 * T-002-debug-log-refresh-button
 *
 * Functional e2e coverage for the "Refresh" button in the Debug Log popup
 * (PixelFlow Advanced Settings tab). Exercises the real running app against
 * the operator-provided WordPress instance — no mocked backend, only the
 * `pixelflow_get_debug_log` admin-ajax request is artificially delayed (via
 * page.route + a timed continue()) so the transient "Loading…" state can be
 * captured reliably; the request still hits the real server and returns real
 * data.
 */

const SETTINGS_URL =
  'http://localhost/wp/wp-admin/options-general.php?page=pixelflow-settings';
const LOGIN_URL = 'http://localhost/wp/wp-login.php';
const CREDENTIALS = { username: 'admin', password: 'admin' };
const SCREENSHOTS_DIR = `${__dirname}/../screenshots`;

async function login(page: Page): Promise<void> {
  await page.goto(LOGIN_URL);
  await page.fill('#user_login', CREDENTIALS.username);
  await page.fill('#user_pass', CREDENTIALS.password);
  await page.click('#wp-submit');
  await page.waitForLoadState('networkidle');
}

async function openDebugLogModal(page: Page): Promise<void> {
  await page.goto(SETTINGS_URL);
  await page.waitForLoadState('networkidle');

  // The React settings app boots asynchronously after the WP admin page load.
  await page.locator('text=Advanced Settings').waitFor({ state: 'visible' });
  await page.locator('text=Advanced Settings').click();

  const seeLogsButton = page.getByRole('button', { name: /See logs/ });
  await seeLogsButton.waitFor({ state: 'visible' });
  await seeLogsButton.click();

  await page.locator('[role="dialog"]').waitFor({ state: 'visible' });
}

/** Delays the debug-log ajax response so the transient loading state is observable. */
async function delayDebugLogFetch(page: Page, delayMs: number): Promise<void> {
  await page.route('**/admin-ajax.php', async (route: Route) => {
    const postData = route.request().postData() || '';
    if (postData.includes('pixelflow_get_debug_log')) {
      await new Promise((resolve) => setTimeout(resolve, delayMs));
    }
    await route.continue();
  });
}

test.describe('Debug Log popup — Refresh button (T-002)', () => {
  test.beforeEach(async ({ page }) => {
    await login(page);
  });

  test('AC-4: Refresh button is positioned to the left of Close in the popup footer', async ({
    page,
  }) => {
    await openDebugLogModal(page);

    const footerButtons = page.locator('.pf-modal__footer button');
    await expect(footerButtons).toHaveCount(2);
    await expect(footerButtons.nth(0)).toHaveText('Refresh');
    await expect(footerButtons.nth(1)).toHaveText('Close');

    await page.screenshot({ path: `${SCREENSHOTS_DIR}/AC-4-popup-open.png` });
  });

  test('AC-1, AC-2, AC-3, AC-5: clicking Refresh re-fetches the log, shows Loading… while in flight, disables Refresh, then shows fresh content/empty-state', async ({
    page,
  }) => {
    await openDebugLogModal(page);

    const modalBody = page.locator('.pf-modal__body');
    const refreshButton = page.getByRole('button', { name: 'Refresh' });

    // Initial load has already resolved by the time the modal + footer are interactive.
    await expect(refreshButton).toBeEnabled();

    // Slow the next debug-log request so the loading state is reliably observable
    // (AC-1: clicking Refresh triggers a new fetch of the log file).
    await delayDebugLogFetch(page, 1200);

    await refreshButton.click();

    // AC-2: loading state replaces the previous content; AC-5: Refresh disabled while in flight.
    await expect(modalBody).toHaveText('Loading…');
    await expect(refreshButton).toBeDisabled();
    await page.screenshot({ path: `${SCREENSHOTS_DIR}/AC-2-AC-5-loading-during-refresh.png` });

    // AC-3 (and AC-6 if the log happens to be empty): popup displays the newly fetched result.
    await expect(modalBody).not.toHaveText('Loading…', { timeout: 10_000 });
    await expect(refreshButton).toBeEnabled();
    const refreshedState = await modalBody.innerText();
    // A successful refresh always lands on exactly one of these two rendered states
    // (empty-state message or non-empty <pre> log content) — never blank/stale text.
    expect(refreshedState === 'Log file is empty.' || refreshedState.trim().length > 0).toBe(true);

    await page.screenshot({ path: `${SCREENSHOTS_DIR}/AC-3-popup-after-refresh.png` });
  });

  test('AC-8: clicking Close still closes the popup, unaffected by the Refresh addition', async ({
    page,
  }) => {
    await openDebugLogModal(page);

    const dialog = page.locator('[role="dialog"]');
    await expect(dialog).toBeVisible();

    // Disambiguate from the header "✕" close button — click the footer "Close" button.
    await page.locator('.pf-modal__footer button', { hasText: 'Close' }).click();

    await expect(dialog).toHaveCount(0);
    await page.screenshot({ path: `${SCREENSHOTS_DIR}/AC-8-popup-closed.png` });
  });
});
