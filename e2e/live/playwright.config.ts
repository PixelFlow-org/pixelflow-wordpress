import { defineConfig } from '@playwright/test';
import { mkdtempSync } from 'node:fs';
import { tmpdir } from 'node:os';
import path from 'node:path';
import { SITE } from './site';

/**
 * Live verification suite — runs against the real rift test site and sends real
 * events. Deliberately separate from ../playwright.config.ts so the admin suite
 * can never trigger it by accident.
 *
 * Artifacts land outside the repository; the run's report points at the path.
 */
const artifactsDir =
  process.env.PF_ARTIFACTS_DIR ?? mkdtempSync(path.join(tmpdir(), 'pixelflow-live-'));

export const ARTIFACTS_DIR = artifactsDir;

/** Run with a visible browser window. Off by default; `PF_HEADED=1` turns it on. */
export const HEADED = process.env.PF_HEADED === '1';

/**
 * The user agent a headless run presents.
 *
 * Chromium's own headless UA contains "HeadlessChrome", which the plugin treats as a bot and
 * skips the event for — so a headless run would report success while sending nothing. This is
 * the same string a headed run on this machine sends, minus that marker.
 */
const STOREFRONT_USER_AGENT =
  'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/149.0.0.0 Safari/537.36';

export default defineConfig({
  testDir: './tests',
  outputDir: path.join(artifactsDir, 'test-results'),
  timeout: 120_000,
  expect: { timeout: 20_000 },
  fullyParallel: false,
  workers: 1,
  retries: 0,
  // The run goes through the whole matrix and reports every failure. Each test
  // resets the carts, truncates the log and applies its own settings preset, so
  // a failing scenario does not invalidate the ones after it.
  globalSetup: './global-setup.ts',
  reporter: [['list'], ['json', { outputFile: path.join(artifactsDir, 'results.json') }]],
  use: {
    baseURL: SITE.baseURL,
    // Headless by default: a run should not need a display, and an unattended run is the
    // normal case. Set PF_HEADED=1 to watch it happen in a real window.
    headless: !HEADED,
    // Headless Chromium announces itself as "HeadlessChrome", which the plugin's own bot
    // filter matches — every event would be skipped with "USER AGENT MATCHED BOT SIGNATURE"
    // and the suite would go green while proving nothing. A headed run keeps its real UA.
    ...(HEADED ? {} : { userAgent: STOREFRONT_USER_AGENT }),
    viewport: { width: 1280, height: 900 },
    actionTimeout: 20_000,
    navigationTimeout: 45_000,
    ignoreHTTPSErrors: true,
    screenshot: 'only-on-failure',
    trace: 'retain-on-failure',
  },
  projects: [
    {
      name: 'deploy',
      testMatch: /deploy\.spec\.ts/,
    },
    {
      name: 'setup',
      testMatch: /auth\.setup\.ts/,
    },
    {
      name: 'live',
      testIgnore: [/deploy\.spec\.ts/, /auth\.setup\.ts/],
      dependencies: ['setup'],
    },
  ],
});
