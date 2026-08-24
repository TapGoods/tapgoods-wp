// @ts-check
const { test, expect } = require('@playwright/test');
const { pageUrl, itemCards, categoryLinks } = require('./helpers');

/**
 * Shop page with inventory grid, from the pre-release QA checklist.
 *
 * The fixture catalog is deliberately small and known: "6ft Round Table" in the
 * Tables category with the sub-category "Round Tables", and "Folding Chair" in
 * Chairs. That is what makes assertions here exact instead of "something rendered".
 */
// Desktop only. Nothing here is viewport-dependent, and on a phone the category
// filter is collapsed behind the accordion by design, so running these there would
// assert the accordion rather than the grid. The mobile project owns that contract
// in category-accordion.spec.js.
test.beforeEach(({}, testInfo) => {
  test.skip(testInfo.project.name !== 'desktop', 'Grid behaviour is viewport-independent.');
});

test.describe('shop grid', () => {
  test('renders the catalog', async ({ page, request }) => {
    await page.goto(await pageUrl(request, 'shop'));

    await expect(itemCards(page)).toHaveCount(2);
    await expect(page.getByText('6ft Round Table').first()).toBeVisible();
    await expect(page.getByText('Folding Chair').first()).toBeVisible();
  });

  test('shows the real categories and not the sub-categories', async ({ page, request }) => {
    // "Only categories are shown, not subcategories". Round Tables is a
    // sub-category of Tables, so it belongs in the tags taxonomy and must never
    // appear here. This is the browser-side twin of CategoryTaxonomyContractTest.
    await page.goto(await pageUrl(request, 'shop'));

    const names = (await categoryLinks(page).allTextContents()).map((t) => t.trim());

    expect(names).toContain('Tables');
    expect(names).toContain('Chairs');
    expect(names).not.toContain('Round Tables');
  });

  test('picking a category filters the grid and keeps you on it', async ({ page, request }) => {
    // "Categories keep you on the inventory grid": the failure this guards against
    // is being navigated to a bare taxonomy archive with no grid on it.
    const shop = await pageUrl(request, 'shop');
    await page.goto(shop);

    await page.locator('a.category-link[data-category-id="tables"]').click();

    await expect(page).toHaveURL(/category=tables/);
    await expect(itemCards(page)).toHaveCount(1);
    await expect(page.getByText('6ft Round Table').first()).toBeVisible();
    // Still the shop, not somewhere else.
    await expect(categoryLinks(page).first()).toBeVisible();
  });

  test('All Categories clears the filter', async ({ page, request }) => {
    await page.goto((await pageUrl(request, 'shop')) + '&category=tables');
    await expect(itemCards(page)).toHaveCount(1);

    await page.locator('a.category-link[data-category-id=""]').click();

    await expect(itemCards(page)).toHaveCount(2);
  });

  test('searching narrows the grid', async ({ page, request }) => {
    await page.goto(await pageUrl(request, 'shop'));

    await page.locator('#tg-search').fill('Folding');
    await page.locator('#tg-search').press('Enter');

    await expect(itemCards(page)).toHaveCount(1);
    await expect(page.getByText('Folding Chair').first()).toBeVisible();
  });
});

test.describe('hide item pricing', () => {
  test('prices show by default', async ({ page, request }) => {
    await page.goto(await pageUrl(request, 'shop'));

    await expect(page.locator('.price').first()).toBeVisible();
  });

  test('the hide-pricing shortcode really hides them', async ({ page, request }) => {
    // A business turns this on deliberately, so a regression publishes numbers they
    // chose not to show. Asserted in the browser as well as in PHP because this is
    // the thing a person would actually eyeball before a release.
    await page.goto(await pageUrl(request, 'shop-hide-pricing'));

    await expect(itemCards(page)).toHaveCount(2);
    await expect(page.locator('.price')).toHaveCount(0);
  });
});
