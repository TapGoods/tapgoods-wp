<?php
/**
 * Integration: the shop category menu (tapgrein_get_categories()).
 *
 * This is the function behind the category list in public/partials/tg-filter.php,
 * and it is only meaningfully testable against real WordPress: it depends on
 * taxonomy registration, term relationships, post meta and the SQL that joins
 * them.
 *
 * The regression it guards is a silent one. The old implementation loaded every
 * item id for the location ( 'posts_per_page' => -1 ) and passed the lot into
 * get_terms( 'object_ids' => ... ), producing a single query with an IN() list of
 * the whole catalog. On the live site's default location (~18k items) that
 * returned nothing at all, so the menu rendered empty while smaller locations on
 * the same site rendered fine. No warning, no error: just no categories.
 *
 * So these tests assert both halves of the fix: the right categories come back,
 * AND no single query the function issues grows with the number of items.
 *
 * @package Tapgoods\Tests\Integration
 */

namespace Tapgoods\Tests\Integration;

use WP_UnitTestCase;

final class ShopCategoryMenuTest extends WP_UnitTestCase {

	/**
	 * Byte cap for any single query issued while resolving the menu.
	 *
	 * Chosen to sit well above what the fixed implementation needs (its queries
	 * do not carry item ids at all) and well below what the old implementation
	 * produced for the item counts used here. A query that scales with the
	 * catalog blows through this long before it reaches production size.
	 */
	const MAX_QUERY_BYTES = 4096;

	/** @var string */
	private $loc_a = '9001';

	/** @var string */
	private $loc_b = '9002';

	protected function setUp(): void {
		parent::setUp();
		// The object cache memoises the location-to-terms lookup; each test builds
		// its own fixture, so start from a clean slate.
		wp_cache_flush();
	}

