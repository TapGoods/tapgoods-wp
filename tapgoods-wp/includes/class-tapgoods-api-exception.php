<?php
/**
 * Classes for API client exceptions
 */

class Tapgoods_Error {
	public static function invalid_parameters( $required ) {
		$code    = 'TG_Err: Invalid_Params';
		$message = 'Missing required parameter: ' . implode( ',', $required );
		return new WP_Error( $code, $message );
	}

	public static function invalid_auth() {
		$code = 'TG_Err: Invalid Auth';
		$message = 'Unablid to authenticate, please check your API Key';
		return new WP_Error( $code, $message );
	}
}

class TG_Bad_Request_Exception extends Exception { //phpcs:ignore

	public function __construct( $url, $response ) {
		$message = "400 HTTP Error: Bad request to $url<br>Details: {$reponse['details']}<br/>Problem: {$repsonse['problem']}";
		parent::__construct( $message, E_USER_WARNING, null );
	}
	public function __toString() {
		return __CLASS__ . ": {$this->message}\n";
	}
}

class TG_Invalid_Auth_Excpetion extends Exception { //phpcs:ignore

	public function __construct() {
		$message = 'Unablid to authenticate, please check your API Key';
		parent::__construct( $message, E_USER_WARNING, null );
	}

	public function __toString() {
		return __CLASS__ . ": {$this->message}\n";
	}
}

class TG_Unauthorized_Request_Exception extends Exception { //phpcs:ignore

	public function __construct() {
		$message = '401 HTTP Error: Error not Authorized, please check your API Key';
		parent::__construct( $message, E_USER_WARNING, null );
	}

	public function __toString() {
		return __CLASS__ . ": {$this->message}\n";
	}
}

class TG_Internal_Server_Exception extends Exception { //phpcs:ignore

	public function __construct() {
		$message = '500 HTTP ERROR: Internal server error';
		parent::__construct( $message, E_USER_WARNING, null );
	}

	public function __toString() {
		return __CLASS__ . ": {$this->message}\n";
	}
}

class TG_Unknown_Exception extends Exception { //phpcs:ignore

	public function __construct( $error = '' ) {

		if ( is_wp_error( $error ) ) {
			$errors = $error->errors;
			$error = '';
			foreach ( $errors as $k => $v ) {
				$error .= "Error $k: (" . implode( ',', $v ) . ')';
			}
		}

		$message = "An unknown error when contacting the TG API: $error";
		parent::__construct( $message, E_USER_WARNING, null );
	}

	public function __toString() {
		return __CLASS__ . ": {$this->message}\n";
	}
}

class TG_HTTP_Request_Failed_Exception extends Exception { //phpcs:ignore

	public function __construct( $url ) {
		$message = "URL failed to respond: $url";
		if ( '' === $url ) {
			$message = 'No URL Provided';
		}
		parent::__construct( $message, E_USER_WARNING, null );
	}

	public function __toString() {
		return __CLASS__ . ": {$this->message}\n";
	}
}
