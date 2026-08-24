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

// PLAIN permalinks on purpose. The wp-env web container is nginx with no
// try_files rule, so every pretty permalink returns a bare 404 before the request
// ever reaches WordPress; /sample-page/ 404s too, so this is the environment and
// not the plugin. The specs therefore address pages as /?page_id=N, discovered
// through the REST API rather than hardcoded. Anything that genuinely needs pretty
// URLs (tapgrein_parse_request routing) stays with the PHP integration suite,
// which runs inside WordPress and does not care about the web server.
update_option( 'permalink_structure', '' );
flush_rewrite_rules();

WP_CLI::success( 'Seeded.' );
