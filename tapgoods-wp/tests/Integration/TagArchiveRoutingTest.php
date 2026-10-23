<?php
/**
 * Integration: a tg_tags term archive lands on the shop grid, filtered (WPB-166).
 *
 * A tag is reachable from the "Tags" list on an item page and from
 * TapGoods > Tags > View in wp-admin. Both went to the term archive, which is
 * not a storefront, and the visitor saw an empty grid. What they expect is the
 * shop they came from, narrowed to that tag, the same way a category click
 * narrows it.
 *
 * This needs real WordPress: it turns on is_tax(), rewrite resolution of a real
 * /tags/<slug>/ URL, WordPress's own shortcode parser, the page lookup that
 * finds the shop, and the shortcodes rendering the redirect target.
 *
 * Most of the routing tests here run under a PRETTY permalink structure on
 * purpose. Under plain permalinks the original bug does not even appear: the
 * old REQUEST_URI segment parse found no slug, the shortcode attribute stayed
 * empty, and the grid's own tg_tags query var filtered it correctly. Testing
 * this on plain permalinks would have been testing the one configuration where
 * it already worked.
 *
 * @package Tapgoods\Tests\Integration
 */

namespace Tapgoods\Tests\Integration;

use WP_UnitTestCase;

/** Thrown in place of the exit() that follows wp_safe_redirect(). */
class TagRedirected extends \Exception {

	/** @var string */
	public $location;

	public function __construct( $location ) {
		$this->location = $location;
		parent::__construct( 'redirected to ' . $location );
	}
}

final class TagArchiveRoutingTest extends WP_UnitTestCase {

	const LOCATION = '5001';

	/** sanitize_title() of a Japanese tag name, as WordPress stores it. */
	const JP_SLUG = '%e6%97%a5%e6%9c%ac%e8%aa%9e';

	/** @var int */
	private $shop_page;

	/** @var int */
	private $gold_term;

	/** @var int */
	private $chairs_term;

	protected function setUp(): void {
		parent::setUp();

		update_option( 'tapgreino_default_location', self::LOCATION );
		update_option(
			'tg_location_' . self::LOCATION,
			array(
				'cart_url'    => 'https://shop.example.com/cart?externalRedirect=true',
				'add_to_cart' => 'https://shop.example.com/addToCart',
			)
		);

		// The discovery query is memoised; every test here changes what it should
		// find, so start from a clean slate. Deliberately the raw cache delete and
		// not tapgrein_flush_shop_page_cache(): setUp must not depend on a
		// function this change introduces, or running the file against the old
		// code errors in setUp and hides which behaviours actually differ.
		wp_cache_delete( 'tg_shop_page_id', 'tapgoods' );
		delete_option( 'tg_shop_page_id' );

		$this->shop_page = $this->make_page( 'Rentals', '[tapgoods-inventory]' );

		$chairs            = wp_insert_term( 'Chairs', 'tg_category' );
		$this->chairs_term = (int) $chairs['term_id'];

		$gold            = wp_insert_term( 'Gold', 'tg_tags', array( 'slug' => 'tag-gold' ) );
		$this->gold_term = (int) $gold['term_id'];

		$this->make_item( 'Folding Chair', array( $this->chairs_term ), array( $this->gold_term ) );
		$this->make_item( 'Table Cloth', array( $this->chairs_term ), array() );
	}

	protected function tearDown(): void {
		remove_all_filters( 'wp_redirect' );
		remove_all_filters( 'tg_shop_page_id' );
		wp_cache_delete( 'tg_shop_page_id', 'tapgoods' );

		// Registered taxonomies are global state the DB rollback does not undo,
		// so a test that renamed tg_tag_base would leak its rewrite slug into the
		// next one. Restore the option first, then rebuild from it.
		delete_option( 'tapgreino_permalinks' );
		$this->use_permalinks( '' );

		parent::tearDown();
	}

	// -- fixtures -------------------------------------------------------------

	private function make_page( string $title, string $content ): int {
		return (int) self::factory()->post->create(
			array(
				'post_type'    => 'page',
				'post_status'  => 'publish',
				'post_title'   => $title,
				'post_content' => $content,
			)
		);
	}

	/**
	 * @param int[] $category_ids
	 * @param int[] $tag_ids
	 */
	private function make_item( string $title, array $category_ids, array $tag_ids ): int {
		$post_id = (int) self::factory()->post->create(
			array(
				'post_type'   => 'tg_inventory',
				'post_status' => 'publish',
				'post_title'  => $title,
				'meta_input'  => array(
					'tg_locationId' => self::LOCATION,
					'tg_dailyPrice' => 10.0,
					'tg_id'         => wp_rand( 1000, 9999 ),
				),
			)
		);

		if ( $category_ids ) {
			wp_set_object_terms( $post_id, $category_ids, 'tg_category' );
		}
		if ( $tag_ids ) {
			wp_set_object_terms( $post_id, $tag_ids, 'tg_tags' );
		}

		return $post_id;
	}

