import { expect, test } from '@playwright/test';

const BASE = '/admin/config/system/import-engine';

// Nothing is confirmed here: the pages are only looked at.

test('a delete says it cannot be undone, and why it is not possible when it is not', async ({ page }) => {
  await page.goto(`${BASE}/definitions/accounts/delete`);
  await expect(page.locator('.import-wizard-header')).toContainText('Delete the import Accounts?');
  await expect(page.locator('.import-wizard-header .import-pill--failed')).toContainText('Cannot be undone');
  // The import is in a run set, so there is no button, only the reason.
  await expect(page.locator('.import-callout')).toContainText('The run sets');
  await expect(page.locator('#confirm-summary-plain')).toContainText('deleted together with its runs');
  await expect(page.locator('[data-gin-sticky-form-selector][value="Delete"]')).toHaveCount(0);
});

test('a run is asked for in the accent colour, with its option', async ({ page }) => {
  await page.goto(`${BASE}/definitions/accounts/run`);
  await expect(page.locator('.import-wizard-header')).toContainText('Run the import Accounts?');
  await expect(page.locator('[name=full]')).toBeVisible();
  await expect(page.locator('#confirm-summary-plain')).toContainText('progress bar');
});

test('opening a circuit breaker asks to take care', async ({ page }) => {
  await page.goto(`${BASE}/breakers/trip/e2e.example.test`);
  await expect(page.locator('.import-wizard-header .import-pill--template')).toContainText('Take care');
  await expect(page.locator('#confirm-summary-plain')).toContainText('No import will call this server');
});
