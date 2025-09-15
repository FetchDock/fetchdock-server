// @ts-check
const { test, expect } = require('@playwright/test');

test('homepage', async ({ page }) => {
  await page.goto('http://localhost/');
  await expect(page).toHaveTitle('Download Router - API Platform');
});

test('swagger', async ({ page }) => {
  await page.goto('http://localhost/docs');
  await expect(page).toHaveTitle('Download Router - API Platform');
  await expect(page.locator('.operation-tag-content > span')).toHaveCount(5);
});