	/**
	 * Switch permalink structure and rebuild what depends on it.
	 *
	 * Two things make this less trivial than set_permalink_structure():
	 *
	 * - WP_Rewrite::init(), which set_permalink_structure() triggers, empties
	 *   extra_permastructs. Without re-registering, get_term_link() keeps
	 *   answering the plain ?tg_tags= form and the test quietly measures the one
	 *   URL shape where the bug never appeared.
	 * - Tapgoods_Post_Types::tapgrein_register_taxonomies() returns early when
	 *   tg_category already exists, so it has to be unregistered first. This is
	 *   also what lets the tg_tag_base test see a different rewrite slug.
	 */
	private function use_permalinks( string $structure ) {
		$this->set_permalink_structure( $structure );

		foreach ( array( 'tg_category', 'tg_tags', 'tg_location', 'tg_inventory_type', 'tg_inventory_colors' ) as $taxonomy ) {
			if ( taxonomy_exists( $taxonomy ) ) {
				unregister_taxonomy( $taxonomy );
			}
		}

		\Tapgoods_Post_Types::tapgrein_register_taxonomies();
		flush_rewrite_rules( false );
	}

	/** Intercept wp_safe_redirect(), which is followed by exit() in production. */
	private function catch_redirects() {
		add_filter(
			'wp_redirect',
			static function ( $location ) {
				throw new TagRedirected( $location );
			}
		);
	}

	/**
	 * Visit a URL and run the routing hook; report where it sent the visitor.
	 *
	 * @return string|null Redirect target, or null when nothing was redirected.
	 */
	private function route_to( string $url ) {
		$this->go_to( $url );
		$this->catch_redirects();

		try {
			tapgrein_redirect_tag_archives();
		} catch ( TagRedirected $redirect ) {
			return $redirect->location;
		}

		return null;
	}

	private function route_to_term( int $term_id, string $taxonomy ) {
		return $this->route_to( get_term_link( $term_id, $taxonomy ) );
	}

	// -- Finding the shop page ------------------------------------------------

	public function test_the_shop_page_is_the_page_that_hosts_the_inventory_shortcode() {
		// Not home_url('/shop/'). The page is named "Rentals" here precisely
		// because most sites do not call it "shop", and the old dead code in
		// core-functions hardcoded that path.
		$this->assertSame( $this->shop_page, tapgrein_get_shop_page_id() );
		$this->assertSame( get_permalink( $this->shop_page ), tapgrein_get_shop_url() );
	}

	public function test_a_bare_grid_page_is_not_mistaken_for_the_shop() {
		wp_delete_post( $this->shop_page, true );
		wp_cache_delete( 'tg_shop_page_id', 'tapgoods' );

		$this->make_page( 'Grid only', '[tapgoods-inventory-grid category="chairs"]' );

		// A bare grid has no filters and no search, so it is not a shop page.
		$this->assertSame( 0, tapgrein_get_shop_page_id() );
	}

	public function test_a_category_scoped_page_is_never_chosen_as_the_shop() {
		// The reviewer's finding. A page built as
		// [tapgoods-inventory category="tables"] is a curated landing page; the
		// grid ANDs its category with the tag, so sending /tags/tag-linens/ there
		// produces an empty grid -- the WPB-166 symptom, one level down.
		wp_delete_post( $this->shop_page, true );
		wp_cache_delete( 'tg_shop_page_id', 'tapgoods' );

		$scoped = $this->make_page( 'Tables', '[tapgoods-inventory category="tables"]' );
		$real   = $this->make_page( 'Everything', '[tapgoods-inventory show_pricing="false"]' );

		$this->assertLessThan( $real, $scoped, 'The scoped page must be the older one, or this proves nothing.' );
		$this->assertSame( $real, tapgrein_get_shop_page_id() );
	}

	public function test_a_site_whose_only_grid_pages_are_scoped_has_no_shop_page() {
		wp_delete_post( $this->shop_page, true );
		wp_cache_delete( 'tg_shop_page_id', 'tapgoods' );

		$this->make_page( 'Tables', '[tapgoods-inventory category="tables"]' );
		$this->make_page( 'Linens', '[tapgoods-inventory tags="tag-linens"]' );

		// Rendering the archive in place beats redirecting to a page that would
		// intersect its own filter with the tag.
		$this->assertSame( 0, tapgrein_get_shop_page_id() );
		$this->assertSame( '', tapgrein_get_shop_url() );
		$this->assertNull( $this->route_to_term( $this->gold_term, 'tg_tags' ) );
	}

