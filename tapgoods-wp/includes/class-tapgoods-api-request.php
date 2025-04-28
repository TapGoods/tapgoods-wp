<?php

if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly

class Tapgoods_API_Request {

	private $config  = array();
	private $cookies = array();

	private $last_request_time;

	public function __construct( $config ) {

		if ( ! is_array( $config ) ) {
			throw new InvalidArgumeException( 'requires config array' );
		}

		$required = array( 'base_url', 'api_key' );
		$defaults = array(
			'response_type'    => 'json',
			'cache_enabled'    => false,
			'cache_lifetime'   => 60 * 1,     // Default cache 5 minutes
			'cache_prefix'     => 'tg_api_',
			'check_for_update' => true,
			'force_caching'    => false,
			'no_cache_routes'  => array(),
		);

		$config = wp_parse_args( $config, $defaults );

		if ( ! $this->verify_parameters( $required, $config ) ) {
			throw new InvalidParametersException( 'base_url is required' );
		}

		$this->set_config( $config );
	}

	public function request( $url, $args = array() ) {

		// Check if the cache is enabled via config
		if ( false !== $this->get_config( 'cache_enabled' ) ) {

			// Check if the cache is being explicitly disabled by the request
			if ( array_key_exists( 'cache_enabled', $args ) && false !== $args['cache_enabled'] ) {

				// Check if there's a transient for this request
				$transient_name = $this->transient_name( $url );
				$transient      = get_transient( $transient_name );

				if ( false !== $transient ) {
					return $transient;
				}

				$response = $this->do_request( $url, $args );

				// Skip caching and return if there was a WP Erorr
				if ( is_wp_error( $response ) ) {
					return $response;
				}

				// If we got a valid response that isn't in the excluded routes list, cache it
				if ( ( $this->is_cacheable( $url ) || $this->get_config( 'force_caching' ) ) && ! $response->is_error() ) {
					set_transient( $transient_name, $response, $this->get_config( 'cache_lifetime' ) );
				}
				return $response;
			}
		}

		// Response caching is disabled
		$response = $this->do_request( $url, $args );
		return $response;
	}

	private function is_cacheable( $url ) {

		$parse_url = wp_parse_url( $url );
		$route     = str_replace( $this->get_config( 'base_url' ), '', $url );

		if ( in_array( $route, $this->get_config( 'no_cache_routes' ), true ) ) {
			return false;
		}
		return true;
	}

	private function do_request( $url, $args ) {

		$headers = array(
			'Content-Type'  => 'application/json',
			'Cache-Control' => 'no-cache',
			'Host'          => preg_replace( '`https://`', '', $this->get_config( 'base_url' ) ),
			'Authorization' => 'Bearer ' . $this->get_config( 'api_key' ),
		);

		if ( array_key_exists( 'headers', $args ) ) {
			if ( array_key_exists( 'Host', $args['headers'] ) ) {
				$headers['Host'] = $args['headers']['Host'];
				unset( $args['headers']['Host'] );
			}
		}

		$args = array_merge_recursive(
			array(
				'timeout'     => '10',
				'redirection' => '3',
				'httpversion' => '1.1',
				'blocking'    => true,
				'headers'     => $headers,
				'sslverify'   => false,
				'cookies'     => $this->cookies,
			),
			$args
		);

		if ( ! isset( $args['method'] ) ) {
			$args['method'] = 'GET';
		}

		if ( 'POST' === $args['method'] && array_key_exists( 'body', $args ) ) {
			$args['headers']['Content-Length'] = strlen( $args['body'] );
		}

		if ( ! array_key_exists( 'method', $args ) ) {
			$args['method'] = 'GET';
		}

		$now = current_time( 'U' );
		if ( $this->last_request_time === $now ) {
			usleep( 400 * 1000 );
			$this->last_request_time = current_time( 'U' );
		}

		// tg_write_log( 'Remote Request: ' . $url );
		// tg_write_log( $args );

		$response = wp_remote_request( $url, $args );

		if ( is_wp_error( $response ) ) {

			if ( is_array( $response->errors ) && count( $response->errors ) == 1 ) {
				// If there was only 1 error let's see if I can deal with it
				$error = array_keys( $response->errors );
				$error = $error[0];

				switch ( $error ) {
					case 'http_request_failed':
						throw new TG_Http_Request_Failed_Exception( esc_url_raw( $url ) );
					default:
						// WordPress had some error that I do not know about
						throw new TG_Unknown_Error_Exception( esc_html( $response ) );
				}
				echo '1 error';
			} else {
				// WordPress had another error
				throw new TG_Unknown_Error_Exception( esc_html( $response ) );
			}
			die();
		}

		$res = new Tapgoods_API_Response( $response );
		$this->cookies = $res->get_cookies();
		return $res;
	}

	public function build_url( $endpoint, $params = null, $override = '', ) {

		$url = trailingslashit( $this->get_config( 'base_url' ) ) . $endpoint;

		if ( ! is_null( $params ) ) {
			$url = add_query_arg( $params, $url );
		}

		if ( '' !== $override ) {
			return $override;
		}

		return $url;
	}

	public function get_config( $param ) {
		return ( isset( $this->config[ $param ] ) ) ? $this->config[ $param ] : null;
	}

	public function set_config( $param, $value = null ) {
		if ( is_array( $param ) ) {
			foreach ( $param as $k => $v ) {
				$this->set_config( $k, $v );
			}
		} else {
			$this->config[ $param ] = $value;
		}
	}

	public function transient_name( $name ) {
		return $this->get_config( 'cache_prefix' ) . md5( $name );
	}

	public function verify_parameters( $required, $params ) {

		if ( ! is_array( $params ) ) {
			return false;
		}

		$params = array_keys( $params );

		foreach ( $required as $req ) {
			if ( in_array( $req, $params, true ) ) {
				return true;
			}
		}
		return false;
	}
}
