<?php

if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly
class Tapgoods_Shortcodes {

	// register plugin shortcodes
	private static $instance = null;
	public static $shortcodes_info;

	private function __construct() {

		$shortcodes = self::get_shortcodes();
		$this->register_shortcodes( $shortcodes );
	}

	private function register_shortcodes( $shortcodes ) {

		foreach ( $shortcodes as $shortcode => $info ) {
			$template = self::get_template( $shortcode );
			if ( ! shortcode_exists( $shortcode ) ) {
				if ( $template && is_file( $template ) && file_exists( $template ) ) {
					add_shortcode( $shortcode, [ $this, $shortcode ] );
				}
			}
		}
	}

	public function __call( $tag, $args ) {
		if ( array_key_exists( $tag, self::get_shortcodes() ) ) {
			return $this->shortcode_handler( self::get_template( $tag ), $args );
		}
	}

	private static function get_template( $tag ) {
		$tag = str_replace( 'tapgoods-', 'tg-', $tag );
		$template_name = str_replace( '_', '-', $tag );

		return tg_locate_template( $template_name );
	}

	// This function receives the arguments passed to the shortcode callback and loads the PHP template from /public/partials
	protected function shortcode_handler( $template, $args ) {

		// Verificar que el template sea válido antes de incluirlo
		if ( ! $template || ! is_file( $template ) || ! file_exists( $template ) ) {
			return '';
		}

		// Force enqueue of TapGoods scripts and styles when shortcode is used
		$this->force_enqueue_assets($args[2]);

		$tag     = $args[2];
		$content = ( '' !== $args[1] ) ? $args[1] : false;
		$atts    = Tapgoods_Shortcodes::get_atts( $tag );
		$atts    = shortcode_atts( $atts, $args[0], $tag );

		ob_start();
		include $template;
		$output = ob_get_clean();
		
		// For cart shortcode, localize script with cart data after template execution
		if ($tag === 'tg-cart' && wp_script_is('tapgoods-cart-init', 'enqueued')) {
			// Extract cart URL from the generated HTML
			if (preg_match('/data-target="([^"]+)"/', $output, $matches)) {
				$cart_url = html_entity_decode($matches[1]);
				wp_localize_script('tapgoods-cart-init', 'tapgoodsCartData', array(
					'cartUrl' => $cart_url,
					'ajaxUrl' => admin_url('admin-ajax.php')
				));
				error_log('TapGoods: Localized cart script with URL: ' . $cart_url);
			}
		}
		
		return do_shortcode( shortcode_unautop( $output ) );
	}

	/**
	 * Force enqueue of TapGoods assets when shortcode is used
	 */
	private function force_enqueue_assets($tag = '') {
		// Enqueue main public styles
		if (!wp_style_is('tapgoods-public', 'enqueued')) {
			wp_enqueue_style(
				'tapgoods-public',
				plugin_dir_url(dirname(__FILE__)) . 'public/css/tapgoods-public.css',
				array(),
				TAPGOODSWP_VERSION
			);
		}

		// Enqueue custom styles
		if (!wp_style_is('tapgoods-custom', 'enqueued')) {
			wp_enqueue_style(
				'tapgoods-custom',
				plugin_dir_url(dirname(__FILE__)) . 'public/css/tapgoods-custom.css',
				array(),
				TAPGOODSWP_VERSION
			);
		}

		// Enqueue inline styles
		if (!wp_style_is('tapgoods-inline-styles', 'enqueued')) {
			wp_enqueue_style(
				'tapgoods-inline-styles',
				plugin_dir_url(dirname(__FILE__)) . 'assets/css/tapgoods-inline-styles.css',
				array(),
				TAPGOODSWP_VERSION
			);
		}

		// Disabled - using tapgoods-public-complete.js instead
		// if (!wp_script_is('tapgoods-public', 'enqueued')) {
		//	wp_enqueue_script(
		//		'tapgoods-public',
		//		plugin_dir_url(dirname(__FILE__)) . 'public/js/tapgoods-public.js',
		//		array('jquery'),
		//		TAPGOODSWP_VERSION,
		//		true
		//	);
		// }

		// Enqueue inline script
		if (!wp_script_is('tapgoods-public-inline', 'enqueued')) {
			wp_enqueue_script(
				'tapgoods-public-inline',
				plugin_dir_url(dirname(__FILE__)) . 'public/js/tapgoods-public-inline.js',
				array('jquery'),
				TAPGOODSWP_VERSION,
				true
			);

			// Localize script with necessary data
			wp_localize_script('tapgoods-public-inline', 'tg_public_vars', array(
				'ajaxurl' => admin_url('admin-ajax.php'),
				'default_location' => get_option('tg_default_location'),
				'plugin_url' => plugin_dir_url(dirname(__FILE__))
			));
		}

		// Add location styles inline
		$location_styles = $this->get_location_styles();
		if (!empty($location_styles)) {
			wp_add_inline_style('tapgoods-public', $location_styles);
		}
		
		// Enqueue specific scripts based on shortcode type
		if ($tag === 'tg-cart') {
			error_log('TapGoods: Enqueuing cart-init script for shortcode: ' . $tag);
			if (!wp_script_is('tapgoods-cart-init', 'enqueued')) {
				wp_enqueue_script(
					'tapgoods-cart-init',
					plugin_dir_url(dirname(__FILE__)) . 'public/js/tapgoods-cart-init.js',
					array(),
					TAPGOODSWP_VERSION,
					true
				);
				error_log('TapGoods: Successfully enqueued tapgoods-cart-init.js');
			} else {
				error_log('TapGoods: tapgoods-cart-init.js was already enqueued');
			}
			
			// Set cart-specific flag to pass data later
			global $tapgoods_cart_needs_data;
			$tapgoods_cart_needs_data = true;
		}
	}

	/**
	 * Get location styles
	 */
	private function get_location_styles() {
		if (function_exists('tg_location_styles')) {
			return tg_location_styles();
		}
		return '';
	}

	public static function get_shortcodes() {

		$path       = TAPGOODS_PLUGIN_PATH . '/includes/shortcodes.json';
		$json       = Tapgoods_Filesystem::get_file( $path );
		$shortcodes = json_decode( $json, true );
		return $shortcodes;
	}

	public static function get_atts( $shortcode ) {

		$shortcodes = self::get_shortcodes();
		$atts       = [];
		foreach ( $shortcodes[ $shortcode ]['atts'] as $att => $data ) {
			$atts[ $att ] = $data['default'];
		}
		return $atts;
	}

	public function __clone() { }

	public function __wakeup() { }

	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}
}