	public function test_an_escaped_shortcode_is_not_a_shop_page() {
		// [[tapgoods-inventory]] renders as literal text, in a documentation page
		// explaining the shortcode to a client. The escape is the doubled bracket
		// pair; reading the regex's group 5 instead tested the ENCLOSED CONTENT
		// and got this exactly backwards.
		wp_delete_post( $this->shop_page, true );
		wp_cache_delete( 'tg_shop_page_id', 'tapgoods' );

		$this->make_page( 'How to build a shop', 'Paste [[tapgoods-inventory]] into a page.' );

		$this->assertSame( 0, tapgrein_get_shop_page_id() );
	}

	public function test_an_enclosing_shortcode_is_a_shop_page() {
		// The other half of the same mistake: [tapgoods-inventory]...[/...] is a
		// perfectly ordinary unscoped grid, and testing group 5 skipped it.
		wp_delete_post( $this->shop_page, true );
		wp_cache_delete( 'tg_shop_page_id', 'tapgoods' );

		$enclosing = $this->make_page( 'Shop', '[tapgoods-inventory]Browse our range[/tapgoods-inventory]' );

		$this->assertSame( $enclosing, tapgrein_get_shop_page_id() );
	}

	public function test_the_shop_page_is_found_behind_many_scoped_pages() {
		// A single LIMIT could not express this: with more scoped landing pages
		// than the batch size ahead of it, the real shop page fell off the end of
		// the one query and the site was told it had none.
		wp_delete_post( $this->shop_page, true );
		wp_cache_delete( 'tg_shop_page_id', 'tapgoods' );

		for ( $i = 0; $i < 25; $i++ ) {
			$this->make_page( 'Landing ' . $i, '[tapgoods-inventory category="chairs"]' );
		}

		$real = $this->make_page( 'Everything', '[tapgoods-inventory]' );

		$this->assertSame( $real, tapgrein_get_shop_page_id() );
	}

	public function test_candidate_paging_has_a_hard_ceiling() {
		// Bounded as well as paged: 5 batches of 20 is 100 candidate pages, and
		// past that it answers "none" rather than walking an unbounded number of
		// rows on every cache miss. A site shaped like that should set the
		// option.
		wp_delete_post( $this->shop_page, true );
		wp_cache_delete( 'tg_shop_page_id', 'tapgoods' );

		// 100 scoped pages, then the real one at position 101.
		for ( $i = 0; $i < 100; $i++ ) {
			$this->make_page( 'Landing ' . $i, '[tapgoods-inventory category="chairs"]' );
		}

		$past_the_ceiling = $this->make_page( 'Everything', '[tapgoods-inventory]' );

		$this->assertSame( 0, tapgrein_get_shop_page_id() );

		// Move it inside the ceiling and it is found, so this is the ceiling and
		// not some other reason for answering nothing.
		wp_delete_post( $past_the_ceiling, true );
		wp_cache_delete( 'tg_shop_page_id', 'tapgoods' );

		$inside = $this->make_page( 'Everything', '[tapgoods-inventory]' );
		wp_update_post(
			array(
				'ID'           => (int) get_page_by_path( 'landing-0' )->ID,
				'post_content' => 'No shortcode here.',
			)
		);
		wp_cache_delete( 'tg_shop_page_id', 'tapgoods' );

		// One of the 100 no longer carries a grid, so the new page is candidate
		// number 100 rather than 101.
		$this->assertSame( $inside, tapgrein_get_shop_page_id() );
	}

	public function test_a_later_grid_page_does_not_steal_the_shop() {
		$later = $this->make_page( 'Second', '[tapgoods-inventory]' );

		$this->assertGreaterThan( $this->shop_page, $later );
		$this->assertSame( $this->shop_page, tapgrein_get_shop_page_id() );
	}

	public function test_a_site_can_name_its_shop_page_explicitly() {
		$by_option = $this->make_page( 'By option', '[tapgoods-inventory]' );
		$by_filter = $this->make_page( 'By filter', '[tapgoods-inventory]' );

		update_option( 'tg_shop_page_id', $by_option );
		$this->assertSame( $by_option, tapgrein_get_shop_page_id(), 'The option must beat discovery.' );

		add_filter(
			'tg_shop_page_id',
			static function () use ( $by_filter ) {
				return $by_filter;
			}
		);
		$this->assertSame( $by_filter, tapgrein_get_shop_page_id(), 'The filter must beat the option.' );
	}

