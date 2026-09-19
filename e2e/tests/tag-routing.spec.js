// @ts-check
const { test, expect } = require('@playwright/test');
const { pageUrl, withQuery, itemCards, categoryLinks } = require('./helpers');

/**
 * WPB-166: a tag on an item page must land on the shop grid, filtered.
 *
 * The reported symptom was an empty grid. The visitor path is exactly this one:
 * open an item, click a tag under it, and expect the shop you came from with
 * only that tag's items in it.
 *
 * These run against REAL pretty permalinks (the seed sets /%postname%/ and
 * writes the .htaccess), so the URL under test is /tags/tag-round-tables/ --
 * the shape a customer site actually serves. On plain permalinks the original
 * bug does not appear at all, so a suite pinned to those would have passed
 * throughout.
 *
 * The fixture catalog makes the assertion exact. "6ft Round Table" carries the
 * sub-category "Round Tables", which the sync stores as a tg_tags term;
 * "Folding Chair" carries no tags at all. So a working filter shows exactly one
 * card, and a filter that has quietly stopped filtering shows two.
 */
test.beforeEach(({}, testInfo) => {
  test.skip(testInfo.project.name !== 'desktop', 'Tag routing is viewport-independent.');
});

/** Open the item page for a known fixture item by clicking through the shop. */
async function openItem(page, request, title) {
  await page.goto(await pageUrl(request, 'shop'));
  await page.locator('a.item-name', { hasText: title }).click();
  await expect(page.locator('.tags')).toBeVisible();
}

test.describe('tag routing', () => {
  test('a tag URL redirects to the shop page filtered by that tag', async ({ page, request }) => {
    const shop = await pageUrl(request, 'shop');

    // The ticket's URL, typed straight in: this is what wp-admin's
    // Tags > View links to.
    const response = await page.goto('/tags/tag-round-tables/');

    expect(response?.status()).toBe(200); // After following the redirect.
    await expect(page).toHaveURL(new RegExp(`${shop}\\?tags=tag-round-tables$`));

    await expect(itemCards(page)).toHaveCount(1);
    await expect(page.getByText('6ft Round Table').first()).toBeVisible();
    await expect(page.getByText('Folding Chair')).toHaveCount(0);
  });

  test('clicking a tag on an item lands on the filtered shop grid', async ({ page, request }) => {
    await openItem(page, request, '6ft Round Table');

    const tag = page.locator('.tags a', { hasText: 'Round Tables' });
    await expect(tag).toBeVisible();
    // The link on the item page is the real term permalink, not a query string.
    await expect(tag).toHaveAttribute('href', /\/tags\/tag-round-tables\/$/);
    await tag.click();

    // On the shop page, not a bare taxonomy archive. The [?&] matters: the term
    // archive this used to land on is "?tg_tags=tag-round-tables", which
    // contains "tags=tag-round-tables" as a substring, so a looser pattern
    // passes on the broken behaviour.
    await expect(page).toHaveURL(/[?&]tags=tag-round-tables/);
    await expect(page).not.toHaveURL(/\/tags\//);
    // The filter sidebar and the search box are the tell that this is the shop.
    await expect(categoryLinks(page).first()).toBeVisible();
    await expect(page.locator('#tg-search')).toBeVisible();

    // And filtered to that tag, not merely non-empty.
    await expect(itemCards(page)).toHaveCount(1);
    await expect(page.getByText('6ft Round Table').first()).toBeVisible();
    await expect(page.getByText('Folding Chair')).toHaveCount(0);
  });

  test('the redirect keeps campaign parameters on the URL', async ({ page }) => {
    // A tag link shared in a newsletter arrives with these; dropping them loses
    // the attribution for every visit that starts at a tag.
    await page.goto('/tags/tag-round-tables/?utm_source=newsletter');

    await expect(page).toHaveURL(/utm_source=newsletter/);
    await expect(page).toHaveURL(/[?&]tags=tag-round-tables/);
    await expect(itemCards(page)).toHaveCount(1);
  });

  test('the tag filter survives searching', async ({ page, request }) => {
    const shop = await pageUrl(request, 'shop');
    await page.goto(withQuery(shop, 'tags=tag-round-tables'));

    await expect(itemCards(page)).toHaveCount(1);

    // The search box must post the tag along with the query, or the first
    // keystroke widens the results back to the whole catalog.
    await expect(page.locator('input[name="tags"]')).toHaveValue('tag-round-tables');

    await page.locator('#tg-search').fill('Folding');
    // "Folding Chair" does not carry this tag, so a filter that is still applied
    // finds nothing; a dropped filter would surface the chair.
    await expect(page.getByText('Folding Chair')).toHaveCount(0);
  });

  test('a page curated with a tags attribute ignores ?tags= from the URL', async ({ page, request }) => {
    // shop-tag-curated is [tapgoods-inventory tags="tag-round-tables"]. A
    // visitor appending ?tags= must not be able to re-point a page the site
    // curated: the attribute wins, the URL only applies where the page said
    // nothing.
    const curated = await pageUrl(request, 'shop-tag-curated');
    await page.goto(withQuery(curated, 'tags=no-such-tag'));

    await expect(itemCards(page)).toHaveCount(1);
    await expect(page.getByText('6ft Round Table').first()).toBeVisible();
    await expect(page.locator('input[name="tags"]')).toHaveValue('tag-round-tables');
  });

  test('the stripped slug in a URL filters the same way', async ({ page, request }) => {
    // Storefront links have historically carried the slug without the sync's
    // "tag-" prefix, and bookmarks made before this change still do.
    const shop = await pageUrl(request, 'shop');
    await page.goto(withQuery(shop, 'tags=round-tables'));

    await expect(itemCards(page)).toHaveCount(1);
    await expect(page.getByText('6ft Round Table').first()).toBeVisible();
  });

  test('picking a category clears the tag filter', async ({ page, request }) => {
    const shop = await pageUrl(request, 'shop');
    await page.goto(withQuery(shop, 'tags=tag-round-tables'));

    await page.locator('a.category-link[data-category-id="chairs"]').click();

    await expect(page).toHaveURL(/category=chairs/);
    await expect(page).not.toHaveURL(/[?&]tags=/);
    await expect(page.getByText('Folding Chair').first()).toBeVisible();
  });
});
