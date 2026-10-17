import { execFileSync } from 'node:child_process';
import { mkdirSync } from 'node:fs';
import { chromium, type FullConfig } from '@playwright/test';

const SITE = 'https://site-b.ddev.site';

/** Runs drush on site B, from the root of the project. */
function drush(...args: string[]): string {
  return execFileSync('vendor/bin/drush', [`--uri=${SITE}`, ...args], {
    cwd: '..',
    encoding: 'utf8',
  }).trim();
}

/**
 * Clean data, then a login.
 *
 * Everything the tests make has an ID that starts with e2e_; it is removed
 * here, so a run starts from the configuration of the project and not from what
 * an earlier run (or a failed one) left. Nothing else is touched.
 */
export default async function globalSetup(config: FullConfig): Promise<void> {
  // New services and routes of the code that is checked out are known.
  drush('cache:rebuild');
  drush(
    'php:eval',
    `foreach (['import_definition', 'import_connection', 'import_run_set'] as $t) {
       $s = \\Drupal::entityTypeManager()->getStorage($t);
       foreach ($s->loadMultiple() as $e) {
         if (str_starts_with((string) $e->id(), 'e2e_')) { $e->delete(); }
       }
     }`,
  );

  const link = drush('user:login', '--uid=1', '--no-browser', '/admin/config/system/import-engine');
  mkdirSync('.auth', { recursive: true });
  const browser = await chromium.launch();
  const context = await browser.newContext({
    ignoreHTTPSErrors: true,
    baseURL: config.projects[0].use.baseURL,
  });
  const page = await context.newPage();
  await page.goto(link);
  // The one-time link logs in and goes on to the page asked for.
  await page.waitForURL(/import-engine/);
  await context.storageState({ path: '.auth/admin.json' });
  await browser.close();
}