	public function test_an_unpublished_filtered_page_falls_back_to_discovery() {
		// The filter is the documented workaround, so it gets the same status
		// check the option has: pointing it at a trashed page must not send
		// visitors to a 404.
		$draft = self::factory()->post->create(
			array(
				'post_type'   => 'page',
				'post_status' => 'draft',
			)
		);

		add_filter(
			'tg_shop_page_id',
			static function () use ( $draft ) {
				return $draft;
			}
		);

		$this->assertSame( $this->shop_page, tapgrein_get_shop_page_id() );
	}

	public function test_deleting_an_inventory_item_does_not_touch_the_shop_page_cache() {
		// deleted_post / trashed_post fire for every post type, and a sync
		// reconcile force-deletes thousands of tg_inventory posts in one run.
		// Flushing on each of those is pure waste: no item can change which page
		// holds the shortcode.
		$this->assertSame( $this->shop_page, tapgrein_get_shop_page_id() );

		$item = $this->make_item( 'Doomed', array(), array() );
		wp_delete_post( $item, true );

		$this->assertSame(
			$this->shop_page,
			(int) wp_cache_get( 'tg_shop_page_id', 'tapgoods' ),
			'The memoised id must still be there after an inventory item was deleted.'
		);
	}

	public function test_deleting_a_page_does_flush_the_cache() {
		$this->assertSame( $this->shop_page, tapgrein_get_shop_page_id() );

		$other = $this->make_page( 'Some page', 'No shortcode here.' );
		wp_delete_post( $other, true );

		$this->assertFalse( wp_cache_get( 'tg_shop_page_id', 'tapgoods' ) );
	}

	public function test_setting_the_option_for_the_first_time_flushes_the_cache() {
		// add_option_* fires instead of update_option_* when the option did not
		// exist, which is the usual case for a workaround being applied.
		$this->assertSame( $this->shop_page, tapgrein_get_shop_page_id() );

		$other = $this->make_page( 'Chosen', '[tapgoods-inventory]' );
		add_option( 'tg_shop_page_id', $other );

		$this->assertSame( $other, tapgrein_get_shop_page_id() );
	}

	public function test_an_unpublished_configured_page_falls_back_to_discovery() {
		$draft = self::factory()->post->create(
			array(
				'post_type'   => 'page',
				'post_status' => 'draft',
			)
		);

		update_option( 'tg_shop_page_id', $draft );

		$this->assertSame( $this->shop_page, tapgrein_get_shop_page_id() );
	}

	public function test_a_trashed_shop_page_is_not_served_from_cache() {
		// With a persistent object cache the memoised id outlives the page. A
		// stale id means a 302 to a 404, which is worse than not redirecting.
		$this->assertSame( $this->shop_page, tapgrein_get_shop_page_id() );

		// Trash it WITHOUT letting the invalidation hooks run, to prove the read
		// path re-validates rather than relying on them alone. Both hooks have to
		// go: wp_trash_post() goes through wp_update_post(), so save_post_page
		// fires as well as trashed_post, and leaving either one in place flushes
		// the entry and the re-validation is never exercised.
		remove_action( 'trashed_post', 'tapgrein_maybe_flush_shop_page_cache' );
		remove_action( 'save_post_page', 'tapgrein_maybe_flush_shop_page_cache' );
		wp_trash_post( $this->shop_page );
		add_action( 'trashed_post', 'tapgrein_maybe_flush_shop_page_cache' );
		add_action( 'save_post_page', 'tapgrein_maybe_flush_shop_page_cache' );

		$this->assertSame( 0, tapgrein_get_shop_page_id() );
	}

	public function test_publishing_a_shop_page_invalidates_the_cached_miss() {
		wp_delete_post( $this->shop_page, true );
		wp_cache_delete( 'tg_shop_page_id', 'tapgoods' );

		$this->assertSame( 0, tapgrein_get_shop_page_id(), 'Primes the negative cache.' );

		$fresh = $this->make_page( 'New shop', '[tapgoods-inventory]' );

		$this->assertSame( $fresh, tapgrein_get_shop_page_id() );
	}

	public function test_the_negative_answer_is_cached() {
		global $wpdb;

		wp_delete_post( $this->shop_page, true );
		wp_cache_delete( 'tg_shop_page_id', 'tapgoods' );

		$this->assertSame( 0, tapgrein_get_shop_page_id() );

		// A site with no shop page must not re-run the scan on every tag hit.
		$before = $wpdb->num_queries;
		$this->assertSame( 0, tapgrein_get_shop_page_id() );
		$this->assertSame( $before, $wpdb->num_queries );
	}

	// -- Routing, under real pretty URLs --------------------------------------

