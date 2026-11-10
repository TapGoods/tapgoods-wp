<?php
/**
 * The API-key guard and the Sync button's error clearing.
 *
 * Pins two behaviours a live site's sync log showed going wrong (Nov 2026):
 *
 *  1. The key-change wipe deleted the whole catalog on runs that could not sync.
 *     It ran before the error-latch check and before the run lock, and it treated
 *     a saved key the request could not decrypt (get_key() === false) as a new
 *     key. false cannot be stored back as an option, so EVERY later run saw a
 *     "change" and deleted the catalog again, then failed with a 401.
 *  2. A latched ERROR could only be cleared by the "Clear Errors" button. Clicking
 *     Sync queued a slice that the latch refused, so it did nothing; the site sat
 *     un-synced for three and a half days after a ten-minute API outage.
 *
 * Isolated (no WordPress) via Brain\Monkey, with a real Tapgoods_Sync_Log in a
 * temp directory so the log lines can be asserted too.
 *
 * @package Tapgoods\Tests
 */

namespace Tapgoods\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Tapgoods_Connection;
use Tapgoods_Encryption;
use Tapgoods_Sync_Log;
use Tapgoods_Sync_State;

final class ConnectionKeyGuardTest extends TestCase {

	/** @var array In-memory option store. */
	private $store = array();

	/** @var bool Whether tapgrein_delete_data() ran (it starts with get_posts()). */
	private $wiped = false;

	/** @var int|false What get_transient() returns: 1 = run lock held. */
	private $lock = false;

	/** @var string Temp directory the logger writes into. */
	private $log_dir;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		$this->store = array();
		$this->wiped = false;
		$this->lock  = false;
		$store       = &$this->store;
		$wiped       = &$this->wiped;
		$lock        = &$this->lock;

		$this->log_dir = sys_get_temp_dir() . '/tg-keyguard-' . uniqid( '', true );
		mkdir( $this->log_dir, 0777, true );

		Functions\when( 'add_action' )->justReturn( true );
		Functions\when( 'add_filter' )->justReturn( true );
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
		Functions\when( 'current_time' )->justReturn( 1000 );

		Functions\when( 'get_transient' )->alias(
			static function () use ( &$lock ) {
				return $lock;
			}
		);
		Functions\when( 'set_transient' )->justReturn( true );
		Functions\when( 'delete_transient' )->justReturn( true );

		// tapgrein_delete_data(): get_posts() is its first call, so it is the spy.
		Functions\when( 'get_posts' )->alias(
			static function () use ( &$wiped ) {
				$wiped = true;
				return array();
			}
		);
		Functions\when( 'get_terms' )->justReturn( array() );
		Functions\when( 'wp_delete_post' )->justReturn( true );
		Functions\when( 'wp_delete_term' )->justReturn( true );

		require_once ABSPATH . 'includes/class-tapgoods-post-types.php';
		require_once ABSPATH . 'includes/class-tapgoods-sync-state.php';
		require_once ABSPATH . 'includes/class-tapgoods-sync-scheduler.php';
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
	 * Inject the API client via the `tapgoods_api_client` filter without
	 * hijacking the logger's own apply_filters() calls.
	 */
	private function inject_client( $client ): void {
		Functions\when( 'apply_filters' )->alias(
			static function ( $tag, $value = null ) use ( $client ) {
				return ( 'tapgoods_api_client' === $tag ) ? $client : $value;
			}
		);
	}

	/** A client that fails the test if the run reaches the API at all. */
	private function exploding_client() {
		return new class() {
			public function get_location_ids() {
				throw new \RuntimeException( 'The run must stop before calling the API.' );
			}
		};
	}

	/** A client whose PREP fails cleanly, so a run stops right after the key guard. */
	private function failing_prep_client() {
		return new class() {
			public function get_location_ids() {
				return false;
			}
			public function validate_key() {
				return false;
			}
		};
	}

	/** Save a key the way the Connect button does, readable in this request. */
	private function save_readable_key( $plain ): void {
		$this->store['tg_key'] = ( new Tapgoods_Encryption() )->tapgrein_encrypt( $plain );
	}

