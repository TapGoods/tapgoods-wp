// @ts-check
const { test, expect } = require('@playwright/test');
const { pageUrl, withQuery } = require('./helpers');

/**
 * Quantity field and Add to Cart (WPB-168).
 *
 * Two bugs, one field:
 *
 * 1. The field started empty behind a "Qty" placeholder, so the obvious gesture --
 *    press Add -- was the one that failed.
 * 2. Pressing Add with nothing in it raised a native alert() the visitor could not
 *    get past. Six of them, in fact: #tg-shop, the row wrapper and every card all
 *    carry .tapgoods-inventory, initInventoryGrid() binds a handler per matching
 *    container, and it runs twice (module bootstrap plus the inline script
 *    Tapgoods_Enqueue adds). Three ancestors x two runs = six handlers on one
 *    button, so dismissing the dialog just brought up the next one.
 *
 * So the assertions here are: the field ships with 1 in it, no dialog ever opens,
 * and an invalid quantity produces one inline message.
 *
 * The message count does NOT measure how many handlers ran -- showing a message
 * clears the previous one first, so six handlers still leave one message. The
 * handler count is measured separately, in "the Add handler runs once per click",
 * by counting the writes each run makes to localStorage. That block ends with a
 * test that removes the bind-once guards from the served script and checks the
 * count goes up, so the measurement cannot quietly stop measuring.
 */

/** Every add-to-cart validation message currently on the page. */
function qtyErrors(scope) {
  return scope.locator('.tg-qty-error');
}

/** Fail loudly on any native dialog; the whole point is that none can open. */
function watchDialogs(page) {
  const dialogs = [];
  page.on('dialog', (dialog) => {
    dialogs.push(dialog.message());
    dialog.dismiss().catch(() => {});
  });
  return dialogs;
}

/**
 * Answer the storefront the Add button navigates to. The seed points it at
 * example.test, which does not resolve.
 */
async function stubStorefront(page) {
  await page.route('https://example.test/**', (route) =>
    route.fulfill({ status: 200, contentType: 'text/html', body: 'ok' })
  );
}

/**
 * Answer it with 204 instead, which leaves the browser on the current page, so
 * the card is still there to count and assert on after a successful add.
 */
async function stubStorefrontWithoutLeaving(page) {
  await page.route('https://example.test/**', (route) => route.fulfill({ status: 204 }));
}

/**
 * Start counting add-to-cart handler runs. Must be called before goto().
 *
 * Counted without touching production code: every run of either handler writes
 * the cart to localStorage, so wrapping setItem counts the runs. Nothing else on
 * a freshly loaded page writes "cartData" -- restoring the cart only reads it --
 * and the ten-second reset, which does write, is far outside these tests.
 */
async function countHandlerRuns(page) {
  await page.addInitScript(() => {
    window.__tgCartWrites = 0;
    const original = Storage.prototype.setItem;
    Storage.prototype.setItem = function (key) {
      if (key === 'cartData') {
        window.__tgCartWrites += 1;
      }
      return original.apply(this, arguments);
    };
  });
}

const resetHandlerRuns = (page) => page.evaluate(() => { window.__tgCartWrites = 0; });
const handlerRuns = (page) => page.evaluate(() => window.__tgCartWrites || 0);

test.describe('quantity defaults to 1', () => {
  // Runs on both projects on purpose. The default is server-rendered, so it must
  // be the same markup on a phone and on a desktop: these pages are cached per URL
  // with no device variance, and a per-device default would be served to whoever
  // did not warm the cache.
  test('on the shop grid', async ({ page, request }) => {
    await page.goto(await pageUrl(request, 'shop'));

    const inputs = page.locator('.qty-input');
    await expect(inputs.first()).toHaveValue('1');

    const values = await inputs.evaluateAll((els) => els.map((el) => el.value));
    expect(values.every((v) => v === '1')).toBe(true);
  });

  test('on the single product page', async ({ page }) => {
    await page.goto('/products/6ft-round-table/');

    await expect(page.locator('.qty-input')).toHaveValue('1');
  });
});