	public function test_the_router_is_actually_hooked() {
		// Every other routing test here calls the function directly, so they would
		// all keep passing if the hook registration were dropped and nothing ran
		// it on a real request.
		$this->assertNotFalse(
			has_action( 'template_redirect', 'tapgrein_redirect_tag_archives' )
		);
	}

	public function test_a_pretty_tag_url_is_sent_to_the_shop_filtered_by_that_tag() {
		// The ticket's URL shape, resolved by WordPress's own rewrite rules.
		$this->use_permalinks( '/%postname%/' );

		$this->assertSame( home_url( '/tags/tag-gold/' ), get_term_link( $this->gold_term, 'tg_tags' ) );

		$location = $this->route_to( home_url( '/tags/tag-gold/' ) );

		$this->assertNotNull( $location, 'The tag archive must not be left to render as a bare archive.' );
		$this->assertSame( home_url( '/rentals/' ) . '?tags=tag-gold', $location );
	}

	public function test_a_custom_tag_permalink_base_routes_the_same_way() {
		// Settings > Permalinks lets a site change tg_tag_base.
		$permalinks              = tapgrein_get_permalink_structure();
		$permalinks['tg_tag_base'] = 'etiquetas';
		update_option( 'tapgreino_permalinks', $permalinks );

		$this->use_permalinks( '/%postname%/' );

		$this->assertSame( home_url( '/etiquetas/tag-gold/' ), get_term_link( $this->gold_term, 'tg_tags' ) );

		$location = $this->route_to( home_url( '/etiquetas/tag-gold/' ) );

		$this->assertSame( home_url( '/rentals/' ) . '?tags=tag-gold', $location );
	}

	public function test_a_plain_permalink_tag_url_routes_too() {
		$location = $this->route_to_term( $this->gold_term, 'tg_tags' );

		$this->assertStringContainsString( (string) $this->shop_page, (string) $location );
		$this->assertStringContainsString( 'tags=tag-gold', (string) $location );
	}

	public function test_the_redirect_carries_the_rest_of_the_query_string() {
		// A tag link can arrive with campaign parameters on it. Dropping them
		// loses the attribution for every visit that starts at a tag.
		$this->use_permalinks( '/%postname%/' );

		$location = $this->route_to( home_url( '/tags/tag-gold/?utm_source=newsletter&utm_medium=email' ) );

		$this->assertStringContainsString( 'utm_source=newsletter', (string) $location );
		$this->assertStringContainsString( 'utm_medium=email', (string) $location );
		$this->assertStringContainsString( 'tags=tag-gold', (string) $location );
	}

	public function test_the_redirect_drops_wordpress_own_query_vars() {
		// These described the archive being left. On the page being entered they
		// mean something else: ?embed=true carried through lands the visitor on
		// the shop page's oEmbed document instead of the shop.
		$this->use_permalinks( '/%postname%/' );

		$location = (string) $this->route_to(
			home_url( '/tags/tag-gold/?embed=true&s=chair&order=desc&orderby=title&utm_source=newsletter&gclid=abc123' )
		);

		$this->assertNotSame( '', $location, 'Still redirected; these vars are dropped, not honoured.' );
		$this->assertStringNotContainsString( 'embed=', $location );
		$this->assertStringNotContainsString( 's=chair', $location );
		// order/orderby are only caught by the general "drop what WordPress
		// routes on" rule, not by any name this function spells out, so they are
		// what proves the rule is doing the work.
		$this->assertStringNotContainsString( 'order=desc', $location );
		$this->assertStringNotContainsString( 'orderby=title', $location );
		$this->assertStringContainsString( 'utm_source=newsletter', $location );
		$this->assertStringContainsString( 'gclid=abc123', $location );
		$this->assertStringContainsString( 'tags=tag-gold', $location );
	}

	public function test_a_huge_tag_list_cannot_build_an_unbounded_query() {
		// ?tags=a,b,c...x5000 -- or a POST to the logged-out tg_search_grid
		// action -- used to reach the tax_query whole, doubled by the variants
		// helper. Same failure shape as the sync's killed queries: one statement
		// carrying a list that grows with whatever the caller sent.
		$slugs = array();

		for ( $i = 1; $i <= 5000; $i++ ) {
			$slugs[] = 'tag-' . $i;
		}

		$this->go_to( add_query_arg( 'tags', implode( ',', $slugs ), get_permalink( $this->shop_page ) ) );

		$resolved = tapgrein_resolve_tag_filter( array() );

		$this->assertCount( TAPGOODS_MAX_SLUG_FILTER, explode( ',', $resolved ) );

		// And what the grid actually renders is still correct, just bounded.
		$html = do_shortcode( '[tapgoods-inventory]' );

		$this->assertStringNotContainsString( 'Folding Chair', $html );
		$this->assertStringNotContainsString( 'Table Cloth', $html );
	}

