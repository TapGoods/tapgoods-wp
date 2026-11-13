<?php
/**
 * No request reaches TapGoods without an API key.
 *
 * A live log (Nov 2026) showed background syncs getting 401s that read like a
 * wrong key or a bad token format. There was no key yet: the request went out
 * with `Authorization: Bearer ` and nothing after it. The sync run now stops
 * before PREP when it has no key (ConnectionKeyGuardTest), and this pins the
 * check underneath it, in Tapgoods_API_Request::request(), which every API call
 * passes through: no key means no HTTP request, an ERROR line in the sync log
 * naming the call, and the caller's usual failure value.
 *
 * Isolated (no WordPress) via Brain\Monkey, driving the real client methods.
 *
 * @package Tapgoods\Tests
 */

namespace Tapgoods\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Tapgoods_API_Client;
use Tapgoods_API_Request;
use Tapgoods_API_Response;
use Tapgoods_Sync_Log;

final class ApiKeyMissingTest extends TestCase {

	/** @var array[] Args of every wp_remote_request() call. */
	private $sent = array();

	/** @var string Temp directory the logger writes into. */
	private $log_dir;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		$this->sent = array();
		$sent       = &$this->sent;

		Functions\when( 'wp_parse_args' )->alias(
			static function ( $args, $defaults ) {
				return array_merge( $defaults, $args );
			}
		);
		Functions\when( 'trailingslashit' )->alias( static fn( $s ) => rtrim( $s, '/' ) . '/' );
		Functions\when( 'wp_json_encode' )->alias( static fn( $data ) => json_encode( $data ) );
		Functions\when( 'add_query_arg' )->alias( static fn( $params, $url ) => $url . '?' . http_build_query( $params ) );
		Functions\when( 'is_wp_error' )->justReturn( false );
		Functions\when( 'get_transient' )->justReturn( false );
		Functions\when( 'set_transient' )->justReturn( true );
		Functions\when( 'current_time' )->justReturn( 1000 );

		// The network. With a key, TapGoods "answers" a bearerTokenValidator.
		Functions\when( 'wp_remote_request' )->alias(
			static function ( $url, $args ) use ( &$sent ) {
				$sent[] = $args;
				return array( 'reply' => true );
			}
		);
		Functions\when( 'wp_remote_retrieve_response_code' )->justReturn( 200 );
		Functions\when( 'wp_remote_retrieve_headers' )->justReturn( array() );
		Functions\when( 'wp_remote_retrieve_cookies' )->justReturn( array() );
		Functions\when( 'wp_remote_retrieve_body' )->justReturn(
			json_encode( array( 'data' => array( 'bearerTokenValidator' => array( 'businessId' => 1042, 'locationIds' => array( 5001 ) ) ) ) )
		);

