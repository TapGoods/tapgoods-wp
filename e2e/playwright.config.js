// @ts-check
const path = require('path');
const { defineConfig, devices } = require('@playwright/test');

const ADMIN_AUTH_FILE = path.join(__dirname, '.auth', 'admin.json');

/**
 * Browser suite for the parts of the pre-release QA checklist that only a browser
 * can answer: what a visitor actually sees and clicks.
 *
 * It runs against the local wp-env site, not a staging storefront. The plugin
 * there is pinned to the offline mock API (TG_MOCK in .wp-env.json), so the
 * catalog is the same fixture set the PHP suites use. A browser suite pointed at a
 * live site fails for reasons that have nothing to do with the change under test,
 * and then people stop believing it.
 *
 * Start the environment and seed it first:
 *
 *   npx wp-env start
 *   npm run e2e:seed
 *   npm run e2e
 */
module.exports = defineConfig({
  testDir: './tests',
  // A failing browser assertion is usually a real difference, not a flake. Retry
  // once in CI to absorb genuine startup races, and never locally, so a flake is
  // visible while it is being written.
  retries: process.env.CI ? 1 : 0,
  forbidOnly: !!process.env.CI,
  workers: process.env.CI ? 2 : undefined,
  reporter: process.env.CI ? [['list'], ['html', { open: 'never' }]] : [['list']],
  use: {
    baseURL: process.env.TG_E2E_BASE_URL || 'http://localhost:8888',
    trace: 'retain-on-failure',
    screenshot: 'only-on-failure',
  },
  projects: [
    {
      name: 'desktop',
      use: { ...devices['Desktop Chrome'] },
      // wp-admin has its own "admin" project below (storageState from a one-time
      // login, not the anonymous storefront context every other spec here wants).
      testIgnore: /admin-shortcodes\.spec\.js/,
    },
    {
      // The categories accordion is the reason this project exists: its whole
      // contract is that one cached HTML adapts to the viewport (WPB-179).
      //
      // A Chromium-based phone profile on purpose. The contract is about viewport
      // width, not about a rendering engine, and staying on Chromium keeps CI to a
      // single browser download instead of pulling WebKit for one breakpoint.
      name: 'mobile',
      use: { ...devices['Pixel 7'] },
      testIgnore: /admin-shortcodes\.spec\.js/,
    },
    {
      // Logs into wp-admin once and saves the session to ADMIN_AUTH_FILE (see
      // admin.setup.js). wp-admin is not part of the mobile-viewport contract
      // (that is what the "mobile" project above owns), so this runs once, not
      // once per project, and admin-shortcodes.spec.js does not need its own
      // per-test/per-project skip logic for that any more.
      name: 'admin-setup',
      testMatch: /admin\.setup\.js/,
    },
    {
      name: 'admin',
      use: { ...devices['Desktop Chrome'], storageState: ADMIN_AUTH_FILE },
      testMatch: /admin-shortcodes\.spec\.js/,
      dependencies: ['admin-setup'],
    },
  ],
});
