/**
 * Installs the freshly built archive the way WordPress itself does — through
 * Plugins → Add New → Upload Plugin, confirming the "replace current with
 * uploaded" screen — so a broken update surfaces here rather than in production.
 *
 * PF_PLUGIN_ZIP points at the archive produced by build_plugin.sh.
 */
import { test, expect } from '@playwright/test';
import fs from 'node:fs';
import path from 'node:path';
import { ADMIN, URLS } from '../site';
import { wp } from '../helpers/ssh';

const ZIP = process.env.PF_PLUGIN_ZIP ?? '';

test('upload the built plugin through the WordPress plugin installer', async ({ page }) => {
  expect(ZIP, 'PF_PLUGIN_ZIP is not set — run the skill, not this spec directly').not.toBe('');
  expect(fs.existsSync(ZIP), `plugin archive not found at ${ZIP}`).toBe(true);

  await page.goto(URLS.login);
  await page.fill('#user_login', ADMIN.username);
  await page.fill('#user_pass', ADMIN.password);
  await page.click('#wp-submit');
  await page.waitForLoadState('networkidle');
  await expect(page.locator('#login_error'), 'WordPress rejected the admin login').toHaveCount(0);

  await page.goto(URLS.pluginUpload);
  await page.setInputFiles('#pluginzip', path.resolve(ZIP));
  await page.click('#install-plugin-submit');
  await page.waitForLoadState('networkidle');

  // WordPress offers the comparison screen when the plugin is already installed.
  // Its confirm control is a plain link whose href carries the overwrite nonce;
  // following it is exactly what clicking does, and does not depend on the
  // link being scrolled into a clickable position.
  const replace = page.getByRole('link', { name: /replace current with uploaded/i });
  if ((await replace.count()) > 0) {
    const href = await replace.first().getAttribute('href');
    expect(href, 'the replace link carried no href').toBeTruthy();
    await page.goto(new URL(href!, page.url()).toString(), { waitUntil: 'domcontentloaded' });
  }

  const body = page.locator('#wpbody-content');
  await expect(body, 'the plugin upload did not report success').toContainText(
    /Plugin (updated|installed) successfully|Plugin downgraded successfully/i
  );

  const activate = page.getByRole('link', { name: /^Activate Plugin$/i });
  if (await activate.isVisible().catch(() => false)) {
    await activate.click();
    await page.waitForLoadState('networkidle');
  }

  // The build step reads the version out of the plugin header and passes it in.
  const expected = process.env.PF_PLUGIN_VERSION ?? '';
  const installed = wp('plugin get pixelflow --field=version').trim();
  if (expected) {
    expect(installed, 'the installed version does not match the built archive').toBe(expected);
  }
  expect(wp('plugin get pixelflow --field=status').trim()).toBe('active');
});