test.describe('add to cart', () => {
  test.beforeEach(({}, testInfo) => {
    test.skip(testInfo.project.name !== 'desktop', 'Not viewport-dependent.');
  });

  test('the default quantity adds one of the item', async ({ page, request }) => {
    const dialogs = watchDialogs(page);
    await stubStorefront(page);
    await page.goto(await pageUrl(request, 'shop'));

    // No typing: exactly what a visitor who trusts the pre-filled field does.
    await page.locator('.add-cart').first().click();

    await expect(page).toHaveURL(/example\.test\/\d+\/addToCart.*quantity=1/);
    expect(dialogs).toEqual([]);
  });

  test('the same on the single product page', async ({ page }) => {
    const dialogs = watchDialogs(page);
    await stubStorefront(page);
    await page.goto('/products/6ft-round-table/');

    await page.locator('.add-cart').click();

    await expect(page).toHaveURL(/example\.test\/\d+\/addToCart.*quantity=1/);
    expect(dialogs).toEqual([]);
  });

  test('the field returns to the default when the button resets', async ({ page, request }) => {
    // After ten seconds the card forgets the add: green button back to "Add", and
    // the quantity back to 1 rather than to an empty field, which is what put the
    // visitor back in front of the original bug.
    //
    // The add navigates to the storefront. Answer it with 204 rather than
    // aborting: a browser stays put on a 204, so the card is still there to watch,
    // whereas an aborted top-level navigation replaces the document with an error
    // page and there is nothing left to assert on.
    test.slow();
    await page.route('https://example.test/**', (route) => route.fulfill({ status: 204 }));
    await page.goto(await pageUrl(request, 'shop'));

    const qty = page.locator('.qty-input').first();
    const button = page.locator('.add-cart').first();

    await qty.fill('4');
    await button.click();

    await expect(button).toHaveText('Added');
    await expect(button).toHaveText('Add', { timeout: 15000 });
    await expect(qty).toHaveValue('1');
  });

  test('a quantity already in the cart still wins over the default', async ({ page, request }) => {
    // The default must not overwrite what the visitor already has. Cart state is
    // per-visitor localStorage, applied client side over the cached markup.
    const shop = await pageUrl(request, 'shop');
    await page.goto(shop);

    const itemId = await page.locator('.add-cart').first().getAttribute('data-item-id');
    const locationId = await page
      .locator('[id^="tg-item-"][data-location-id]')
      .first()
      .getAttribute('data-location-id');

    await page.evaluate(
      ([loc, item]) => {
        localStorage.setItem('cartData', JSON.stringify({ [loc]: { [item]: 7 } }));
        localStorage.setItem('cart', '1');
        document.cookie = `tg_user_location=${loc}; path=/`;
      },
      [locationId, itemId]
    );

    await page.goto(shop);

    await expect(page.locator(`#qty-${itemId}`)).toHaveValue('7');
  });
});

