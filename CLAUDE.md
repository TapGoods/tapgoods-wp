# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## What this is

A WordPress plugin ("TapGoods Rental Inventory") that integrates a WordPress site with the TapGoods rental platform. It syncs inventory from the TapGoods API into WordPress and renders a storefront (shop grid, filters, cart, product pages) via shortcodes.

## Repo layout: two nested levels

- **Repo root** (`/`) is a local dev harness: `docker-compose.yml`, build scripts (`package.json`), and the `releases/` output.
- **`tapgoods-wp/`** is the actual plugin (the shippable WordPress plugin directory). Almost all real code lives here. When editing plugin code, you are in `tapgoods-wp/`.

## Common commands

Build (compile SCSS to CSS; run before packaging):
```bash
npm run build          # clean + build-sass
npm run build-sass     # sass tapgoods-wp/assets/scss/custom.scss -> tapgoods-wp/assets/css/custom.css
npm run zip-plugin     # zip plugin into releases/tapgoods-wp.zip
```

Lint (PHP CodeSniffer with WordPress Coding Standards, config in `tapgoods-wp/phpcs.xml`):
```bash
cd tapgoods-wp && composer install     # one-time: installs phpcs + wpcs
cd tapgoods-wp && vendor/bin/phpcs      # lint
cd tapgoods-wp && vendor/bin/phpcbf     # auto-fix
```
VS Code is configured to run phpcs inside the `wordpress` Docker container (see `.vscode/settings.json`). Indentation is **tabs**, not spaces.

Local WordPress environment (docker-compose):
```bash
docker compose up                                   # WP at https://wordpress.local (nginx + mkcert), phpMyAdmin at :8181
docker compose exec wordpress /bin/bash             # shell into WP container
docker compose run --rm wpcli [command]             # WP-CLI, e.g. `wpcli plugin activate tapgoods-wp`
```
See `readme.md` for first-time setup (mkcert certs, `/etc/hosts`, `cp example.env localdev.env`). The plugin dir is bind-mounted into the container, so edits are live.

Tests (PHPUnit, isolated unit tests, no WordPress bootstrap; see "Testing & verification" below):
```bash
cd tapgoods-wp && composer install     # installs phpunit + brain/monkey + mockery
cd tapgoods-wp && composer test        # run the whole unit suite
cd tapgoods-wp && vendor/bin/phpunit --filter test_encrypt_decrypt_roundtrip   # single test
```

## Releases are automated. Do not hand-bump versions

`.github/workflows/package-plugin.yml` runs on every push to `master`: it auto-increments the patch version in `tapgoods-wp/tapgoods.php` (plugin header `Version:`) and `tapgoods-wp/readme.txt` (`Stable tag`), commits "Bump version to X", then builds and commits the zip to `releases/`. Don't manually edit those version fields or the releases folder. Note the `TAPGOODSWP_VERSION` PHP constant (used for asset cache-busting) is separate and is not what CI bumps.

