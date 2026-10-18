import { expect, test } from '@playwright/test';

test.describe.configure({ mode: 'serial' });

const BASE = '/admin/config/system/import-engine/connections';

test('a connection is made, and the page says what it does', async ({ page }) => {
  await page.goto(`${BASE}/add`);
  await expect(page.locator('.import-wizard-header')).toContainText('New connection');
  await expect(page.locator('.import-wizard-header')).toContainText('Not saved yet');
  // Before it is saved the panel says what a connection is.
  await expect(page.locator('#connection-summary-plain')).toContainText('A connection is an address with its login');

  await page.fill('[name=label]', 'E2E connection');
  await expect(page.locator('[name=id]')).toHaveValue('e2e_connection');
  await page.fill('[name="source[settings][url]"]', 'http://example.invalid/graphql');
  await page.locator('input[name="op"][value="Save"], [data-gin-sticky-form-selector][value="Save"]').first().click();
  await page.waitForLoadState('networkidle');

  await expect(page.locator('.messages--status')).toContainText('E2E connection');
  await expect(page.locator('table tbody tr', { hasText: 'e2e_connection' })).toContainText('http://example.invalid/graphql');
});

test('a saved connection shows who uses it and how it logs in', async ({ page }) => {
  await page.goto(`${BASE}/e2e_connection/edit`);
  const header = page.locator('.import-wizard-header');
  await expect(header).toContainText('E2E connection');
  await expect(header).toContainText('Not used yet');
  await expect(header).toContainText('http://example.invalid');
  const panel = page.locator('#connection-summary-plain');
  await expect(panel).toContainText('Reads from http://example.invalid, as a GraphQL source.');
  await expect(panel).toContainText('It does not log in.');
  await expect(panel).toContainText('No import uses it yet.');
  await expect(panel).not.toContainText('graphql/');

  // The word in the form has an icon with its meaning.
  await expect(page.locator('.import-info__icon').first()).toBeVisible();

  // The connection of the project is used by imports.
  await page.goto(`${BASE}/site_a/edit`);
  await expect(page.locator('.import-wizard-header')).toContainText(/Used by \d+ imports/);
  await expect(page.locator('#connection-summary-plain')).toContainText('SITE_A_API_KEY');
});
