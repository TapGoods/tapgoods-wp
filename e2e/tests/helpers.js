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

/**
 * Log into wp-admin as the wp-env default administrator (admin/password).
 *
 * Called once, by the "admin-setup" Playwright project (e2e/tests/admin.setup.js),
 * not per test -- see admin-shortcodes.spec.js for why. That still means it can run
 * on a machine under real load (several agents' wp-env instances competing for CPU
 * here), and one run of this did land with "password" typed into the *username*
 * field and the password field empty, which reads like the page reset between the
 * two fills rather than a slow selector. Each fill is verified to have actually
 * stuck (`toHaveValue`, which polls) before moving on, and the whole thing is
 * retried once on failure, rather than trusting two independent `.fill()` calls to
 * both land on a page that did not change out from under them.
 */
async function loginAsAdmin(page) {
  const { expect } = require('@playwright/test');

  const attempt = async () => {
    await page.goto('/wp-login.php', { waitUntil: 'domcontentloaded' });

    const username = page.locator('#user_login');
    const password = page.locator('#user_pass');

    await expect(username).toBeVisible();
    await username.fill('admin');
    await expect(username).toHaveValue('admin');

    await password.fill('password');
    await expect(password).toHaveValue('password');

    await page.locator('#wp-submit').click();
    await page.waitForURL('**/wp-admin/**', { timeout: 15000 });
  };

  try {
    await attempt();
  } catch (err) {
    await attempt();
  }
}

module.exports = { pageUrl, withQuery, itemCards, categoryLinks, loginAsAdmin };
