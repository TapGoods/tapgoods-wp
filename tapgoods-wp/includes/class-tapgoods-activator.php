<?php

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
		return $wpdb->query(
			$wpdb->prepare(
				'SELECT * FROM %s WHERE option_name = %s LIMIT 1',
				array(
					$site_wide ? $wpdb->base_prefix : $wpdb->prefix,
					$name,
				)
			)
		);
	}
}
