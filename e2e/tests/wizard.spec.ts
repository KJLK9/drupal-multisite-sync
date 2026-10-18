import { expect, test, type Page } from '@playwright/test';

// One import, made in the first test, is edited and copied in the next ones.
test.describe.configure({ mode: 'serial' });

const ADD = '/admin/config/system/import-engine/definitions/add';
const LIST = '/admin/config/system/import-engine/definitions';

const QUERY = `query ($limit: Int!, $offset: Int!) {
  customers(limit: $limit, offset: $offset) {
    totalCount
    items { id label customerNumber status description }
  }
}`;

/**
 * Presses a button of the wizard and waits until what it does is done.
 *
 * A button that works by AJAX is waited for by its answer, any other one by the
 * page it loads; waiting for the network to be quiet is not enough, since it
 * can be quiet before the request has started.
 */
async function press(page: Page, name: string): Promise<void> {
  // Gin repeats the main buttons in a bar that stays in view; that is the one a
  // person presses, and the one that is pressed here when there is one.
  const sticky = page.locator(`input[name="${name}"][data-gin-sticky-form-selector]`);
  const button = (await sticky.count()) > 0
    ? sticky
    : page.locator(`form.import-definition-wizard [name="${name}"]`);
  const byAjax = ((await button.getAttribute('data-once')) ?? '').includes('drupal-ajax');
  if (byAjax) {
    await Promise.all([page.waitForResponse((r) => r.url().includes('ajax_form=1')), button.click()]);
  }
  else {
    await Promise.all([page.waitForNavigation(), button.click()]);
  }
  await page.waitForLoadState('networkidle');
}

/** Tries the source: the panel is closed until it has been used. */
async function tryTheSource(page: Page): Promise<void> {
  const panel = page.locator('#import-wizard-source-test details').first();
  if (!(await panel.evaluate((el) => el.hasAttribute('open')))) {
    await panel.locator('summary').first().click();
  }
  await press(page, 'test_source');
}

test('a new import is made in five steps and starts switched off', async ({ page }) => {
  await page.goto(ADD);

  // Step 1: the name, and a source from a connection.
  await page.fill('[name=label]', 'E2E accounts');
  await expect(page.locator('[name=id]')).toHaveValue('e2e_accounts');
  await page.selectOption('[name="source[connection]"]', 'site_a');
  await page.waitForLoadState('networkidle');
  await page.fill('[name="source[settings][query]"]', QUERY);
  await page.fill('[name="source[settings][items_path]"]', 'data.customers.items');
  await expect(page.locator('.import-wizard-intro')).toContainText('Where does the data come from?');
  await press(page, 'next');

  // Step 2: paging, and the source is tried.
  await expect(page.locator('h2.import-wizard-title')).toContainText('Step 2 of 5');
  await page.selectOption('[name="pagination[plugin]"]', 'offset_limit');
  await page.waitForLoadState('networkidle');
  await page.fill('[name="pagination[settings][offset_param]"]', 'variables.offset');
  await page.fill('[name="pagination[settings][limit_param]"]', 'variables.limit');
  await page.fill('[name="pagination[settings][page_size]"]', '50');
  await page.fill('[name="pagination[settings][total_path]"]', 'data.customers.totalCount');
  await tryTheSource(page);
  await expect(page.locator('#import-wizard-source-test')).toContainText('customerNumber');
  await press(page, 'next');

  // Step 3: the key and the target.
  await expect(page.locator('h2.import-wizard-title')).toContainText('Step 3 of 5');
  await page.fill('[name=source_key]', 'customerNumber');
  await page.selectOption('[name="target[settings][content]"]', { label: 'Account' });
  await page.waitForLoadState('networkidle');
  await page.fill('[name="target[settings][owner]"]', 'admin (1)');
  await press(page, 'next');

  // Step 4: the mapping. The sample shows what each path holds, and the
  // preview what the mapping makes of it.
  await expect(page.locator('h2.import-wizard-title')).toContainText('Step 4 of 5');
  await tryTheSource(page);
  await press(page, 'suggest_mapping');
  const name = page.locator('[name="mapping[name][sources][value]"]');
  await expect(name).not.toHaveValue('');
  await expect(name.locator('xpath=ancestor::*[contains(@class,"form-item")][1]').locator('.import-wizard-example'))
    .toContainText('In the sample:');
  await press(page, 'preview_mapping');
  await expect(page.locator('#import-wizard-result-preview table')).toContainText('Will be saved as');
  await expect(page.locator('#import-wizard-result-preview table tbody tr').first()).toContainText('ok');
  await press(page, 'next');

  // Step 5: the summary, and the import is switched off until someone turns it on.
  await expect(page.locator('h2.import-wizard-title')).toContainText('Step 5 of 5');
  await expect(page.locator('[name=status]')).not.toBeChecked();
  const summary = page.locator('#import-wizard-summary-plain');
  await expect(summary).toContainText('Reads items from the connection "site_a"');
  await expect(summary).toContainText('An item is recognised by customerNumber');
  await expect(summary).toContainText('It is switched off.');
  await press(page, 'save');

  await expect(page.locator('.messages--status')).toContainText('The import E2E accounts is saved.');
  await page.goto(LIST);
  const row = page.locator('table tbody tr', { hasText: 'e2e_accounts' });
  await expect(row).toContainText('disabled');
});

