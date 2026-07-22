<?php
/**
 * Unit tests for Tapgoods_Connection driven entirely by the offline mock service.
 *
 * These prove the create_client() seam works end-to-end with no network:
 *   - a client injected via the `tapgoods_api_client` filter is used, and
 *   - the `tg_mock` env var swaps in Tapgoods_Mock_API_Client.
 *
 * class-tapgoods-connection.php runs add_action()/get_instance() at include time,
 * so it is required inside setUp() (once), after Brain\Monkey has stubbed those
 * WordPress functions. The singleton is reset before each test.
 *
 * @package Tapgoods\Tests
 */

namespace Tapgoods\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Filters;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Tapgoods_Connection;
use Tapgoods_Mock_API_Client;

final class ConnectionMockFlowTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		// Stub the WordPress calls that class-tapgoods-connection.php makes at
		// include time (bottom-of-file add_action + get_instance -> get_key).
		Functions\when( 'add_action' )->justReturn( true );
		Functions\when( 'get_option' )->justReturn( false );

		require_once ABSPATH . 'includes/class-tapgoods-connection.php';
		require_once dirname( __DIR__ ) . '/mock/class-tapgoods-mock-api-client.php';

		$this->reset_singleton();
	}

	protected function tearDown(): void {
		putenv( 'tg_mock' ); // ensure env gating never leaks between tests.
		Monkey\tearDown();
		parent::tearDown();
	}

	private function reset_singleton(): void {
		$ref  = new ReflectionClass( Tapgoods_Connection::class );
		$prop = $ref->getProperty( 'instance' );
		$prop->setValue( null, null );
	}

	private function make_mock(): Tapgoods_Mock_API_Client {
		return new Tapgoods_Mock_API_Client(
			array(
				'base_url' => 'https://openapi.tapgoods.com',
				'api_key'  => 'test-key',
				'tg_env'   => 'tapgoods.com',
			)
		);
	}

	public function test_get_business_runs_end_to_end_against_injected_mock() {
		$mock = $this->make_mock();

		// The seam: create_client() applies this filter; returning a client here
		// bypasses the network entirely.
		Filters\expectApplied( 'tapgoods_api_client' )->andReturn( $mock );

		// get_business() persists the results via update_option().
		$saved = array();
		Functions\when( 'update_option' )->alias(
			static function ( $key, $value ) use ( &$saved ) {
				$saved[ $key ] = $value;
				return true;
			}
		);

		$business = Tapgoods_Connection::get_instance()->get_business();

		$this->assertIsArray( $business );
		$this->assertSame( 1042, $business['businessId'] );
		$this->assertSame( array( 5001, 5002 ), $business['locationIds'] );

		// And it wrote the identifiers back to WordPress options.
		$this->assertSame( 1042, $saved['tg_businessId'] );
		$this->assertSame( array( 5001, 5002 ), $saved['tg_locationIds'] );
	}

	public function test_env_var_gating_toggles_mock_usage() {
		putenv( 'tg_mock=1' );
		$this->assertTrue( Tapgoods_Connection::use_mock_api() );

		putenv( 'tg_mock=off' );
		$this->assertFalse( Tapgoods_Connection::use_mock_api() );

		// The string "false" must be treated as falsy, not as a truthy non-empty
		// string (guards the same normalization used for the TG_MOCK constant).
		putenv( 'tg_mock=false' );
		$this->assertFalse( Tapgoods_Connection::use_mock_api() );

		putenv( 'tg_mock=yes' );
		$this->assertTrue( Tapgoods_Connection::use_mock_api() );

		putenv( 'tg_mock' ); // unset
		$this->assertFalse( Tapgoods_Connection::use_mock_api() );
	}

	public function test_env_var_swaps_in_mock_client_via_get_connection() {
		putenv( 'tg_mock=1' );

		// No filter injection here: the env var alone must select the mock client
		// (create_client() requires tests/mock/... via TAPGOODS_PLUGIN_PATH).
		$client = Tapgoods_Connection::get_instance()->get_client();

		$this->assertInstanceOf( Tapgoods_Mock_API_Client::class, $client );
	}
}
