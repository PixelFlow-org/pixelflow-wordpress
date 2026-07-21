import { defineConfig } from '@playwright/test';

export default defineConfig({
  testDir: './tests',
  timeout: 30_000,
  expect: { timeout: 10_000 },
  fullyParallel: false,
  workers: 1,
  retries: 0,
  reporter: [['list']],
  use: {
    baseURL: 'http://localhost',
    headless: true,
    viewport: { width: 1280, height: 900 },
    actionTimeout: 10_000,
    ignoreHTTPSErrors: true,
  },
});
