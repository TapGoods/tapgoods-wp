<?php

if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly

class Tapgoods_API_Client extends Tapgoods_API_Request {

	private $tokens  = array();
	private $cookies = array();
	private $valid   = false;
	private $bid     = false;

	// only used for development
	private $admin_tokens = array();

	public function validate_key() {

		$endpoint = 'v1/external/graphql';

		$data = array(
			'businessId',
			'locationIds',
		);

		$variables = new stdClass();

		$query_str = '{bearerTokenValidator( bearerToken: \"' . $this->get_config( 'api_key' ) . '\"){ ' . implode( ',', $data ) . ' }}';

		$query_arr = array(
			'query'     => '%s',
			'variables' => $variables,
		);

		$query = wp_json_encode( $query_arr );
		$query = sprintf( $query, $query_str );

		$url = $this->build_url( $endpoint );

		$args = array();

		$args['method']        = 'POST';
		$args['body']          = $query;
		$args['cache_enabled'] = false;

		$request  = $this->request( $url, $args );
		$response = $request->get_response();

		if ( array_key_exists( 'errors', $response ) ) {
			return false;
		}

		if ( array_key_exists( 'message', $response ) ) {
			return false;
		}

		$location_ids   = $response['data']['bearerTokenValidator']['locationIds'];
		$transient_name = $this->transient_name( 'location_ids' );
		set_transient( $transient_name, $location_ids, 300 );

		return $response['data']['bearerTokenValidator'];
	}

	public function get_location_ids() {

		// Check if we already have a valid transient list of location ids
		$transient_name = $this->transient_name( 'location_ids' );

		$transient = get_transient( $transient_name );

		if ( false !== $transient && '' !== $transient ) {
			return $transient;
		}

		$request = $this->validate_key();

		if ( false === $request ) {
			return false;
		}

		$location_ids = $request['locationIds'];

		// cache the location_ids list for a short time for subsequent requests
		set_transient( $transient_name, $location_ids, 300 );

		return $location_ids;
	}

	public function get_location_details_from_graph( $lid, $details = array() ) {

		$this->bid = $this->get_config( 'bid' );

		// Default properties to retrieve if we didn't receive an array of specific fields
		if ( empty( $details ) ) {
			$details = array(
				'id',
				'token',
				'name',
				'locationCode',
				'locationColor',
				'website',
				'subdomain',
				'locale',
				'sfSubscriptionTier',
				'businessId',
				'email',
				'createdAt',
				'active',
				'physicalAddress { streetAddress1, streetAddress2, city, locale, postalCode, country, latitude, longitude}',
			);
		}

		$sf_settings = implode(
			',',
			array(
				'id',
				'darkFontColor',
				'darkSecondaryColor',
				'lightFontColor',
				'lightSecondaryColor',
				'primaryColor',
				'shopShowItemPricing',
				'theme',
				'shopAllowAutobook',
				'buttonStyle',
				'domains{id,name,domainableType}',
			)
		);

		$details[] = 'storefrontSetting{' . $sf_settings . '},';

		$sf_shop_settings = implode(
			',',
			array(
				'id',
				'allowedFilters',
				'seoTitle',
				'shopShowItemPricing',
				'sortBy',
			)
		);

		$details[] = 'storefrontShopSettings{' . $sf_shop_settings . '}';

		// Not used but need to include for the GQL query
		$variables = new stdClass();

		// Building the GQL Query
		$query_arr = array(
			'query'     => '{getLocationDetails (id: ' . $lid . ') {' . implode( ',', $details ) . '}}',
			'variables' => $variables,
		);

		$query = wp_json_encode( $query_arr );

		$args['method'] = 'POST';
		$args['body']   = $query;

		$args['cache_enabled'] = false;

		// Build the request URL
		$endpoint = 'v1/external/graphql';
		$url      = $this->build_url( $endpoint );

		// Do the request and pluck the response data
		$request = $this->request( $url, $args );

		// If the request failed lets bail out
		if ( $request->is_error() || is_wp_error( $request ) ) {
			return false;
		}

		$response = $request->get_response();

		if ( array_key_exists( 'errors', $response ) && ! empty( $response['errors'] ) ) {
			return false;
		}

		$data = $response['data']['getLocationDetails'];

		$data['fullName'] = "{$data['name']} ({$data['locationCode']})";

		$env = $this->get_config( 'tg_env' );

		$domains_array = $response['data']['getLocationDetails']['storefrontSetting']['domains'];

		if (!empty($domains_array) && isset($domains_array[0]['name'])) {
		    $storefront_url = 'https://' . $domains_array[0]['name'];
		} else {
		    $storefront_url = 'https://' . $data['subdomain'] . '.' . $env;
		}

		$data['sf_url']      = trailingslashit( $storefront_url );
		$data['cart_url']    = trailingslashit( $storefront_url ) . 'cart?externalRedirect=true';
		$data['signup_url']  = trailingslashit( $storefront_url ) . 'signup';
		$data['login_url']   = trailingslashit( $storefront_url ) . 'login';
		$data['add_to_cart'] = trailingslashit( $storefront_url ) . 'addToCart';

		return $data;
	}

