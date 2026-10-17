import { defineConfig } from '@playwright/test';

// Runs inside DDEV (`ddev composer e2e`): the browser and the sites are in the
// same container network, so the site is reached by its DDEV hostname.
export default defineConfig({
  testDir: './tests',
  globalSetup: './global-setup.ts',
  // One import is made, edited and copied in turn; the tests share it.
  workers: 1,
  fullyParallel: false,
  retries: 0,
  timeout: 60_000,
  reporter: [['list']],
  use: {
    baseURL: process.env.E2E_BASE_URL ?? 'https://site-b.ddev.site',
    ignoreHTTPSErrors: true,
    storageState: '.auth/admin.json',
    trace: 'retain-on-failure',
  },
  outputDir: 'test-results',
});
