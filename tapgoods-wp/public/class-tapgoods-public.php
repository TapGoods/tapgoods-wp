<?php

if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly

// Public facing functionality

class Tapgoods_Public {

	private $plugin_name;
	private $version;

	public function __construct( $plugin_name, $version ) {

		$this->plugin_name = $plugin_name;
		$this->version     = $version;
	}

	public function enqueue_styles() {

		add_action( 'wp_enqueue_scripts', function() {
			wp_enqueue_style( 'custom-css',  'custom.css', array(), $this->version, 'all' );
		}, 15 );

		add_action( 'wp_enqueue_scripts', function() {
			wp_enqueue_style( $this->plugin_name . '-public-css', plugin_dir_url( __FILE__ ) . 'css/tapgoods-public.css', array( 'custom-css' ), $this->version, 'all' );
		}, 30 );

		add_action( 'wp_enqueue_scripts', function() {
			wp_enqueue_style( $this->plugin_name . '-custom-css', plugin_dir_url( __FILE__ ) . 'css/tapgoods-custom.css', array( $this->plugin_name . '-public-css' ), $this->version, 'all' );
		}, 50 );



		$sf_styles = tg_location_styles();
		wp_add_inline_style( $this->plugin_name . '-public-css', $sf_styles );
		wp_enqueue_style( 'dashicons' );
	}

	public function tapgoods_sf_css() {
		header( 'Content-type: text/css; charset: UTF-8' );
		require TAPGOODS_PLUGIN_PATH . 'public/css/tapgoods-sf-styles.php';
	}

	public function enqueue_scripts() { 
		$location = tg_get_wp_location_id();
		$cart_url = tg_get_cart_url($location);
	
		wp_enqueue_script($this->plugin_name . '-public', plugin_dir_url(__FILE__) . 'js/tapgoods-public.js', array('jquery'), $this->version, false);
		wp_enqueue_script(
			'tg-bootstrap',
			TAPGOODS_PLUGIN_URL . 'assets/js/bootstrap.bundle.min.js', // Archivo local en lugar de CDN
			array(),
			'5.3.3',
			true
		);
		
		wp_localize_script(
			$this->plugin_name . '-public',
			'tg_ajax',
			array(
				'ajaxurl'    => admin_url('admin-ajax.php'),
				'locationId' => $location,
				'cart_url'   => $cart_url,
				'domain'     => wp_parse_url(get_site_url(), PHP_URL_HOST),
			)
		);
	}
	
	

	public static function tg_locate_template( $template = '' ) {

		if ( empty( $template ) ) {
			return;
		}

		$tg_template    = sprintf( TAPGOODS_PLUGIN_PATH . 'public/partials/%s.php', $template );
		$theme_template = sprintf( get_stylesheet_directory() . '/tapgoods/%s.php', $template );

		$valid_tg_template    = file_exists( $tg_template );
		$valid_theme_template = file_exists( $theme_template );

		if ( $valid_theme_template ) {
			return $theme_template;
		}

		if ( $valid_tg_template ) {
			return $tg_template;
		}

		return $template;
	}

	public static function load_tg_template( $template = '' ) {

		if ( empty( $template ) ) {
			return;
		}

		$tg_template    = sprintf( TAPGOODS_PLUGIN_PATH . 'public/partials/%s.php', $template );
		$theme_template = sprintf( get_stylesheet_directory() . '/tapgoods/%s.php', $template );

		$template = file_exists( $theme_template ) ? $theme_template : $tg_template;

		ob_start();

		include $template;

		$contents = ob_get_contents();
		ob_end_clean();

		// echo $contents; //phpcs:ignore
		return $contents;
	}

	public function load_single_template( $template = '' ) {

		if ( '' === $template ) {
			return;
		}

		if ( is_admin() || ! is_singular( 'tg_inventory' ) ) {
			return $content;
		}

		$template = self::load_tg_template( $template );

		return $template;
	}

	public function load_single_content( $content, $id = null ) {

		if ( is_admin() || ! is_singular( 'tg_inventory' ) ) {
			return $content;
		}

		$template = $this->load_single_template( 'tg-product-single' );
		return $template;
	}

	public function tg_get_search_template( $template ) {
		global $wp_query;
		$post_type = get_query_var( 'post_type' );

		if ( empty( $wp_query->is_search ) || 'tg_inventory' !== $post_type ) {
			return $template;
		}

		$template = tg_locate_template( 'tg-search-results' );
		return $template;
	}