	public function get_categories_from_graph( $lid, $keyword = false ) {
		$endpoint = 'v1/external/graphql';

		$url = $this->build_url( $endpoint );

		$fields = array(
			'id',
			'name',
			'slug',
			'sfSubCategories {id,name,sfCategories{id}}',
		);

		$variables = new stdClass();

		$search = '';
		if ( false !== $keyword && is_string( $keyword ) ) {
			$search = "search: {$keyword}";
		}

		$query_str = '{getStorefrontCagetories( locationId: ' . $lid . $search . ') {' . implode( ',', $fields ) . '}}';

		$query_arr = array(
			'query'     => '%s',
			'variables' => $variables,
		);

		$query = wp_json_encode( $query_arr );
		$query = sprintf( $query, $query_str );

		$args           = array();
		$args['method'] = 'POST';
		$args['body']   = $query;

		$request = $this->request( $url, $args );

		if ( $request->is_error() || is_wp_error( $request ) ) {
			return false;
		}

		$response = $request->get_response();

		if ( array_key_exists( 'errors', $response ) && ! empty( $response['errors'] ) ) {
			return false;
		}

		return $response['data']['getStorefrontCagetories'];
	}

	public function get_inventories_from_graph( $lid, $page = 1, $per_page = 10 ) {

		$query_str = '{ getInventories ( perPage: ' . $per_page . ', locationId: ' . $lid . ' page: ' . $page . ') {';

		$metadata   = array( 'currentPage', 'limitValue', 'totalCount', 'totalPages' );
		$query_str .= 'metadata {' . implode( ',', $metadata ) . '}';

		$product_fields = $this->default_product_fields();

		$query_str .= '... on Inventories { collection { ... on Product {' . implode( ',', $product_fields ) . '}';

		$bundle_fields = $this->default_bundle_fields();

		$query_str .= '... on Bundle {' . implode( ',', $bundle_fields ) . '} ';

		$add_on_fields = $this->default_addon_fields();

		$query_str .= '... on AddOn {' . implode( ',', $add_on_fields ) . '}}}}}';

		$variables = new stdClass();

		// Building the GQL Query
		$query_arr = array(
			'query'     => '%s',
			'variables' => $variables,
		);

		$query = wp_json_encode( $query_arr );
		$query = sprintf( $query, $query_str );

		$endpoint = 'v1/external/graphql';

		$args['method'] = 'POST';
		$args['body']   = $query;

		$args['cache_enabled'] = false;

		$url = $this->build_url( $endpoint );

		$request = $this->request( $url, $args );

		if ( $request->is_error() || is_wp_error( $request ) ) {
			return false;
		}

		$response = $request->get_response();
		$data     = $response['data']['getInventories'];

		return $data;
	}

	// Takes location id and the ID of a product, addon, or bundle and returns bool if the item was found from the API
	public function item_exists( $lid, $id ) {

		$endpoint   = 'v1/external/graphql';
		$query_str  = '{ getInventories ( perPage: 1, locationId: ' . $lid . ', page: 1, id: ' . $id . ') {';
		$query_str .= 'metadata { totalCount }';
		$query_arr  = array(
			'query' => '%s',
		);

		$query = wp_json_encode( $query_arr );
		$query = sprintf( $query, $query_str );

		$args['method'] = 'POST';
		$args['body']   = $query;

		$args['cache_enabled'] = false;

		$url = $this->build_url( $endpoint );

		$request = $this->request( $url, $args );

		if ( $request->is_error() || is_wp_error( $request ) ) {
			return false;
		}

		$response = $request->get_response();

		$count = isset( $response['data']['getInventories']['metadata']['totalCount'] ) ? $response['data']['getInventories']['metadata']['totalCount'] : 0;
		if ( $count > 0 ) {
			return true;
		}
		return false;
	}

