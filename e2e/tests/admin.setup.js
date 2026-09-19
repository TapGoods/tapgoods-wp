// @ts-check
const path = require('path');
const { test: setup } = require('@playwright/test');
const { loginAsAdmin } = require('./helpers');

/**
 * Playwright auth setup project (see playwright.config.js: the "admin" project
 * depends on this and reuses the saved storageState).
 *
 * admin-shortcodes.spec.js used to call loginAsAdmin() (a full wp-login.php form
 * submit) in every test's beforeEach -- 17+ logins per run. That is slow enough on
 * its own, and under --repeat-each it compounded into real flakiness (a run hit a
 * 30s test timeout mid-login). WordPress' login form is exactly the kind of thing
 * that does not need re-proving 17 times in one run: log in once here, save the
 * resulting cookies, and every admin test starts already authenticated.
 */
setup('authenticate as the wp-env default administrator', async ({ page }) => {
  await loginAsAdmin(page);
  await page.context().storageState({ path: path.join(__dirname, '..', '.auth', 'admin.json') });
});
