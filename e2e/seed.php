<?php
/**
 * Seed the wp-env dev site for the Playwright suite.
 *
 * Run through WP-CLI (see `npm run e2e:seed`). Everything here is deterministic:
 * the plugin is pinned to the offline mock API by TG_MOCK in .wp-env.json, so the
 * catalog the browser sees is the fixture set, not a live storefront. That is the
 * whole point. A browser suite pointed at a real site fails for reasons that have
 * nothing to do with the change under test.
 *
 * Idempotent: safe to re-run.
 */

if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	return;
}

WP_CLI::log( 'Seeding TapGoods e2e fixtures...' );

// 1. Mark the connection live. With TG_MOCK the key is never used against a real
//    API, but the plugin refuses to sync without it.
update_option( 'tg_api_connected', '1' );
update_option( 'tg_last_api_key', 'e2e-mock-key' );

// 2. Location settings the storefront templates read (cart links, location select).
$locations = array( 5001, 5002 );
update_option( 'tg_locationIds', $locations );
update_option( 'tapgreino_default_location', 5001 );

foreach ( $locations as $lid ) {
	$name = 5001 === $lid ? 'Downtown Warehouse' : 'Airport Depot';
	$code = 5001 === $lid ? 'DTW' : 'APT';

	update_option(
		'tg_location_' . $lid,
		array(
			'id'          => $lid,
			'fullName'    => "{$name} ({$code})",
			'cart_url'    => "https://example.test/{$lid}/cart?externalRedirect=true",
			'add_to_cart' => "https://example.test/{$lid}/addToCart",
			'signup_url'  => "https://example.test/{$lid}/signup",
			'login_url'   => "https://example.test/{$lid}/login",
			'sf_url'      => "https://example.test/{$lid}/",
		)
	);
}

// 3. Run a full sync against the mock so the shop has real posts and terms.
$conn = Tapgoods_Connection::get_instance();

for ( $slice = 0; $slice < 60; $slice++ ) {
	$conn->sync_from_api( 'e2e_seed' );

	if ( Tapgoods_Sync_State::STATE_COMPLETED === Tapgoods_Sync_State::get_instance()->get_state() ) {
		break;
	}
}

$state = Tapgoods_Sync_State::get_instance()->get_state();
WP_CLI::log( "Sync state after seeding: {$state}" );

$items = (int) wp_count_posts( 'tg_inventory' )->publish;
WP_CLI::log( "Published items: {$items}" );

if ( 0 === $items ) {
	WP_CLI::error( 'Seeding produced no items; the browser suite would test an empty shop.' );
}

// 4. The pages the specs visit. Each is a shortcode the checklist names.
$pages = array(
	'shop'                => '[tapgoods-inventory]',
	'shop-hide-pricing'   => '[tapgoods-inventory show_pricing="false"]',
	// Two grids on one page, each filtered to a category, the way a client builds
	// a "browse everything" page. Every grid renders the same element ids, which
	// is what WPB-180 tripped over.
	'shop-multi'          => '[tapgoods-inventory category="tables"][tapgoods-inventory category="chairs"]',
	// A page the site curated to one tag. A visitor appending ?tags= must not be
	// able to re-point it (the WPB-166 precedence rule: attribute beats URL).
	'shop-tag-curated'    => '[tapgoods-inventory tags="tag-round-tables"]',
	'tg-cart'             => '[tapgoods-cart]',
);

foreach ( $pages as $slug => $content ) {
	$existing = get_page_by_path( $slug );

	if ( $existing ) {
		wp_update_post(
			array(
				'ID'           => $existing->ID,
				'post_content' => $content,
			)
		);
		WP_CLI::log( "Updated page /{$slug}/" );
		continue;
	}

	wp_insert_post(
		array(
			'post_type'    => 'page',
			'post_status'  => 'publish',
			'post_title'   => ucwords( str_replace( '-', ' ', $slug ) ),
			'post_name'    => $slug,
			'post_content' => $content,
		)
	);
	WP_CLI::log( "Created page /{$slug}/" );
}

// PRETTY permalinks, because half of what this suite is for only exists under
// them. A tag URL is /tags/<slug>/ on a customer site, and WPB-166 could not even
// be reproduced on plain permalinks: the old code parsed the slug out of the path,
// found nothing there, and the grid's own query var quietly filtered the page
// correctly. Testing the one configuration where the bug did not appear is how it
// stayed open.
//
// This used to say the web container was nginx with no try_files rule, so pretty
// permalinks 404'd. That is no longer true -- wp-env serves WordPress through
// Apache -- but it does need a .htaccess. That file is MOUNTED by .wp-env.json
// (see e2e/htaccess) rather than generated here: writing it needs permission on
// the WordPress root, which the wp-cli container has on macOS and does not have
// on Linux CI, where the directory is root-owned. Generating it passed locally
// and failed every CI run.
update_option( 'permalink_structure', '/%postname%/' );
flush_rewrite_rules( false );

// Check the mount rather than assume it: without the rewrite block every spec
// fails on a 404, with a confusing error far from the cause. Checked on disk and
// not over HTTP on purpose -- this runs in the wp-cli container, which cannot
// reach the host port the browser uses.
$htaccess = ABSPATH . '.htaccess';

if ( ! file_exists( $htaccess ) || false === strpos( (string) file_get_contents( $htaccess ), 'RewriteRule' ) ) {
	WP_CLI::error(
		'No rewrite rules in ' . $htaccess . '. The .htaccess mapping in .wp-env.json is missing or '
		. 'the environment predates it -- run "npx wp-env destroy && npx wp-env start". Pretty '
		. 'permalinks would 404 and the browser suite would fail everywhere for this one reason.'
	);
}

WP_CLI::success( 'Seeded.' );
