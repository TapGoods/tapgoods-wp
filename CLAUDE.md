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
- Sync runs on **WP-Cron**: a custom `five_minutes` schedule fires `tapgoods_cron_hook` every 300s → `sync_inventory_in_batches()`. A `tapgrein_sync_lock` transient prevents overlapping runs.

**Data model (WordPress side).** `includes/class-tapgoods-post-types.php` registers custom post type `tg_inventory` (plus `tg_bundle`, `tg_accessory`) and taxonomies `tg_category`, `tg_tags`, `tg_location`. Synced API data lands in these posts and their post meta / term meta. Gutenberg is force-disabled for `tg_inventory` (classic editor + custom metaboxes only).

**Frontend storefront.**
- Shortcodes are defined declaratively in `includes/shortcodes.json` (tag, attributes, defaults) and registered by `Tapgoods_Shortcodes` (`includes/class-tapgoods-shortcodes.php`). Each shortcode `tapgoods-x` renders the template `public/partials/tg-x.php` (e.g. `tapgoods-inventory` → `tg-inventory.php`, `tapgoods-cart` → `tg-cart.php`). Template lookup goes through `tapgrein_locate_template()`, so themes can override.
- `Tapgoods_Public` (`public/class-tapgoods-public.php`) handles frontend enqueue and AJAX (search, cart URL, availability).
- Pretty URLs: `tapgrein_parse_request()` (in `includes/tapgoods-core-functions.php`) intercepts `parse_request` to resolve storefront/category URLs (default permalink structure `shop/%tg_category%`) and redirect to the right term or inventory post.

**Admin.** `admin/class-tapgoods-admin.php` (`Tapgoods_Admin`) builds the settings page (partials in `admin/partials/`) and handles AJAX for connecting/testing the API key and triggering syncs. `admin/class-tapgoods-admin-permalinks.php` manages the permalink settings.

**Shared helpers.** `includes/tapgoods-core-functions.php` (large) holds most procedural helpers: price/dimension getters, storefront URL builders (`tapgrein_get_cart_url`, `tapgrein_get_add_to_cart_url`, etc.), template location, and logging (`tapgrein_write_log`). `includes/tapgoods-formatting-functions.php` handles output formatting. `Tapgoods_Helpers`, `Tapgoods_Filesystem`, `Tapgoods_Enqueue` are small utility classes.

## Naming conventions (important, easy to trip on)

The plugin was originally named differently, so prefixes are inconsistent:
- **Classes**: `Tapgoods_*` (e.g. `Tapgoods_Connection`).
- **Functions**: prefixed `tapgrein_` (not `tapgoods_`), e.g. `tapgrein_get_prices()`, `tapgrein_parse_request()`.
- **Options / meta / transients**: `tg_*` (e.g. `tg_key`, `tg_api_connected`, `tg_businessId`) and some `tapgreino_*` / `tapgrein_*`.
- **Post types / taxonomies / meta keys**: `tg_*`.

Match the existing prefix of whatever you are extending rather than introducing a new convention.

## Styling / assets

Bootstrap 5 based. Author styles in `tapgoods-wp/assets/scss/*.scss` and compile with `npm run build-sass` (do not hand-edit the generated `assets/css/custom.css`). There are also prebuilt CSS bundles in `public/css/` and `assets/css/` referenced directly by enqueue code.
