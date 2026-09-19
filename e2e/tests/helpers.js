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

module.exports = { pageUrl, itemCards, categoryLinks, loginAsAdmin };