	public function test_the_redirect_maps_a_paged_tag_url() {
		// /tags/x/page/2/ is a real URL WordPress serves. Landing the visitor on
		// page one of the shop instead is a quiet wrong answer.
		$this->make_item( 'Gold Charger', array(), array( $this->gold_term ) );
		update_option( 'posts_per_page', 1 ); // So page 2 of this archive exists.

		$this->use_permalinks( '/%postname%/' );

		$location = $this->route_to( home_url( '/tags/tag-gold/page/2/' ) );

		$this->assertStringContainsString( 'tags=tag-gold', (string) $location );
		$this->assertStringContainsString( 'paged=2', (string) $location );
	}

	public function test_the_redirect_carries_the_real_slug_not_a_stripped_one() {
		// A tag whose slug never had the sync's "tag-" prefix. Stripping blindly
		// (or prefixing blindly at the other end) is what made these unroutable.
		$plain = wp_insert_term( 'Velvet', 'tg_tags', array( 'slug' => 'velvet' ) );
		$this->make_item( 'Velvet Runner', array(), array( (int) $plain['term_id'] ) );

		$location = $this->route_to_term( (int) $plain['term_id'], 'tg_tags' );

		$this->assertStringContainsString( 'tags=velvet', (string) $location );

		// And the target really shows it.
		$this->go_to( add_query_arg( 'tags', 'velvet', get_permalink( $this->shop_page ) ) );
		$html = do_shortcode( '[tapgoods-inventory]' );

		$this->assertStringContainsString( 'Velvet Runner', $html );
		$this->assertStringNotContainsString( 'Folding Chair', $html );
	}

	public function test_a_category_archive_is_left_alone() {
		// Category archives were deliberately un-redirected for the
		// redemption-tents issue (WP-132). Tags must not drag them back in.
		$this->assertNull( $this->route_to_term( $this->chairs_term, 'tg_category' ) );
	}

	public function test_a_site_with_no_shop_page_is_not_redirected_anywhere() {
		wp_delete_post( $this->shop_page, true );
		wp_cache_delete( 'tg_shop_page_id', 'tapgoods' );

		// Nowhere to send them: the tag archive renders in place via
		// tg-tag-results.php instead of bouncing to a 404.
		$this->assertNull( $this->route_to_term( $this->gold_term, 'tg_tags' ) );
	}

	public function test_an_embed_request_is_left_alone() {
		// oEmbed serves its own tiny document; a redirect there breaks the embed
		// rather than helping anyone. WordPress only routes /embed/ for singular
		// requests, so the flag is set directly here: the point is the guard, not
		// a URL shape core does not produce.
		$this->go_to( get_term_link( $this->gold_term, 'tg_tags' ) );

		global $wp_query;
		$wp_query->is_embed = true;

		$this->assertTrue( is_embed() );

		$this->catch_redirects();
		tapgrein_redirect_tag_archives();

		$this->assertTrue( is_tax( 'tg_tags' ), 'Still the tag archive, just not redirected.' );
	}

	public function test_the_shop_page_itself_does_not_redirect() {
		$this->go_to( add_query_arg( 'tags', 'tag-gold', get_permalink( $this->shop_page ) ) );
		$this->catch_redirects();

		// No loop: the destination is a page, never a tg_tags archive. If the hook
		// fired here, the filter above would throw and fail the test.
		$this->assertFalse( is_tax( 'tg_tags' ) );
		tapgrein_redirect_tag_archives();
	}

	// -- Non-latin slugs ------------------------------------------------------

	public function test_a_non_latin_tag_round_trips_from_redirect_to_grid_to_search() {
		// Three separate encodings had to agree for this to work, and none of
		// them did: rawurlencode() double-encoded the stored slug,
		// sanitize_text_field() stripped its percent octets in the search box,
		// and the [A-Za-z0-9-] sanitizer ate them in the shortcode attribute. The
		// visible result was an empty grid and a search that answered with the
		// whole catalog.
		$jp = wp_insert_term( "\xe6\x97\xa5\xe6\x9c\xac\xe8\xaa\x9e", 'tg_tags' );
		$this->assertNotWPError( $jp );

		$term = get_term( (int) $jp['term_id'], 'tg_tags' );
		$this->assertSame( self::JP_SLUG, $term->slug, 'WordPress stores a non-latin slug percent-encoded.' );

		$this->make_item( 'Tatami Mat', array(), array( (int) $jp['term_id'] ) );

		$location = $this->route_to_term( (int) $jp['term_id'], 'tg_tags' );

		$this->assertStringContainsString( 'tags=' . self::JP_SLUG, (string) $location );
		$this->assertStringNotContainsString( '%25', (string) $location, 'Double-encoded.' );

		$this->go_to( add_query_arg( 'tags', self::JP_SLUG, get_permalink( $this->shop_page ) ) );
		$html = do_shortcode( '[tapgoods-inventory]' );

		$this->assertStringContainsString( 'Tatami Mat', $html );
		$this->assertStringNotContainsString( 'Folding Chair', $html );

		// The hidden field is what the AJAX search posts back, so assert the
		// round trip rather than a byte pattern: WordPress hands the query var
		// back decoded, and both forms must resolve to the same one item.
		$posted = $this->search_field_value( $html, 'tags' );

		$this->assertNotSame( '', $posted, 'Empty here is the bug: the search answers with everything.' );
		$this->assertSame( 1, $this->items_matching_posted_tags( $posted ) );
	}

