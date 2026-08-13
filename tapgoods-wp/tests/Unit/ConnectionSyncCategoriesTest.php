<?php
/**
 * Unit tests for the BOUNDED, RESUMABLE categories/tags pass in
 * Tapgoods_Connection::run_sync_slice() (WPB-165 sync-stuck fix) and its
 * MEMORY-BOUNDED, run-token dedup (WPB-172 OOM fix).
 *
 * Root cause fixed here: the old one-shot sync_categories_from_api() looped every
 * location, upserted thousands of terms, then removed obsolete ones, all in a
 * single blocking request. On a host with a request-time limit that request was
 * killed before categories_done was ever set, so paging never began and every
 * cron tick redid categories and was killed again (0/N forever).
 *
 * The fix mirrors the bounded paging loop at TWO granularities (across locations
 * via cat_location_index, and WITHIN a location via cat_item_index). Cross-location
 * dedup is now by RUN TOKEN stamped on each term (self::SYNC_RUN_META), NOT by a
 * growing in-cursor set: a term already carrying the current run's token is skipped.
 * Obsolete-term removal is deferred to the resumable finalize phase, which deletes
 * whatever is NOT stamped with this run's token, so it never runs on a partial pass.
 *
 * These tests drive the private run_sync_slice() via reflection against a probe (an
 * anonymous subclass) whose tg_insert_or_update_term() simulates the term DB with a
 * SHARED in-memory stamp map (so cross-tick dedup, which is really a DB concern, can
 * still be exercised in isolation), and whose finalize removal seams record calls.
 *
 * Isolated (no WordPress) via Brain\Monkey.
 *
 * @package Tapgoods\Tests
 */

namespace Tapgoods\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;
use Tapgoods_Connection;
use Tapgoods_Sync_Log;
use Tapgoods_Sync_State;

final class ConnectionSyncCategoriesTest extends TestCase {

	/** @var array In-memory option store. */
	private $store = array();

	/** @var object Fake clock holder ({ t: int }). */
	private $clock;

	/** @var object Shared "term DB" stamp map ({ map: [tax][id] => token }). */
	private $stamped;

	/** @var string Temp directory the logger writes into. */
	private $log_dir;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		$this->store = array();
		$store       = &$this->store;

		$this->clock   = (object) array( 't' => 1000 );
		$clock         = $this->clock;
		$this->stamped = (object) array( 'map' => array() );
		$this->log_dir = sys_get_temp_dir() . '/tg-cats-' . uniqid( '', true );
		mkdir( $this->log_dir, 0777, true );

		Functions\when( 'add_action' )->justReturn( true );
		Functions\when( 'do_action' )->justReturn( true );
		Functions\when( 'get_option' )->alias(
			static function ( $key, $default = false ) use ( &$store ) {
				return array_key_exists( $key, $store ) ? $store[ $key ] : $default;
			}
		);
		Functions\when( 'update_option' )->alias(
			static function ( $key, $value ) use ( &$store ) {
				$store[ $key ] = $value;
				return true;
			}
		);
		Functions\when( 'current_time' )->alias(
			static function () use ( $clock ) {
				return $clock->t;
			}
		);
		Functions\when( 'get_transient' )->justReturn( false );
		Functions\when( 'set_transient' )->justReturn( true );
		Functions\when( 'delete_transient' )->justReturn( true );
		Functions\when( 'is_wp_error' )->justReturn( false );

		require_once ABSPATH . 'includes/class-tapgoods-sync-state.php';
		require_once ABSPATH . 'includes/class-tapgoods-connection.php';

