<?php
/**
 * Location settings are synced first in a sync run when the site is missing them.
 *
 * Location data (tg_locationIds, tg_businessId, tg_location_{id},
 * tg_location_settings, tapgreino_default_location) used to be written only by
 * the admin Sync button. A site whose catalog was filled by background slices
 * (connect a key, let cron and Action Scheduler run) ended up with every item and
 * no locations: no location picker, no default location, no storefront colours
 * or cart domain.
 *
 * Pinned here:
 *  - the pure decision (location_sync_action) for every stored-data shape;
 *  - a run fills missing location data before it fetches categories or pages
 *    any inventory, and costs no API call when the data is already there;
 *  - a location failure never stops the catalog sync, and is tried once per run,
 *    not once per slice;
 *  - the location step is bounded by the slice clock, inside its loop;
 *  - sync_location_settings() never deletes stored data before new data arrives
 *    (a forced refresh used to delete the stored ids first, and wrote back
 *    whatever it fetched, so one failed request emptied the location data).
 *
 * Drives the private run_sync_slice() via reflection against a probe, the same
 * way ConnectionSyncCategoriesTest does. Isolated (no WordPress) via Brain\Monkey.
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

final class ConnectionLocationSyncTest extends TestCase {

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
		$this->log_dir = sys_get_temp_dir() . '/tg-locs-' . uniqid( '', true );
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
		Functions\when( 'delete_option' )->alias(
			static function ( $key ) use ( &$store ) {
				unset( $store[ $key ] );
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

		foreach ( array( Tapgoods_Connection::class, Tapgoods_Sync_State::class, Tapgoods_Sync_Log::class ) as $class ) {
			$this->set_singleton( $class, null );
		}
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

	private function set_singleton( $class, $value ): void {
		$ref  = new ReflectionClass( $class );
		$prop = $ref->getProperty( 'instance' );
		$prop->setValue( null, $value );
	}

	/**
	 * A fake API client that records every call, in order, in $calls. Calls named
	 * in $advance_on move the fake clock forward, to simulate a slow host.
	 */
	private function make_client() {
		return new class( $this->clock ) {
			/** @var string[] Every call, in order. */
			public $calls = array();
			/** @var array|false What validate_key() answers (false = API failure). */
			public $business = array(
				'businessId'  => 1042,
				'locationIds' => array( 5001, 5002 ),
			);
			/** @var array lid => true for locations whose details fail. */
			public $details_fail = array();
			/** @var array call name => seconds the clock moves when it is made. */
			public $advance_on = array();
			/** @var string Prefix for location names, to tell two fetches apart. */
			public $name_prefix = 'Location';
			public $clock;

			public function __construct( $clock ) {
				$this->clock = $clock;
			}
			public function hit( $name ) {
				$this->calls[] = $name;
				if ( isset( $this->advance_on[ $name ] ) ) {
					$this->clock->t += $this->advance_on[ $name ];
				}
			}
			public function validate_key() {
				$this->hit( 'validate_key' );
				return $this->business;
			}
			public function get_location_ids() {
				$this->hit( 'location_ids' );
				return ( false === $this->business ) ? false : $this->business['locationIds'];
			}
			public function get_location_details_from_graph( $lid, $details = array() ) {
				$this->hit( "details:$lid" );
				if ( ! empty( $this->details_fail[ $lid ] ) ) {
					return false;
				}
				return array(
					'id'              => $lid,
					'name'            => "{$this->name_prefix} $lid",
					'physicalAddress' => array(
						'city'        => 'Austin',
						'locale'      => 'TX',
						'fullAddress' => '100 Main St',
					),
				);
			}
			public function transient_name( $name ) {
				return 'tg_' . $name;
			}
			public function get_categories_from_graph( $lid, $keyword = false ) {
				return array();
			}
			public function get_inventories_from_graph( $lid, $page = 1, $size = 25 ) {
				$this->hit( "inventories:$lid" );
				return array(
					'collection' => array(),
					'metadata'   => array( 'totalPages' => 1 ),
				);
			}
			public function get_last_http_code() {
				return 200;
			}
		};
	}

	/**
	 * A Tapgoods_Connection whose category fetch records into the client's call log
	 * and whose finalize seams touch no database, so a whole slice runs in isolation.
	 */
	private function make_probe( $client ) {
		Functions\when( 'apply_filters' )->alias(
			static function ( $tag, $value = null ) use ( $client ) {
				return ( 'tapgoods_api_client' === $tag ) ? $client : $value;
			}
		);

		return new class( $client ) extends Tapgoods_Connection {
			public $fake;

			// Intentionally does NOT call the private parent constructor.
			public function __construct( $fake ) {
				$this->fake = $fake;
			}
			public function get_location_categories_cached( $lid ) {
				$this->fake->hit( "categories:$lid" );
				return array();
			}
			public function clear_location_categories_cache( $lid ) {}
			public function remove_items_not_in_run( $run_token, $limit ) {
				return 0;
			}
			public function remove_terms_not_in_run( $taxonomy, $run_token, $limit ) {
				return 0;
			}
			public function remove_unused_terms_batch( $taxonomy, $after_term_id, $limit ) {
				return array( 'removed' => 0, 'last_term_id' => (int) $after_term_id, 'exhausted' => true );
			}
			public function remove_duplicate_items_batch( $after_value, $group_limit ) {
				return array( 'removed' => 0, 'cursor' => (string) $after_value, 'exhausted' => true );
			}
			public function update_sync_info( $start_time ) {}
		};
	}

	private function run_slice( $probe, Tapgoods_Sync_State $state ) {
		$method = new ReflectionMethod( Tapgoods_Connection::class, 'run_sync_slice' );
		return $method->invoke( $probe, $state );
	}

	private function fresh_state( array $location_ids ): Tapgoods_Sync_State {
		$state = Tapgoods_Sync_State::get_instance();
		$state->begin_prep( 2 )->mark_active();
		$state->init_cursor( $location_ids );
		return $state;
	}

	/** Location data exactly as a completed sync_location_settings() leaves it. */
	private function seed_complete_location_data(): void {
		$this->store['tg_locationIds']             = array( 5001, 5002 );
		$this->store['tg_businessId']              = 1042;
		$this->store['tg_location_settings']       = array(
			5001 => array( 'id' => 5001, 'name' => 'Old 5001' ),
			5002 => array( 'id' => 5002, 'name' => 'Old 5002' ),
		);
		$this->store['tapgreino_default_location'] = 5001;
	}

	/** Position of the first call matching $prefix, or -1. */
	private function first_index( array $calls, string $prefix ): int {
		foreach ( $calls as $i => $call ) {
			if ( 0 === strpos( $call, $prefix ) ) {
				return $i;
			}
		}
		return -1;
	}

	/** Position of the last call matching $prefix, or -1. */
	private function last_index( array $calls, string $prefix ): int {
		$found = -1;
		foreach ( $calls as $i => $call ) {
			if ( 0 === strpos( $call, $prefix ) ) {
				$found = $i;
			}
		}
		return $found;
	}

	private function count_calls( array $calls, string $prefix ): int {
		$n = 0;
		foreach ( $calls as $call ) {
			if ( 0 === strpos( $call, $prefix ) ) {
				++$n;
			}
		}
		return $n;
	}

	private function log_contents(): string {
		$contents = Tapgoods_Sync_Log::get_instance()->read_all();
		return ( false === $contents ) ? '' : $contents;
	}

	// --- The decision ----------------------------------------------------------

	public function test_nothing_stored_means_refresh() {
		$this->assertSame( 'refresh', Tapgoods_Connection::location_sync_action( array( 5001, 5002 ), false, false, false ) );
		$this->assertSame( 'refresh', Tapgoods_Connection::location_sync_action( array( 5001, 5002 ), array(), array(), '' ) );
	}

	public function test_ids_from_another_business_mean_refresh() {
		// The key now belongs to another business: the stored ids are the old one's.
		$this->assertSame( 'refresh', Tapgoods_Connection::location_sync_action( array( 5001, 5002 ), array( 9001 ), array( 9001 => array( 'id' => 9001 ) ), 9001 ) );
		// A location the business added.
		$this->assertSame( 'refresh', Tapgoods_Connection::location_sync_action( array( 5001, 5002 ), array( 5001 ), array( 5001 => array( 'id' => 5001 ) ), 5001 ) );
		// A location the business removed: the stored list would keep offering it.
		$this->assertSame( 'refresh', Tapgoods_Connection::location_sync_action( array( 5001 ), array( 5001, 5002 ), array( 5001 => array( 'id' => 5001 ) ), 5001 ) );
	}

	public function test_missing_settings_or_default_mean_fill() {
		$ids = array( 5001, 5002 );
		$all = array(
			5001 => array( 'id' => 5001 ),
			5002 => array( 'id' => 5002 ),
		);

		// The case the bug left behind: ids stored, settings empty.
		$this->assertSame( 'fill', Tapgoods_Connection::location_sync_action( $ids, $ids, array(), 5001 ) );
		$this->assertSame( 'fill', Tapgoods_Connection::location_sync_action( $ids, $ids, '', 5001 ) );
		// One location's settings missing.
		$this->assertSame( 'fill', Tapgoods_Connection::location_sync_action( $ids, $ids, array( 5001 => array( 'id' => 5001 ) ), 5001 ) );
		// No default location, or one that is not this business's.
		$this->assertSame( 'fill', Tapgoods_Connection::location_sync_action( $ids, $ids, $all, '' ) );
		$this->assertSame( 'fill', Tapgoods_Connection::location_sync_action( $ids, $ids, $all, 9001 ) );
	}

	public function test_complete_data_means_none() {
		$all = array(
			5001 => array( 'id' => 5001 ),
			5002 => array( 'id' => 5002 ),
		);
		$this->assertSame( 'none', Tapgoods_Connection::location_sync_action( array( 5001, 5002 ), array( 5001, 5002 ), $all, 5001 ) );

		// The cursor holds ids as strings, the options as ints: same locations.
		$this->assertSame( 'none', Tapgoods_Connection::location_sync_action( array( '5001', '5002' ), array( 5002, 5001 ), $all, '5002' ) );

		// A run with no locations has nothing to compare against.
		$this->assertSame( 'none', Tapgoods_Connection::location_sync_action( array(), false, false, false ) );
	}

	// --- In a run ----------------------------------------------------------------

	public function test_empty_location_data_is_synced_before_categories_and_inventory() {
		$client = $this->make_client();
		$probe  = $this->make_probe( $client );
		$state  = $this->fresh_state( array( 5001, 5002 ) );

		$result = $this->run_slice( $probe, $state );

		$this->assertTrue( $result['success'] );
		$this->assertSame( Tapgoods_Sync_State::STATE_COMPLETED, $state->get_state() );

		// Every piece of location data the storefront reads is now there.
		$this->assertSame( array( 5001, 5002 ), $this->store['tg_locationIds'] );
		$this->assertSame( 1042, $this->store['tg_businessId'] );
		$this->assertSame( array( 5001, 5002 ), array_keys( $this->store['tg_location_settings'] ) );
		$this->assertSame( 'Location 5002', $this->store['tg_location_settings'][5002]['name'] );
		$this->assertSame( 'Location 5001', $this->store['tg_location_5001']['name'] );
		$this->assertSame( 5001, $this->store['tapgreino_default_location'] );

		// And it came first: every location call precedes the first category fetch
		// and the first inventory page.
		$calls        = $client->calls;
		$last_details = $this->last_index( $calls, 'details:' );
		$this->assertSame( 0, $this->first_index( $calls, 'validate_key' ), 'The business is read first.' );
		$this->assertGreaterThan( -1, $last_details );
		$this->assertGreaterThan( $last_details, $this->first_index( $calls, 'categories:' ) );
		$this->assertGreaterThan( $last_details, $this->first_index( $calls, 'inventories:' ) );

		$this->assertStringContainsString( 'sync.location.missing', $this->log_contents() );
		$this->assertStringContainsString( 'action=refresh', $this->log_contents() );
	}

	public function test_complete_location_data_costs_no_api_call() {
		$this->seed_complete_location_data();
		$before = $this->store['tg_location_settings'];

		$client = $this->make_client();
		$probe  = $this->make_probe( $client );
		$state  = $this->fresh_state( array( 5001, 5002 ) );

		$result = $this->run_slice( $probe, $state );

		$this->assertTrue( $result['success'] );
		$this->assertSame( 0, $this->count_calls( $client->calls, 'validate_key' ) );
		$this->assertSame( 0, $this->count_calls( $client->calls, 'details:' ) );
		$this->assertSame( $before, $this->store['tg_location_settings'], 'Nothing is rewritten.' );
		$this->assertStringNotContainsString( 'sync.location.', $this->log_contents() );
	}

	public function test_only_the_missing_kind_of_refresh_is_done() {
		// Ids match, settings were emptied: fill from the stored ids without asking
		// the API for the business again.
		$this->seed_complete_location_data();
		$this->store['tg_location_settings'] = array();

		$client = $this->make_client();
		$probe  = $this->make_probe( $client );
		$state  = $this->fresh_state( array( 5001, 5002 ) );

		$this->run_slice( $probe, $state );

		$this->assertSame( 0, $this->count_calls( $client->calls, 'validate_key' ) );
		$this->assertSame( 2, $this->count_calls( $client->calls, 'details:' ) );
		$this->assertSame( array( 5001, 5002 ), array_keys( $this->store['tg_location_settings'] ) );
	}

	public function test_a_key_for_another_business_replaces_its_locations() {
		$this->store['tg_locationIds']             = array( 9001 );
		$this->store['tg_businessId']              = 77;
		$this->store['tg_location_settings']       = array( 9001 => array( 'id' => 9001, 'name' => 'Other business' ) );
		$this->store['tapgreino_default_location'] = 9001;

		$client = $this->make_client();
		$probe  = $this->make_probe( $client );
		$state  = $this->fresh_state( array( 5001, 5002 ) );

		$this->run_slice( $probe, $state );

		$this->assertSame( array( 5001, 5002 ), $this->store['tg_locationIds'] );
		$this->assertSame( 1042, $this->store['tg_businessId'] );
		$this->assertSame( array( 5001, 5002 ), array_keys( $this->store['tg_location_settings'] ), 'The old business\'s location is dropped.' );
		$this->assertSame( 5001, $this->store['tapgreino_default_location'] );
	}

	public function test_a_location_failure_does_not_stop_the_catalog() {
		$client           = $this->make_client();
		$client->business = false; // The business lookup fails.
		$probe            = $this->make_probe( $client );
		$state            = $this->fresh_state( array( 5001, 5002 ) );

		$result = $this->run_slice( $probe, $state );

		$this->assertTrue( $result['success'] );
		$this->assertSame( Tapgoods_Sync_State::STATE_COMPLETED, $state->get_state() );
		$this->assertSame( 2, $this->count_calls( $client->calls, 'inventories:' ), 'Both locations were still paged.' );
		$this->assertArrayNotHasKey( 'tg_location_settings', $this->store );
		$this->assertStringContainsString( 'sync.location.error', $this->log_contents() );
	}

	public function test_an_exception_from_the_location_api_does_not_stop_the_catalog() {
		$client = new class( $this->clock ) {
			public $calls = array();
			public function __construct( $clock ) {}
			public function hit( $name ) {
				$this->calls[] = $name;
			}
			public function validate_key() {
				throw new \Exception( 'http_request_failed' );
			}
			public function get_inventories_from_graph( $lid, $page = 1, $size = 25 ) {
				$this->hit( "inventories:$lid" );
				return array( 'collection' => array(), 'metadata' => array( 'totalPages' => 1 ) );
			}
			public function get_last_http_code() {
				return null;
			}
		};
		$probe = $this->make_probe( $client );
		$state = $this->fresh_state( array( 5001, 5002 ) );

		$result = $this->run_slice( $probe, $state );

		$this->assertTrue( $result['success'] );
		$this->assertSame( Tapgoods_Sync_State::STATE_COMPLETED, $state->get_state() );
		$this->assertSame( 2, $this->count_calls( $client->calls, 'inventories:' ) );
		$this->assertStringContainsString( 'reason=exception', $this->log_contents() );
	}

	public function test_the_location_step_is_bounded_by_the_clock_and_runs_once_per_run() {
		$client = $this->make_client();
		// The first location's details take longer than the whole slice budget.
		$client->advance_on['details:5001'] = Tapgoods_Connection::sync_time_budget() + 2;
		$probe = $this->make_probe( $client );
		$state = $this->fresh_state( array( 5001, 5002 ) );

		// Slice 1: the clock is checked inside the location loop, so 5002 is not
		// fetched, and the slice stops before categories.
		$first = $this->run_slice( $probe, $state );

		$this->assertTrue( $first['success'] );
		$this->assertTrue( $first['in_progress'] );
		$this->assertSame( array( 'validate_key', 'details:5001' ), $client->calls );
		$this->assertSame( array( 5001 ), array_keys( $this->store['tg_location_settings'] ), 'What was fetched is stored.' );
		$this->assertTrue( $state->get_cursor()['locations_done'] );
		$this->assertStringContainsString( 'sync.location.deadline', $this->log_contents() );
		$this->assertStringContainsString( 'after=locations', $this->log_contents() );

		// Slice 2 of the same run: no location call again, straight to the catalog.
		$client->calls = array();
		$second        = $this->run_slice( $probe, $state );

		$this->assertTrue( $second['success'] );
		$this->assertSame( Tapgoods_Sync_State::STATE_COMPLETED, $state->get_state() );
		$this->assertSame( 0, $this->count_calls( $client->calls, 'validate_key' ) );
		$this->assertSame( 0, $this->count_calls( $client->calls, 'details:' ) );
		$this->assertSame( 2, $this->count_calls( $client->calls, 'inventories:' ) );
	}

	public function test_a_run_already_in_flight_fills_location_data_on_its_next_slice() {
		// A cursor written before this change has no locations_done flag, and may be
		// well into paging. Its next slice fills the location data and carries on.
		$client = $this->make_client();
		$probe  = $this->make_probe( $client );
		$state  = $this->fresh_state( array( 5001, 5002 ) );

		$cursor = $state->get_cursor();
		unset( $cursor['locations_done'] );
		$cursor['categories_done'] = true;
		$cursor['location_index']  = 1;
		$state->save_cursor( $cursor );

		$result = $this->run_slice( $probe, $state );

		$this->assertTrue( $result['success'] );
		$this->assertSame( array( 5001, 5002 ), array_keys( $this->store['tg_location_settings'] ) );
		$this->assertSame( array( 'inventories:5002' ), array_values( array_filter( $client->calls, static function ( $c ) {
			return 0 === strpos( $c, 'inventories:' );
		} ) ), 'Paging resumed where it was.' );
	}

	// --- sync_location_settings() never deletes before new data arrives ----------

	public function test_a_forced_refresh_that_cannot_reach_the_business_keeps_stored_locations() {
		$this->seed_complete_location_data();
		$before = $this->store;

		$client           = $this->make_client();
		$client->business = false;
		$probe            = $this->make_probe( $client );

		// The Sync button path.
		$this->assertFalse( $probe->sync_location_settings( true ) );

		foreach ( array( 'tg_locationIds', 'tg_businessId', 'tg_location_settings', 'tapgreino_default_location' ) as $key ) {
			$this->assertSame( $before[ $key ], $this->store[ $key ], "$key must survive a failed refresh." );
		}
	}

	public function test_a_pass_that_fetches_no_details_writes_nothing() {
		$this->seed_complete_location_data();
		$before = $this->store['tg_location_settings'];

		$client               = $this->make_client();
		$client->details_fail = array( 5001 => true, 5002 => true );
		$probe                = $this->make_probe( $client );

		$this->assertFalse( $probe->sync_location_settings( true ) );
		$this->assertSame( $before, $this->store['tg_location_settings'] );
	}

	public function test_a_location_whose_details_fail_keeps_its_previous_settings() {
		$this->seed_complete_location_data();

		$client               = $this->make_client();
		$client->name_prefix  = 'New';
		$client->details_fail = array( 5002 => true );
		$probe                = $this->make_probe( $client );

		$this->assertTrue( $probe->sync_location_settings( true ) );

		$settings = $this->store['tg_location_settings'];
		$this->assertSame( array( 5001, 5002 ), array_keys( $settings ), 'Business order kept.' );
		$this->assertSame( 'New 5001', $settings[5001]['name'] );
		$this->assertSame( 'Old 5002', $settings[5002]['name'], 'Not replaced by nothing.' );
	}
}
