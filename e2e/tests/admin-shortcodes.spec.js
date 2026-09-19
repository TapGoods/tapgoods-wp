// @ts-check
const { test, expect } = require('@playwright/test');

/**
 * Settings -> Shortcodes tab (WPB-167).
 *
 * The bug: each shortcode box was rendered as `<input disabled value="[tapgoods-x]">`
 * with no name/autocomplete, inside a bare `<form>`, on the same admin page as the
 * Connection tab's `<input type="password">` API key field (all tabs sit in the DOM
 * at once; Bootstrap JS only toggles which pane is visually shown). The reporter saw
 * the box flash "[tapgoods-inventory]" on load and then silently change to their
 * WordPress username, and the copy button then copied the username.
 *
 * Most likely explanation: a browser password manager / autofill extension treated
 * the page as a login form and filled the first text-like field with a saved
 * username. That is a hypothesis, not something reproduced here -- real
 * password-manager autofill cannot be triggered from headless Playwright. What was
 * ruled out directly: no plugin script writes to these elements (grepped
 * admin/js/*.js for the five element ids and for `.value =` / `.val()` assignments;
 * the only write is copyText() itself, which reads `.value`/`data-shortcode`, never
 * writes it).
 *
 * What this suite actually proves, without needing to reproduce the real cause:
 *   1. None of the five boxes is a form-associated element any more
 *      (HTMLInputElement / HTMLTextAreaElement / HTMLSelectElement, or present in a
 *      <form>'s `.elements`) -- scoped to these five specific boxes, not a general
 *      claim about the page as a whole.
 *   2. copyText() copies the exact shortcode for all five boxes.
 *   3. copyText() keeps copying the real shortcode even after something sets the
 *      *displayed* element's `value` property to something else. Setting `.value`
 *      simulates any writer that could mutate that property, not autofill
 *      specifically -- it does not prove autofill was the actual cause, only that
 *      whatever wrote it can no longer change what gets copied. This is the part
 *      that actually distinguishes before/after: it fails on unmodified master
 *      (a real `<input>`, so `.value` is exactly what copyText() read) and passes
 *      after the fix.
 *
 * A manual check with a real password manager, ideally close to how the reporter's
 * browser was configured, is still worth doing before this ships -- see the PR body.
 */

const SHORTCODES = [
  { title: 'Show Inventory', id: 'tapgoods-inventory-input', code: '[tapgoods-inventory]' },
  { title: 'Select Location', id: 'tapgoods-location-select-input', code: '[tapgoods-location-select]' },
  { title: 'Cart Button', id: 'tapgoods-cart-input', code: '[tapgoods-cart]' },
  { title: 'Sign In Link', id: 'tapgoods-sign-in-input', code: '[tapgoods-sign-in]' },
  { title: 'Sign Up Link', id: 'tapgoods-sign-up-input', code: '[tapgoods-sign-up]' },
];

