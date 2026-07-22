<?php
/**
 * Offline mock of Tapgoods_API_Client.
 *
 * Serves static JSON fixtures (tests/fixtures/*.json) that emulate the real
 * TapGoods GraphQL responses, so tests and local dev can exercise
 * Tapgoods_Connection end-to-end without touching the network.
 *
 * Activated through Tapgoods_Connection::create_client() when the TG_MOCK
 * constant / `tg_mock` env var is set, or when a test injects it via the
 * `tapgoods_api_client` filter.
 *
 * By design this class calls NO WordPress functions, so it stays usable in
 * fully isolated unit tests.
 *
 * @package Tapgoods
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

class Tapgoods_Mock_API_Client {

	private $config = array();

	public function __construct( $config = array() ) {
		$this->config = is_array( $config ) ? $config : array();
	}

	public function set_config( $param, $value = null ) {
		if ( is_array( $param ) ) {
			foreach ( $param as $k => $v ) {
				$this->config[ $k ] = $v;
			}
		} else {
			$this->config[ $param ] = $value;
		}
	}

	public function get_config( $param ) {
		return isset( $this->config[ $param ] ) ? $this->config[ $param ] : null;
	}

	/**
	 * Load and decode a fixture file emulating a GraphQL response envelope.
	 *
	 * @param string $file File name inside tests/fixtures/.
	 * @return array Decoded JSON as an associative array.
	 */
	private function fixture( $file ) {
		$base = defined( 'TG_FIXTURES_PATH' ) ? TG_FIXTURES_PATH : __DIR__ . '/../fixtures/';
		$path = rtrim( $base, '/' ) . '/' . $file;
		if ( ! file_exists( $path ) ) {
			throw new RuntimeException( "Missing mock fixture: {$path}" );
		}
		$decoded = json_decode( file_get_contents( $path ), true );
		if ( JSON_ERROR_NONE !== json_last_error() ) {
			throw new RuntimeException( "Invalid JSON in mock fixture {$path}: " . json_last_error_msg() );
		}
		return $decoded;
	}

	// --- Emulated client surface used by Tapgoods_Connection ------------------

	public function validate_key() {
		$response = $this->fixture( 'bearer-token-validator.json' );
		if ( isset( $response['errors'] ) || isset( $response['message'] ) ) {
			return false;
		}
		return $response['data']['bearerTokenValidator'];
	}

	public function get_location_ids() {
		$business = $this->validate_key();
		return ( false === $business ) ? false : $business['locationIds'];
	}

	public function get_location_details_from_graph( $lid, $details = array() ) {
		$response = $this->fixture( 'get-location-details.json' );
		if ( isset( $response['errors'] ) && ! empty( $response['errors'] ) ) {
			return false;
		}

		$data       = $response['data']['getLocationDetails'];
		$data['id'] = $lid; // Echo back the requested id, as the real endpoint does.

		// Mirror the derived fields the real client adds.
		$data['fullName'] = "{$data['name']} ({$data['locationCode']})";

		$env     = $this->get_config( 'tg_env' ) ? $this->get_config( 'tg_env' ) : 'tapgoods.com';
		$domains = isset( $data['storefrontSetting']['domains'] ) ? $data['storefrontSetting']['domains'] : array();

		if ( ! empty( $domains ) && isset( $domains[0]['name'] ) ) {
			$storefront_url = 'https://' . $domains[0]['name'];
		} else {
			$storefront_url = 'https://' . $data['subdomain'] . '.' . $env;
		}

		$storefront_url      = rtrim( $storefront_url, '/' ) . '/';
		$data['sf_url']      = $storefront_url;
		$data['cart_url']    = $storefront_url . 'cart?externalRedirect=true';
		$data['signup_url']  = $storefront_url . 'signup';
		$data['login_url']   = $storefront_url . 'login';
		$data['add_to_cart'] = $storefront_url . 'addToCart';

		return $data;
	}

	public function get_categories_from_graph( $lid, $keyword = false ) {
		$response = $this->fixture( 'get-storefront-categories.json' );
		if ( isset( $response['errors'] ) && ! empty( $response['errors'] ) ) {
			return false;
		}
		return $response['data']['getStorefrontCagetories'];
	}

	public function get_inventories_from_graph( $lid, $page = 1, $per_page = 10 ) {
		$response = $this->fixture( 'get-inventories.json' );
		$data     = $response['data']['getInventories'];

		$data['metadata']['currentPage'] = (int) $page;

		// Single page of fixtures: everything is on page 1, later pages are empty
		// so the batched sync loop terminates.
		if ( (int) $page > 1 ) {
			$data['collection'] = array();
		}

		return $data;
	}

	public function item_exists( $lid, $id ) {
		return true;
	}

	public function transient_name( $name ) {
		$prefix = $this->get_config( 'cache_prefix' ) ? $this->get_config( 'cache_prefix' ) : 'tg_api_';
		return $prefix . md5( $name );
	}
}
