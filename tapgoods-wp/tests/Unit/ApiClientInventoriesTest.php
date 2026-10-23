<?php
/**
 * Unit tests for Tapgoods_API_Client::get_inventories_from_graph().
 *
 * This one method decides what the sync is allowed to delete. The run stamps
 * every item a page returns, and finalize permanently removes whatever was not
 * stamped. So the contract here is narrow and it matters: a page we could not
 * read must come back as false (an error, which aborts the run before finalize),
 * and never as an empty or null page (which the run reads as "this location has
 * no more items").
 *
 * The case that slipped through: GraphQL reports failures as HTTP 200 with an
 * `errors` array and `data: null`. The status check passed, the method returned
 * the missing node, and a location's whole catalog became "obsolete".
 *
 * @package Tapgoods\Tests
 */

namespace Tapgoods\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use Tapgoods_API_Client;

final class ApiClientInventoriesTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		Functions\when( 'wp_parse_args' )->alias(
			static fn( $args, $defaults ) => array_merge( $defaults, (array) $args )
		);
		Functions\when( 'trailingslashit' )->alias( static fn( $s ) => rtrim( $s, '/' ) . '/' );
		Functions\when( 'wp_json_encode' )->alias( static fn( $data ) => json_encode( $data ) );
		Functions\when( 'is_wp_error' )->justReturn( false );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * A client whose HTTP layer is replaced by a canned response, so the test
	 * exercises only how the body is interpreted.
	 *
	 * @param mixed $body     What get_response() returns (the decoded JSON body).
	 * @param bool  $is_error What is_error() returns (true for a non-200 status).
	 */
	private function client_answering( $body, bool $is_error = false ): Tapgoods_API_Client {
		$reply = new class( $body, $is_error ) {
			private $body;
			private $is_error;

			public function __construct( $body, $is_error ) {
				$this->body     = $body;
				$this->is_error = $is_error;
			}

			public function is_error() {
				return $this->is_error;
			}

			public function get_response() {
				return $this->body;
			}
		};

		return new class( $reply ) extends Tapgoods_API_Client {
			private $reply;

			public function __construct( $reply ) {
				parent::__construct(
					array(
						'base_url' => 'https://openapi.tapgoods.com',
						'api_key'  => 'test-key',
					)
				);
				$this->reply = $reply;
			}

			public function request( $url, $args = array() ) {
				return $this->reply;
			}
		};
	}

	public function test_a_healthy_page_is_returned_as_is() {
		$page = array(
			'metadata'   => array( 'currentPage' => 1, 'totalPages' => 3, 'totalCount' => 60, 'limitValue' => 25 ),
			'collection' => array( array( 'id' => 101 ), array( 'id' => 102 ) ),
		);

		$client = $this->client_answering( array( 'data' => array( 'getInventories' => $page ) ) );

		$this->assertSame( $page, $client->get_inventories_from_graph( 5001, 1, 25 ) );
	}

	public function test_a_genuinely_empty_page_is_still_a_page() {
		// The end of a location: an array with an empty collection. This is the
		// one "nothing here" answer the sync is allowed to act on, so it must not
		// be confused with an error.
		$page = array(
			'metadata'   => array( 'currentPage' => 4, 'totalPages' => 3, 'totalCount' => 60, 'limitValue' => 25 ),
			'collection' => array(),
		);

		$client = $this->client_answering( array( 'data' => array( 'getInventories' => $page ) ) );

		$this->assertSame( $page, $client->get_inventories_from_graph( 5001, 4, 25 ) );
	}

	public function test_a_graphql_error_on_http_200_is_an_error_not_an_empty_page() {
		// What a rate limit or an expired token looks like: status 200, errors
		// set, data null. Returning null here is what let finalize delete a whole
		// location.
		$client = $this->client_answering(
			array(
				'errors' => array( array( 'message' => 'Rate limit exceeded' ) ),
				'data'   => null,
			)
		);

		$this->assertFalse( $client->get_inventories_from_graph( 5001, 7, 25 ) );
	}

	public function test_errors_win_even_when_partial_data_came_back() {
		// GraphQL may return errors alongside partial data. A partial page cannot
		// be trusted to stamp the catalog, so it is an error too.
		$client = $this->client_answering(
			array(
				'errors' => array( array( 'message' => 'Field resolution failed' ) ),
				'data'   => array( 'getInventories' => array( 'collection' => array( array( 'id' => 101 ) ) ) ),
			)
		);

		$this->assertFalse( $client->get_inventories_from_graph( 5001, 1, 25 ) );
	}

	public function test_a_body_without_the_inventories_node_is_an_error() {
		$client = $this->client_answering( array( 'data' => array() ) );

		$this->assertFalse( $client->get_inventories_from_graph( 5001, 1, 25 ) );
	}

	public function test_a_null_inventories_node_is_an_error() {
		$client = $this->client_answering( array( 'data' => array( 'getInventories' => null ) ) );

		$this->assertFalse( $client->get_inventories_from_graph( 5001, 1, 25 ) );
	}

	public function test_an_undecodable_body_is_an_error() {
		// An HTML error page from a proxy decodes to null.
		$client = $this->client_answering( null );

		$this->assertFalse( $client->get_inventories_from_graph( 5001, 1, 25 ) );
	}

	public function test_a_non_200_status_is_an_error() {
		$client = $this->client_answering( array( 'data' => array( 'getInventories' => array( 'collection' => array() ) ) ), true );

		$this->assertFalse( $client->get_inventories_from_graph( 5001, 1, 25 ) );
	}
}