	public function default_product_fields() {
		return apply_filters(
			'tapgrein_default_product_fields',
			array(
				'active',
				'categoryId',
				'createdAt',
				'dailyPrice',
				'defaultPricing',
				'description',
				'flatPrice',
				'flatPrices { amount, name }',
				'gaDescription',
				'gaKeywords',
				'halfDayPrice',
				'height',
				'heightFt',
				'holidayHourlyPrice',
				'hourlyPrice',
				'id',
				'inventoryNotes',
				'length',
				'lengthFt',
				'locationId',
				'material',
				'monthlyPrice',
				'name',
				'notes',
				'pictures { url, imgixUrl }',
				'productGroupId',
				'productType',
				//'purchasePrice',
				'quantity',
				'serializationStatus',
				'sfCategories {id,name,slug}',
				'sfSubCategories{id,name}',
				'shouldIncludeNotesInRentals',
				'showPriceStorefront',
				'slug',
				'storefrontShared',
				'taxExempt',
				'token',
				'updatedAt',
				'warehouseLocation',
				'weeklyPrice',
				'weight',
				'width',
				'widthFt',
			)
		);
	}

	public function default_bundle_fields() {
		return apply_filters(
			'tg_default_bundle_fields',
			array(
				'active',
				'categoryId',
				'createdAt',
				'dailyPrice',
				'defaultPricing',
				'description',
				'discountEligible',
				'discountPercent',
				'flatPrices { amount, name }',
				'gaDescription',
				'gaKeywords',
				'halfDayPrice',
				'hourlyPrice',
				'id',
				'inventoryNotes',
				'locationId',
				'monthlyPrice',
				'name',
				'notes',
				'pictures {url, imgixUrl}',
				'productType',
				//'purchasePrice',
				'sfCategories {id,name,slug}',
				'sfSubCategories{id,name}',
				'shouldIncludeNotesInRentals',
				'showItemsToCustomer',
				'showPriceStorefront',
				'slug',
				'storefrontShared',
				'token',
				'updatedAt',
				'weeklyPrice',
			)
		);
	}

	public function default_addon_fields() {
		return apply_filters(
			'tg_default_addon_fields',
			array(
				'active',
				'addOnGroupId',
				'categoryId',
				'createdAt',
				'description',
				'gaDescription',
				'gaKeywords',
				'height',
				'heightFt',
				'id',
				'inventoryNotes',
				'length',
				'lengthFt',
				'locationId',
				'material',
				'name',
				'notes',
				'pictures { url, imgixUrl }',
				'pricing',
				'productType',
				//'purchasePrice',
				'quantity',
				'quantitySold',
				'sfCategories {id,name,slug}',
				'sfSubCategories{id,name}',
				'shouldIncludeNotesInRentals',
				'showPriceStorefront',
				'slug',
				'storefrontShared',
				'taxExempt',
				'token',
				'updatedAt',
				'warehouseLocation',
				'weight',
				'width',
				'widthFt',
			)
		);
	}

	public function get_location_details_from_api() {

		$transient_name = $this->transient_name( 'locations' );

		// The locations come back from the login request, but maybe better to fetch each one individually?
		$request  = $this->tgdev_login_location();
		$response = $request->get_response();

		// Tapgoods_Helpers::tgdd( $response );

		$location_info = array();
		$option_keys   = array(
			'token',
			'name',
			'fullName',
			'locationCode',
			'locationColor',
			'website',
			'subdomain',
			'locale',
			'showUnitPricingToCustomers',
			'businessId',
		);

		foreach ( $response['data']['locations'] as $location ) {

			// Grab the storefront settings from the API

			$location_ids[] = $location['id'];
			foreach ( $location as $k => $v ) {
				if ( in_array( $k, $option_keys, true ) ) {
					$location_info[ $location['id'] ][ $k ] = $v;
				}
				$storefront_url = 'https://' . $location['subdomain'] . '.tapgoods.com';

				$location_info[ $location['id'] ]['cart_url']       = trailingslashit( $storefront_url ) . 'cart';
				$location_info[ $location['id'] ]['signup_url']     = trailingslashit( $storefront_url ) . 'signup';
				$location_info[ $location['id'] ]['login_url']      = trailingslashit( $storefront_url ) . 'login';

			}
		}

		update_option( 'tg_locations_info', $location_info );
		set_transient( $transient_name, $location_ids, 300 );

		return $location_info;
	}