		$this->log_dir = sys_get_temp_dir() . '/tg-nokey-' . uniqid( '', true );
		mkdir( $this->log_dir, 0777, true );
		$ref  = new ReflectionClass( Tapgoods_Sync_Log::class );
		$prop = $ref->getProperty( 'instance' );
		$prop->setValue( null, new Tapgoods_Sync_Log( $this->log_dir, 'testsecret' ) );
	}

	protected function tearDown(): void {
		$ref  = new ReflectionClass( Tapgoods_Sync_Log::class );
		$prop = $ref->getProperty( 'instance' );
		$prop->setValue( null, null );
		if ( is_dir( $this->log_dir ) ) {
			foreach ( glob( $this->log_dir . '/*' ) as $f ) {
				@unlink( $f );
			}
			@rmdir( $this->log_dir );
		}
		Monkey\tearDown();
		parent::tearDown();
	}

	private function client( $key ): Tapgoods_API_Client {
		return new Tapgoods_API_Client(
			array(
				'base_url' => 'https://openapi.tapgoods.com',
				'api_key'  => $key,
			)
		);
	}

	private function log_contents(): string {
		$path = Tapgoods_Sync_Log::get_instance()->get_file_path();
		return file_exists( $path ) ? (string) file_get_contents( $path ) : '';
	}

	public function test_no_call_is_sent_without_a_key_and_each_skip_is_logged_as_an_error() {
		foreach ( array( 'empty' => '', 'blank' => "  \n", 'not set' => null ) as $case => $key ) {
			$client = $this->client( $key );

			// Every client method that reaches the API returns its usual failure
			// value, and none of them fatals on the skipped response.
			$this->assertFalse( $client->validate_key(), $case );
			$this->assertFalse( $client->get_location_ids(), $case );
			$this->assertFalse( $client->get_location_details_from_graph( 5001 ), $case );
			$this->assertFalse( $client->get_categories_from_graph( 5001 ), $case );
			$this->assertFalse( $client->get_inventories_from_graph( 5001, 1, 25 ), "$case: an unread page is false, never an empty page." );
			$this->assertFalse( $client->item_exists( 5001, 77 ), $case );
			$this->assertFalse( $client->get_inventory_from_rest( 5001 ), $case );
			$this->assertNull( $client->get_last_http_code(), "$case: no status, because TapGoods was never asked." );
		}

		$this->assertSame( array(), $this->sent, 'Nothing was sent to TapGoods.' );

		$log = $this->log_contents();
		foreach ( array( 'bearerTokenValidator', 'getLocationDetails', 'getStorefrontCagetories', 'getInventories', 'api/portal/inventories/paginated' ) as $op ) {
			$this->assertMatchesRegularExpression( '#ERROR api\.key\.missing op=' . preg_quote( $op, '#' ) . ' action=skipped_call#', $log, $op );
		}
		// One line per skipped call: 3 cases x 7 calls.
		$this->assertSame( 21, substr_count( $log, 'api.key.missing' ) );
	}

	public function test_a_call_with_a_key_is_sent_as_a_bearer_token() {
		$business = $this->client( 'the-real-key' )->validate_key();

		$this->assertSame( 1042, $business['businessId'] );
		$this->assertCount( 1, $this->sent );
		$this->assertSame( 'Bearer the-real-key', $this->sent[0]['headers']['Authorization'] );
		$this->assertStringNotContainsString( 'api.key.missing', $this->log_contents() );
	}

	public function test_the_skipped_response_is_an_error_with_no_status() {
		$res = Tapgoods_API_Response::not_sent( 'why' );

		$this->assertTrue( $res->is_error() );
		$this->assertNull( $res->get_http_code() );
		$this->assertSame( array( 'errors' => array( array( 'message' => 'why' ) ) ), $res->get_response() );
	}

	public function test_operation_name_never_includes_the_query() {
		$validate = json_encode( array( 'query' => '{bearerTokenValidator( bearerToken: "secret-abc"){ businessId }}' ) );
		$this->assertSame( 'bearerTokenValidator', Tapgoods_API_Request::operation_name( 'https://openapi.tapgoods.com/v1/external/graphql', array( 'body' => $validate ) ) );

		$inventories = json_encode( array( 'query' => '{ getInventories ( perPage: 25, locationId: 5001 page: 1) { id } }' ) );
		$this->assertSame( 'getInventories', Tapgoods_API_Request::operation_name( 'https://openapi.tapgoods.com/v1/external/graphql', array( 'body' => $inventories ) ) );

		$this->assertSame( 'api/portal/inventories/paginated', Tapgoods_API_Request::operation_name( 'https://openapi.tapgoods.com/api/portal/inventories/paginated?location_id=5001&token=abc' ) );
		$this->assertSame( 'v1/external/graphql', Tapgoods_API_Request::operation_name( 'https://openapi.tapgoods.com/v1/external/graphql', array( 'body' => 'not json' ) ) );
		$this->assertSame( 'unknown', Tapgoods_API_Request::operation_name( 'https://openapi.tapgoods.com/' ) );
	}
}
