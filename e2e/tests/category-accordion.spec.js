// @ts-check
const { test, expect } = require('@playwright/test');
const { pageUrl } = require('./helpers');

/**
 * WPB-179: the categories accordion starts closed on mobile and open on desktop.
 *
 * This is the one item on the checklist that genuinely needs two viewports, and it
 * is also the one that was fixed twice. The first fix branched on wp_is_mobile()
 * while rendering, which cannot work on a cached site: the HTML is stored per URL
 * with no device variance, so whichever device warmed the cache decided for
 * everyone. The markup now ships closed and the viewport opens it in the browser,
 * which is precisely what a browser test can confirm and a PHP test cannot.
 */
test.describe('categories accordion', () => {
  test('is open on a desktop viewport', async ({ page, request }, testInfo) => {
    test.skip(testInfo.project.name !== 'desktop', 'Desktop viewport only.');

    await page.goto(await pageUrl(request, 'shop'));

    await expect(page.locator('#collapseOne')).toBeVisible();
    await expect(page.locator('[data-bs-target="#collapseOne"]')).toHaveAttribute('aria-expanded', 'true');
  });

  test('is closed on a mobile viewport, so the items stay above the fold', async ({ page, request }, testInfo) => {
    test.skip(testInfo.project.name !== 'mobile', 'Mobile viewport only.');

    await page.goto(await pageUrl(request, 'shop'));

    await expect(page.locator('#collapseOne')).toBeHidden();
    await expect(page.locator('[data-bs-target="#collapseOne"]')).toHaveAttribute('aria-expanded', 'false');
  });

  test('can still be opened by tapping it on mobile', async ({ page, request }, testInfo) => {
    test.skip(testInfo.project.name !== 'mobile', 'Mobile viewport only.');

    await page.goto(await pageUrl(request, 'shop'));
    await page.locator('[data-bs-target="#collapseOne"]').click();

    await expect(page.locator('#collapseOne')).toBeVisible();
  });
});
