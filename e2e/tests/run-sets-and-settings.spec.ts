import { expect, test } from '@playwright/test';

test.describe.configure({ mode: 'serial' });

const BASE = '/admin/config/system/import-engine';

test('a run set is made, and the page says what it does', async ({ page }) => {
  await page.goto(`${BASE}/run-sets/add`);
  await expect(page.locator('.import-wizard-header')).toContainText('New run set');
  await expect(page.locator('#run-set-summary-plain')).toContainText('A run set is a named list of imports');

  await page.fill('[name=label]', 'E2E set');
  await expect(page.locator('[name=id]')).toHaveValue('e2e_set');
  await page.selectOption('[name="imports[0][import]"]', 'accounts');
  await page.locator('[data-gin-sticky-form-selector][value="Save"]').first().click();
  await page.waitForLoadState('networkidle');
  await expect(page.locator('.messages--status')).toContainText('E2E set');

  await page.goto(`${BASE}/run-sets/e2e_set/edit`);
  await expect(page.locator('.import-wizard-header')).toContainText('1 import');
  await expect(page.locator('.import-wizard-header')).toContainText('Not scheduled');
  const panel = page.locator('#run-set-summary-plain');
  await expect(panel).toContainText('Runs Accounts, in this order');
  await expect(panel).toContainText('It does not run by itself');
  await expect(page.locator('.import-info__icon').first()).toBeVisible();
});

test('the settings say how long things are kept, without showing markup', async ({ page }) => {
  await page.goto(`${BASE}/settings`);
  await expect(page.locator('.import-wizard-header')).toContainText('Retention and cron');
  await expect(page.locator('#settings-summary-plain')).toContainText('Items that are done are kept for');
  await expect(page.locator('#settings-summary-plain')).toContainText('Cron');

  // The icons are put on once; the configuration forms are rendered twice.
  await expect(page.locator('.import-info__icon').first()).toBeVisible();
  const text = await page.locator('form.import-engine-form').innerText();
  expect(text).not.toContain('<span');
  expect(text).not.toContain('import-info__icon');
});