test.describe('an invalid quantity is reported inline, once', () => {
  test.beforeEach(({}, testInfo) => {
    test.skip(testInfo.project.name !== 'desktop', 'Not viewport-dependent.');
  });

  // "999999999999999999999" is the one that is not merely silly: without a
  // ceiling it parses to a finite number whose String() is "1e+21", and that is
  // what would have gone out as &quantity=.
  for (const value of ['', '0', '-1', 'abc', '1.5', '10000', '999999999999999999999']) {
    test(`quantity "${value}" on the shop grid`, async ({ page, request }) => {
      const dialogs = watchDialogs(page);
      await stubStorefront(page);
      const shop = await pageUrl(request, 'shop');
      await page.goto(shop);

      await page.locator('.qty-input').first().fill(value);
      await page.locator('.add-cart').first().click();

      await expect(qtyErrors(page)).toHaveCount(1);
      expect(dialogs).toEqual([]);
      // Nothing was added and the visitor is still on the shop.
      await expect(page).toHaveURL(new RegExp(`${shop}$`));
    });
  }

  test('on the single product page', async ({ page }) => {
    const dialogs = watchDialogs(page);
    await stubStorefront(page);
    await page.goto('/products/6ft-round-table/');

    await page.locator('.qty-input').fill('0');
    await page.locator('.add-cart').click();

    await expect(qtyErrors(page)).toHaveCount(1);
    expect(dialogs).toEqual([]);
  });

  test('after an AJAX search re-renders the grid', async ({ page, request }) => {
    const dialogs = watchDialogs(page);
    await page.goto(await pageUrl(request, 'shop'));

    await page.locator('#tg-search').fill('Folding');
    await expect(page.locator('[id^="tg-item-"]')).toHaveCount(1);

    await page.locator('.qty-input').first().fill('0');
    await page.locator('.add-cart').first().click();

    await expect(qtyErrors(page)).toHaveCount(1);
    expect(dialogs).toEqual([]);
  });

  test('after three consecutive AJAX refreshes', async ({ page, request }) => {
    // Re-running the setup after every refresh is what used to add a handler each
    // time. One message after three refreshes means binding is idempotent.
    const dialogs = watchDialogs(page);
    await page.goto(await pageUrl(request, 'shop'));

    for (const term of ['Folding', 'Round', 'Folding']) {
      await page.locator('#tg-search').fill(term);
      await expect(page.locator('[id^="tg-item-"]')).toHaveCount(1);
    }

    await page.locator('.qty-input').first().fill('0');
    await page.locator('.add-cart').first().click();

    await expect(qtyErrors(page)).toHaveCount(1);
    expect(dialogs).toEqual([]);
  });

  test('after clicking a category', async ({ page, request }) => {
    const dialogs = watchDialogs(page);
    await page.goto(await pageUrl(request, 'shop'));

    await page.locator('a.category-link[data-category-id="tables"]').click();
    await expect(page).toHaveURL(/category=tables/);

    await page.locator('.qty-input').first().fill('0');
    await page.locator('.add-cart').first().click();

    await expect(qtyErrors(page)).toHaveCount(1);
    expect(dialogs).toEqual([]);
  });

  test('on a tag-filtered grid', async ({ page, request }) => {
    const dialogs = watchDialogs(page);
    await page.goto(withQuery(await pageUrl(request, 'shop'), 'tags=tag-round-tables'));

    await page.locator('.qty-input').first().fill('0');
    await page.locator('.add-cart').first().click();

    await expect(qtyErrors(page)).toHaveCount(1);
    expect(dialogs).toEqual([]);
  });

  test('in the second of two grids, and only there', async ({ page, request }) => {
    const dialogs = watchDialogs(page);
    await page.goto(await pageUrl(request, 'shop-multi'));

    const second = page.locator('#tg-shop').nth(1);
    await second.locator('.qty-input').fill('0');
    await second.locator('.add-cart').click();

    await expect(qtyErrors(second)).toHaveCount(1);
    await expect(qtyErrors(page.locator('#tg-shop').nth(0))).toHaveCount(0);
    expect(dialogs).toEqual([]);
  });
});

test.describe('the inline message can be got rid of', () => {
  test.beforeEach(({}, testInfo) => {
    test.skip(testInfo.project.name !== 'desktop', 'Not viewport-dependent.');
  });

  test('the dismiss control closes it and returns focus to the field', async ({ page, request }) => {
    await page.goto(await pageUrl(request, 'shop'));

    const qty = page.locator('.qty-input').first();
    await qty.fill('0');
    await page.locator('.add-cart').first().click();

    const message = qtyErrors(page).first();
    await expect(message).toBeVisible();
    // Announced, and tied to the field it is about.
    await expect(message).toHaveAttribute('role', 'alert');
    await expect(qty).toHaveAttribute('aria-invalid', 'true');
    await expect(qty).toHaveAttribute('aria-describedby', await message.getAttribute('id') || '');

    await message.locator('.tg-qty-error-dismiss').click();

    await expect(qtyErrors(page)).toHaveCount(0);
    await expect(qty).toBeFocused();
    await expect(qty).not.toHaveAttribute('aria-invalid', 'true');
  });

  test('typing a usable quantity clears it', async ({ page, request }) => {
    await page.goto(await pageUrl(request, 'shop'));

    const qty = page.locator('.qty-input').first();
    await qty.fill('0');
    await page.locator('.add-cart').first().click();
    await expect(qtyErrors(page)).toHaveCount(1);

    await qty.fill('3');

    await expect(qtyErrors(page)).toHaveCount(0);
  });

  test('pressing Add again does not stack a second message', async ({ page, request }) => {
    await page.goto(await pageUrl(request, 'shop'));

    await page.locator('.qty-input').first().fill('0');
    await page.locator('.add-cart').first().click();
    await page.locator('.add-cart').first().click();
    await page.locator('.add-cart').first().click();

    await expect(qtyErrors(page)).toHaveCount(1);
  });

  test('the same item in two grids keeps its own message', async ({ page, request }) => {
    // The shop-multi fixture cannot catch this: its two grids hold different
    // items, so no id is repeated. Here both grids hold both items, "qty-11001"
    // exists twice, and a message found by getElementById answered with whichever
    // grid came first -- dismissing the second grid's message removed the first
    // grid's and left its own on screen.
    await page.goto(await pageUrl(request, 'shop-duplicate'));

    const first = page.locator('#tg-shop').nth(0);
    const second = page.locator('#tg-shop').nth(1);

    await first.locator('.qty-input').first().fill('0');
    await first.locator('.add-cart').first().click();
    await second.locator('.qty-input').first().fill('0');
    await second.locator('.add-cart').first().click();

    await expect(qtyErrors(first)).toHaveCount(1);
    await expect(qtyErrors(second)).toHaveCount(1);
    // Each field points at the message in its own card, not at the other grid's.
    const secondQty = second.locator('.qty-input').first();
    const secondMessageId = await qtyErrors(second).first().getAttribute('id');
    expect(await secondQty.getAttribute('aria-describedby')).toBe(secondMessageId);
    expect(await first.locator('.qty-input').first().getAttribute('aria-describedby'))
      .not.toBe(secondMessageId);

    await qtyErrors(second).first().locator('.tg-qty-error-dismiss').click();

    await expect(qtyErrors(second)).toHaveCount(0);
    await expect(qtyErrors(first)).toHaveCount(1);
  });
});

