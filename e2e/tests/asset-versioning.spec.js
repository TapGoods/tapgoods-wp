// @ts-check
const { test, expect } = require('@playwright/test');
const { pageUrl } = require('./helpers');

/**
 * WPB-182: plugin JS/CSS must be versioned by file mtime, not by the
 * TAPGOODSWP_VERSION constant.
 *
 * Every plugin release keeps the same asset URL (the release workflow bumps
 * the plugin header `Version:` and readme `Stable tag`, never the
 * TAPGOODSWP_VERSION constant those URLs used as `?ver=`), so a browser, CDN
 * or page cache on a customer host kept serving the pre-upgrade file forever.
 * tapgrein_asset_version() (includes/tapgoods-asset-functions.php) fixes
 * that by versioning each asset with its own filemtime() instead.
 *
 * This suite only asserts the browser-visible contract: the ?ver= on the two
 * assets three pending bug-fix PRs (#25/#26/#27) touch is a plain integer
 * (an mtime), not the literal "0.1.2" constant or one of its old ad-hoc
 * suffixes ("-search-fix", "0.1.124-tag-fix", "0.1.124-tag-inline",
 * "0.1.124-tag-direct") -- and that each asset is loaded exactly once, since
 * both handles used to be registered from more than one code path (see
 * CLAUDE.md's "three overlapping enqueue paths" and the tag-page-specific
 * fourth path in tapgoods-core-functions.php / tg-tag-results.php).
 */

const OLD_VERSION_PATTERN = /^0\.1\.(2|124)(-|$)/;

/** Every `?ver=` value the page's HTML source carries for a given asset filename. */
function assetVersions(html, filename) {
  const escaped = filename.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
  const pattern = new RegExp(`${escaped}\\?ver=([^"'&]+)`, 'g');
  const versions = [];
  let match;
  while ((match = pattern.exec(html)) !== null) {
    versions.push(match[1]);
  }
  return versions;
}

function assertSingleNumericVersion(html, filename) {
  const versions = assetVersions(html, filename);

  expect(versions, `${filename} should be loaded exactly once`).toHaveLength(1);
  expect(versions[0]).toMatch(/^\d+$/);
  expect(versions[0]).not.toMatch(OLD_VERSION_PATTERN);
}

test.describe('asset cache-busting (WPB-182)', () => {
  test('shop page versions the public script and complete styles by mtime, once each', async ({ page, request }) => {
    await page.goto(await pageUrl(request, 'shop'));
    const html = await page.content();

    assertSingleNumericVersion(html, 'tapgoods-public-complete.js');
    assertSingleNumericVersion(html, 'tapgoods-complete-styles.css');
  });

  test('the tag archive fallback template versions the same assets consistently', async ({ page }) => {
    // tg-tag-results.php (public/partials/tg-tag-results.php) and
    // tapgrein_enqueue_tag_page_scripts() (includes/tapgoods-core-functions.php)
    // are two more code paths that register 'tapgoods-public-complete'; before
    // this fix each one hardcoded a different literal ("0.1.124-tag-fix" /
    // "0.1.124-tag-inline"). "round-tables" is a real tg_tags term slug in the
    // mock fixture catalog (tests/fixtures/get-storefront-categories.json).
    await page.goto('/?tg_tags=round-tables');
    const html = await page.content();

    assertSingleNumericVersion(html, 'tapgoods-public-complete.js');
    assertSingleNumericVersion(html, 'tapgoods-complete-styles.css');
  });
});