	/**
	 * Create $count published tg_inventory posts in a location, round-robin
	 * assigned to $term_ids (or left uncategorised when none are given).
	 *
	 * Inserted directly so a few thousand rows stay fast enough to run in CI.
	 *
	 * @param int   $count       How many items.
	 * @param string $location_id Location meta value.
	 * @param int[] $term_ids    Categories to spread the items across.
	 * @return int[] Created post ids.
	 */
	private function seed_items( $count, $location_id, array $term_ids = array() ) {
		global $wpdb;

		$prefix = 'tgtest-' . $location_id . '-';
		$now    = current_time( 'mysql' );
		$rows   = array();

		for ( $i = 0; $i < $count; $i++ ) {
			$rows[] = $wpdb->prepare(
				'(%d,%s,%s,%s,%s,%s,%s,%s,%s)',
				1,
				$now,
				$now,
				'TG Test Item ' . $location_id . ' ' . $i,
				'publish',
				'tg_inventory',
				$prefix . $i,
				'',
				''
			);
		}

		$wpdb->query(
			"INSERT INTO {$wpdb->posts}
			(post_author,post_date,post_date_gmt,post_title,post_status,post_type,post_name,post_content,post_excerpt)
			VALUES " . implode( ',', $rows )
		);

		$ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT ID FROM {$wpdb->posts} WHERE post_type = %s AND post_name LIKE %s",
				'tg_inventory',
				$wpdb->esc_like( $prefix ) . '%'
			)
		);
		$ids = array_map( 'intval', (array) $ids );

		foreach ( $ids as $i => $post_id ) {
			update_post_meta( $post_id, 'tg_locationId', $location_id );

			if ( $term_ids ) {
				wp_set_object_terms( $post_id, array( (int) $term_ids[ $i % count( $term_ids ) ] ), 'tg_category', false );
			}
		}

		return $ids;
	}

	/**
	 * @param string[] $names Category names to create.
	 * @return int[] Term ids, in the order given.
	 */
	private function seed_categories( array $names ) {
		$ids = array();

		foreach ( $names as $name ) {
			$term = wp_insert_term( $name, 'tg_category' );
			$this->assertNotWPError( $term, "Failed to create category {$name}." );
			$ids[] = (int) $term['term_id'];
		}

		return $ids;
	}

	/**
	 * Run a callback while recording every SQL statement wpdb executes, returning
	 * [ result, queries ].
	 *
	 * Uses the `query` filter rather than SAVEQUERIES: the constant is read at
	 * query time and cannot be toggled per test, and wpdb exposes no writable
	 * logging flag.
	 *
	 * @param callable $callback Code under measurement.
	 * @return array{0:mixed,1:string[]}
	 */
	private function capture_queries( callable $callback ) {
		$queries = array();

		$spy = function ( $query ) use ( &$queries ) {
			$queries[] = $query;
			return $query;
		};

		add_filter( 'query', $spy );
		$result = $callback();
		remove_filter( 'query', $spy );

		return array( $result, $queries );
	}

	/** @param string[] $queries Recorded statements. */
	private function longest_query( array $queries ) {
		$longest = '';

		foreach ( $queries as $query ) {
			if ( strlen( $query ) > strlen( $longest ) ) {
				$longest = $query;
			}
		}

		return $longest;
	}

	/** @param WP_Term[]|array $terms Terms returned by the function. */
	private function names_of( $terms ) {
		$this->assertIsArray( $terms );

		$names = array();
		foreach ( $terms as $term ) {
			$names[] = $term->name;
		}
		sort( $names );

		return $names;
	}

	public function test_returns_only_categories_with_items_in_that_location() {
		list( $linen, $lounge, $tenting ) = $this->seed_categories( array( 'ZZ Linen', 'ZZ Lounge', 'ZZ Tenting' ) );

		$this->seed_items( 6, $this->loc_a, array( $linen, $lounge ) );
		$this->seed_items( 3, $this->loc_b, array( $tenting ) );

		$this->assertSame(
			array( 'ZZ Linen', 'ZZ Lounge' ),
			$this->names_of( tapgrein_get_categories( $this->loc_a ) ),
			'Location A must list its own categories and not location B\'s.'
		);

		$this->assertSame(
			array( 'ZZ Tenting' ),
			$this->names_of( tapgrein_get_categories( $this->loc_b ) )
		);
	}

	public function test_categories_with_no_items_in_the_location_are_excluded() {
		list( $used ) = $this->seed_categories( array( 'ZZ Used' ) );
		$this->seed_categories( array( 'ZZ Empty Placeholder (Uncategorized)' ) );

		$this->seed_items( 4, $this->loc_a, array( $used ) );

		$this->assertSame(
			array( 'ZZ Used' ),
			$this->names_of( tapgrein_get_categories( $this->loc_a ) ),
			'A term with no items in the location must not reach the menu.'
		);
	}

	public function test_location_with_no_items_returns_an_empty_array() {
		$this->seed_categories( array( 'ZZ Somewhere Else' ) );

		$result = tapgrein_get_categories( '9999999' );

		$this->assertIsArray( $result );
		$this->assertSame( array(), $result );
	}

	public function test_items_without_a_category_do_not_produce_terms() {
		$this->seed_items( 5, $this->loc_a ); // No categories assigned.

		$this->assertSame( array(), $this->names_of( tapgrein_get_categories( $this->loc_a ) ) );
	}

	public function test_large_location_still_returns_its_categories() {
		// The exact regression: the biggest location is the one that broke, and it
		// broke by returning nothing.
		$term_ids = $this->seed_categories( array( 'ZZ Big One', 'ZZ Big Two', 'ZZ Big Three' ) );
		$this->seed_items( 800, $this->loc_a, $term_ids );

		$this->assertSame(
			array( 'ZZ Big One', 'ZZ Big Three', 'ZZ Big Two' ),
			$this->names_of( tapgrein_get_categories( $this->loc_a ) ),
			'A location with a large catalog must still list its categories.'
		);
	}

	public function test_no_query_carries_the_whole_catalog() {
		$term_ids = $this->seed_categories( array( 'ZZ Bounded' ) );
		$this->seed_items( 800, $this->loc_a, $term_ids );
		wp_cache_flush();

		list( $terms, $queries ) = $this->capture_queries(
			function () {
				return tapgrein_get_categories( $this->loc_a );
			}
		);

		$this->assertSame( array( 'ZZ Bounded' ), $this->names_of( $terms ) );

		$longest = $this->longest_query( $queries );

		$this->assertLessThan(
			self::MAX_QUERY_BYTES,
			strlen( $longest ),
			'No query may grow with the number of items. Longest was: ' . substr( $longest, 0, 300 )
		);

		// Belt and braces: an IN() list long enough to hold hundreds of ids is the
		// shape that failed in production, whatever its total byte count.
		foreach ( $queries as $query ) {
			$this->assertSame(
				0,
				preg_match( '/IN\s*\([\d,\s]{1500,}\)/', $query ),
				'A query carried a very long IN() list: ' . substr( $query, 0, 300 )
			);
		}
	}

	public function test_query_count_does_not_grow_with_the_catalog() {
		$term_ids = $this->seed_categories( array( 'ZZ Fixed Cost' ) );
		$this->seed_items( 50, $this->loc_a, $term_ids );
		wp_cache_flush();

		list( , $small ) = $this->capture_queries(
			function () {
				return tapgrein_get_categories( $this->loc_a );
			}
		);

		$this->seed_items( 800, $this->loc_b, $term_ids );
		wp_cache_flush();

		list( , $large ) = $this->capture_queries(
			function () {
				return tapgrein_get_categories( $this->loc_b );
			}
		);

		$this->assertSame(
			count( $small ),
			count( $large ),
			'Resolving the menu must cost the same number of queries at any catalog size.'
		);
	}

	public function test_resolved_term_ids_are_memoised_per_location() {
		$term_ids = $this->seed_categories( array( 'ZZ Cached' ) );
		$this->seed_items( 10, $this->loc_a, $term_ids );
		wp_cache_flush();

		$first = tapgrein_get_category_ids_for_location( $this->loc_a );

		list( , $queries ) = $this->capture_queries(
			function () {
				return tapgrein_get_category_ids_for_location( $this->loc_a );
			}
		);

		$this->assertSame( $term_ids, $first );
		$this->assertSame( array(), $queries, 'The second lookup must be served from cache.' );
	}

	public function test_menu_can_be_filtered() {
		list( $keep, $drop ) = $this->seed_categories( array( 'ZZ Keep', 'ZZ Drop (Uncategorized)' ) );
		$this->seed_items( 4, $this->loc_a, array( $keep, $drop ) );

		$filter = function ( $terms ) {
			return array_values(
				array_filter(
					$terms,
					function ( $term ) {
						return false === strpos( $term->name, '(Uncategorized)' );
					}
				)
			);
		};

		add_filter( 'tg_shop_categories', $filter );
		$filtered = tapgrein_get_categories( $this->loc_a );
		remove_filter( 'tg_shop_categories', $filter );

		$this->assertSame(
			array( 'ZZ Keep' ),
			$this->names_of( $filtered ),
			'tg_shop_categories must let a site drop placeholder categories without a code change.'
		);
	}
}
