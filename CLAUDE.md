# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## What this is

A WordPress plugin ("TapGoods Rental Inventory") that integrates a WordPress site with the TapGoods rental platform. It syncs inventory from the TapGoods API into WordPress and renders a storefront (shop grid, filters, cart, product pages) via shortcodes.

## Repo layout: two nested levels

- **Repo root** (`/`) is a local dev harness: `docker-compose.yml`, build scripts (`package.json`, `zip-plugin.js`), and the `releases/` output.
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
See `readme.md` for first-time setup (mkcert certs, `/etc/hosts`, `cp example.env localdev.env`). The plugin dir is bind-mounted into the container, so edits are live. There is no PHP test suite in this repo.

## Releases are automated. Do not hand-bump versions

`.github/workflows/package-plugin.yml` runs on every push to `master`: it auto-increments the patch version in `tapgoods-wp/tapgoods.php` (plugin header `Version:`) and `tapgoods-wp/readme.txt` (`Stable tag`), commits "Bump version to X", then builds and commits the zip to `releases/`. Don't manually edit those version fields or the releases folder. Note the `TAPGOODSWP_VERSION` PHP constant (used for asset cache-busting) is separate and is not what CI bumps.

## Architecture

**Bootstrap.** `tapgoods-wp/tapgoods.php` defines constants (`TAPGOODS_PLUGIN_PATH`, `TAPGOODS_UPLOADS`, etc.), registers activation/deactivation hooks, and on `plugins_loaded` calls `Tapgoods::get_instance()->init()`.

**Orchestrator + hook loader.** `includes/class-tapgoods-wp.php` (`Tapgoods`, singleton) is the center. Its constructor `require_once`s every dependency (there is no autoloader; new class files must be added to the `$includes` array in `load_dependencies()`), then registers hooks through `Tapgoods_Loader` (`includes/class-tapgoods-loader.php`). The loader is a deferred registry: `add_action`/`add_filter` just collect hooks into arrays, and `run()` (called from `init()`) registers them all with WordPress at once. Admin hooks, public hooks, and general hooks (cron) are grouped in `define_admin_hooks()`, `define_public_hooks()`, `define_general_hooks()`.

