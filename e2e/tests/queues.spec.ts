import { expect, test } from '@playwright/test';

const BASE = '/admin/config/system/import-engine';

test('the dead letter queue says what waits and why, with the buttons in their colours', async ({ page }) => {
  await page.goto(`${BASE}/dead-letter`);
  await expect(page.locator('.import-wizard-header')).toContainText('Waiting for a person');
  await expect(page.locator('.import-run-filter')).toContainText('All imports');
  await expect(page.locator('#dead-letter-summary-plain')).toContainText('In plain words');
  await expect(page.locator('#dead-letter-summary-plain')).toContainText('Discard removes them, after asking.');
});

test('the circuit breakers say which servers are spared', async ({ page }) => {
  await page.goto(`${BASE}/breakers`);
  await expect(page.locator('.import-wizard-header')).toContainText('Servers that are spared');
  await expect(page.locator('#breaker-summary-plain')).toContainText('When a source keeps failing');
  // The tip of the word is on the page that explains it: the same words as the icons.
  await expect(page.locator('#breaker-summary-plain')).toContainText('the import stops calling it for a while');
});