	public function get_inventory_from_rest( $lid, $page = 1 ) {

		$endpoint = 'api/portal/inventories/paginated';

		$params = array(
			'location_id' => $lid,
			'per'         => 10, // @TODO respect pagination
			'page'        => $page,
		);
		$url    = $this->build_url( $endpoint, $params );

		$request  = $this->request( $url );

		if ( $request->is_error() ) {
			return false;
		}

		$response = $request->get_response();

		return $response;
	}

	public function get_product_from_rest( $token, $lid, $extra_params = array() ) {

		$endpoint = "api/portal/products/{$token}";
		$params   = array_merge(
			array(
				'location_id' => $lid,
			),
			$extra_params
		);

		$url = $this->build_url( $endpoint, $params );

		$request = $this->request( $url );

		if ( $request->is_error() || is_wp_error( $request ) ) {
			return false;
		}

		$response = $request->get_response();
		return $response['product'];
	}

	public function available_in_period( $lid, $start = null, $end = null, $product = array(), $bundles = array(), $add_ons = array() ) {
		$endpoint = 'api/portal/inventories/available_in_period';
		$params   = array(
			'location_id' => $lid,
		);

		$url = $this->build_url( $endpoint, $params );

		$args['method'] = 'POST';
		$args['body']   = $query;
	}

	private function set_tokens( $tokens ) {
		Tapgoods_Helpers::tgqm( 'set_tokens' );
		Tapgoods_Helpers::tgqm( $tokens );

		if ( is_array( $tokens ) ) {
			foreach ( $tokens as $k => $v ) {
				$this->tokens[ $k ] = $v;
			}
		}
		$transient_name = $this->transient_name( 'location_tokens' );
		set_transient( $transient_name, $tokens );
	}

	private function set_tokens_admin( $tokens ) {
		Tapgoods_Helpers::tgqm( 'set_tokens' );
		Tapgoods_Helpers::tgqm( $tokens );

		if ( is_array( $tokens ) ) {
			foreach ( $tokens as $k => $v ) {
				$this->admin_tokens[ $k ] = $v;
			}
		}
		$transient_name = $this->transient_name( 'location_tokens' );
		set_transient( $transient_name, $tokens );
	}

	public function tgdev_login_admin() {
		$endpoint = 'api/admin_auth/sign_in';

		$user = tapgrein_getenv_docker( 'admin_user', false );
		$pass = tapgrein_getenv_docker( 'admin_pass', false );
		if ( false === $user || false === $pass ) {
			return false;
		}

		$creds = wp_json_encode(
			array(
				'email'    => $user,
				'password' => $pass,
			)
		);

		$args = array(
			'method'        => 'POST',
			'body'          => $creds,
			'cache_enabled' => false,
		);

		$url = $this->build_url( $endpoint );

		$response = $this->request( $url, $args );

		if ( 401 === $response->get_http_code() ) {
			throw new TG_Invalid_Auth_Excpetion();
		}

		$cookies = $response->get_cookies();
		foreach ( $cookies as $c ) {
			if ( 'business-id' === $c->name ) {
				$this->bid = $c->value;
			}
		}

		$tokens = array();

		$tokens['client']       = $response->get_header( 'client' );
		$tokens['access-token'] = $response->get_header( 'access-token' );
		$tokens['uid']          = $response->get_header( 'uid' );

		$valid = $this->validate_location_token( $tokens );
		if ( $valid ) {
			$this->valid = true;
			$this->set_tokens_admin( $tokens );
		}

		return $response;
	}