**API / sync layer.**
- `Tapgoods_Connection` (`includes/class-tapgoods-connection.php`, singleton) is the sync controller: validates the connection, runs batched inventory sync, syncs location settings, and writes results into WP posts/meta.
- `Tapgoods_API_Client` builds and sends **GraphQL** queries to `https://openapi.{env}/v1/external/graphql` (env from `TG_ENV` constant or `tg_env` env var, default `tapgoods.com`).
- `Tapgoods_API_Request` does the actual HTTP (`wp_remote_*`) and caches responses in **transients**; `Tapgoods_API_Response` / `Tapgoods_API_Exception` wrap results and errors.
- Auth is a bearer API key. It is stored encrypted (`Tapgoods_Encryption`) in the `tg_key` option, or read from the `TAPGOODS_KEY` constant if defined in code.
- Sync runs on **WP-Cron, but indirectly**: a custom `five_minutes` schedule fires `tapgoods_cron_hook` every 300s → `Tapgoods::tapgrein_cron_exec()` → (if `tg_api_connected`) `Tapgoods_Connection::tapgrein_async_sync_from_api()`, which fires a **non-blocking self-HTTP GET** to `admin-ajax.php?action=tapgrein_api_sync` rather than running sync code directly. That request has no auth cookies, so WordPress dispatches it to the logged-out hook `wp_ajax_nopriv_tapgrein_api_sync`, which only `Tapgoods_Admin::tapgrein_api_sync()` listens on (wired via the loader) → `Tapgoods_Connection::sync_from_api()` → `sync_categories_from_api()` + `sync_inventory_in_batches(false)`.
  - The admin "Sync Now" button calls the **same** `action=tapgrein_api_sync` endpoint, but while logged in, so it fires the logged-in hook `wp_ajax_tapgrein_api_sync` instead. `Tapgoods_Connection` *also* self-registers directly (not via the loader, at the bottom of `class-tapgoods-connection.php`) on that exact logged-in hook (`manual_sync_trigger`). That direct registration happens earlier (during `load_dependencies()`, before the loader's hooks are ever added), so it always runs first — and because every branch of `manual_sync_trigger` ends in `wp_send_json_*()` (which calls `wp_die()`), `Tapgoods_Admin::tapgrein_api_sync()` never actually runs for the logged-in path even though it's hooked to the same action. Net effect: **cron-triggered syncs run `sync_from_api()`**, **manual admin syncs run `manual_sync_trigger()`** (`sync_location_settings(true)` + `sync_inventory_in_batches(true)`) — two different code paths behind what looks like one endpoint. `sync_inventory_in_batches()` is guarded by a `tapgrein_sync_lock` transient (900s) so the two paths can't run concurrently. Also note: the nonce check on `tapgrein_api_sync` is commented out (needed for the unauthenticated cron self-ping), which makes `admin-ajax.php?action=tapgrein_api_sync` a publicly triggerable sync endpoint with no nonce.

**Data model (WordPress side).** `includes/class-tapgoods-post-types.php` registers exactly **one** custom post type, `tg_inventory` (default rewrite slug `products`, so item URLs are `/products/%postname%/` by default). `tg_bundle` and `tg_accessory` appear only as extra object-types passed into `register_taxonomy()` for `tg_category`/`tg_tags` (mirroring the API's Bundle/AddOn GraphQL types) — there is no `register_post_type()` call for either, so they don't exist as actual WP post types. Taxonomies registered: `tg_category`, `tg_tags`, `tg_location` (plus two apparently-unused ones, `tg_inventory_type` and `tg_inventory_colors`, registered against a non-existent `inventory` post type — dead code). Synced API data lands in `tg_inventory` posts and their post meta / term meta. Gutenberg is force-disabled for `tg_inventory` (classic editor + custom metaboxes only).

**Frontend storefront.**
- Shortcodes are defined declaratively in `includes/shortcodes.json` (tag, attributes, defaults) and registered by `Tapgoods_Shortcodes` (`includes/class-tapgoods-shortcodes.php`). Each shortcode `tapgoods-x` renders the template `public/partials/tg-x.php` (e.g. `tapgoods-inventory` → `tg-inventory.php`, `tapgoods-cart` → `tg-cart.php`). Template lookup goes through `tapgrein_locate_template()`, so themes can override.
- `Tapgoods_Public` (`public/class-tapgoods-public.php`) handles frontend enqueue and AJAX (search, cart URL, availability).
- Pretty URLs: `tapgrein_parse_request()` (in `includes/tapgoods-core-functions.php`) intercepts `parse_request` to resolve storefront/category URLs and redirect to the right term or inventory post. Default rewrite bases (from `tapgrein_get_permalink_structure()`, stored in the `tapgreino_permalinks` option) are `products` for items and `categories` for `tg_category`. `shop/%tg_category%` is only one of several selectable structures on Settings → Permalinks (`admin/class-tapgoods-admin-permalinks.php`) — it is not the shipped default (a same-named default set by `Tapgoods_Activator::activate()` on the unrelated `tg_inventory_permalink` option is never actually read by the permalink logic, so it has no effect).

**Admin.** `admin/class-tapgoods-admin.php` (`Tapgoods_Admin`) builds the settings page (partials in `admin/partials/`) and handles AJAX for connecting/testing the API key and triggering syncs. `admin/class-tapgoods-admin-permalinks.php` manages the permalink settings.

**Shared helpers.** `includes/tapgoods-core-functions.php` (large, 1700+ lines) holds most procedural helpers: price/dimension getters, storefront URL builders (`tapgrein_get_cart_url`, `tapgrein_get_add_to_cart_url`, etc.), template location, and logging (`tapgrein_write_log`). `includes/tapgoods-formatting-functions.php` handles output formatting. `Tapgoods_Helpers` and `Tapgoods_Filesystem` are small utility classes. `Tapgoods_Enqueue` (`includes/class-tapgoods-enqueue.php`) is **not** small (560+ lines): it self-registers its own `admin_enqueue_scripts`/`wp_enqueue_scripts` hooks (the class instantiates its own singleton at the bottom of its file on include, independent of `Tapgoods_Loader`), and it overlaps with two other enqueue paths — `Tapgoods_Public`/`Tapgoods_Admin`'s own `enqueue_styles()`/`enqueue_scripts()` (wired through the loader) and `Tapgoods_Shortcodes::force_enqueue_assets()` (which enqueues its own copies of the public CSS/JS every time a shortcode renders). All three can fire on the same page load; if you're chasing duplicate or conflicting frontend assets, check all three.

## Naming conventions (important, easy to trip on)

The plugin was originally named differently, so prefixes are inconsistent:
- **Classes**: `Tapgoods_*` (e.g. `Tapgoods_Connection`).
- **Functions**: prefixed `tapgrein_` (not `tapgoods_`), e.g. `tapgrein_get_prices()`, `tapgrein_parse_request()`.
- **Options / meta / transients**: `tg_*` (e.g. `tg_key`, `tg_api_connected`, `tg_businessId`) and some `tapgreino_*` / `tapgrein_*`.
- **Post types / taxonomies / meta keys**: `tg_*`.

Match the existing prefix of whatever you are extending rather than introducing a new convention.

## Styling / assets

Bootstrap 5 based. Author styles in `tapgoods-wp/assets/scss/*.scss` and compile with `npm run build-sass` (do not hand-edit the generated `assets/css/custom.css`). There are also prebuilt CSS bundles in `public/css/` and `assets/css/` referenced directly by enqueue code.