test.describe('the Add handler runs once per click', () => {
  // This is the block that actually measures the WPB-168 root cause. An invalid
  // quantity cannot measure it -- one message is shown however many handlers ran --
  // so these use a VALID quantity and count the writes each run makes.
  //
  // The quantity is always typed in rather than left at the default, so that these
  // measure the binding and nothing else: on a build where the field still starts
  // empty they must report the handler count, not fall to zero because the click
  // was rejected before it wrote anything.
  test.beforeEach(({}, testInfo) => {
    test.skip(testInfo.project.name !== 'desktop', 'Not viewport-dependent.');
  });

  test('on the shop grid', async ({ page, request }) => {
    await countHandlerRuns(page);
    await stubStorefrontWithoutLeaving(page);
    await page.goto(await pageUrl(request, 'shop'));

    await page.locator('.qty-input').first().fill('2');
    await resetHandlerRuns(page);
    await page.locator('.add-cart').first().click();

    expect(await handlerRuns(page)).toBe(1);
  });

  // The two refresh cases below stay at one even with the guards removed, because
  // the refresh path was also changed to bind the new grid once instead of twice.
  // They are here to hold that, not to demonstrate the guard; the guard is what
  // the last test in this block measures.
  test('after an AJAX search re-renders the grid', async ({ page, request }) => {
    await countHandlerRuns(page);
    await stubStorefrontWithoutLeaving(page);
    await page.goto(await pageUrl(request, 'shop'));

    for (const term of ['Folding', 'Round', 'Folding']) {
      await page.locator('#tg-search').fill(term);
      await expect(page.locator('[id^="tg-item-"]')).toHaveCount(1);
    }

    await page.locator('.qty-input').first().fill('2');
    await resetHandlerRuns(page);
    await page.locator('.add-cart').first().click();

    expect(await handlerRuns(page)).toBe(1);
  });

  test('after AJAX pagination', async ({ page, request }) => {
    // One item per page, so the two-item fixture catalogue really paginates.
    await countHandlerRuns(page);
    await stubStorefrontWithoutLeaving(page);
    await page.goto(await pageUrl(request, 'shop-paged'));

    // The AJAX pagination only appears once a search request has answered, so
    // type and clear to get the default page through the same path a visitor
    // would, then step to page 2.
    await page.locator('#tg-search').fill('a');
    await page.locator('#tg-search').fill('');
    const nextPage = page.locator('#tg-inventory-pagination .page-link[data-page="2"]').first();
    await expect(nextPage).toBeVisible();
    await nextPage.click();
    await expect(page.locator('.item-name')).toHaveText([/Folding Chair/]);

    await page.locator('.qty-input').first().fill('2');
    await resetHandlerRuns(page);
    await page.locator('.add-cart').first().click();

    expect(await handlerRuns(page)).toBe(1);
  });

  test('in each of two grids on one page', async ({ page, request }) => {
    await countHandlerRuns(page);
    await stubStorefrontWithoutLeaving(page);
    await page.goto(await pageUrl(request, 'shop-multi'));

    await page.locator('#tg-shop').nth(0).locator('.qty-input').fill('2');
    await resetHandlerRuns(page);
    await page.locator('#tg-shop').nth(0).locator('.add-cart').click();
    expect(await handlerRuns(page)).toBe(1);

    await page.locator('#tg-shop').nth(1).locator('.qty-input').fill('2');
    await resetHandlerRuns(page);
    await page.locator('#tg-shop').nth(1).locator('.add-cart').click();
    expect(await handlerRuns(page)).toBe(1);
  });

  test('on the single product page', async ({ page }) => {
    // initProductSingle() also runs twice, from the module and from the inline
    // script, so this button used to carry two handlers.
    await countHandlerRuns(page);
    await stubStorefrontWithoutLeaving(page);
    await page.goto('/products/6ft-round-table/');

    await page.locator('.qty-input').fill('2');
    await resetHandlerRuns(page);
    await page.locator('.add-cart').click();

    expect(await handlerRuns(page)).toBe(1);
  });

  test('and the count notices when the bind-once guards are removed', async ({ page, request }) => {
    // Without this, the whole block above could be measuring nothing. Serve the
    // module with the two guards stripped -- the shape of the bug as it shipped --
    // and the same click must count more than one run. It counted six on a shop
    // page: #tg-shop, the row wrapper and the card all match .tapgoods-inventory,
    // times the two calls to initInventoryGrid().
    await page.route('**/tapgoods-public-complete.js*', async (route) => {
      const response = await route.fetch();
      const original = await response.text();
      const stripped = original
        .replace('if (button.dataset.tgCartBound) return;', '')
        .replace('if (addButton.dataset.tgCartBound) return;', '');

      if (stripped === original) {
        throw new Error(
          'Neither bind-once guard was found in tapgoods-public-complete.js. If they were ' +
          'renamed, update this test: until then the counts above prove nothing.'
        );
      }

      await route.fulfill({ status: 200, contentType: 'application/javascript', body: stripped });
    });

    await countHandlerRuns(page);
    await stubStorefrontWithoutLeaving(page);
    await page.goto(await pageUrl(request, 'shop'));

    await page.locator('.qty-input').first().fill('2');
    await resetHandlerRuns(page);
    await page.locator('.add-cart').first().click();

    expect(await handlerRuns(page)).toBeGreaterThan(1);
  });
});

