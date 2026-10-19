import { expect, test } from '@playwright/test';

// The accounts of the demo were written by the catalog import, so they have a
// history (the module import_audit is on); a page of the site that no import
// wrote has no such tab.
test('an imported entity has a history tab that says what it leaves out', async ({ page }) => {
  await page.goto('/admin/site-b/accounts/1');
  // Gin keeps the tabs of an entity in a menu: the link is there, not shown.
  const tab = page.locator('a[href="/admin/site-b/accounts/1/import-history"]');
  await expect(tab).toHaveCount(1);
  await expect(tab).toHaveText('Import history');
  await page.goto('/admin/site-b/accounts/1/import-history');

  await expect(page.locator('.import-wizard-header')).toContainText('Import history of');
  await expect(page.locator('#import-history-about')).toContainText('A change made by hand is not in it.');
  await expect(page.locator('#import-history-about')).toContainText('Events are kept for');
  await expect(page.locator('#import-history-about')).toContainText('The old and new values of the fields are kept for');
  await expect(page.locator('table thead')).toContainText('Changes');
});

test('an entity that no import wrote has no history', async ({ page }) => {
  const response = await page.goto('/admin/site-b/accounts/999999/import-history');
  expect(response?.status()).toBe(404);
});