	public function test_a_curated_page_can_name_a_non_latin_tag() {
		// The slug reaches the search box still percent-encoded on this path,
		// because it comes from the page's own shortcode rather than from a URL
		// PHP already decoded. sanitize_text_field() strips percent octets, so
		// running the value through it leaves the hidden field EMPTY and the AJAX
		// search answers with the whole catalog. This is the test that fails if
		// anyone reaches for sanitize_text_field() here again.
		$jp = wp_insert_term( "\xe6\x97\xa5\xe6\x9c\xac\xe8\xaa\x9e", 'tg_tags' );
		$this->make_item( 'Tatami Mat', array(), array( (int) $jp['term_id'] ) );

		$this->go_to( get_permalink( $this->shop_page ) );

		$html = do_shortcode( '[tapgoods-inventory tags="' . self::JP_SLUG . '"]' );

		$this->assertStringContainsString( 'Tatami Mat', $html );
		$this->assertStringNotContainsString( 'Folding Chair', $html );
		$this->assertSame( self::JP_SLUG, $this->search_field_value( $html, 'tags' ) );
	}

	public function test_the_ajax_search_keeps_a_non_latin_tag_filter() {
		$jp = wp_insert_term( "\xe6\x97\xa5\xe6\x9c\xac\xe8\xaa\x9e", 'tg_tags' );
		$this->make_item( 'Tatami Mat', array(), array( (int) $jp['term_id'] ) );

		// The stored form and the decoded form both arrive from real browsers,
		// depending on how the link was built. sanitize_text_field() erased the
		// first outright, which is how the filter silently became "no filter".
		$this->assertSame( 1, $this->items_matching_posted_tags( self::JP_SLUG ) );
		$this->assertSame( 1, $this->items_matching_posted_tags( "\xe6\x97\xa5\xe6\x9c\xac\xe8\xaa\x9e" ) );
	}

	/** Value of a hidden field in the rendered search form. */
	private function search_field_value( string $html, string $name ): string {
		if ( preg_match( '/name="' . preg_quote( $name, '/' ) . '"\s+value="([^"]*)"/', $html, $match ) ) {
			return html_entity_decode( $match[1], ENT_QUOTES, 'UTF-8' );
		}

		return '';
	}

	/** Run the AJAX search handler's tag resolution and count what it would return. */
	private function items_matching_posted_tags( string $posted ): int {
		$slugs = tapgrein_sanitize_slug_list( $posted );

		if ( '' === $slugs ) {
			return -1; // Distinct from "no matches", which is what makes this test meaningful.
		}

		$query = new \WP_Query(
			array(
				'post_type'      => 'tg_inventory',
				'posts_per_page' => 50,
				'tax_query'      => array(
					array(
						'taxonomy' => 'tg_tags',
						'field'    => 'slug',
						'terms'    => tapgrein_tag_slug_variants( explode( ',', $slugs ) ),
					),
				),
			)
		);

		return (int) $query->found_posts;
	}

	// -- Precedence -----------------------------------------------------------

	public function test_a_curated_pages_tag_attribute_wins_over_the_url() {
		// A page built as [tapgoods-inventory tags="tag-gold"] is curated. A
		// visitor appending ?tags=anything must not be able to re-point it.
		$this->go_to( add_query_arg( 'tags', 'velvet', get_permalink( $this->shop_page ) ) );

		$plain = wp_insert_term( 'Velvet', 'tg_tags', array( 'slug' => 'velvet' ) );
		$this->make_item( 'Velvet Runner', array(), array( (int) $plain['term_id'] ) );

		$html = do_shortcode( '[tapgoods-inventory tags="tag-gold"]' );

		$this->assertStringContainsString( 'Folding Chair', $html );
		$this->assertStringNotContainsString( 'Velvet Runner', $html );
		$this->assertMatchesRegularExpression( '/name="tags"\s+value="tag-gold"/', $html );
	}