**Pre-master test builds.** `.github/workflows/beta-zip.yml` ("Beta plugin zip") builds an installable zip from any branch so a change can be tested on a real WordPress site before it reaches `master`. It runs on `workflow_dispatch` (optional `ref` input; publishes a GitHub **pre-release** with a shareable link) and on every `pull_request` (artifact only). It gates on `composer test` + `composer compat`, the same checks `package-plugin.yml` runs. It deliberately does **not** commit anything: the build version `<base>+<branch-slug>.<short-sha>` is stamped into the working copy only (`Version:` header, `readme.txt` `Stable tag`, and `TAPGOODSWP_VERSION` so testers don't get stale cached assets). Never commit a suffixed version: `package-plugin.yml` parses the header with a `[\d.]+` regex and would mangle it. Two traps worth knowing if you edit this workflow: `zip -r` *updates* an existing archive rather than replacing it, so the tracked `releases/tapgoods-wp.zip` must be removed before packaging or stale files leak into the build; and piping `unzip -l` into `grep -q` under `set -o pipefail` fails on a valid zip (grep exits early, unzip takes SIGPIPE, pipeline returns 141).

The plugin header carries `Update URI: false`, which stops WordPress from asking wordpress.org about the `tapgoods-wp` slug. Without it a same-named .org plugin could be offered to customer sites as an "update" and overwrite this one. The plugin has no update mechanism of its own (no `Update URI` server, no `site_transient_update_plugins` filter, no update-checker library), so customers only get new versions by installing a zip by hand. Remove that header only if the plugin is genuinely published to wordpress.org.

## Architecture

**Bootstrap.** `tapgoods-wp/tapgoods.php` defines constants (`TAPGOODS_PLUGIN_PATH`, `TAPGOODS_UPLOADS`, etc.), registers activation/deactivation hooks, and on `plugins_loaded` calls `Tapgoods::get_instance()->init()`.

**Orchestrator + hook loader.** `includes/class-tapgoods-wp.php` (`Tapgoods`, singleton) is the center. Its constructor `require_once`s every dependency (there is no autoloader; new class files must be added to the `$includes` array in `load_dependencies()`), then registers hooks through `Tapgoods_Loader` (`includes/class-tapgoods-loader.php`). The loader is a deferred registry: `add_action`/`add_filter` just collect hooks into arrays, and `run()` (called from `init()`) registers them all with WordPress at once. Admin hooks, public hooks, and general hooks (cron) are grouped in `define_admin_hooks()`, `define_public_hooks()`, `define_general_hooks()`.

**API / sync layer.**
- `Tapgoods_Connection` (`includes/class-tapgoods-connection.php`, singleton) is the sync controller: validates the connection, runs batched inventory sync, syncs location settings, and writes results into WP posts/meta.
- `Tapgoods_API_Client` builds and sends **GraphQL** queries to `https://openapi.{env}/v1/external/graphql` (env from `TG_ENV` constant or `tg_env` env var, default `tapgoods.com`).
- `Tapgoods_API_Request` does the actual HTTP (`wp_remote_*`) and caches responses in **transients**; `Tapgoods_API_Response` / `Tapgoods_API_Exception` wrap results and errors.
- Auth is a bearer API key. It is stored encrypted (`Tapgoods_Encryption`) in the `tg_key` option, or read from the `TAPGOODS_KEY` constant if defined in code.
- **Sync is driven by Action Scheduler, one action per slice.** AS 3.9.3 is vendored at `tapgoods-wp/lib/action-scheduler/` (the release zip excludes composer `vendor/`, so it cannot be a composer runtime dep) and loaded from `tapgoods.php` before `plugins_loaded`. `Tapgoods_Sync_Scheduler` (`includes/class-tapgoods-sync-scheduler.php`) enqueues hook `tapgoods_sync_slice` in group `tapgoods-sync`; each action runs exactly ONE bounded slice and, if the run is still in progress, chains the next one. The `five_minutes` WP-Cron hook is now only a **watchdog**: it re-arms a slice when a run is in progress and the chain has stalled, and it throttles a *fresh* full sync to one per `DEFAULT_MIN_INTERVAL` (900s, override `TG_SYNC_MIN_INTERVAL`). If `as_*` functions are unavailable, `tapgrein_cron_exec()` falls back to the old non-blocking self-ping to `admin-ajax.php?action=tapgrein_api_sync`.
- **The run is a state machine, not a loop.** `Tapgoods_Sync_State` (`includes/class-tapgoods-sync-state.php`, option `tg_sync_state`) holds state (IDLE / PREP / ACTIVE / COMPLETED / ERROR), a resumable cursor, and a per-run token. Phases run in this order and the cursor checkpoints *within* each one: **categories and tags** (`cat_location_index` / `cat_item_index`), then **item paging** (`location_index` / `next_page`), then **finalize** (`finalize_step`: items → obsolete_cat → obsolete_tag → cleanup_terms_cat → cleanup_terms_tag → cleanup_dupes). Every slice is bounded by `SYNC_TIME_BUDGET` (18s) and returns, leaving the run resumable; `RUN_LOCK` (`tapgrein_sync_lock`, 300s) stops two slices overlapping. Retries belong to the state machine, never to AS, so a failing run cannot become a retry storm.
- **Reconciliation is by run token, and it deletes.** Every post and term the run touches is stamped with `SYNC_RUN_META` (`tg_sync_run`); finalize removes what is *not* stamped. That is how items deleted in TapGoods finally disappear (measured on a live site: 8,414 stale posts removed on the first completed run, 26,596 → 18,182). Deletion is permanent (`wp_delete_post` with force), so **both cleanups are guarded** and those guards must not be weakened: terms refuse to reconcile a taxonomy the run stamped nothing in, and items require the stamped set to be at least `cleanup_min_stamped_ratio()` (0.5, override `TG_SYNC_CLEANUP_MIN_RATIO`) of what is stored, so a partial run that reaches finalize cannot wipe the catalog. Skips are logged as `sync.cleanup.skipped` with the counts.
- **Two limits bound the work, count and clock.** Term queries chunk at `TERM_CHUNK_SIZE` (150); pages fetch `SYNC_PAGE_SIZE` (25) items with at most `SYNC_MAX_PAGES_PER_RUN` (25) per slice; category upserts run `SYNC_CATEGORY_BATCH` (25) at a time **and** take a deadline callback, because on a slow host one batch outlasted the whole slice budget (measured 22-48s against 18s). Anything new in a slice needs both bounds, or a slow site turns it into a request the host kills. `reset_runtime_memory()` plus `wp_suspend_cache_addition()` keep peak memory flat across a long run.
- **`Tapgoods_Sync_Log`** (`includes/class-tapgoods-sync-log.php`) writes a structured always-on log, downloadable from the Status tab. Read it first when diagnosing a sync. One trap it taught: `has_error()` is true while a *recovered* failure's message is still around, so it must never label a slice; `has_latched_error()` answers "is the run stopped" and `Tapgoods_Connection::run_end_status()` is the only thing that should decide `sync.run.end result=`.
- The admin "Sync Now" button and the cron path still share the `tapgrein_api_sync` AJAX action, and still resolve to **two different code paths**: `Tapgoods_Connection` self-registers `manual_sync_trigger` directly (not via the loader, at the bottom of `class-tapgoods-connection.php`) on the logged-in hook, and because every branch of it ends in `wp_send_json_*()` (which calls `wp_die()`), `Tapgoods_Admin::tapgrein_api_sync()` never runs for a logged-in request even though it is hooked to the same action. The logged-out hook `wp_ajax_nopriv_tapgrein_api_sync` is still registered and still has **no nonce** (the self-ping fallback needs it), so `admin-ajax.php?action=tapgrein_api_sync` remains a publicly triggerable sync entry point. Both entries funnel into the same guarded, bounded slice.

**Data model (WordPress side).** `includes/class-tapgoods-post-types.php` registers exactly **one** custom post type, `tg_inventory` (default rewrite slug `products`, so item URLs are `/products/%postname%/` by default). `tg_bundle` and `tg_accessory` appear only as extra object-types passed into `register_taxonomy()` for `tg_category`/`tg_tags` (mirroring the API's Bundle/AddOn GraphQL types) — there is no `register_post_type()` call for either, so they don't exist as actual WP post types. Taxonomies registered: `tg_category`, `tg_tags`, `tg_location` (plus two apparently-unused ones, `tg_inventory_type` and `tg_inventory_colors`, registered against a non-existent `inventory` post type — dead code). Synced API data lands in `tg_inventory` posts and their post meta / term meta. Gutenberg is force-disabled for `tg_inventory` (classic editor + custom metaboxes only).

**Frontend storefront.**
- Shortcodes are defined declaratively in `includes/shortcodes.json` (tag, attributes, defaults) and registered by `Tapgoods_Shortcodes` (`includes/class-tapgoods-shortcodes.php`). Each shortcode `tapgoods-x` renders the template `public/partials/tg-x.php` (e.g. `tapgoods-inventory` → `tg-inventory.php`, `tapgoods-cart` → `tg-cart.php`). Template lookup goes through `tapgrein_locate_template()`, so themes can override.
- `Tapgoods_Public` (`public/class-tapgoods-public.php`) handles frontend enqueue and AJAX (search, cart URL, availability).
- The shop's category menu (`public/partials/tg-filter.php`) comes from `tapgrein_get_categories()`, which resolves "categories with items in this location" via one bounded SQL join (`tapgrein_get_category_ids_for_location()`), then hydrates the terms in `TERM_CHUNK_SIZE` chunks. It must stay bounded: it previously loaded every item id for the location (`posts_per_page => -1`) and passed the whole list to `get_terms( 'object_ids' => ... )`, so the single resulting `IN()` query returned nothing on the default location of a large catalog (~18k items) and the menu silently rendered empty, while small locations on the same site worked. Results are memoised in the `tapgoods` object-cache group for 300s. The returned list also passes through the `tg_shop_categories` filter.
- **Only storefront-visible categories are imported.** `getStorefrontCagetories` returns every `NestedSfCategory` for a location, including internal buckets: an API migration (`db/data/20250831153417_migrate_sub_categories_without_parent.rb` in `tapgoods_rails_api`) gave every parentless storefront sub-category a synthetic parent named `"<sub-category> (Uncategorized)"` with `visible_on_sf: false`. The client asks for `visibleOnSf` and `Tapgoods_Connection::filter_storefront_visible()` drops what is not visible, sub-categories included. Three rules there, each learned the hard way: filter on the **flag, never the name** (a merchant may have a real category with "(Uncategorized)" in it); a **missing** flag counts as visible, so an older API cannot silently empty a menu; and filtering happens on **both** sides of the per-location transient (`CAT_LIST_CACHE_TTL`, 1h), because that cache outlives a plugin upgrade and a list written by an older build otherwise keeps replaying its hidden buckets. Before this, a live site created and then deleted 6,343 terms on **every** pass.
- Sub-categories become `tg_tags`, not categories. Pages built against the older model (filtering `category="glassware"` where Glassware is now a tag with 376 items) render empty; the fix is the shortcode's `tags` attribute, not a data change.
- Pretty URLs: `tapgrein_parse_request()` (in `includes/tapgoods-core-functions.php`) intercepts `parse_request` to resolve storefront/category URLs and redirect to the right term or inventory post. Default rewrite bases (from `tapgrein_get_permalink_structure()`, stored in the `tapgreino_permalinks` option) are `products` for items and `categories` for `tg_category`. `shop/%tg_category%` is only one of several selectable structures on Settings → Permalinks (`admin/class-tapgoods-admin-permalinks.php`) — it is not the shipped default (a same-named default set by `Tapgoods_Activator::activate()` on the unrelated `tg_inventory_permalink` option is never actually read by the permalink logic, so it has no effect).

**Admin.** `admin/class-tapgoods-admin.php` (`Tapgoods_Admin`) builds the settings page (partials in `admin/partials/`) and handles AJAX for connecting/testing the API key and triggering syncs. `admin/class-tapgoods-admin-permalinks.php` manages the permalink settings.

**Shared helpers.** `includes/tapgoods-core-functions.php` (large, 1700+ lines) holds most procedural helpers: price/dimension getters, storefront URL builders (`tapgrein_get_cart_url`, `tapgrein_get_add_to_cart_url`, etc.), template location, and logging (`tapgrein_write_log`). `includes/tapgoods-formatting-functions.php` handles output formatting. `Tapgoods_Helpers` and `Tapgoods_Filesystem` are small utility classes. `Tapgoods_Enqueue` (`includes/class-tapgoods-enqueue.php`) is **not** small (560+ lines): it self-registers its own `admin_enqueue_scripts`/`wp_enqueue_scripts` hooks (the class instantiates its own singleton at the bottom of its file on include, independent of `Tapgoods_Loader`), and it overlaps with two other enqueue paths — `Tapgoods_Public`/`Tapgoods_Admin`'s own `enqueue_styles()`/`enqueue_scripts()` (wired through the loader) and `Tapgoods_Shortcodes::force_enqueue_assets()` (which enqueues its own copies of the public CSS/JS every time a shortcode renders). All three can fire on the same page load; if you're chasing duplicate or conflicting frontend assets, check all three.

## Customer hosting will break assumptions this plugin used to make

Every rule here was paid for on a live site (WP Engine, and a customer host we do not control). They read like paranoia and are not.

- **A rendered page is cached per URL, with no device variance.** Never branch on `wp_is_mobile()` (or anything else about the visitor) to decide markup: whichever device warms the cache decides for everyone. Reproduced in both directions on one URL. Ship one variant and let CSS or a small inline script adapt it client side, as `tg-filter.php` does for the categories accordion.
- **Transients are not in the database.** With a persistent object cache they survive a plugin upgrade entirely, so cached data shaped by plugin logic must be re-filtered on read, not only before the write. A "fix" can look completely inert because it is being fed data from the previous version.
- **Unbounded `IN ( ... )` queries fail silently.** Both the sync's killed queries and the empty category menu were one statement carrying every id. `get_terms()` returns nothing rather than an error, `foreach` over it renders nothing, and no warning appears anywhere. Chunk at `TERM_CHUNK_SIZE` and keep result sets sized by the number of *terms*, not the number of items.
- **A request has an unknown, short ceiling.** WP Engine kills around 23-35s and there is no PHP fatal to find afterwards. Bound work by a clock as well as a count, and check the clock *inside* a batch, not just between batches.
- **Debugging a cached site from outside:** unknown cookies are stripped on cacheable requests, so `curl --cookie` cannot exercise per-cookie code paths. Use POST to the page, or an `admin-ajax.php` action; confirm the cookie really arrived by sending a deliberately invalid value and checking the response changes.

### Sync tuning constants

All optional, all `define()`-able in `wp-config.php` for one site. Defaults are chosen for a slow, request-limited host; raise them only with evidence.

| Constant | Default | What it bounds |
|---|---|---|
| `TG_SYNC_TIME_BUDGET` | 18s | Wall clock for one slice |
| `TG_SYNC_MAX_PAGES` | 25 | Item pages per slice |
| `TG_SYNC_PAGE_SIZE` | 25 | Items per `getInventories` call |
| `TG_SYNC_CATEGORY_BATCH` | 25 | Term upserts per batch |
| `TG_FINALIZE_DELETE_BATCH` | see `finalize_delete_batch()` | Rows deleted per finalize batch |
| `TG_SYNC_MIN_INTERVAL` | 900s | Minimum gap between *fresh* full syncs |
| `TG_SYNC_CLEANUP_MIN_RATIO` | 0.5 | Share of stored items a run must stamp before item cleanup may delete |
| `TG_HTTP_TIMEOUT` | 30s | Per-request API timeout |
| `TG_MOCK` / `tg_mock` | off | Serve the offline mock API |
| `TG_ENV` / `tg_env` | `tapgoods.com` | API host |

## Naming conventions (important, easy to trip on)

The plugin was originally named differently, so prefixes are inconsistent:
- **Classes**: `Tapgoods_*` (e.g. `Tapgoods_Connection`).
- **Functions**: prefixed `tapgrein_` (not `tapgoods_`), e.g. `tapgrein_get_prices()`, `tapgrein_parse_request()`.
- **Options / meta / transients**: `tg_*` (e.g. `tg_key`, `tg_api_connected`, `tg_businessId`) and some `tapgreino_*` / `tapgrein_*`.
- **Post types / taxonomies / meta keys**: `tg_*`.

Match the existing prefix of whatever you are extending rather than introducing a new convention.

## Styling / assets

Bootstrap 5 based. Author styles in `tapgoods-wp/assets/scss/*.scss` and compile with `npm run build-sass` (do not hand-edit the generated `assets/css/custom.css`). There are also prebuilt CSS bundles in `public/css/` and `assets/css/` referenced directly by enqueue code.

## Testing & verification

The goal is to let AI agents (and humans) verify as much of the plugin as possible with trustworthy signal. Verification is a **layered stack**, run in order of speed/cost. Everything lives under `tapgoods-wp/`; dependencies are in `composer.json` require-dev (plus the isolated integration toolchain in `tapgoods-wp/tools/phpunit9/`).

| Layer | Tool | Command | Config |
|---|---|---|---|
| 1. Static analysis | PHPStan + `szepeviktor/phpstan-wordpress` | `composer analyze` | `phpstan.neon.dist` (+ `phpstan-baseline.neon`) |
| 2. Isolated unit tests | PHPUnit 10+ + Brain\Monkey (+ Mockery) | `composer test` | `phpunit.xml.dist`, bootstrap `tests/bootstrap.php` |
| 3. Integration tests | PHPUnit 9.6 + wp-phpunit inside `@wordpress/env` | `npm run test:integration` | `phpunit-integration.xml.dist`, bootstrap `tests/Integration/bootstrap.php` |
| 4. Coverage | PHPUnit + PCOV | CLI coverage flags (see below) | `<source>` scope in the PHPUnit configs |
| (PHP 7.2 gate) | PHPCS + PHPCompatibility | `composer compat` | `phpcompat.xml.dist` |

CI wires these into `.github/workflows/ci.yml` (jobs `static`, `unit`, `integration`), triggered on every `pull_request`/`push`. The release workflow `package-plugin.yml` keeps its own unit + compat gate on push to `master` and is intentionally left without the slower integration job.

**Guiding principle: each package and method should be usable and testable in isolation.** The Layer 2 bootstrap deliberately does *not* load WordPress; each unit is exercised on its own with WP functions stubbed via Brain\Monkey (`Functions\when('get_option')->justReturn(...)`, etc.). This keeps the fast gate fast and forces production code to expose seams (see the `create_client()` / `tapgoods_api_client` mock seam) rather than reaching into global WordPress state. Prefer Layer 2 for new code; use Layer 3 only for behaviour that genuinely needs real WordPress (CPT/taxonomy registration, rewrite/routing, sync writing real posts/meta/terms). The offline TapGoods API mock (below) applies in **both** layers, so no test ever hits the network.

### Layer 1: static analysis (PHPStan)

`composer analyze` runs PHPStan (level 5) over production code only (`includes/`, `admin/`, `public/`, `tapgoods.php`, `uninstall.php`). Because this is a legacy codebase, pre-existing issues are captured in `phpstan-baseline.neon` (128 entries at introduction) so the suite is **green today and only NEW problems fail the build**. When you legitimately fix baselined debt, regenerate it: `composer analyze -- --generate-baseline` (needs a generous memory limit; CLI default `-1` is fine). Do not add blanket ignores to grow the baseline for new code.

`phpstan/phpstan` is pinned to an **exact version** (`2.2.5`) in `composer.json`, not a `^2.0` range. `composer.lock` is not committed, so CI resolves dev dependencies fresh on every run; under a range, a PHPStan patch release lands in CI without anyone touching the repo and reports errors the baseline does not cover, failing `static` on unrelated PRs (2.2.7 did exactly this with `empty.variable` in `class-tapgoods-post-types.php`). Do not relax the pin back to a range. To move to a newer PHPStan, bump the exact version deliberately and regenerate the baseline in the same commit. Verify a bump the way CI sees it, resolving with no lock present:

```bash
# from a copy of tapgoods-wp/ with vendor/ and composer.lock removed
docker run --rm -v "$PWD":/app -w /app --entrypoint bash composer:2 -c \
  'composer install --no-interaction --no-progress && php -d memory_limit=-1 vendor/bin/phpstan analyse'
```

### Layer 2: isolated unit tests

Requires **PHP 8.1+** (PHPUnit 10+; CI uses PHP 8.2) even though the shipped plugin targets PHP 7.2. The 7.2 floor applies to production code and is enforced separately by `composer compat`. If you don't have PHP + Composer locally, use the repo's Docker (Colima) setup:
```bash
# one-time
cd tapgoods-wp && composer install
# or, with Docker only (no local PHP):
docker run --rm -v "$PWD/tapgoods-wp":/app -w /app composer:2 composer install

# run the suite
cd tapgoods-wp && composer test
docker run --rm -v "$PWD/tapgoods-wp":/app -w /app --entrypoint php composer:2 vendor/bin/phpunit

# a single test / filter
cd tapgoods-wp && vendor/bin/phpunit --filter test_get_business_runs_end_to_end_against_injected_mock
cd tapgoods-wp && vendor/bin/phpunit tests/Unit/EncryptionTest.php
```

Unit coverage now spans the sync engine as well as the small pure helpers: `Tapgoods_Encryption` (encrypt/decrypt round-trip; also documents the known `defined('LOGGED_IN_KEY ')` trailing-space bug that forces the insecure fallback key), the formatting helpers in `includes/tapgoods-formatting-functions.php`, `Tapgoods_API_Request` (`build_url`, `verify_parameters`, `transient_name`, config get/set), `Tapgoods_Connection::prepare_meta_input()`, the mock API client and the `create_client()` seam, the bounded/resumable slice arithmetic (`ConnectionSyncCategoriesTest`, `ConnectionSyncSliceTest`, `ConnectionFinalizeTest`), the cleanup ratio guard, the storefront-visibility filter, the Status-screen activity label, and the `sync.run.end` result rule.

The sync tests are worth reading before changing anything in that engine, because several of them exist to pin a *bound* rather than a happy path: no query may carry the whole catalog, resolving the category menu must cost the same number of queries at any catalog size, a batch must stop on the clock as well as the count, and a run that stamped little must delete nothing. When you touch that code, the honest check is to break the fix deliberately and confirm the test fails; several of these were verified that way, and one of them turned out to be pinning the old bug instead of the new contract.

### Layer 3: integration tests against real WordPress

These run inside `@wordpress/env` (`.wp-env.json` maps `./tapgoods-wp` as a plugin) against a real WordPress + throwaway MySQL. They verify what the isolated layer cannot faithfully check: `tg_inventory` CPT and `tg_category`/`tg_tags`/`tg_location` taxonomy registration, `tapgrein_parse_request()` URL routing, a full sync writing real posts/meta/terms (including that storefront-hidden categories never become terms), the shop category menu (right terms, and no query that grows with the catalog), and that the categories filter markup is byte-identical for mobile and desktop so a page cache cannot serve it to the wrong device. The TapGoods API is pinned to the offline mock via `TG_MOCK` in `tests/Integration/bootstrap.php`, so it is "real WP + deterministic external boundary, no network".

**Important version split:** the WordPress core test framework is **not** compatible with PHPUnit 10+ (it calls `PHPUnit\Util\Test::parseTestMethodAnnotations()`, removed in 10). So integration runs under an **isolated PHPUnit 9.6 toolchain** in `tapgoods-wp/tools/phpunit9/` (its own `composer.json`/`vendor/`), completely separate from the PHPUnit 10 used by Layer 2. That is why `phpunit-integration.xml.dist` uses the PHPUnit 9 config schema.

The Docker runtime here is **Colima**. First-time / local run:
```bash
colima start                    # if the Docker daemon isn't already up
cd tapgoods-wp && composer install
cd tapgoods-wp && composer install --working-dir=tools/phpunit9   # PHPUnit 9.6 runner + Yoast polyfills
npm install                     # provides @wordpress/env at the repo root
npx wp-env start                # boots WordPress (dev :8888, tests :8889) + MySQL
npm run test:integration        # wraps: wp-env run tests-cli ... php tools/phpunit9/vendor/bin/phpunit -c phpunit-integration.xml.dist
```
`tests/Integration/wp-tests-config.php` reads DB creds from the container env (`WORDPRESS_DB_*`, which wp-env injects: host `tests-mysql`, db `tests-wordpress`, user/pass `root`/`password`) with sensible fallbacks, and points WordPress core at `/var/www/html/`. Inside the container you can also run `composer test:integration` directly from the plugin dir.

### Layer 4: coverage

Coverage scope is defined by the `<source>` element in both PHPUnit configs (production `includes/`, `admin/`, `public/`); no driver is needed just to define scope, so `composer test` stays green everywhere. Reports are produced on demand where a driver exists (CI uses `coverage: pcov`):
```bash
php -d pcov.enabled=1 -d pcov.directory=. vendor/bin/phpunit --coverage-text --coverage-clover coverage/clover.xml
```
There is intentionally **no coverage threshold yet** (report-only). Setting `pcov.directory` matters, or PCOV attributes 0% to everything.

### Offline mock TapGoods API

So tests (and, optionally, local dev) never hit the network, there is an env-var-gated mock of the TapGoods GraphQL API.

- **Seam:** `Tapgoods_Connection::get_connection()` builds its client through `create_client()` (`includes/class-tapgoods-connection.php`). That method (a) lets a client be injected via the `tapgoods_api_client` filter, and (b) when the mock is enabled, returns `Tapgoods_Mock_API_Client` instead of the real `Tapgoods_API_Client`. Production behaviour is unchanged unless the mock is explicitly requested.
- **Enable it:** define the `TG_MOCK` constant truthy, or set the `tg_mock` environment variable to a truthy value (`1`/`true`/`yes`/`on`). Gating logic lives in `Tapgoods_Connection::use_mock_api()`.
- **Mock client:** `tapgoods-wp/tests/mock/class-tapgoods-mock-api-client.php`. It emulates the client surface `Tapgoods_Connection` calls (`validate_key`, `get_location_ids`, `get_location_details_from_graph`, `get_categories_from_graph`, `get_inventories_from_graph`, `item_exists`, …) and calls **no** WordPress functions, so it stays usable in isolated tests.
- **Fixtures:** static JSON under `tapgoods-wp/tests/fixtures/`, one file per GraphQL response envelope: `bearer-token-validator.json` (`validate_key`), `get-location-details.json`, `get-storefront-categories.json`, `get-inventories.json`. **To add/extend a fixture:** drop a JSON file mirroring the real `{ "data": { ... } }` GraphQL shape in `tests/fixtures/`, then add a method to the mock client that loads it via `$this->fixture('your-file.json')` and returns the same structure the real client returns. In tests, inject the mock through the `tapgoods_api_client` filter (`Filters\expectApplied('tapgoods_api_client')->andReturn($mock)`) or set `tg_mock`.

### The pre-release QA checklist

`QA-CHECKLIST.md` at the repo root is the team's manual checklist for promoting the
plugin to production, with each line marked **auto** (a named test in this repo),
**browser** (needs a real browser), or **manual**. It is there so the expectations
live next to the code: two of its lines were failing on two live sites at once
before anyone noticed, and both are now integration tests.

When you automate one of its lines, change its status in the same commit. A
checklist that quietly disagrees with the tests is worse than no checklist.

### Expectation for new code

New code ships with tests and a verification step. Before saying a change works: add/extend isolated unit tests (Layer 2) for the new behaviour, or an integration test (Layer 3) when it needs real WordPress; then run the relevant gates and confirm they pass. At minimum keep `composer analyze`, `composer test`, and `composer compat` green; run `npm run test:integration` when you touch CPT/taxonomy registration, routing, or the sync-to-WP path. Call out explicitly any test intentionally left failing/skipped to document a known bug, as the encryption test does. Do not grow the PHPStan baseline to hide issues in new code.
