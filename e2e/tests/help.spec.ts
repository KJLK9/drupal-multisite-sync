import { expect, test } from '@playwright/test';

const HELP = '/admin/config/system/import-engine/help';

test('the help page explains the words', async ({ page }) => {
  await page.goto(HELP);
  await expect(page.getByRole('heading', { name: 'The big picture' })).toBeVisible();
  await expect(page.getByRole('heading', { name: 'Your first import' })).toBeVisible();
  await expect(page.locator('dt#breaker')).toHaveText('Circuit breaker');
});

test('an info icon explains a word on hover and links to the help page', async ({ page }) => {
  await page.goto('/admin/config/system/import-engine/definitions/add');
  await page.fill('[name=label]', 'E2E help');
  await page.locator('.import-wizard-title').waitFor();
  const icon = page.locator('.import-info__icon').first();
  const tip = page.locator('.import-info__tip').first();
  await expect(tip).toBeHidden();
  await icon.hover();
  await expect(tip).toBeVisible();
  await tip.getByRole('link', { name: 'More about this' }).click();
  await expect(page).toHaveURL(/import-engine\/help#/);
  await expect(page.locator('dt:target')).toBeVisible();
});