	public function test_the_url_applies_where_the_page_did_not_say() {
		$this->go_to( add_query_arg( 'tags', 'tag-gold', get_permalink( $this->shop_page ) ) );

		$html = do_shortcode( '[tapgoods-inventory]' );

		$this->assertStringContainsString( 'Folding Chair', $html );
		$this->assertStringNotContainsString( 'Table Cloth', $html );
	}

	public function test_grid_and_search_box_agree_about_the_filter() {
		// They used to read ?tags= with three different rules, which is how the
		// grid could be filtered while the search box was not.
		$this->go_to( add_query_arg( 'tags', 'gold', get_permalink( $this->shop_page ) ) );

		$html = do_shortcode( '[tapgoods-inventory]' );

		$this->assertMatchesRegularExpression( '/name="tags"\s+value="gold"/', $html );
		$this->assertStringContainsString( 'Folding Chair', $html );
		$this->assertStringNotContainsString( 'Table Cloth', $html );
	}

	// -- What the visitor actually gets ---------------------------------------

	public function test_shortcode_source_is_not_entity_escaped_on_its_way_down() {
		// The WPB-166 mechanism, reproduced through the shortcode attribute:
		// esc_attr() on shortcode SOURCE turned tags="gold" into
		// tags=&quot;gold&quot;, the parser read the entity as part of the value,
		// and everything downstream got a slug that could not exist.
		$this->go_to( get_permalink( $this->shop_page ) );

		$html = do_shortcode( '[tapgoods-inventory tags="gold"]' );

		$this->assertStringNotContainsString( '&amp;quot;', $html );
		$this->assertMatchesRegularExpression( '/name="tags"\s+value="gold"/', $html );
		$this->assertStringContainsString( 'Folding Chair', $html );
		$this->assertStringNotContainsString( 'Table Cloth', $html );
	}

	public function test_the_category_filter_reaches_the_search_box_too() {
		// Same esc_attr() bug, same line, other attribute: the category never
		// reached the search box either, so searching a category-filtered shop
		// answered with the whole catalog.
		$this->go_to( add_query_arg( 'category', 'chairs', get_permalink( $this->shop_page ) ) );

		$html = do_shortcode( '[tapgoods-inventory]' );

		$this->assertMatchesRegularExpression( '/name="category"\s+value="chairs"/', $html );
	}

	public function test_the_fallback_archive_renders_the_tags_items_under_a_pretty_url() {
		// The reported symptom, reproduced: a pretty tag URL on a site with no
		// shop page. tg-tag-results.php read the slug out of REQUEST_URI and
		// passed it down through esc_attr(), so the grid received
		// tags=&quot;tag-gold&quot;, looked up a slug that cannot exist and
		// rendered nothing.
		wp_delete_post( $this->shop_page, true );
		wp_cache_delete( 'tg_shop_page_id', 'tapgoods' );

		$this->use_permalinks( '/%postname%/' );
		$this->go_to( home_url( '/tags/tag-gold/' ) );

		$this->assertTrue( is_tax( 'tg_tags' ) );

		ob_start();
		include TAPGREIN_PLUGIN_DIR . 'public/partials/tg-tag-results.php';
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( 'Folding Chair', $html, 'The tag archive must show the tag items.' );
		$this->assertStringNotContainsString( 'Table Cloth', $html );
	}

	public function test_the_fallback_archive_survives_a_custom_tag_permalink_base() {
		// The old template looked for the literal segment "tags" in REQUEST_URI,
		// so on a site that renamed tg_tag_base it found no slug at all.
		wp_delete_post( $this->shop_page, true );
		wp_cache_delete( 'tg_shop_page_id', 'tapgoods' );

		$permalinks                = tapgrein_get_permalink_structure();
		$permalinks['tg_tag_base'] = 'etiquetas';
		update_option( 'tapgreino_permalinks', $permalinks );

		$this->use_permalinks( '/%postname%/' );
		$this->go_to( home_url( '/etiquetas/tag-gold/' ) );

		$this->assertTrue( is_tax( 'tg_tags' ) );

		ob_start();
		include TAPGREIN_PLUGIN_DIR . 'public/partials/tg-tag-results.php';
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( 'Folding Chair', $html );
		$this->assertStringNotContainsString( 'Table Cloth', $html );
	}

	public function test_an_unknown_tag_shows_nothing_rather_than_everything() {
		$this->go_to( add_query_arg( 'tags', 'no-such-tag', get_permalink( $this->shop_page ) ) );

		$html = do_shortcode( '[tapgoods-inventory]' );

		$this->assertStringNotContainsString( 'Folding Chair', $html );
		$this->assertStringNotContainsString( 'Table Cloth', $html );
	}
}
