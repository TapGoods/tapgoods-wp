<?php
/**
 * Unit tests for the BOUNDED, RESUMABLE categories/tags pass in
 * Tapgoods_Connection::run_sync_slice() (WPB-165 sync-stuck fix).
 *
 * Root cause fixed here: the old one-shot sync_categories_from_api() looped every
 * location, upserted thousands of terms, then removed obsolete ones, all in a
 * single blocking request. On a host with a request-time limit that request was
 * killed before categories_done was ever set, so paging never began and every
 * cron tick redid categories and was killed again (0/N forever).
 *
 * The fix mirrors the bounded paging loop at TWO granularities:
 *   - across locations (cat_location_index), and
 *   - WITHIN a location (cat_item_index): categories are upserted in bounded
 *     batches and checkpointed, so one large location can no longer exceed the
 *     request window before its first checkpoint.
 * Obsolete-term reconciliation is deferred until EVERY location has been collected
 * (the full accumulated valid-id set), never on a partial pass.
 *
 * These tests drive the private run_sync_slice() via reflection against a probe
 * (an anonymous subclass) that stubs the term-DB seams so the loop's orchestration
 * is asserted in isolation:
 *   - categories resume across slices at the right cat_location_index / cat_item_index,
 *   - categories_done is set only after all locations,
 *   - obsolete-term removal runs once, with the full id set, never on a partial pass,
 *   - paging does not start until categories are done.
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

	/** @var string Temp directory the logger writes into. */
	private $log_dir;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		$this->store = array();
		$store       = &$this->store;

		$this->clock   = (object) array( 't' => 1000 );
		$clock         = $this->clock;
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
	 * term-DB seams the categories loop depends on. get_location_categories_cached()
	 * serves canned lists and tg_insert_or_update_term() returns the record's tg_id
	 * (advancing the fake clock per upsert), so the REAL bounded-batch loop
	 * (upsert_category_batch + run_sync_slice) is exercised without WordPress.
	 */
	private function make_probe( $client ) {
		$this->inject_client( $client );

		$probe         = new class( $this->clock ) extends Tapgoods_Connection {
			public $clock;
			public $advance = 0;                // seconds added per term upsert.
			public $lists   = array();          // lid(string) => array|false category list.
			public $fetched = array();          // lids passed to get_location_categories_cached.
			public $upserts = array();          // [{tax, id}] upsert calls, in order.
			public $obsolete_calls = array();   // [{taxonomy, ids}].
			public $client;                     // fake client (for inv_calls).

			// Note: intentionally does NOT call the private parent constructor.
			public function __construct( $clock ) {
				$this->clock = $clock;
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
			public function tg_insert_or_update_term( $term, $tax, $parent = null ) {
				$this->clock->t  += $this->advance;
				$this->upserts[] = array( 'tax' => $tax, 'id' => $term['id'] );
				return $term['id'];
			}
			public function remove_obsolete_terms( $taxonomy, $valid_ids ) {
				$this->obsolete_calls[] = array(
					'taxonomy' => $taxonomy,
					'ids'      => array_values( (array) $valid_ids ),
				);
			}
			// Neutralise the finalize machinery so a completing slice never hits the DB.
			public function get_all_existing_inventory_ids() {
				return array();
			}
			public function remove_missing_items_from_wordpress( $existing_items, $synced_items ) {
				return 0;
			}
			public function remove_unused_terms( $taxonomy ) {
				return 0;
			}
			public function remove_duplicate_items() {
				return 0;
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

	// -------------------------------------------------------------------------

	/**
	 * upsert_category_batch() in isolation: it processes at most one budget's worth
	 * of upserts, stops on a whole-category boundary, and reports the resume point.
	 */
	public function test_upsert_category_batch_is_bounded_and_reports_resume_point() {
		$probe = $this->make_probe( $this->make_client() );

		$categories = array(
			$this->cat( 701 ),          // 1 upsert
			$this->cat( 702, array( 811, 812 ) ), // 3 upserts (cat + 2 tags)
			$this->cat( 703 ),          // 1 upsert
			$this->cat( 704 ),          // 1 upsert
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
		$this->assertCount( 25, $cursor['valid_category_ids'], 'Exactly one batch of ids accumulated so far.' );
		$this->assertSame( array(), $probe1->obsolete_calls, 'No reconciliation on a partial pass.' );
		$this->assertSame( 0, $probe1->client->inv_calls, 'Paging must not start until categories are done.' );

		// Resume slice: generous budget, finishes the location + the whole run.
		$probe2          = $this->make_probe( $this->make_client() );
		$probe2->advance = 0;
		$probe2->lists   = array( '6001' => $big );
		$result2         = $this->run_slice( $probe2, Tapgoods_Sync_State::get_instance() );

		$this->assertArrayNotHasKey( 'in_progress', $result2, 'The resumed slice completes the run.' );
		// The resume must continue at index 25, upserting only the REMAINING 5.
		$this->assertSame( 5, count( $probe2->upserts ), 'Resume processes only the 5 leftover categories.' );
		// Reconciliation ran exactly once per taxonomy, only after the location finished.
		$this->assertCount( 2, $probe2->obsolete_calls, 'Reconcile once per taxonomy, after the whole location is collected.' );
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
		$this->assertSame( array( 701 ), $cursor['valid_category_ids'], 'Only location 5001 category ids accumulated so far.' );
		$this->assertSame( array( 801, 802 ), $cursor['valid_tag_ids'] );
		$this->assertSame( 'paging', $cursor['phase'], 'Still in the paging phase (categories precede paging).' );

		$this->assertSame( array( '5001' ), $probe->fetched, 'Exactly one location processed this slice.' );
		$this->assertSame( array(), $probe->obsolete_calls, 'Obsolete-term removal must NOT run on a partial pass.' );

		$this->assertSame( 0, $probe->client->inv_calls, 'Paging must not start until categories are done.' );
		$this->assertSame( 0, Tapgoods_Sync_State::get_instance()->get_pages_completed(), 'No page was completed.' );
		$this->assertSame( Tapgoods_Sync_State::STATE_ACTIVE, Tapgoods_Sync_State::get_instance()->get_state() );
	}

	/**
	 * Resuming from a cursor that already did location 0 must continue at the next
	 * location (never reprocess 0), and once every location is collected it must
	 * reconcile obsolete terms EXACTLY ONCE per taxonomy against the FULL
	 * accumulated id set, set categories_done, then proceed to paging + complete.
	 */
	public function test_categories_resume_reconciles_once_with_full_set_then_pages() {
		$probe          = $this->make_probe( $this->make_client() );
		$probe->advance = 0; // generous budget: the resumed slice finishes categories.
		$probe->lists   = array(
			'5002' => array( $this->cat( 703 ) ),
			'5003' => array( $this->cat( 704, array( 805 ) ) ),
		);

		// Simulate "tick 1 already synced location 5001": seed the checkpoint.
		$state  = $this->fresh_state( array( 5001, 5002, 5003 ) );
		$cursor = $state->get_cursor();
		$cursor['cat_location_index'] = 1;
		$cursor['valid_category_ids'] = array( 701 );
		$cursor['valid_tag_ids']      = array( 801, 802 );
		$state->save_cursor( $cursor );

		$result = $this->run_slice( $probe, $state );

		$this->assertTrue( $result['success'] );
		$this->assertArrayNotHasKey( 'in_progress', $result, 'A generous slice completes the whole run.' );

		// Resume continued at 5002/5003 and never reprocessed 5001.
		$this->assertSame( array( '5002', '5003' ), $probe->fetched );

		// Reconciliation ran once per taxonomy, with the FULL accumulated set.
		$this->assertCount( 2, $probe->obsolete_calls, 'Exactly one reconciliation per taxonomy.' );
		$by_tax = array();
		foreach ( $probe->obsolete_calls as $call ) {
			$by_tax[ $call['taxonomy'] ] = $call['ids'];
		}
		$this->assertSame( array( 701, 703, 704 ), $by_tax['tg_category'], 'Full accumulated category set across all locations.' );
		$this->assertSame( array( 801, 802, 805 ), $by_tax['tg_tags'], 'Full accumulated tag set across all locations.' );

		// Paging ran only after categories were done, and the run completed.
		$this->assertGreaterThan( 0, $probe->client->inv_calls, 'Paging must run after categories complete.' );
		$this->assertSame( Tapgoods_Sync_State::STATE_COMPLETED, Tapgoods_Sync_State::get_instance()->get_state() );
	}

	/**
	 * CROSS-LOCATION DEDUP (the perf fix): categories overlap heavily across a
	 * storefront's locations. A category id upserted while syncing location A must
	 * NOT be re-upserted when it reappears under B or C. Proven by asserting
	 * tg_insert_or_update_term runs exactly once per UNIQUE source id across all
	 * locations, while the accumulators (and the obsolete-term reconciliation) still
	 * see the FULL unique set.
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

		// The upsert count equals the unique count, NOT locations x categories: 701
		// appears 3x, 702 2x, 801 3x in the input, but each is written once.
		$this->assertSame( 4, count( $cat_upserts ) );
		$this->assertSame( 2, count( $tag_upserts ) );

		// The dedup skip count is reported in the categories.done log (the completed run
		// clears the cursor, so this is read from the log, not the cursor). Skips are
		// counted at the category level; an already-processed category is not descended
		// into, so its duplicate sub-tags are not separately counted: 701 under 5002,
		// 702 under 5002, 701 under 5003 = 3.
		$log = (string) file_get_contents( Tapgoods_Sync_Log::get_instance()->get_file_path() );
		$this->assertStringContainsString( 'sync.categories.done', $log );
		$this->assertStringContainsString( 'dupes_skipped=3', $log, 'Three duplicate categories skipped across locations.' );

		// DELETION SAFETY preserved: reconciliation runs once per taxonomy against the
		// FULL unique valid-id set (the probe returns tg_id as term_id, so the term ids
		// equal the source ids here).
		$by_tax = array();
		foreach ( $probe->obsolete_calls as $call ) {
			$by_tax[ $call['taxonomy'] ] = $call['ids'];
		}
		$this->assertCount( 2, $probe->obsolete_calls, 'Reconcile once per taxonomy, after all locations.' );
		$this->assertSame( array( 701, 702, 703, 704 ), $by_tax['tg_category'], 'Reconcile sees the full unique category set.' );
		$this->assertSame( array( 801, 802 ), $by_tax['tg_tags'], 'Reconcile sees the full unique tag set.' );
		$this->assertSame( Tapgoods_Sync_State::STATE_COMPLETED, Tapgoods_Sync_State::get_instance()->get_state() );
	}

	/**
	 * The dedup set is durable across ticks: a category upserted while syncing
	 * location A on tick 1 must NOT be re-upserted when the SAME id appears under
	 * location B on tick 2. The processed sets persist in the cursor between slices.
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
		$state  = $this->fresh_state( array( 5001, 5002 ) );
		$this->run_slice( $probe1, $state );

		// Tick 1 upserted 701 + 801 once (location 5001) and checkpointed.
		$this->assertSame( array( 701 ), $this->upsert_ids_for( $probe1, 'tg_category' ) );
		$this->assertSame( array( 801 ), $this->upsert_ids_for( $probe1, 'tg_tags' ) );
		$cursor = Tapgoods_Sync_State::get_instance()->get_cursor();
		$this->assertSame( array( 701 ), array_keys( $cursor['processed_category_ids'] ) );
		$this->assertSame( array( 801 ), array_keys( $cursor['processed_tag_ids'] ) );

		// Tick 2: generous budget, resumes at location 5002. 701/801 must be SKIPPED
		// (already processed on tick 1); only 702 is new.
		$probe2          = $this->make_probe( $this->make_client() );
		$probe2->advance = 0;
		$probe2->lists   = array(
			'5002' => array( $this->cat( 701, array( 801 ) ), $this->cat( 702 ) ),
		);
		$this->run_slice( $probe2, Tapgoods_Sync_State::get_instance() );

		$this->assertSame( array( 702 ), $this->upsert_ids_for( $probe2, 'tg_category' ), '701 already processed on tick 1 is not re-upserted.' );
		$this->assertSame( array(), $this->upsert_ids_for( $probe2, 'tg_tags' ), '801 already processed on tick 1 is not re-upserted.' );

		// Reconciliation on the resumed slice sees the FULL set (701 from tick 1 + 702).
		$by_tax = array();
		foreach ( $probe2->obsolete_calls as $call ) {
			$by_tax[ $call['taxonomy'] ] = $call['ids'];
		}
		$this->assertSame( array( 701, 702 ), $by_tax['tg_category'], 'Full accumulated set across ticks.' );
		$this->assertSame( array( 801 ), $by_tax['tg_tags'] );
		$this->assertSame( Tapgoods_Sync_State::STATE_COMPLETED, Tapgoods_Sync_State::get_instance()->get_state() );
	}

	/**
	 * upsert_category_batch() in isolation honours the by-ref processed sets: a source
	 * id already in the set is skipped (no upsert, not counted against budget, no id
	 * returned), and a newly upserted id is recorded into the set.
	 */
	public function test_upsert_category_batch_skips_already_processed_ids() {
		$probe = $this->make_probe( $this->make_client() );

		$categories = array(
			$this->cat( 701, array( 801 ) ),
			$this->cat( 702 ),
		);

		$processed_cat = array( 701 => true ); // 701 already processed earlier this run.
		$processed_tag = array( 801 => true ); // 801 already processed earlier this run.

		$batch = $probe->upsert_category_batch( $categories, 0, PHP_INT_MAX, $processed_cat, $processed_tag );

		$this->assertTrue( $batch['done'] );
		// 701 is skipped at the category level; its subtree (tag 801) is not descended
		// into, so it counts as a single skip. Only 702 is upserted.
		$this->assertSame( array( 702 ), $batch['category_ids'] );
		$this->assertSame( array(), $batch['tag_ids'] );
		$this->assertSame( 1, $batch['skipped'], '701 skipped as a whole category (its subtree is not counted separately).' );
		$this->assertSame( array( 702 ), $this->upsert_ids_for( $probe, 'tg_category' ), 'Only the new category hit the term DB.' );
		// 702 is now recorded in the processed set (by reference); 801 untouched.
		$this->assertSame( array( 701, 702 ), array_keys( $processed_cat ) );
		$this->assertSame( array( 801 ), array_keys( $processed_tag ) );
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

		$by_tax = array();
		foreach ( $probe->obsolete_calls as $call ) {
			$by_tax[ $call['taxonomy'] ] = $call['ids'];
		}
		$this->assertSame( array( 701, 704 ), $by_tax['tg_category'], 'The failed location contributes no ids.' );
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
