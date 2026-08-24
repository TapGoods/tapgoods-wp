# Pre-release QA checklist

The team's manual checklist for promoting the plugin to production, kept here so
it is versioned next to the code and so each line says who checks it: a test, a
browser run, or a person.

Status key:

| | Meaning |
|---|---|
| **auto** | Covered by a test in this repo. The test file is named; if it breaks, CI fails. |
| **browser** | Needs a real browser. The Playwright suite lives in `e2e/` and runs in CI; lines still marked browser without a named spec are candidates, not coverage. |
| **manual** | A person has to do it. Kept short on purpose. |

When you automate a line, change its status here in the same commit. A checklist
that quietly disagrees with the tests is worse than no checklist.

---

## WordPress Admin

### TapGoods page

| Item | Status | Where |
|---|---|---|
| Connection tab: email link | manual | mailto, nothing to assert |
| Connection tab: Reset to Default, TapGoods link opens in new tab | browser | |
| Connection tab: Reset to Default, does it reconnect | **manual, destructive** | See the warning below |
| Shortcodes tab: copy to clipboard | browser | Clipboard permission needed |
| Shortcodes tab: click menu link | browser | |
| Multilocation: change default location updates the front end | **auto** | `LocationAndCartUrlTest` (precedence); browser for the click-through |
| Multilocation: change default location updates the cart link | **auto** | `LocationAndCartUrlTest` |
| Multilocation: click menu link | browser | |
| Status tab | auto | `SyncStateActivityLabelTest` (phase copy), `ConnectionSyncLogTest` (log) |

> **Reset to Default is destructive.** It deletes `tg_key`, `tg_api_connected`,
> `tg_locationIds` and `tg_businessId`. The API key is only shown once when it is
> generated, so anyone who runs this test needs a fresh key from support. Only run
> it on a throwaway site.

### Categories page

| Item | Status | Where |
|---|---|---|
| Can't edit (only delete) | browser | Capability/UI, worth a spec |
| View | browser | |
| Subcategories should not show up under categories | **auto** | `CategoryTaxonomyContractTest::test_a_sub_category_never_becomes_a_category` |
| Only categories that have items sync over, no empty categories | **auto** | `CategoryTaxonomyContractTest::test_no_category_is_left_without_items` |

Those last two were failing on two live sites at once when this file was written.
See `Tapgoods_Connection::filter_storefront_roots()` for why.

### Tags page

| Item | Status | Where |
|---|---|---|
| Can't edit (only delete) | browser | |
| View takes you to the filtered inventory grid | auto | `ParseRequestRoutingTest` for the routing; browser for the click |

## Front end

### Shop page with inventory grid

| Item | Status | Where |
|---|---|---|
| Mobile-responsive | **browser, auto** | `e2e/tests/category-accordion.spec.js` covers the one responsive contract we have; wider visual checks are still by eye |
| Searching | **browser, auto** | `e2e/tests/shop-grid.spec.js`. "No price when searching" still by eye: it renders through the AJAX path |
| Pagination | browser | Needs a fixture catalog bigger than one page |
| Category filter keeps you on the inventory grid | **auto** | `ParseRequestRoutingTest`, and clicked for real in `e2e/tests/shop-grid.spec.js` |
| Only categories are shown, not subcategories | **auto** | `CategoryTaxonomyContractTest` |
| Only categories in the selected location show | **auto** | `ShopCategoryMenuTest` |
| Categories accordion starts closed on mobile, open on desktop | **auto** | `e2e/tests/category-accordion.spec.js` for what the visitor gets, `CategoryFilterMarkupTest` for the markup being identical for both so a page cache cannot serve the wrong one |

### Multilocation

| Item | Status | Where |
|---|---|---|
| Changing the location in wp-admin must NOT change the front-end selection | **auto** | `LocationAndCartUrlTest::test_a_visitors_choice_wins_over_the_admin_default` |
| Changing the front-end location updates the cart link | **auto** | `LocationAndCartUrlTest::test_the_cart_link_follows_the_selected_location` |
| Add to cart from a non-default location goes to the right cart | browser + manual | Ends in TapGoods |

