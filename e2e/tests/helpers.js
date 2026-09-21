// @ts-check

/**
 * Page URLs are resolved through the REST API rather than hardcoded.
 *
 * The seed runs the site on pretty permalinks (see e2e/seed.php), because a tag
 * URL is /tags/<slug>/ on a customer site and WPB-166 only appears there. So the
 * URL this returns is the real permalink WordPress hands out, not /?page_id=N:
 * asking WordPress keeps the specs readable and immune to ids that change
 * whenever the environment is rebuilt.
 */
async function pageUrl(request, slug) {
  // ?rest_route= rather than /wp-json/, so the lookup works the same whether or
  // not the pretty REST route is being served.
  const response = await request.get(`/?rest_route=/wp/v2/pages&slug=${slug}`);

  if (!response.ok()) {
    throw new Error(`Could not look up the "${slug}" page: HTTP ${response.status()}`);
  }

  const pages = await response.json();

  if (!pages.length) {
    throw new Error(`No page with slug "${slug}". Has "npm run e2e:seed" been run?`);
  }

  // `link` is absolute; make it relative so the spec stays on Playwright's baseURL.
  return new URL(pages[0].link).pathname;
}

/**
 * Append a query string to a URL from pageUrl().
 *
 * Its own helper because the separator changed with permalinks: specs used to
 * concatenate "&category=tables" onto "/?page_id=6", which silently becomes part
 * of the path once the URL is "/shop/".
 */
function withQuery(url, query) {
  return `${url}${url.includes('?') ? '&' : '?'}${query}`;
}

/** Item cards rendered by the inventory grid. */
function itemCards(page) {
  return page.locator('[id^="tg-item-"]');
}

/** Category links in the shop filter, excluding the "All Categories" reset. */
function categoryLinks(page) {
  return page.locator('a.category-link[data-category-id]:not([data-category-id=""])');
}

module.exports = { pageUrl, withQuery, itemCards, categoryLinks };
