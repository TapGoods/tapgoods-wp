// @ts-check

/**
 * Page URLs are resolved through the REST API rather than hardcoded.
 *
 * The wp-env web container is nginx with no try_files rule, so pretty permalinks
 * 404 before reaching WordPress and the seed leaves permalinks plain. That makes
 * page URLs id-based, and ids are not stable across a rebuilt environment. Asking
 * WordPress keeps the specs readable (`shop`, not `/?page_id=6`) and immune to it.
 */
async function pageUrl(request, slug) {
  // ?rest_route= rather than /wp-json/, because permalinks are plain here and the
  // pretty REST route would 404 in nginx before WordPress saw it.
  const response = await request.get(`/?rest_route=/wp/v2/pages&slug=${slug}`);

  if (!response.ok()) {
    throw new Error(`Could not look up the "${slug}" page: HTTP ${response.status()}`);
  }

  const pages = await response.json();

  if (!pages.length) {
    throw new Error(`No page with slug "${slug}". Has "npm run e2e:seed" been run?`);
  }

  return `/?page_id=${pages[0].id}`;
}

/** Item cards rendered by the inventory grid. */
function itemCards(page) {
  return page.locator('[id^="tg-item-"]');
}

/** Category links in the shop filter, excluding the "All Categories" reset. */
function categoryLinks(page) {
  return page.locator('a.category-link[data-category-id]:not([data-category-id=""])');
}

module.exports = { pageUrl, itemCards, categoryLinks };