test.describe('Admin Settings -> Shortcodes tab (WPB-167)', () => {
  // Auth comes from the "admin" project's storageState (see playwright.config.js
  // and admin.setup.js) -- one real wp-login.php submission per run, not one per
  // test. Logging in per test here used to be a full form submit for each of the
  // 17+ tests below, which was slow and, under --repeat-each, flaky (hit a 30s
  // test timeout mid-login on a loaded machine).
  test.beforeEach(async ({ page, context }) => {
    // Needed to read back what copyText() actually placed on the clipboard.
    await context.grantPermissions(['clipboard-read', 'clipboard-write']);

    await page.goto('/wp-admin/admin.php?page=tapgoods');
    // The Connection tab (with the password-type API key field that makes browsers
    // treat this page as a login form) is the default active tab; the Shortcodes
    // pane exists in the DOM either way, but switch to it so its controls are
    // visible/interactable like a real user would see them.
    await page.locator('#nav-shortcodes-tab').click();
    await expect(page.locator('#tapgrein-shortcodes')).toBeVisible();
  });

  for (const { title, id, code } of SHORTCODES) {
    test(`"${title}" box is a non-form display element holding "${code}"`, async ({ page }) => {
      const el = page.locator(`#${id}`);
      await expect(el).toBeVisible();
      await expect(el).toHaveText(code);

      const isAutofillableControl = await el.evaluate(
        (node) =>
          node instanceof HTMLInputElement ||
          node instanceof HTMLTextAreaElement ||
          node instanceof HTMLSelectElement
      );
      expect(isAutofillableControl).toBe(false);

      // Belt and suspenders: also confirm it's not sitting in the current form's
      // `.elements` collection, which is the set a password manager actually walks.
      const inFormElements = await el.evaluate((node) => {
        const form = node.closest('form');
        return !!form && Array.from(form.elements).includes(node);
      });
      expect(inFormElements).toBe(false);
    });
  }

  for (const { title, id, code } of SHORTCODES) {
    test(`copy button copies the exact "${title}" shortcode`, async ({ page }) => {
      await page.locator(`button[onclick*="${id}"]`).click();
      const clipboardText = await page.evaluate(() => navigator.clipboard.readText());
      expect(clipboardText).toBe(code);
    });
  }

  test('copy survives a simulated value overwrite (WPB-167)', async ({ page }) => {
    const { id, code } = SHORTCODES[0];

    // Setting `.value` simulates any writer that could mutate that property --
    // this is not a reproduction of a password manager specifically, since real
    // autofill cannot be driven from headless Playwright. What it does show: on
    // unmodified master #tapgoods-inventory-input was a real <input>, so this line
    // changes what the box holds and copyText() (which read `input.value`) copied
    // the overwritten value -- matching what the reporter described. After the fix
    // the element is a <span>; setting `.value` on it is a no-op with no bearing on
    // what is displayed or copied. Run this test against git-checked-out master's
    // two files to see it fail there.
    await page.evaluate((elId) => {
      const el = document.getElementById(elId);
      // @ts-ignore -- deliberately setting .value even if el is not an <input>
      el.value = 'attacker-username';
    }, id);

    await page.locator(`button[onclick*="${id}"]`).click();
    const clipboardText = await page.evaluate(() => navigator.clipboard.readText());
    expect(clipboardText).toBe(code);
  });

  test('copy survives a simulated overwrite of every shortcode box at once', async ({ page }) => {
    // Broader version of the test above: hit all five boxes with both a `.value`
    // overwrite (the property a form-control writer would set) and a
    // `.textContent` overwrite (a stricter check that copyText() reads from the
    // authoritative data-shortcode attribute, not from what is merely displayed).
    for (const { id } of SHORTCODES) {
      await page.evaluate((elId) => {
        const el = document.getElementById(elId);
        // @ts-ignore
        el.value = 'attacker-username';
        el.textContent = 'attacker-username';
      }, id);
    }

    for (const { id, code } of SHORTCODES) {
      await page.locator(`button[onclick*="${id}"]`).click();
      const clipboardText = await page.evaluate(() => navigator.clipboard.readText());
      expect(clipboardText).toBe(code);
    }
  });

  for (const { title, id, code } of SHORTCODES) {
    test(`"${title}" box is keyboard-focusable, selects its text on focus, and is exposed to assistive tech as read-only text`, async ({
      page,
    }) => {
      const el = page.locator(`#${id}`);

      await expect(el).toHaveAttribute('role', 'textbox');
      await expect(el).toHaveAttribute('aria-readonly', 'true');
      await expect(el).toHaveAttribute('tabindex', '0');

      // Real accessibility-tree check (not just raw attributes): the element must
      // actually resolve to an accessible "textbox" with a name, the way a screen
      // reader would see it.
      await expect(page.getByRole('textbox', { name: `${title} shortcode`, exact: true })).toHaveText(code);

      await el.focus();
      await expect(el).toBeFocused();

      // A <span> has no built-in "select my text when you tab to me" behaviour the
      // way a real <input> does, so a keyboard-only user tabbing here would land on
      // inert text with nothing to Ctrl/Cmd+C. admin/js/tapgoods-admin-complete.js
      // selects the element's contents on focus specifically so that still works.
      const selectedText = await page.evaluate(() => window.getSelection()?.toString() ?? '');
      expect(selectedText).toBe(code);

      const copyButton = page.locator(`button[onclick*="${id}"]`);
      await expect(copyButton).toHaveAttribute('aria-label', `Copy ${title} shortcode to clipboard`);
    });
  }
});

/**
 * WPB-167 follow-up: once the Shortcodes tab boxes stopped being real <input>s,
 * #tapgoods_api_key (Connection tab, type=password) became the only text-like input
 * left anywhere on this page -- every tab is present in the DOM at once (see the
 * doc comment above). A password manager that had been offering to fill the
 * shortcode box could now offer the admin's own WordPress login into the API key
 * field instead, and an accepted "save password" prompt would send those
 * credentials to TapGoods on the next real connect. Same reasoning for tg_ttl
 * (Advanced tab): it is a saved settings field, not a login field.
 */
test.describe('Connection tab hardening (WPB-167 follow-up)', () => {
  // See the comment on the describe block above: auth is the "admin" project's
  // storageState, not a per-test login.
  test.beforeEach(async ({ page }) => {
    await page.goto('/wp-admin/admin.php?page=tapgoods');
  });

  test('the API key field and its form opt out of autocomplete/autofill', async ({ page }) => {
    const form = page.locator('#tapgrein_connection_form');
    await expect(form).toHaveAttribute('autocomplete', 'off');

    const apiKeyField = page.locator('#tapgoods_api_key');
    // "new-password" and not "off": password managers are documented to ignore
    // autocomplete="off" on password-type fields specifically, but respect
    // "new-password" as a signal not to offer or save a value here.
    await expect(apiKeyField).toHaveAttribute('autocomplete', 'new-password');
    await expect(apiKeyField).toHaveAttribute('type', 'password');
  });

  test('the Advanced tab tg_ttl field opts out of autocomplete', async ({ page }) => {
    await page.locator('#nav-advanced-tab').click();
    await expect(page.locator('#tg_ttl')).toHaveAttribute('autocomplete', 'off');
  });
});
