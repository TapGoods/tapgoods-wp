<?php

if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly

class Tapgoods_Filesystem {

	// static reference to filesytem to prevent doing this more than once
	private static $filesystem = null;

	public static function get_filesystem() {

		if ( null === self::$filesystem ) {
			global $wp_filesystem;
			include_once ABSPATH . 'wp-admin/includes/file.php';
			WP_Filesystem();
			self::$filesystem = $wp_filesystem;
		}
		return self::$filesystem;
	}

	// Uses the WP Filesystem to get the contents of a file
	public static function get_file( $path ) {

		$filesystem = self::get_filesystem();
		return $filesystem->get_contents( $path );
	}


	public static function put_file( $submit, $contents, $filepath, $nonce, $nonce_name ) {

		// return false if not handling a POST request
		if ( empty( $_POST ) ) {
			return false;
		}

		check_admin_referer( $nonce, $nonce_name );

		$method      = '';
		$form_fields = array( $submit, $contents );
		$url         = wp_nonce_url( 'options.php?page=tapgoods', $nonce );

		// Check if we have credentials to write files
		if ( false === ( $creds = request_filesystem_credentials( $url, $method, false, false, $form_fields, true ) ) ) {
			return true;
		}

		if ( ! WP_Filesystem( $creds ) ) {
			// our credentials were no good, ask the user for them again
			request_filesystem_credentials( $url, $method, true, false, $form_fields, true );
			return true;
		}

		$filesystem = self::get_filesystem();

		$folder_exists = $filesystem->exists( TAPGOODS_UPLOADS );
		if ( ! $folder_exists ) {
			$make_folder = $filesystem->mkdir( TAPGOODS_UPLOADS );
			echo "couldn't make folder";
			if ( ! $make_folder ) {
				return false;
			}
		}

		$success = $filesystem->put_contents( $filepath, $contents, FS_CHMOD_FILE );
		if ( ! $success ) {
			return false;
		}
		return $success;
	}
}
