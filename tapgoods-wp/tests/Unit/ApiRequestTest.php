<?php
/**
 * Unit tests for Tapgoods_API_Request (includes/class-tapgoods-api-request.php)
 *
 * Exercises the pure, network-free parts of the request layer: URL building,
 * parameter verification, transient naming and config get/set.
 *
 * @package Tapgoods\Tests
 */

namespace Tapgoods\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use Tapgoods_API_Request;

final class ApiRequestTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		// wp_parse_args is used in the constructor.
		Functions\when( 'wp_parse_args' )->alias(
			static fn( $args, $defaults ) => array_merge( $defaults, (array) $args )
		);
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	private function make_request(): Tapgoods_API_Request {
		return new Tapgoods_API_Request(
			array(
				'base_url' => 'https://openapi.tapgoods.com',
				'api_key'  => 'test-key',
			)
		);
	}

	public function test_build_url_appends_endpoint_to_base() {
		Functions\when( 'trailingslashit' )->alias( static fn( $s ) => rtrim( $s, '/' ) . '/' );

		$req = $this->make_request();

		$this->assertSame(
			'https://openapi.tapgoods.com/v1/external/graphql',
			$req->build_url( 'v1/external/graphql' )
		);
	}

	public function test_build_url_adds_query_args_when_params_given() {
		Functions\when( 'trailingslashit' )->alias( static fn( $s ) => rtrim( $s, '/' ) . '/' );
		Functions\when( 'add_query_arg' )->alias(
			static fn( $params, $url ) => $url . '?' . http_build_query( $params )
		);

		$req = $this->make_request();
		$url = $req->build_url( 'api/portal/inventories/paginated', array( 'page' => 2, 'per' => 10 ) );

		$this->assertSame(
			'https://openapi.tapgoods.com/api/portal/inventories/paginated?page=2&per=10',
			$url
		);
	}

	public function test_build_url_override_short_circuits() {
		Functions\when( 'trailingslashit' )->alias( static fn( $s ) => rtrim( $s, '/' ) . '/' );

		$req = $this->make_request();
		$this->assertSame(
			'https://override.example/x',
			$req->build_url( 'ignored', null, 'https://override.example/x' )
		);
	}

	public function test_verify_parameters_present_and_absent() {
		$req = $this->make_request();

		// KNOWN BUG (documented, not fixed here): verify_parameters() returns true
		// when ANY required key is present, not when ALL are (see
		// class-tapgoods-api-request.php:205-209). When that is fixed to require ALL
		// keys, flip the two assertions below from assertTrue to assertFalse (only
		// the all-keys-present case should pass).
		$this->assertTrue( $req->verify_parameters( array( 'base_url', 'api_key' ), array( 'base_url' => 'x' ) ) );
		$this->assertTrue( $req->verify_parameters( array( 'base_url', 'api_key' ), array( 'api_key' => 'x' ) ) );
		$this->assertFalse( $req->verify_parameters( array( 'base_url', 'api_key' ), array( 'unrelated' => 'x' ) ) );
		$this->assertFalse( $req->verify_parameters( array( 'base_url' ), 'not-an-array' ) );
	}

	public function test_transient_name_is_prefixed_md5() {
		$req = $this->make_request();
		$this->assertSame( 'tg_api_' . md5( 'some-url' ), $req->transient_name( 'some-url' ) );
	}

	public function test_no_request_yet_means_no_http_status() {
		$this->assertNull( $this->make_request()->get_last_http_code() );
	}

	/**
	 * A transport failure must not inherit the previous call's status.
	 *
	 * This is the shape that matters for the sync log: the client collapses every
	 * failure to `false`, so the status is what tells support a revoked key from an
	 * outage. When the request never reached TapGoods at all there is no status,
	 * and reporting the last successful 200 would read as evidence that TapGoods
	 * answered. The 401 case cannot catch this; only a throwing request can.
	 */
	public function test_a_transport_failure_reports_no_status_rather_than_a_stale_one() {
		Functions\when( 'current_time' )->justReturn( 1000 );
		Functions\when( 'wp_remote_retrieve_headers' )->justReturn( array() );
		Functions\when( 'wp_remote_retrieve_cookies' )->justReturn( array() );
		Functions\when( 'wp_remote_retrieve_body' )->justReturn( '{"data":{}}' );
		Functions\when( 'esc_url_raw' )->returnArg();
		Functions\when( 'esc_html' )->returnArg();

		$req = $this->make_request();

		// First call succeeds and records its status.
		Functions\when( 'wp_remote_request' )->justReturn( array( 'response' => array( 'code' => 200 ) ) );
		Functions\when( 'wp_remote_retrieve_response_code' )->justReturn( 200 );
		Functions\when( 'is_wp_error' )->justReturn( false );

		$req->request( 'https://openapi.tapgoods.com/v1/external/graphql' );
		$this->assertSame( 200, $req->get_last_http_code() );

		// Second call dies in transport: WordPress returns a WP_Error shaped like a
		// DNS failure, and the request layer throws before any response exists.
		$wp_error         = new \stdClass();
		$wp_error->errors = array( 'http_request_failed' => array( 'cURL error 6: Could not resolve host' ) );
		Functions\when( 'wp_remote_request' )->justReturn( $wp_error );
		Functions\when( 'is_wp_error' )->justReturn( true );

		$caught = null;
		try {
			$req->request( 'https://openapi.tapgoods.com/v1/external/graphql' );
		} catch ( \Throwable $e ) {
			$caught = $e;
		}

		$this->assertInstanceOf( \TG_HTTP_Request_Failed_Exception::class, $caught );
		$this->assertNull( $req->get_last_http_code(), 'A request that never got a response must carry no status.' );
	}

	public function test_config_get_set() {
		$req = $this->make_request();

		$this->assertSame( 'https://openapi.tapgoods.com', $req->get_config( 'base_url' ) );
		$this->assertNull( $req->get_config( 'does-not-exist' ) );

		$req->set_config( 'base_url', 'https://openapi.staging.tapgoods.dev' );
		$this->assertSame( 'https://openapi.staging.tapgoods.dev', $req->get_config( 'base_url' ) );

		$req->set_config( array( 'a' => 1, 'b' => 2 ) );
		$this->assertSame( 1, $req->get_config( 'a' ) );
		$this->assertSame( 2, $req->get_config( 'b' ) );
	}
}
