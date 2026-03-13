// @ts-check
const { test, expect } = require('@playwright/test');

test('homepage', async ({ page }) => {
  await page.goto('https://localhost/');
  await expect(page).toHaveTitle('Welcome to FetchDock!');
});

// test('swagger', async ({ page }) => {
//   await page.goto('https://localhost/docs');
//   await expect(page).toHaveTitle('FetchDock Server - API Platform');
//   await expect(page.locator('.operation-tag-content > span')).toHaveCount(12);
// });