	/**
	 * Save a key encrypted under a different LOGGED_IN_SALT, which is what the
	 * stored key looks like after the site's salts change: Tapgoods_Encryption
	 * cannot decrypt it and get_key() returns false.
	 */
	private function save_unreadable_key( $plain ): void {
		$salt = 'a-salt-this-request-does-not-have';
		$iv   = random_bytes( openssl_cipher_iv_length( 'aes-256-ctr' ) );
		$raw  = openssl_encrypt( $plain . $salt, 'aes-256-ctr', 'this-is-a-fallback-key-but-not-secure', 0, $iv );

		$this->store['tg_key'] = base64_encode( $iv . $raw ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
	}

	private function latch_error(): void {
		$state = Tapgoods_Sync_State::get_instance();
		for ( $i = 0; $i <= Tapgoods_Sync_State::MAX_RETRIES; $i++ ) {
			$state->mark_error( 'URL failed to respond' );
		}
		$this->assertTrue( $state->has_latched_error(), 'Setup: the error must be latched.' );
	}

	private function log_contents(): string {
		$path = Tapgoods_Sync_Log::get_instance()->get_file_path();
		return file_exists( $path ) ? (string) file_get_contents( $path ) : '';
	}

	// --- The decision itself ---------------------------------------------------

	public function test_key_change_action_only_wipes_between_two_readable_keys() {
		// No usable key now (missing, or saved but undecryptable): never delete.
		foreach ( array( false, null, '', '   ' ) as $unusable ) {
			$this->assertSame( 'none', Tapgoods_Connection::key_change_action( $unusable, 'old-key', false ) );
			$this->assertSame( 'none', Tapgoods_Connection::key_change_action( $unusable, '', false ) );
		}

		$this->assertSame( 'none', Tapgoods_Connection::key_change_action( 'key', 'key', false ), 'Same key.' );
		$this->assertSame( 'none', Tapgoods_Connection::key_change_action( 'new-key', 'old-key', true ), 'A run in flight finishes first.' );

		// No previous key on record: record it, there is nothing to compare against.
		foreach ( array( false, null, '' ) as $no_previous ) {
			$this->assertSame( 'record', Tapgoods_Connection::key_change_action( 'key', $no_previous, false ) );
		}

		$this->assertSame( 'wipe', Tapgoods_Connection::key_change_action( 'new-key', 'old-key', false ) );
	}

	public function test_saved_key_unreadable() {
		$this->assertTrue( Tapgoods_Connection::saved_key_unreadable( false, 'ciphertext' ) );
		$this->assertTrue( Tapgoods_Connection::saved_key_unreadable( null, 'ciphertext' ) );
		$this->assertFalse( Tapgoods_Connection::saved_key_unreadable( 'key', 'ciphertext' ), 'Readable.' );
		$this->assertFalse( Tapgoods_Connection::saved_key_unreadable( '', '' ), 'Nothing saved: not connected, not unreadable.' );
		$this->assertFalse( Tapgoods_Connection::saved_key_unreadable( '', false ), 'Option missing.' );
	}

	// --- Runs that cannot sync must not delete ---------------------------------

	public function test_unreadable_saved_key_never_deletes_the_catalog_however_many_runs() {
		// The live log: after the key became unreadable, every single run logged
		// sync.key.changed and deleted the catalog again.
		$this->save_unreadable_key( 'the-real-key' );
		$this->store['tg_last_api_key'] = 'the-real-key';
		$this->inject_client( $this->exploding_client() );

		$conn = Tapgoods_Connection::get_instance();
		for ( $run = 1; $run <= 4; $run++ ) {
			$result = $conn->sync_inventory_in_batches( false, 'as_slice' );
			$this->assertFalse( $result['success'], "Run $run cannot sync." );
		}

		$this->assertFalse( $this->wiped, 'An unreadable key must never delete local data.' );
		$this->assertSame( 'the-real-key', $this->store['tg_last_api_key'], 'The last good key stays on record.' );

		// An ordinary failure: retried, then latched with a message that says what to do.
		$state = Tapgoods_Sync_State::get_instance();
		$this->assertTrue( $state->has_latched_error() );
		$this->assertSame( Tapgoods_Connection::KEY_UNREADABLE_MESSAGE, $state->get_error_message() );

		$log = $this->log_contents();
		$this->assertStringContainsString( 'sync.key.unreadable', $log );
		$this->assertStringNotContainsString( 'sync.key.changed', $log );
		$this->assertStringNotContainsString( 'the-real-key', $log, 'Never log the key.' );
	}

	public function test_latched_error_blocks_the_run_before_a_key_change_can_delete() {
		$this->save_readable_key( 'new-key' );
		$this->store['tg_last_api_key'] = 'old-key';
		$this->latch_error();
		$this->inject_client( $this->exploding_client() );

		$result = Tapgoods_Connection::get_instance()->sync_inventory_in_batches( false, 'as_slice' );

		$this->assertFalse( $result['success'] );
		$this->assertFalse( $this->wiped, 'A blocked run must not delete anything.' );
		$this->assertSame( 'old-key', $this->store['tg_last_api_key'], 'The change is handled by the next run that can sync.' );
		$this->assertStringContainsString( 'sync.run.blocked', $this->log_contents() );
		$this->assertStringNotContainsString( 'sync.key.changed', $this->log_contents() );
	}

	public function test_held_run_lock_blocks_the_key_change_wipe() {
		$this->save_readable_key( 'new-key' );
		$this->store['tg_last_api_key'] = 'old-key';
		$this->lock                     = 1;
		$this->inject_client( $this->exploding_client() );

		$result = Tapgoods_Connection::get_instance()->sync_inventory_in_batches( false, 'as_slice' );

		$this->assertTrue( $result['in_progress'] );
		$this->assertFalse( $this->wiped, 'Only the request holding the run lock may delete.' );
		$this->assertSame( 'old-key', $this->store['tg_last_api_key'] );
	}

	// --- What still deletes, and what no longer needs to ------------------------

	public function test_a_genuine_key_change_still_clears_local_data() {
		$this->save_readable_key( 'new-key' );
		$this->store['tg_last_api_key'] = 'old-key';
		$this->inject_client( $this->failing_prep_client() );

		Tapgoods_Connection::get_instance()->sync_inventory_in_batches( false, 'as_slice' );

		$this->assertTrue( $this->wiped, 'Switching to a different readable key clears the old catalog.' );
		$this->assertSame( 'new-key', $this->store['tg_last_api_key'] );
		$this->assertStringContainsString( 'sync.key.changed', $this->log_contents() );
	}

	public function test_first_readable_key_is_recorded_without_deleting() {
		// No key on record: a fresh install, after Reset to Default, or a site whose
		// last key was blanked by the unreadable-key bug and still has its catalog.
		$this->save_readable_key( 'the-key' );
		$this->store['tg_last_api_key'] = '';
		$this->inject_client( $this->failing_prep_client() );

		Tapgoods_Connection::get_instance()->sync_inventory_in_batches( false, 'as_slice' );

		$this->assertFalse( $this->wiped );
		$this->assertSame( 'the-key', $this->store['tg_last_api_key'] );
		$this->assertStringContainsString( 'sync.key.recorded', $this->log_contents() );
	}

	// --- The Sync button --------------------------------------------------------

	public function test_sync_button_clears_a_latched_error() {
		$this->save_readable_key( 'the-key' );
		$this->store['tg_last_api_key'] = 'the-key';
		$this->latch_error();
		$this->inject_client( $this->failing_prep_client() );

		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\when( 'as_enqueue_async_action' )->justReturn( 1 );
		Functions\when( 'as_unschedule_all_actions' )->justReturn( true );
		Functions\when( 'as_has_scheduled_action' )->justReturn( false );
		Functions\when( 'as_next_scheduled_action' )->justReturn( false );
		// wp_send_json_* end the request with wp_die(); stand in with an exception.
		Functions\when( 'wp_send_json_success' )->alias(
			static function () {
				throw new \RuntimeException( 'wp_send_json_success' );
			}
		);
		Functions\when( 'wp_send_json_error' )->alias(
			static function () {
				throw new \RuntimeException( 'wp_send_json_error' );
			}
		);

		try {
			Tapgoods_Connection::get_instance()->manual_sync_trigger();
			$this->fail( 'manual_sync_trigger() must end with wp_send_json_*().' );
		} catch ( \RuntimeException $e ) {
			$this->assertSame( 'wp_send_json_success', $e->getMessage() );
		}

		$state = Tapgoods_Sync_State::get_instance();
		$this->assertFalse( $state->has_latched_error(), 'Clicking Sync must clear the latch so the queued slice can run.' );
		$this->assertSame( 0, $state->get_failure_count(), 'The retry budget starts over.' );
		$this->assertStringContainsString( 'sync.errors.cleared', $this->log_contents() );
	}
}