	/**
	 * Development function to get tokens from location login
	 * Uses localdev.env file to pass location_user and location_pass
	 * Tokens and Business ID set for subsequent requests after login
	 */
	public function tgdev_login_location() {

		$endpoint = 'api/auth/sign_in';

		$user = tapgrein_getenv_docker( 'location_user', false );
		$pass = tapgrein_getenv_docker( 'location_pass', false );
		if ( false === $user || false === $pass ) {
			return false;
		}

		$creds = json_encode(
			array(
				'email'                 => $user,
				'password'              => $pass,
				'password_inquiry_only' => true,
			)
		);

		$args = array(
			'method'        => 'POST',
			'body'          => $creds,
			'cache_enabled' => false,
		);

		$url = $this->build_url( $endpoint );
		$response = $this->request( $url, $args );

		if ( 401 === $response->get_http_code() ) {
			throw new TG_Invalid_Auth_Excpetion();
		}

		$cookies = $response->get_cookies();
		foreach ( $cookies as $c ) {
			if ( 'business-id' === $c->name ) {
				$this->bid = $c->value;
			}
		}

		$tokens = array();

		$tokens['client']       = $response->get_header( 'client' );
		$tokens['access-token'] = $response->get_header( 'access-token' );
		$tokens['uid']          = $response->get_header( 'uid' );

		$valid = $this->validate_location_token( $tokens );
		if ( $valid ) {
			$this->valid = true;
			$this->set_tokens( $tokens );
		}

		return $response;
	}

	public function tgdev_get_admin_tokens() {
		// check if we have tokens
		$transient_name = $this->transient_name( 'admin_tokens' );

		$tokens = get_transient( $transient_name );

		if ( ! $tokens || empty( $this->tokens ) ) {
			// if not, get new tokens from the API
			Tapgoods_Helpers::tgqm( 'get new tokens' );
			$tokens = $this->tgdev_get_new_admin_tokens( $transient_name );
		}

		// validate the tokens
		$valid = $this->validate_admin_token( $tokens );
		Tapgoods_Helpers::tgqm( $valid );

		return $tokens;
	}

	public function tgdev_get_location_tokens() {
		// check if we have tokens
		$transient_name = $this->transient_name( 'location_tokens' );

		$tokens = get_transient( $transient_name );

		if ( false === $tokens || empty( $tokens ) || false === $this->valid ) {
			// if not, get new tokens from the API
			Tapgoods_Helpers::tgqm( 'tokens expired' );
			$tokens = $this->tgdev_get_new_location_tokens( $transient_name );
		}

		return $tokens;
	}

	private function tgdev_get_new_admin_tokens( $transient_name ) {
		$tokens = array();

		$response = $this->tgdev_login_admin();

		$tokens['client']       = $response->get_header( 'client' );
		$tokens['access-token'] = $response->get_header( 'access-token' );
		$tokens['uid']          = $response->get_header( 'uid' );

		set_transient( $transient_name, $tokens );
		return $tokens;
	}

	private function tgdev_get_new_location_tokens( $transient_name ) {
		$tokens = array();

		$response = $this->tgdev_login_location();

		// if this fails throw Auth exception?

		$tokens['client']       = $response->get_header( 'client' );
		$tokens['access-token'] = $response->get_header( 'access-token' );
		$tokens['uid']          = $response->get_header( 'uid' );

		$valid = $this->validate_location_token( $tokens );
		if ( $valid ) {
			$this->valid = true;
			$this->set_tokens( $tokens );
		}
		Tapgoods_Helpers::tgqm( 'setting transient: ' . $transient_name );

		set_transient( $transient_name, $tokens );
		return $tokens;
	}

	public function tgdev_test_api() {
		$test = $this->validate_key();
		Tapgoods_Helpers::tgqm( $test );

		if ( false === $test ) {
			return false;
		}

		return true;
	}

	public function validate_admin_token( $tokens ) {
		$endpoint = 'api/admin_auth/validate_token';

		Tapgoods_Helpers::tgqm( 'tokens: ' );
		Tapgoods_Helpers::tgqm( $tokens );

		$params   = array(
			'uid'          => $tokens['uid'],
			'client'       => $tokens['client'],
			'access-token' => $tokens['access-token'],
		);

		$args['cache_enabled'] = false;

		$url = $this->build_url( $endpoint, $params );

		$response = $this->request( $url, $args );
		return $response;
	}

	public function validate_location_token( $tokens, $retry = false ) {
		$endpoint = 'api/auth/validate_token';

		Tapgoods_Helpers::tgqm( 'tokens: ' );
		Tapgoods_Helpers::tgqm( $tokens );

		$params   = array(
			'uid'          => $tokens['uid'],
			'client'       => $tokens['client'],
			'access-token' => $tokens['access-token'],
		);

		$args['cache_enabled'] = false;

		$url = $this->build_url( $endpoint, $params );

		$response = $this->request( $url, $args );

		$success = $response->get_response();

		if ( false === $success['success'] && $retry ) {
			throw new TG_Invalid_Auth_Excpetion();
		}

		return ( true === $success['success'] ) ? true : false;
	}
}