		$this->reset_singletons();
		$this->set_singleton( Tapgoods_Sync_Log::class, new Tapgoods_Sync_Log( $this->log_dir, 'testsecret' ) );
	}

	protected function tearDown(): void {
		if ( is_dir( $this->log_dir ) ) {
			foreach ( glob( $this->log_dir . '/*' ) as $f ) {
				@unlink( $f );
			}
			@rmdir( $this->log_dir );
		}
		Monkey\tearDown();
		parent::tearDown();
	}

	private function reset_singletons(): void {
		foreach ( array( Tapgoods_Connection::class, Tapgoods_Sync_State::class, Tapgoods_Sync_Log::class ) as $class ) {
			$this->set_singleton( $class, null );
		}
	}

	private function set_singleton( $class, $value ): void {
		$ref  = new ReflectionClass( $class );
		$prop = $ref->getProperty( 'instance' );
		$prop->setValue( null, $value );
	}

	private function inject_client( $client ): void {
		Functions\when( 'apply_filters' )->alias(
			static function ( $tag, $value = null ) use ( $client ) {
				return ( 'tapgoods_api_client' === $tag ) ? $client : $value;
			}
		);
	}

	/**
	 * A minimal fake API client. Records inventory-paging calls so a test can prove
	 * paging never started while categories were still in progress. Category lists
	 * are served by the probe's get_location_categories_cached() override, not here.
	 */
	private function make_client() {
		return new class() {
			/** @var int Number of get_inventories_from_graph() calls. */
			public $inv_calls = 0;

			public function get_location_ids() {
				return array( 5001, 5002, 5003 );
			}
			public function get_categories_from_graph( $lid, $keyword = false ) {
				return array();
			}
			public function get_inventories_from_graph( $lid, $page = 1, $size = 25 ) {
				++$this->inv_calls;
				// Empty collection => each location's paging ends immediately, so a
				// slice that reaches paging finalises without touching the term DB.
				return array( 'collection' => array(), 'metadata' => array( 'totalPages' => 1 ) );
			}
			public function get_last_http_code() {
				return 200;
			}
		};
	}

	/**
	 * Build a simple category record: id + name/slug and optional sub-tag ids.
	 *
	 * @param int   $id       Category tg_id (also the term id the probe returns).
	 * @param int[] $sub_ids  Sub-category tg_ids (become tg_tags).
	 * @return array
	 */
	private function cat( $id, array $sub_ids = array() ) {
		$subs = array();
		foreach ( $sub_ids as $sid ) {
			$subs[] = array( 'id' => $sid, 'name' => "T$sid", 'slug' => "t$sid" );
		}
		return array( 'id' => $id, 'name' => "C$id", 'slug' => "c$id", 'sfSubCategories' => $subs );
	}

	/**
	 * Build a probe: an anonymous subclass of Tapgoods_Connection that stubs the
	 * term-DB seams the categories loop depends on. tg_insert_or_update_term()
	 * simulates the term DB with a SHARED stamp map ($stamped): a term already
	 * carrying the current run token is a skip (sets last_term_skipped, no upsert);
	 * otherwise it records the upsert, advances the fake clock, and stamps the map.
	 * The finalize removal seams record their calls so a test can assert removal is
	 * token-based and runs only after a full pass. So the REAL bounded-batch loop
	 * (upsert_category_batch + run_sync_slice + run_finalize_slice) is exercised
	 * without WordPress.
	 */
	private function make_probe( $client ) {
		$this->inject_client( $client );

		$probe          = new class( $this->clock, $this->stamped ) extends Tapgoods_Connection {
			public $clock;
			public $stamped;                    // shared { map: [tax][id] => token }.
			public $advance = 0;                // seconds added per term upsert.
			public $lists   = array();          // lid(string) => array|false category list.
			public $fetched = array();          // lids passed to get_location_categories_cached.
			public $upserts = array();          // [{tax, id}] real upsert calls, in order.
			public $term_removals = array();    // [{taxonomy, token}] remove_terms_not_in_run calls.
			public $item_removals = array();    // [token] remove_items_not_in_run calls.
			public $client;                     // fake client (for inv_calls).

			// Note: intentionally does NOT call the private parent constructor.
			public function __construct( $clock, $stamped ) {
				$this->clock   = $clock;
				$this->stamped = $stamped;
			}

			public function get_location_categories_cached( $lid ) {
				$this->fetched[] = (string) $lid;
				$key             = (string) $lid;
				if ( ! array_key_exists( $key, $this->lists ) ) {
					return array();
				}
				return $this->lists[ $key ];
			}
			public function clear_location_categories_cache( $lid ) {}

			// Token-aware fake of the term upsert: skips a term already stamped with
			// the current run token (dedup), else records the upsert and stamps it.
			public function tg_insert_or_update_term( $term, $tax, $parent = null, $run_token = null ) {
				$this->last_term_skipped = false;
				$id                      = $term['id'];
				$has_token               = ( null !== $run_token && '' !== (string) $run_token );

				if ( $has_token
					&& isset( $this->stamped->map[ $tax ][ $id ] )
					&& (string) $this->stamped->map[ $tax ][ $id ] === (string) $run_token ) {
					$this->last_term_skipped = true;
					return $id;
				}

				$this->clock->t += $this->advance;
				$this->upserts[] = array( 'tax' => $tax, 'id' => $id );
				if ( $has_token ) {
					$this->stamped->map[ $tax ][ $id ] = (string) $run_token;
				}
				return $id;
			}

			// Token-based finalize removal seams (record only; no DB).
			public function remove_items_not_in_run( $run_token, $limit ) {
				$this->item_removals[] = (string) $run_token;
				return 0;
			}
			public function remove_terms_not_in_run( $taxonomy, $run_token, $limit ) {
				$this->term_removals[] = array( 'taxonomy' => $taxonomy, 'token' => (string) $run_token );
				return 0;
			}
			// Neutralise the rest of the finalize cleanup so it never hits the DB.
			public function remove_unused_terms_batch( $taxonomy, $after_term_id, $limit ) {
				return array( 'removed' => 0, 'last_term_id' => (int) $after_term_id, 'exhausted' => true );
			}
			public function remove_duplicate_items_batch( $after_value, $group_limit ) {
				return array( 'removed' => 0, 'cursor' => (string) $after_value, 'exhausted' => true );
			}
			public function update_sync_info( $start_time ) {}
		};
		$probe->client = $client;
		return $probe;
	}

	private function run_slice( $probe, Tapgoods_Sync_State $state ) {
		$method = new ReflectionMethod( Tapgoods_Connection::class, 'run_sync_slice' );
		return $method->invoke( $probe, $state );
	}

	private function fresh_state( array $location_ids ): Tapgoods_Sync_State {
		$state = Tapgoods_Sync_State::get_instance();
		$state->begin_prep( 3 )->mark_active();
		$state->init_cursor( $location_ids );
		return $state;
	}

	private function run_token(): string {
		return (string) Tapgoods_Sync_State::get_instance()->get_cursor()['run_token'];
	}

	/** category tg_ids upserted this run, in order. */
	private function upsert_ids_for( $probe, $tax ) {
		$ids = array();
		foreach ( $probe->upserts as $u ) {
			if ( $tax === $u['tax'] ) {
				$ids[] = $u['id'];
			}
		}
		return $ids;
	}

	/** taxonomies that finalize invoked token-based term removal on. */
	private function removed_taxonomies( $probe ) {
		$taxes = array();
		foreach ( $probe->term_removals as $r ) {
			$taxes[] = $r['taxonomy'];
		}
		return $taxes;
	}

	// -------------------------------------------------------------------------

	/**
	 * The cursor carries NO unbounded collection (WPB-172): every field is a scalar
	 * or a small location-sized list. This is the invariant the whole fix rests on.
	 */
	public function test_cursor_has_no_unbounded_collections() {
		$defaults = Tapgoods_Sync_State::cursor_defaults();

		// The old giant-array fields are gone.
		foreach ( array( 'synced_ids', 'valid_category_ids', 'valid_tag_ids', 'processed_category_ids', 'processed_tag_ids' ) as $gone ) {
			$this->assertArrayNotHasKey( $gone, $defaults, "Cursor must not carry the unbounded field '$gone'." );
		}

		// The only array field is location_ids (bounded by the business's locations,
		// not the catalog); everything else is a scalar.
		foreach ( $defaults as $key => $value ) {
			if ( 'location_ids' === $key ) {
				$this->assertIsArray( $value );
				continue;
			}
			$this->assertIsNotArray( $value, "Cursor field '$key' must be a scalar, not a growing collection." );
		}

		// The run token is a scalar, minted per run.
		$this->assertSame( '', $defaults['run_token'] );
		$token = Tapgoods_Sync_State::generate_run_token();
		$this->assertIsString( $token );
		$this->assertNotSame( '', $token );
	}

	/**
	 * upsert_category_batch() in isolation: it processes at most one budget's worth
	 * of operations, stops on a whole-category boundary, and reports the resume point.
	 */
	public function test_upsert_category_batch_is_bounded_and_reports_resume_point() {
		$probe = $this->make_probe( $this->make_client() );

		$categories = array(
			$this->cat( 701 ),          // 1 op
			$this->cat( 702, array( 811, 812 ) ), // 3 ops (cat + 2 tags)
			$this->cat( 703 ),          // 1 op
			$this->cat( 704 ),          // 1 op
		);

		// Budget 2: category 701 (1) then category 702 (+3 => 4 >= 2) stops AFTER 702.
		$batch = $probe->upsert_category_batch( $categories, 0, 2 );

		$this->assertTrue( $batch['ok'] );
		$this->assertFalse( $batch['done'], 'Two of four categories remain.' );
		$this->assertSame( 2, $batch['next_index'], 'Resume at the third category.' );
		$this->assertSame( array( 701, 702 ), $batch['category_ids'] );
		$this->assertSame( array( 811, 812 ), $batch['tag_ids'] );

		// Resume: the rest drains and reports done.
		$rest = $probe->upsert_category_batch( $categories, $batch['next_index'], 2 );
		$this->assertTrue( $rest['done'] );
		$this->assertSame( 4, $rest['next_index'] );
		$this->assertSame( array( 703, 704 ), $rest['category_ids'] );
	}

	/**
	 * WITHIN-location checkpoint: one location whose categories exceed the slice
	 * budget must checkpoint at a cat_item_index and NOT advance to the next
	 * location, NOT set categories_done, NOT reconcile, NOT start paging. A second
	 * (generous) slice resumes at that cat_item_index and finishes.
	 */
	public function test_large_location_checkpoints_within_itself_then_resumes() {
		// 30 single-upsert categories; default batch (25) drains 25 per batch.
		$big = array();
		for ( $i = 1; $i <= 30; $i++ ) {
			$big[] = $this->cat( 6000 + $i );
		}

		$probe1          = $this->make_probe( $this->make_client() );
		$probe1->advance = 1; // 25 upserts => 25s, over the 18s budget.
		$probe1->lists   = array( '6001' => $big );

		$state  = $this->fresh_state( array( 6001 ) );
		$result = $this->run_slice( $probe1, $state );

		$this->assertTrue( ! empty( $result['in_progress'] ), 'A location too big for one slice hands off as in-progress.' );

		$cursor = Tapgoods_Sync_State::get_instance()->get_cursor();
		$this->assertSame( 0, (int) $cursor['cat_location_index'], 'Still on the SAME location.' );
		$this->assertSame( 25, (int) $cursor['cat_item_index'], 'Checkpointed a within-location position.' );
		$this->assertFalse( $cursor['categories_done'], 'categories_done must NOT be set mid-location.' );
		$this->assertCount( 25, $probe1->upserts, 'Exactly one batch upserted so far.' );
		$this->assertSame( array(), $probe1->term_removals, 'No reconciliation on a partial pass.' );
		$this->assertSame( 0, $probe1->client->inv_calls, 'Paging must not start until categories are done.' );

		// Resume slice: generous budget, finishes the location + the whole run.
		$probe2          = $this->make_probe( $this->make_client() );
		$probe2->advance = 0;
		$probe2->lists   = array( '6001' => $big );
		$result2         = $this->run_slice( $probe2, Tapgoods_Sync_State::get_instance() );

		$this->assertArrayNotHasKey( 'in_progress', $result2, 'The resumed slice completes the run.' );
		// The resume must continue at index 25, upserting only the REMAINING 5.
		$this->assertSame( 5, count( $probe2->upserts ), 'Resume processes only the 5 leftover categories.' );
		// Token-based reconciliation ran once per taxonomy in finalize, after the full pass.
		$this->assertSame( array( 'tg_category', 'tg_tags' ), $this->removed_taxonomies( $probe2 ), 'Reconcile once per taxonomy, in finalize.' );
		$this->assertSame( Tapgoods_Sync_State::STATE_COMPLETED, Tapgoods_Sync_State::get_instance()->get_state() );
	}

	/**
	 * Budget spent after the FIRST location's categories: the slice must checkpoint
	 * at the next location, leave categories_done false, NOT reconcile obsolete
	 * terms, and NOT start paging. The next tick resumes at cat_location_index = 1.
	 */
	public function test_categories_interrupt_defers_reconciliation_and_paging() {
		$probe          = $this->make_probe( $this->make_client() );
		$probe->advance = 25; // each upsert exceeds the 18s slice budget on its own.
		$probe->lists   = array(
			'5001' => array( $this->cat( 701, array( 801, 802 ) ) ),
			'5002' => array( $this->cat( 703 ) ),
			'5003' => array( $this->cat( 704, array( 805 ) ) ),
		);

		$state  = $this->fresh_state( array( 5001, 5002, 5003 ) );
		$result = $this->run_slice( $probe, $state );

		$this->assertTrue( $result['success'] );
		$this->assertTrue( ! empty( $result['in_progress'] ), 'A budget-interrupted categories pass hands off as in-progress.' );

		$cursor = Tapgoods_Sync_State::get_instance()->get_cursor();
		$this->assertSame( 1, (int) $cursor['cat_location_index'], 'Only the first location was processed.' );
		$this->assertSame( 0, (int) $cursor['cat_item_index'], 'Location boundary => within-location index reset.' );
		$this->assertFalse( $cursor['categories_done'], 'categories_done must NOT be set on a partial pass.' );
		$this->assertSame( array( 701 ), $this->upsert_ids_for( $probe, 'tg_category' ), 'Only location 5001 categories upserted so far.' );
		$this->assertSame( array( 801, 802 ), $this->upsert_ids_for( $probe, 'tg_tags' ) );
		$this->assertSame( 'paging', $cursor['phase'], 'Still in the paging phase (categories precede paging).' );

		$this->assertSame( array( '5001' ), $probe->fetched, 'Exactly one location processed this slice.' );
		$this->assertSame( array(), $probe->term_removals, 'Obsolete-term removal must NOT run on a partial pass.' );

		$this->assertSame( 0, $probe->client->inv_calls, 'Paging must not start until categories are done.' );
		$this->assertSame( 0, Tapgoods_Sync_State::get_instance()->get_pages_completed(), 'No page was completed.' );
		$this->assertSame( Tapgoods_Sync_State::STATE_ACTIVE, Tapgoods_Sync_State::get_instance()->get_state() );
	}

	/**
	 * Resuming from a cursor that already did location 0 must continue at the next
	 * location (never reprocess 0), and once every location is collected it must
	 * reconcile obsolete terms in finalize (token-based, once per taxonomy), set
	 * categories_done, then proceed to paging + complete.
	 */
	public function test_categories_resume_reconciles_once_then_pages() {
		$probe          = $this->make_probe( $this->make_client() );
		$probe->advance = 0; // generous budget: the resumed slice finishes categories.
		$probe->lists   = array(
			'5002' => array( $this->cat( 703 ) ),
			'5003' => array( $this->cat( 704, array( 805 ) ) ),
		);

		// Simulate "tick 1 already synced location 5001": seed the checkpoint and
		// stamp 5001's terms with the run token in the shared "DB".
		$state  = $this->fresh_state( array( 5001, 5002, 5003 ) );
		$token  = $this->run_token();
		$this->stamped->map['tg_category'][701] = $token;
		$this->stamped->map['tg_tags'][801]     = $token;
		$this->stamped->map['tg_tags'][802]     = $token;
		$cursor = $state->get_cursor();
		$cursor['cat_location_index'] = 1;
		$state->save_cursor( $cursor );

		$result = $this->run_slice( $probe, $state );

		$this->assertTrue( $result['success'] );
		$this->assertArrayNotHasKey( 'in_progress', $result, 'A generous slice completes the whole run.' );

		// Resume continued at 5002/5003 and never reprocessed 5001.
		$this->assertSame( array( '5002', '5003' ), $probe->fetched );
		$this->assertSame( array( 703, 704 ), $this->upsert_ids_for( $probe, 'tg_category' ) );
		$this->assertSame( array( 805 ), $this->upsert_ids_for( $probe, 'tg_tags' ) );

		// Token-based reconciliation ran once per taxonomy in finalize, with THIS run's token.
		$this->assertSame( array( 'tg_category', 'tg_tags' ), $this->removed_taxonomies( $probe ) );
		foreach ( $probe->term_removals as $r ) {
			$this->assertSame( $token, $r['token'], 'Finalize reconciliation must use the run token that stamped the terms.' );
		}

		// Paging ran only after categories were done, and the run completed.
		$this->assertGreaterThan( 0, $probe->client->inv_calls, 'Paging must run after categories complete.' );
		$this->assertSame( Tapgoods_Sync_State::STATE_COMPLETED, Tapgoods_Sync_State::get_instance()->get_state() );
	}

	/**
	 * CROSS-LOCATION DEDUP (the perf fix): categories overlap heavily across a
	 * storefront's locations. A category id upserted while syncing location A must
	 * NOT be re-upserted when it reappears under B or C. Proven by asserting
	 * tg_insert_or_update_term runs exactly once per UNIQUE source id across all
	 * locations, driven by the run-token stamp rather than an in-cursor set.
	 */
	public function test_categories_are_upserted_once_per_unique_id_across_locations() {
		$probe          = $this->make_probe( $this->make_client() );
		$probe->advance = 0; // generous budget: one slice drains all three locations.
		// 701 (+ sub-tag 801) is shared by ALL three locations; 702 by two; 703/704
		// are unique. Sub-tag 801 also reappears; 802 is unique to location 5003.
		$probe->lists = array(
			'5001' => array( $this->cat( 701, array( 801 ) ), $this->cat( 702 ) ),
			'5002' => array( $this->cat( 701, array( 801 ) ), $this->cat( 702 ), $this->cat( 703 ) ),
			'5003' => array( $this->cat( 701, array( 801 ) ), $this->cat( 704, array( 802 ) ) ),
		);

		$state  = $this->fresh_state( array( 5001, 5002, 5003 ) );
		$result = $this->run_slice( $probe, $state );

		$this->assertTrue( $result['success'] );
		$this->assertArrayNotHasKey( 'in_progress', $result, 'A generous slice completes the run.' );

		// Each UNIQUE category/tag id hit the term DB exactly once, despite appearing
		// under multiple locations.
		$cat_upserts = $this->upsert_ids_for( $probe, 'tg_category' );
		$tag_upserts = $this->upsert_ids_for( $probe, 'tg_tags' );
		$this->assertSame( array( 701, 702, 703, 704 ), $cat_upserts, 'Every unique category upserted exactly once across locations.' );
		$this->assertSame( array( 801, 802 ), $tag_upserts, 'Every unique tag upserted exactly once across locations.' );

		// The dedup skip count is reported in the categories.done log (the completed run
		// clears the cursor, so this is read from the log, not the cursor). Skips are
		// counted at the category level; an already-processed category is not descended
		// into, so its duplicate sub-tags are not separately counted: 701 under 5002,
		// 702 under 5002, 701 under 5003 = 3.
		$log = (string) file_get_contents( Tapgoods_Sync_Log::get_instance()->get_file_path() );
		$this->assertStringContainsString( 'sync.categories.done', $log );
		$this->assertStringContainsString( 'dupes_skipped=3', $log, 'Three duplicate categories skipped across locations.' );

		// DELETION SAFETY preserved: reconciliation runs once per taxonomy in finalize.
		$this->assertSame( array( 'tg_category', 'tg_tags' ), $this->removed_taxonomies( $probe ) );
		$this->assertSame( Tapgoods_Sync_State::STATE_COMPLETED, Tapgoods_Sync_State::get_instance()->get_state() );
	}

	/**
	 * The dedup is durable across ticks THROUGH THE DB stamp (not the cursor): a
	 * category upserted while syncing location A on tick 1 must NOT be re-upserted
	 * when the SAME id appears under location B on tick 2. Modelled here by the
	 * shared stamp map, which stands in for the persisted term meta.
	 */
	public function test_dedup_persists_across_slices() {
		// Tick 1: only location 5001, budget interrupts after it (advance forces a
		// checkpoint at the location boundary).
		$probe1          = $this->make_probe( $this->make_client() );
		$probe1->advance = 25; // each upsert exceeds the 18s slice budget.
		$probe1->lists   = array(
			'5001' => array( $this->cat( 701, array( 801 ) ) ),
			'5002' => array( $this->cat( 701, array( 801 ) ), $this->cat( 702 ) ),
		);
		$state = $this->fresh_state( array( 5001, 5002 ) );
		$this->run_slice( $probe1, $state );

		// Tick 1 upserted 701 + 801 once (location 5001) and checkpointed.
		$this->assertSame( array( 701 ), $this->upsert_ids_for( $probe1, 'tg_category' ) );
		$this->assertSame( array( 801 ), $this->upsert_ids_for( $probe1, 'tg_tags' ) );

		// Tick 2: generous budget, resumes at location 5002. 701/801 must be SKIPPED
		// (already stamped on tick 1 in the shared "DB"); only 702 is new.
		$probe2          = $this->make_probe( $this->make_client() );
		$probe2->advance = 0;
		$probe2->lists   = array(
			'5002' => array( $this->cat( 701, array( 801 ) ), $this->cat( 702 ) ),
		);
		$this->run_slice( $probe2, Tapgoods_Sync_State::get_instance() );

		$this->assertSame( array( 702 ), $this->upsert_ids_for( $probe2, 'tg_category' ), '701 already stamped on tick 1 is not re-upserted.' );
		$this->assertSame( array(), $this->upsert_ids_for( $probe2, 'tg_tags' ), '801 already stamped on tick 1 is not re-upserted.' );
		$this->assertSame( Tapgoods_Sync_State::STATE_COMPLETED, Tapgoods_Sync_State::get_instance()->get_state() );
	}

	/**
	 * upsert_category_batch() in isolation honours the run-token dedup: a source id
	 * whose term already carries the token is skipped (no upsert, still counts a skip),
	 * and a newly upserted id is stamped into the shared map.
	 */
	public function test_upsert_category_batch_skips_already_stamped_ids() {
		$probe = $this->make_probe( $this->make_client() );
		$token = 'tok123';

		// 701 (and its sub-tag 801) already stamped with this run's token.
		$this->stamped->map['tg_category'][701] = $token;
		$this->stamped->map['tg_tags'][801]     = $token;

		$categories = array(
			$this->cat( 701, array( 801 ) ),
			$this->cat( 702 ),
		);

		$batch = $probe->upsert_category_batch( $categories, 0, PHP_INT_MAX, $token );

		$this->assertTrue( $batch['done'] );
		// 701 is skipped at the category level; its subtree (tag 801) is not descended
		// into, so it counts as a single skip. Only 702 is upserted.
		$this->assertSame( array( 702 ), $batch['category_ids'] );
		$this->assertSame( array(), $batch['tag_ids'] );
		$this->assertSame( 1, $batch['skipped'], '701 skipped as a whole category (its subtree is not counted separately).' );
		$this->assertSame( array( 702 ), $this->upsert_ids_for( $probe, 'tg_category' ), 'Only the new category hit the term DB.' );
		// 702 is now stamped in the shared map; 801 untouched.
		$this->assertSame( $token, $this->stamped->map['tg_category'][702] );
	}

	/**
	 * A location whose categories fail to fetch (list === false) is skipped: it must
	 * not contribute ids, but cat_location_index must still advance so the pass
	 * cannot loop forever on the failed location.
	 */
	public function test_failed_location_is_skipped_but_advances_the_index() {
		$probe          = $this->make_probe( $this->make_client() );
		$probe->advance = 0;
		$probe->lists   = array(
			'5001' => array( $this->cat( 701, array( 801 ) ) ),
			'5002' => false, // fetch failure.
			'5003' => array( $this->cat( 704 ) ),
		);

		$state = $this->fresh_state( array( 5001, 5002, 5003 ) );
		$this->run_slice( $probe, $state );

		$this->assertSame( array( '5001', '5002', '5003' ), $probe->fetched, 'Every location was attempted, including the failed one.' );
		$this->assertSame( array( 701, 704 ), $this->upsert_ids_for( $probe, 'tg_category' ), 'The failed location contributes no ids.' );
		$this->assertSame( Tapgoods_Sync_State::STATE_COMPLETED, Tapgoods_Sync_State::get_instance()->get_state() );
	}

	/**
	 * The categories phase emits sync.categories.start exactly once (even though it
	 * spans multiple slices AND is interrupted mid-location), a sync.checkpoint
	 * stamped stage=categories when the budget interrupts it, and
	 * sync.categories.done only when truly complete.
	 */
	public function test_category_lifecycle_log_events() {
		// A single big location, interrupted within itself on slice 1.
		$big = array();
		for ( $i = 1; $i <= 30; $i++ ) {
			$big[] = $this->cat( 7000 + $i );
		}

		$probe1          = $this->make_probe( $this->make_client() );
		$probe1->advance = 1;
		$probe1->lists   = array( '5001' => $big );
		$state           = $this->fresh_state( array( 5001 ) );
		$this->run_slice( $probe1, $state );

		// Slice 2 (resume): generous budget, finishes categories + run.
		$probe2          = $this->make_probe( $this->make_client() );
		$probe2->advance = 0;
		$probe2->lists   = array( '5001' => $big );
		$this->run_slice( $probe2, Tapgoods_Sync_State::get_instance() );

		$log = (string) file_get_contents( Tapgoods_Sync_Log::get_instance()->get_file_path() );

		$this->assertSame( 1, substr_count( $log, ' sync.categories.start ' ), 'The categories phase announces itself exactly once, even across a within-location interrupt.' );
		$this->assertStringContainsString( 'sync.checkpoint', $log );
		$this->assertStringContainsString( 'stage=categories', $log );
		$this->assertStringContainsString( 'cat_item_index=', $log );
		$this->assertStringContainsString( 'sync.categories.done', $log );
	}
}