test('an import can be edited step by step, and keeps what it had', async ({ page }) => {
  await page.goto(`${LIST}/e2e_accounts/edit`);
  await expect(page.locator('h2.import-wizard-title')).toContainText('Step 1 of 5');
  await expect(page.locator('[name=label]')).toHaveValue('E2E accounts');

  // Every step opens from the menu: they come from the form cache, which broke
  // once on something that could not be stored in it.
  for (const [number, title] of [
    [2, 'Paging and authentication'],
    [3, 'Key and target'],
    [4, 'Mapping'],
    [5, 'Behaviour'],
  ] as const) {
    await press(page, `goto_${number}`);
    await expect(page.locator('h2.import-wizard-title')).toContainText(`Step ${number} of 5: ${title}`);
    await expect(page.locator('.import-info__icon').first()).toBeVisible();
  }

  await press(page, 'goto_4');
  await expect(page.locator('[name="mapping[name][sources][value]"]')).toHaveValue('label');

  await press(page, 'goto_1');
  await page.fill('[name=description]', 'Edited by the end-to-end test.');
  await press(page, 'goto_5');
  await press(page, 'save');
  await expect(page.locator('.messages--status')).toContainText('The import E2E accounts is saved.');

  await page.goto(`${LIST}/e2e_accounts/edit`);
  await expect(page.locator('[name=description]')).toHaveValue('Edited by the end-to-end test.');
  await press(page, 'goto_4');
  await expect(page.locator('[name="mapping[name][sources][value]"]')).toHaveValue('label');
});

test('a check on a field is previewed and saved', async ({ page }) => {
  await page.goto(`${LIST}/e2e_accounts/edit`);
  await press(page, 'goto_4');
  await page.selectOption('[name="mapping[name][validator][plugin]"]', 'length');
  await page.waitForLoadState('networkidle');
  await page.locator('#import-wizard-mapping details', { hasText: 'Settings' }).first();
  await expect(page.locator('[name="mapping[name][validator][on_invalid]"]')).toBeVisible();
  await page.selectOption('[name="mapping[name][validator][on_invalid]"]', 'skip_item');
  await press(page, 'goto_5');
  await expect(page.locator('#import-wizard-summary-plain')).toContainText('checked: Length');
  await expect(page.locator('#import-wizard-summary-plain')).toContainText('the item is skipped');
  await press(page, 'save');
  await expect(page.locator('.messages--status')).toContainText('is saved.');
});

test('an import is copied, and the copy starts switched off', async ({ page }) => {
  await page.goto(LIST);
  const row = page.locator('table tbody tr', { hasText: 'e2e_accounts' });
  // The operations beyond the first are in a menu behind a button.
  await row.getByRole('button', { name: /additional actions/i }).click();
  await row.getByRole('link', { name: 'Clone' }).click();

  await expect(page.locator('.messages--status')).toContainText('This is a copy.');
  await expect(page.locator('[name=label]')).toHaveValue('');
  await page.fill('[name=label]', 'E2E copy');
  await expect(page.locator('[name=id]')).toHaveValue('e2e_copy');
  await press(page, 'goto_4');
  await expect(page.locator('[name="mapping[name][sources][value]"]')).toHaveValue('label');
  await press(page, 'goto_5');
  await expect(page.locator('[name=status]')).not.toBeChecked();
  await press(page, 'save');
  await expect(page.locator('.messages--status')).toContainText('The import E2E copy is saved.');

  await page.goto(LIST);
  await expect(page.locator('table tbody tr', { hasText: 'e2e_copy' })).toContainText('disabled');
});

test('a template is listed apart and can only be copied', async ({ page }) => {
  await page.goto(`${LIST}/e2e_copy/edit`);
  await page.check('[name=template]');
  await press(page, 'goto_5');
  await expect(page.locator('[name=status]')).toBeDisabled();
  await press(page, 'save');
  await expect(page.locator('.messages--status')).toContainText('is saved.');

  await page.goto(LIST);
  const templates = page.locator('h2', { hasText: 'Templates' }).locator('xpath=following::table[1]');
  await expect(templates).toContainText('e2e_copy');
  const operations = templates.locator('tr', { hasText: 'e2e_copy' });
  await expect(operations.getByRole('link', { name: 'Use as template' })).toBeVisible();
  await expect(operations.getByRole('link', { name: 'Run now', includeHidden: true })).toHaveCount(0);
});