test.describe('a quantity already in the cart survives a grid refresh', () => {
  test.beforeEach(({}, testInfo) => {
    test.skip(testInfo.project.name !== 'desktop', 'Not viewport-dependent.');
  });

  test('after an AJAX search', async ({ page, request }) => {
    // The refresh used to re-bind the buttons without restoring the cart, which
    // was harmless while the field came back blank. With a confident default of
    // 1, a visitor who has 5 in the cart is shown 1 next to an enabled "Add".
    const shop = await pageUrl(request, 'shop');
    await page.goto(shop);

    const itemId = await page
      .locator('[id^="tg-item-"]:has-text("6ft Round Table") .add-cart')
      .first()
      .getAttribute('data-item-id');
    const locationId = await page
      .locator('[id^="tg-item-"][data-location-id]')
      .first()
      .getAttribute('data-location-id');

    await page.evaluate(
      ([loc, item]) => {
        localStorage.setItem('cartData', JSON.stringify({ [loc]: { [item]: 5 } }));
        localStorage.setItem('cart', '1');
        document.cookie = `tg_user_location=${loc}; path=/`;
      },
      [locationId, itemId]
    );
    await page.goto(shop);

    await expect(page.locator(`#qty-${itemId}`)).toHaveValue('5');

    await page.locator('#tg-search').fill('Round');
    await expect(page.locator('[id^="tg-item-"]')).toHaveCount(1);

    await expect(page.locator(`#qty-${itemId}`)).toHaveValue('5');
    await expect(page.locator(`.add-cart[data-item-id="${itemId}"]`)).toHaveText('Added');
  });
});