	public function tapgoods_search( $data ) {
		//check_ajax_referer( 'search', '_tgnonce_search' );

		if ( array_key_exists( 's', $_POST ) ) {
			$query = sanitize_text_field( wp_unslash( $_POST['s'] ) );
		}

		$product_query_args = array(
			'post_type'      => 'tg_inventory',
			'post_status'    => 'publish',
			'posts_per_page' => get_option( 'tg_posts_per_page', 12 ),
			's'              => $query,
			'fields'         => 'ids',
		);

		$terms_query_args = array(
			'taxonomy'   => array( 'tg_category', 'tg_tags' ),
			'hide_empty' => false,
			'number'     => 'all',
			'fields'     => 'all',
			'search'     => $query,
		);

		$product_query = new WP_Query( $product_query_args );
		$results       = array();
		$result_html   = '';

		if ( $product_query->have_posts() ) {
			$product_ids = apply_filters( 'tg_product_search_result_ids', $product_query->posts );

			foreach ( $product_ids as $pid ) {
				$title    = get_the_title( $pid );
				$url      = get_the_permalink( $pid );
				$pictures = get_post_meta( $pid, 'tg_pictures', true );
				$img_url  = '';

				if ( is_array( $pictures ) && count( $pictures ) > 0 ) {
					$img_url = $pictures[0]['imgixUrl'];
				}

				$results[] = array(
					'title' => $title,
					'url'   => $url,
					'img'   => $img_url,
				);

				$result_html .= $this->tg_result_item_html( $url, $title );
			}
		}

		$terms_query = new WP_Term_Query( $terms_query_args );

		if ( ! empty( $terms_query->terms ) ) {
			foreach ( $terms_query->terms as $term ) {
				$term_title = $term->name;
				$term_url   = get_term_link( $term, $term->taxonomy );
				$results[]  = array(
					'title' => $term_title,
					'url'   => $term_url,
				);
			}
			$result_html .= $this->tg_result_item_html( $term_url, $term_title );
		}

		wp_send_json_success( $result_html );
		die();
	}

	public static function get_img_tag( $url, $w = false, $h = false, $classes = false, $alt = '' ) {

		$cdn_url = self::get_cdn_url( $url, $w, $h ); //phpcs:ignore
		$srcset  = self::get_image_srcset( $url, $w, $h );

		$tag  = '<img ';
		$tag .= ( $w ) ? 'width="' . $w . '" ' : '';
		$tag .= ( $h ) ? 'height="' . $h . '" ' : '';
		$tag .= ( $classes ) ? 'class="' . $classes . '" ' : '';
		$tag .= 'src="' . $cdn_url . '" ';
		$tag .= 'srcset="' . $srcset . '" ';
		$tag .= 'alt="' . $alt . '">';
		return $tag;
	}

	private static function get_cdn_url( $base_url, $w = false, $h = false, $dpr = false, $q = false, $auto = 'format' ) {
		$params = array();

		if ( false !== $auto ) {
			$params['auto'] = $auto;
		}

		$params['ixlib'] = 'react-9.5.4';

		if ( false !== $w ) {
			$params['w'] = $w;
		}

		if ( false !== $h ) {
			$params['h'] = $h;
		}

		if ( false !== $dpr ) {
			$params['dpr'] = $dpr;
		}

		if ( false !== $q ) {
			$params['q'] = $q;
		}

		$url = add_query_arg( $params, $base_url );
		return $url;
	}

	private static function get_image_srcset( $base_url, $w = false, $h = false ) {
		$srcset  = '';
		$srcset .= self::get_cdn_url( $base_url, $w, $h, 1, 75, false ) . ' 1x, ';
		$srcset .= self::get_cdn_url( $base_url, $w, $h, 2, 50, false ) . ' 2x, ';
		$srcset .= self::get_cdn_url( $base_url, $w, $h, 3, 35, false ) . ' 3x, ';
		$srcset .= self::get_cdn_url( $base_url, $w, $h, 4, 23, false ) . ' 4x, ';
		$srcset .= self::get_cdn_url( $base_url, $w, $h, 5, 20, false ) . ' 5x,';
		return $srcset;
	}

	private function tg_result_item_html( $url, $title, $img = false ) {
		if ( false === $img ) {
			return '<li class="result-item"><a class="result-link p-2" href="' . $url . '">' . $title . '</a></li>';
		}
	}

	public function tg_add_body_classes( $classes ) {
		$tg_classes = array();

		global $post;

		if ( null !== $post && 'tg_inventory' === $post->post_type ) {
			$tg_id        = get_post_meta( $post->ID, 'tg_id', true );
			$tg_classes[] = 'product-' . $tg_id;
		}

		$location_id    = tg_get_tg_location_id();
		$location_class = 'location-' . $location_id;

		$tg_classes[] = $location_class;

		return array_merge( $classes, $tg_classes );
	}

}

