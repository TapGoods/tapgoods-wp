<?php

if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly

class Tapgoods_API_Response {
	private $http_code;
	private $response;
	private $headers;
	private $cookies = array();
	private $error   = false;

	public function __construct( $response ) {
		$this->set_http_code( $response );
		$this->set_headers( $response );
		$this->set_cookies( $response );
		$this->set_response( $response );
	}

	public function set_http_code( $response ) {
		$this->http_code = wp_remote_retrieve_response_code( $response );

		if ( 200 !== $this->http_code ) {
			$this->error = true;
		}
	}

	/**
	 * A response for a request that was never sent.
	 *
	 * An error with no HTTP status, whose body has the GraphQL error shape, so
	 * every caller takes the failure path it already has: the client methods check
	 * is_error() and validate_key() checks for an `errors` key. A WP_Error would
	 * not do: the client methods call ->is_error() on the result first, and that
	 * fatals on a WP_Error.
	 *
	 * @param string $message Why the request was not sent.
	 * @return Tapgoods_API_Response
	 */
	public static function not_sent( $message ) {
		$res            = ( new ReflectionClass( __CLASS__ ) )->newInstanceWithoutConstructor();
		$res->http_code = null;
		$res->headers   = array();
		$res->error     = true;
		$res->response  = array( 'errors' => array( array( 'message' => (string) $message ) ) );
		return $res;
	}

	public function is_error() {
		return $this->error;
	}

	public function get_http_code() {
		return $this->http_code;
	}

	public function set_headers( $response ) {
		$this->headers = wp_remote_retrieve_headers( $response );
	}

	public function get_header( $header ) {
		return ( isset( $this->headers[ $header ] ) ? $this->headers[ $header ] : null );
	}

	public function get_headers() {
		return $this->headers;
	}

	public function set_cookies( $response ) {
		$this->cookies = wp_remote_retrieve_cookies( $response );
	}

	public function get_cookie( $cookie ) {
		return ( isset( $this->cookies[ $cookie ] ) ? $this->cookies[ $cookie ] : null );
	}

	public function get_cookies() {
		return $this->cookies;
	}

	public function set_response( $response ) {
		$this->response = json_decode( preg_replace( '/[\x00-\x1F\x80-\xFF]/', '', wp_remote_retrieve_body( $response ) ), true );
	}

	public function get_response() {
		return $this->response;
	}
}