### Shortcodes

| Item | Status | Where |
|---|---|---|
| Hide item pricing: no price in the grid, and the item link inherits it | **auto** | `ShortcodeContractsTest` (including the curly quotes a page builder inserts) and `e2e/tests/shop-grid.spec.js` |
| Category modifier | **auto** | `ShortcodeContractsTest::test_the_category_modifier_filters_the_grid` |
| Tag modifier | **auto** | `ShortcodeContractsTest::test_the_tag_modifier_filters_the_grid` |

### Item page

| Item | Status | Where |
|---|---|---|
| Mobile-responsive | browser | |
| Tags return you to the inventory grid | auto | `ParseRequestRoutingTest` |
| Multiple images: clickable thumbnails, arrows work | browser | |
| Accordion shortcode renders | browser | Third-party plugin |

### Yoast SEO

| Item | Status | Where |
|---|---|---|
| Deactivated: item with description uses the item's description | **gap** | Integration-testable |
| Deactivated: item without description says "Rent ___ item today" | **gap** | Integration-testable |
| Activated: edit default meta description and title tag | browser | Yoast's own UI |
| Activated: editing one item's SEO only affects that item | browser | |
| Resync keeps the custom SEO | **auto** | `ResyncInvariantsTest::test_per_item_yoast_seo_survives` |

### Custom description

| Item | Status | Where |
|---|---|---|
| Works with Yoast deactivated | **gap** | |
| Editing experience is not weird | browser | Judgement call, keep it human |
| Resync keeps the custom description | **auto** | `ResyncInvariantsTest::test_the_editors_custom_description_survives` |

### Edit in TapGoods and resync

| Item | Status | Where |
|---|---|---|
| Add price, update price, remove price | **auto** | `ResyncInvariantsTest` |
| Edit name | **auto** | `ResyncInvariantsTest` |
| Add image | **auto** | `ResyncInvariantsTest` |
| Add/edit dimensions | **auto** | `ResyncInvariantsTest` |
| Edit description | **auto** | `ResyncInvariantsTest` |
| Nothing else on the post is disturbed | **auto** | `ResyncInvariantsTest::test_meta_belonging_to_the_site_or_other_plugins_survives` |
| Wait 24h: does it sync without a manual sync | **auto** | `SyncSchedulerTest` + `SyncFlowTest` drive the unattended path; no need to wait |

## Cart

| Item | Status | Where |
|---|---|---|
| Sign in / sign up / cart links redirect to the right location | **auto** | `LocationAndCartUrlTest` |
| Empty cart redirect and "Add More Items to Order" | browser | |
| Add to cart UI: quantity and green button clear after 10s | browser | Timing, browser only |
| Returns you to the same page | browser | |
| Cart icon turns to a plus and stays | browser | |
| Place an order, shows in TapGoods | **manual** | Real order in a real account |

---

## Sync engine checks not on the original list

Worth keeping in the same place, since they are what actually broke in production.

| Item | Status | Where |
|---|---|---|
| A large catalog completes instead of stalling | auto | `SyncFlowTest`, `SyncResumeTest` |
| No query grows with the catalog | auto | `ShopCategoryMenuTest`, `ConnectionSyncSliceTest` |
| A slice respects its time budget on a slow host | auto | `ConnectionSyncCategoriesTest` |
| A partial run cannot delete the catalog | auto | `ConnectionCleanupGuardTest` |
| A recovered error does not make every later slice log an error | auto | `RunEndStatusTest` |
| Hidden storefront categories are never imported | auto | `ConnectionCategoryVisibilityTest`, `SyncFlowTest` |

**Before the first sync on a site that has been failing for a while, back up the
database.** The first completed run deletes every item TapGoods no longer has, and
that is permanent. On one customer site it was 8,414 posts.
