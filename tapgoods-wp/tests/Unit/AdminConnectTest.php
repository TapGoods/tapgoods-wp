<?php
/**
 * The Connect button's handler (Tapgoods_Admin::tapgrein_update_connection()).
 *
 * Saving a key used to leave no trace in the sync log, so a 401 in a later run
 * could not be told apart from "no key was saved yet" or "TapGoods refused the
 * key". It now logs connect.test with the result, the HTTP status and the key's
 * fingerprint (never the key). It also used to report success when TapGoods never
 * answered: test_connection() returns the exception it caught, and that object
 * passed the `false === $response` check.
 *
 * Isolated (no WordPress) via Brain\Monkey, with a real Tapgoods_Sync_Log in a
 * temp directory.
 *
 * @package Tapgoods\Tests
 */

namespace Tapgoods\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Tapgoods_Admin;
use Tapgoods_Connection;
use Tapgoods_Sync_Log;
use Tapgoods_Sync_State;

final class AdminConnectTest extends TestCase {

	/** @var array In-memory option store. */
	private $store = array();

	/** @var string Temp directory the logger writes into. */
	private $log_dir;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		$this->store = array();
		$store       = &$this->store;

		$this->log_dir = sys_get_temp_dir() . '/tg-connect-' . uniqid( '', true );
		mkdir( $this->log_dir, 0777, true );

		Functions\when( 'add_action' )->justReturn( true );
		Functions\when( 'add_filter' )->justReturn( true );
		Functions\when( 'check_ajax_referer' )->justReturn( 1 );
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'is_wp_error' )->justReturn( false );
		Functions\when( '__' )->returnArg();
		Functions\when( 'wp_admin_notice' )->justReturn( null );
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
		// wp_send_json_* end the request with wp_die(); stand in with an exception.
		foreach ( array( 'wp_send_json_success', 'wp_send_json_error' ) as $fn ) {
			Functions\when( $fn )->alias(
				static function () use ( $fn ) {
					throw new \RuntimeException( $fn );
				}
			);
		}

		// connection_failed() calls tapgrein_write_log(). Load the real one rather
		// than stubbing it: a Brain\Monkey stub declares the function for the rest of
		// the process, and a later test that loads core functions would then fatal.
		if ( ! defined( 'WP_DEBUG' ) ) {
			define( 'WP_DEBUG', false );
		}
		require_once ABSPATH . 'includes/tapgoods-core-functions.php';
		require_once ABSPATH . 'includes/class-tapgoods-post-types.php';
		require_once ABSPATH . 'includes/class-tapgoods-sync-state.php';
		require_once ABSPATH . 'includes/class-tapgoods-sync-scheduler.php';
		require_once ABSPATH . 'includes/class-tapgoods-connection.php';
		require_once ABSPATH . 'admin/class-tapgoods-admin.php';

		foreach ( array( Tapgoods_Connection::class, Tapgoods_Sync_State::class, Tapgoods_Sync_Log::class ) as $class ) {
			$this->set_singleton( $class, null );
		}
		$this->set_singleton( Tapgoods_Sync_Log::class, new Tapgoods_Sync_Log( $this->log_dir, 'testsecret' ) );

		$_POST = array( 'tapgoods_api_key' => 'the-submitted-key' );
	}

	protected function tearDown(): void {
		$_POST = array();
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
	 * Inject a client whose validate_key() returns or throws what the case needs.
	 *
	 * @param callable $validate Called for validate_key().
	 * @param int|null $status   What get_last_http_code() reports.
	 */
	private function inject_client( callable $validate, $status ): void {
		$client = new class( $validate, $status ) {
			private $validate;
			private $status;
			public $sent_key = null;
			public function __construct( $validate, $status ) {
				$this->validate = $validate;
				$this->status   = $status;
			}
			public function set_config( $config ) {
				$this->sent_key = $config['api_key'] ?? $this->sent_key;
			}
			public function validate_key() {
				return call_user_func( $this->validate );
			}
			public function get_last_http_code() {
				return $this->status;
			}
		};
		Functions\when( 'apply_filters' )->alias(
			static function ( $tag, $value = null ) use ( $client ) {
				return ( 'tapgoods_api_client' === $tag ) ? $client : $value;
			}
		);
	}

	/** Run the handler; returns which wp_send_json_* ended it. */
	private function connect(): string {
		try {
			Tapgoods_Admin::tapgrein_update_connection();
		} catch ( \RuntimeException $e ) {
			return $e->getMessage();
		}
		$this->fail( 'The handler must end with wp_send_json_*().' );
	}

	private function log_contents(): string {
		$path = Tapgoods_Sync_Log::get_instance()->get_file_path();
		return file_exists( $path ) ? (string) file_get_contents( $path ) : '';
	}

	public function test_accepted_key_is_logged_by_fingerprint_with_its_status() {
		$this->inject_client(
			static function () {
				return array( 'businessId' => 1042, 'locationIds' => array( 5001 ) );
			},
			200
		);

		$this->assertSame( 'wp_send_json_success', $this->connect() );
		$this->assertTrue( $this->store['tg_api_connected'] );

		$fp  = Tapgoods_Connection::key_fingerprint( 'the-submitted-key' );
		$log = $this->log_contents();
		$this->assertMatchesRegularExpression( "/INFO connect\.test result=ok status=200 fp=$fp/", $log );
		$this->assertStringNotContainsString( 'the-submitted-key', $log, 'Never the key itself.' );
	}

	public function test_refused_key_is_logged_with_the_401() {
		$this->inject_client(
			static function () {
				return false;
			},
			401
		);

		$this->assertSame( 'wp_send_json_error', $this->connect() );
		$this->assertFalse( $this->store['tg_api_connected'] );
		$this->assertMatchesRegularExpression( '/WARN connect\.test result=failed status=401 fp=[0-9a-f]{8}/', $this->log_contents() );
	}

	public function test_no_answer_from_tapgoods_is_not_reported_as_connected() {
		// test_connection() catches the exception and returns it. That object used
		// to pass the `false === $response` check, so the key was marked connected
		// although TapGoods never checked it.
		$this->inject_client(
			static function () {
				throw new \Exception( 'cURL error 28: Operation timed out' );
			},
			null
		);

		$this->assertSame( 'wp_send_json_error', $this->connect() );
		$this->assertFalse( $this->store['tg_api_connected'] );
		$this->assertStringContainsString( 'WARN connect.test result=error', $this->log_contents() );
	}

	public function test_an_error_thrown_past_test_connection_is_logged_too() {
		// The network exception classes the request layer throws are not declared,
		// so a timeout surfaces as an Error that test_connection() does not catch.
		$this->inject_client(
			static function () {
				throw new \Error( 'Class "TG_Http_Request_Failed_Exception" not found' );
			},
			null
		);

		$this->assertSame( 'wp_send_json_error', $this->connect() );
		$this->assertStringContainsString( 'WARN connect.test result=error', $this->log_contents() );
	}
}
