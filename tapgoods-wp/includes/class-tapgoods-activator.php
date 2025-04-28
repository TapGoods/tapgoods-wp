<?php

if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly

// Fired during plugin activation

class Tapgoods_Activator {

	public static function activate() {
		if ( ! self::option_exists( 'tg_key' ) ) {
			add_option( 'tg_key', '' );
		}

		if ( ! self::option_exists( 'tg_api_connected') ) {
			add_option( 'tg_api_connected', false );
		}

		// If the post type permalink option doesn't exist yet, we can add a default, otherwise we should leave whatever value is there
		if ( ! self::option_exists( 'tg_inventory_permalink' ) ) {
			add_option( 'tg_inventory_permalink', 'shop/%tg_category%' );
		}
	}

	// This could be in the helpers class, but activator fires before the rest of the plugin or dependencies are loaded and we're only using it here
	public static function option_exists( $name, $site_wide = false ) {
		global $wpdb;
	
		// Determine the correct table prefix (global or site-specific)
		$prefix = $site_wide ? $wpdb->base_prefix : $wpdb->prefix;
	
		// Use a unique cache key
		$cache_key = 'option_exists_' . md5($prefix . $name);
		$cached_result = wp_cache_get($cache_key, 'options_cache');
	
		// If cached data exists, return it directly
		if ( false !== $cached_result ) {
			return (int) $cached_result > 0;
		}
	
		// Check the existence of the option without direct database queries
		if ( $site_wide ) {
			$option = get_site_option( $name, null );
		} else {
			$option = get_option( $name, null );
		}
	
		// Determine existence based on option retrieval
		$exists = ($option !== null);
	
		// Store the result in cache for 5 minutes
		wp_cache_set($cache_key, (int) $exists, 'options_cache', 300);
	
		return $exists;
	}
	
	
	
	
	
	
	
	
	
	
}
