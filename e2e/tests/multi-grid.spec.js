// @ts-check
const { test, expect } = require('@playwright/test');
const { pageUrl, itemCards } = require('./helpers');

/**
 * Several [tapgoods-inventory] shortcodes on one page (WPB-180).
 *
 * A client page lists every category as its own grid. Each grid renders the same
 * element ids (tg-shop, tg-inventory-grid, tg-search), so any script that looks
 * an element up document-wide silently talks to the first grid only. That is
 * how "Add" in the second grid alerted "Quantity input field is missing": it
 * searched the first grid for a quantity input that lives in the second.
 *
 * The fixture page holds a Tables grid (6ft Round Table) above a Chairs grid
 * (Folding Chair), so "second grid" is exact and not "some other grid".
 */
test.beforeEach(({}, testInfo) => {
  test.skip(testInfo.project.name !== 'desktop', 'Grid behaviour is viewport-independent.');
});

/**
 * The nth [tapgoods-inventory] wrapper on the page. Matched by its id on purpose:
 * `.tapgoods-inventory` is also on the row wrapper and on every item card, so it
 * would count three levels of the same grid.
 */
function grid(page, index) {
  return page.locator('#tg-shop').nth(index);
}

test.describe('multiple inventory grids on one page', () => {
  test('renders each grid with its own category', async ({ page, request }) => {
    await page.goto(await pageUrl(request, 'shop-multi'));

    await expect(page.locator('#tg-shop')).toHaveCount(2);
    await expect(itemCards(grid(page, 0))).toHaveCount(1);
    await expect(grid(page, 0).getByText('6ft Round Table')).toBeVisible();
    await expect(itemCards(grid(page, 1))).toHaveCount(1);
    await expect(grid(page, 1).getByText('Folding Chair')).toBeVisible();
  });

  test('add to cart works from the second grid', async ({ page, request }) => {
    // The bug: a native alert() that the visitor cannot get past. Any dialog
    // here is a failure, whatever it says.
    const dialogs = [];
    page.on('dialog', (dialog) => {
      dialogs.push(dialog.message());
      dialog.dismiss().catch(() => {});
    });

    // A successful add navigates to the storefront's addToCart URL. The seed
    // points that at example.test, which does not resolve, so answer it here and
    // assert on the URL the button chose.
    await page.route('https://example.test/**', (route) =>
      route.fulfill({ status: 200, contentType: 'text/html', body: 'ok' })
    );

    await page.goto(await pageUrl(request, 'shop-multi'));

    const second = grid(page, 1);
    await second.locator('.qty-input').fill('2');
    await second.locator('.add-cart').click();

    await expect(page).toHaveURL(/example\.test\/\d+\/addToCart.*quantity=2/);
    expect(dialogs).toEqual([]);
  });

  test('add to cart still works from the first grid', async ({ page, request }) => {
    // The first grid always worked because the page-wide lookup happened to
    // answer with it. Keep it working once the lookup is per grid.
    const dialogs = [];
    page.on('dialog', (dialog) => {
      dialogs.push(dialog.message());
      dialog.dismiss().catch(() => {});
    });
    await page.route('https://example.test/**', (route) =>
      route.fulfill({ status: 200, contentType: 'text/html', body: 'ok' })
    );

    await page.goto(await pageUrl(request, 'shop-multi'));

    const first = grid(page, 0);
    await first.locator('.qty-input').fill('1');
    await first.locator('.add-cart').click();

    await expect(page).toHaveURL(/example\.test\/\d+\/addToCart.*quantity=1/);
    expect(dialogs).toEqual([]);
  });

  test('searching in the second grid updates only the second grid', async ({ page, request }) => {
    await page.goto(await pageUrl(request, 'shop-multi'));

    // "Folding" matches nothing in Tables. If the second search box were bound to
    // the first grid, the first grid would empty and the second would not move.
    await grid(page, 1).locator('#tg-search').fill('Folding');

    await expect(itemCards(grid(page, 1))).toHaveCount(1);
    await expect(grid(page, 1).getByText('Folding Chair')).toBeVisible();
    await expect(itemCards(grid(page, 0))).toHaveCount(1);
    await expect(grid(page, 0).getByText('6ft Round Table')).toBeVisible();

    // And a term outside the second grid's category empties only that grid.
    await grid(page, 1).locator('#tg-search').fill('Round');

    await expect(itemCards(grid(page, 1))).toHaveCount(0);
    await expect(itemCards(grid(page, 0))).toHaveCount(1);
  });
});
