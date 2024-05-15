<?php
/**
 * Tapgoods Core Functions
 *
 * Core functions available on both the front-end and admin.
 *
 * @package Tapgoods\Functions
 * @version 0.1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Include core functions (available in both admin and frontend).


/**
 * Define a constant if it is not already defined.
 *
 * @since 0.1.0
 * @param string $name  Constant name.
 * @param mixed  $value Value.
 */
function tg_maybe_define_constant( $name, $value ) {
	if ( ! defined( $name ) ) {
		define( $name, $value );
	}
}

/**
 * Get permalink settings for things like products and taxonomies.
 *
 * Permalink settings are stored to the option instead of
 * being blank and inheritting from the locale. This speeds up page loading
 * times by negating the need to switch locales on each page load.
 *
 * This is inline with WP core behavior which does not localize slugs.
 *
 * @since  0.1.0
 * @return array
 */
function tg_get_permalink_structure() {
	$tg_permalinks = get_option( 'tapgoods_permalinks', array() );

	$permalinks = wp_parse_args(
		array_filter( $tg_permalinks ),
		array(
			'tg_inventory_base'      => _x( 'products', 'permalink', 'tapgoods' ),
			'tg_category_base'       => _x( 'categories', 'permalink', 'tapgoods' ),
			'tg_tag_base'            => _x( 'tags', 'permalink', 'tapgoods' ),
			'use_verbose_page_rules' => 0,
		)
	);

	if ( $tg_permalinks !== $permalinks ) {
		Tapgoods_Helpers::tgqm( 'get permalinks updating: ' );
		Tapgoods_Helpers::tgqm( $tg_permalinks );
		Tapgoods_Helpers::tgqm( $permalinks );
		update_option( 'tapgoods_permalinks', $permalinks );
	}

	$permalinks['tg_inventory_rewrite_slug'] = untrailingslashit( $permalinks['tg_inventory_base'] );
	$permalinks['tg_category_rewrite_slug']  = untrailingslashit( $permalinks['tg_category_base'] );
	$permalinks['tg_tags_rewrite_slug']      = untrailingslashit( $permalinks['tg_tag_base'] );

	return $permalinks;
}

function tg_fix_inventory_rewrite( $rules ) {
	global $wp_rewrite;
	$permalinks = tg_get_permalink_structure();

	Tapgoods_Helpers::tgqm( $permalinks );

	// If the %tg_category% is used in the inventory permalink we need to fix some things
	if ( preg_match( '`/(.+)(/%tg_category%)`', $permalinks['tg_inventory_rewrite_slug'], $matches ) ) {

		$newrules = array();
		// if the category base is the same as the inventory base add this rule so that category perlalinks don't break
		if ( $matches[1] === $permalinks['tg_category_base'] ) {
			$newrules[] = array( '`' . $matches[1] . '/([^/]+)/?$`' => 'index.php?tg_category=$matches[1]' );
			Tapgoods_Helpers::tgqm( 'Adding rules: ' );
			Tapgoods_Helpers::tgqm( $newrules );
		}
		Tapgoods_Helpers::tgqm( 'Matches' );
		Tapgoods_Helpers::tgqm( $matches );
		Tapgoods_Helpers::tgqm( 'Rules' );
		Tapgoods_Helpers::tgqm( $rules );

		foreach ( $rules as $rule => $rewrite ) {

			if ( preg_match( '`^' . preg_quote( $matches[1], '`' ) . '/\(`', $rule ) && preg_match( '/^(index\.php\?tg_category)(?!(.*inventory))/', $rewrite ) ) {
				Tapgoods_Helpers::tgqm( 'removing rule:' );
				Tapgoods_Helpers::tgqm( $rule );
				Tapgoods_Helpers::tgqm( $rewrite );
				unset( $rules[ $rule ] );
			}
		}
		$rules = array_merge( $newrules, $rules );
	}

	Tapgoods_Helpers::tgqm( 'Updated Rules' );
	Tapgoods_Helpers::tgqm( $rules );

	if ( ! $permalinks['use_verbose_page_rules'] ) {
		return $rules;
	}

	$new = array();
	return $rules;
}
add_filter( 'rewrite_rules_array', 'tg_fix_inventory_rewrite' );

function tg_empty_cart_rewrite() {
	add_rewrite_tag( '%empty-cart%', '([^&]+)' );
	add_rewrite_rule( '^empty-cart/([^/]*)/?', 'index.php?empty-cart=$matches[1]', 'top' );
}
add_filter( 'init', 'tg_empty_cart_rewrite', 10, 0 );

function tg_get_product_dimensions( $product_id ) {

	$dimensions = array();

	$height = tg_get_product_height( $product_id );
	$length = tg_get_product_length( $product_id );
	$width  = tg_get_product_width( $product_id );
	$weight = tg_get_product_weight( $product_id );

	if ( $height ) {
		$dimensions['Height'] = $height;
	}

	if ( $length ) {
		$dimensions['Length'] = $length;
	}

	if ( $width ) {
		$dimensions['Width'] = $width;
	}

	if ( $weight ) {
		$dimensions['Weight'] = $weight;
	}

	return $dimensions;
}

function tg_get_product_height( $product_id ) {

	$h_feet = get_post_meta( $product_id, 'tg_heightFt', true );
	$h_inch = get_post_meta( $product_id, 'tg_height', true );

	if ( ( '' === $h_feet || false === $h_feet ) && ( '' === $h_inch || false === $h_inch ) ) {
		return false;
	}

	$h_string  = ( '' !== $h_feet && false !== $h_feet ) ? $h_feet . "' " : '';
	$h_string .= ( '' !== $h_inch && false !== $h_inch ) ? $h_inch . '"' : '0"';

	return $h_string;
}

function tg_get_product_length( $product_id ) {
	$l_feet = get_post_meta( $product_id, 'tg_lengthFt', true );
	$l_inch = get_post_meta( $product_id, 'tg_length', true );

	if ( ( '' === $l_feet || false === $l_feet ) && ( '' === $l_inch || false === $l_inch ) ) {
		return false;
	}

	$l_string  = ( '' !== $l_feet && false !== $l_feet ) ? $l_feet . "' " : '';
	$l_string .= ( '' !== $l_inch && false !== $l_inch ) ? $l_inch . '"' : '0"';

	return $l_string;
}

function tg_get_product_width( $product_id ) {
	$w_feet = get_post_meta( $product_id, 'tg_widthFt', true );
	$w_inch = get_post_meta( $product_id, 'tg_width', true );

	if ( ( '' === $w_feet || false === $w_feet ) && ( '' === $w_inch || false === $w_inch ) ) {
		return false;
	}

	$w_string  = ( '' !== $w_feet && false !== $w_feet ) ? $w_feet . "' " : '';
	$w_string .= ( '' !== $w_inch && false !== $w_inch ) ? $w_inch . '"' : '0"';

	return $w_string;
}

function tg_get_product_weight( $product_id ) {
	$weight = get_post_meta( $product_id, 'tg_weight', true );

	$weight_string = ( '' !== $weight && false !== $weight ) ? $weight . ' lbs' : false;
	return $weight_string;
}

function tg_write_log( $data ) {
	if ( true === WP_DEBUG && true === WP_DEBUG_LOG ) {
		if ( is_array( $data ) || is_object( $data ) ) {
			error_log( 'tgwpdev: ' . print_r( $data, true ) );
		} else {
			error_log( 'tgwpdev: ' . $data );
		}
	}
}


function tg_parse_request( $wp ) {

	// tg_write_log( $wp );

	Tapgoods_Helpers::tgqm( $wp );

	// Check if the request is looking for a TapGoods Category term
	if ( count( $wp->query_vars ) === 1 && array_key_exists( 'tg_category', $wp->query_vars ) ) {
		Tapgoods_Helpers::tgqm( 'tg_category pre query' );

		// Do a term query before the main query to see if the term is found
		$slug      = $wp->query_vars['tg_category'];
		$args      = array(
			'taxonomy' => array( 'tg_category' ),
			'slug'     => $slug,
			'count'    => 1,
			'hide_empty' => false,
		);
		$pre_query = get_terms( $args );
		Tapgoods_Helpers::tgqm( $args );
		Tapgoods_Helpers::tgqm( $pre_query );

		// If the term is found hook tg_term_template_redirect to ensure we get to the term permalink
		if ( count( $pre_query ) !== 0 ) {
			add_action( 'template_redirect', 'tg_term_template_redirect' );
			return;
		}

		// If the term isn't found, update the query_vars to search Tapgoods Inventory, pages, or posts and hook template redirect for the permalink
		$wp->query_vars = array(
			'post_type' => array( 'tg_inventory', 'page', 'post' ),
			'name'      => $slug,
		);
		add_action( 'template_redirect', 'tg_template_redirect' );
		return;
	}
}

function tg_custom_query_vars( $vars ) {
	$vars[] = 'empty-cart';
	$vars[] = 'set-location';
	$vars[] = 'active-filters';
	// tg_write_log( $vars );
	return $vars;
}

function tg_empty_cart( $wp ) {

	$empty = get_query_var( 'empty-cart', false );
	if ( isset( $_GET['empty-cart'] ) ) {
		$empty = sanitize_text_field( wp_unslash( $_GET['empty-cart'] ) ); // phpcs:ignore
	}
	if ( $empty ) {
		$domain = wp_parse_url( get_site_url(), PHP_URL_HOST );
		setcookie( 'tg_has_items', '0', 0, '/', $domain );
	}
}

/**
 * Hooked when a tg_category query is modified to check tg_inventory, Pages and Posts
 * Checks the parsed query for a permalink and redirects to the permalink if different
 */
function tg_template_redirect() {
	if ( ! is_singular( 'tg_inventory' ) ) {
		return;
	}

	global $wp;
	$wp->parse_request();
	$current_url = trim( home_url( $wp->request ), '/' );
	$redirect    = get_permalink();
	$surl        = trim( $redirect, '/' );

	if ( $current_url !== $surl ) {
		wp_safe_redirect( $redirect, 301 );
		exit;
	}
}

function tg_term_template_redirect() {

	// Load wp_query to get the queried object (term) ID to get the permalink, getting permalink by term slug from $wp is unreliable
	global $wp_query;
	if ( ! $wp_query->is_tax() ) {
		return;
	}

	// Load $wp to check the request URL against the term permalink
	global $wp;
	$wp->parse_request();
	$current_url = trim( home_url( $wp->request ), '/' );
	$redirect    = get_term_link( $wp_query->queried_object->term_id );
	$surl        = trim( $redirect, '/' );

	// If the current url isn't the permalink redirect to the permalink
	if ( $current_url !== $surl ) {
		wp_safe_redirect( $redirect, 301 );
		exit;
	}
}

function tg_get_prices( $id = false ) {
	if ( false === $id ) {
		return false;
	}

	$type   = get_post_meta( $id, 'tg_productType', true );
	$prices = array();

	switch ( $type ) {
		case 'addons':
			$prices[] = array( 'each' => get_post_meta( $id, 'tg_pricing', true ) );
			// Tapgoods_Helpers::tgdd( $prices );
			break;
		default:
			$prices[] = array( 'hour' => get_post_meta( $id, 'tg_hourlyPrice', true ) );
			$prices[] = array( 'half day' => get_post_meta( $id, 'tg_halfDayPrice', true ) );
			$prices[] = array( 'day' => get_post_meta( $id, 'tg_dailyPrice', true ) );
			$prices[] = array( 'week' => get_post_meta( $id, 'tg_weeklyPrice', true ) );
			$prices[] = array( 'month' => get_post_meta( $id, 'tg_monthlyPrice', true ) );

			$flat = get_post_meta( $id, 'tg_flatPrices', true );
			if ( is_array( $flat ) ) {
				foreach ( $flat as $price ) {
					$prices[] = array( $price['name'] => $price['amount'] );
				}
			}
			break;
	}
	//Tapgoods_Helpers::tgdd( $prices );

	$prices_count = count( $prices );
	for ( $i = 0; $i < $prices_count; $i++ ) {
		if ( false === current( $prices[ $i ] ) || '' === current( $prices [ $i ] ) ) {
			unset( $prices[ $i ] );
		}
	}
	if ( 455 === $id ) {
		// Tapgoods_Helpers::tgdd( $prices );
	}

	return $prices;
}

function tg_get_single_display_price( $id ) {
	$prices = tg_get_prices( $id );
	if ( is_array( $prices ) ) {
		$price = reset( $prices );
		$price_string = '$' . current( $price ) . ' / ' . array_key_first( $price );
		return $price_string;
	}
}

function tg_get_all_categories() {

	$args = apply_filters(
		'tg_get_all_category_args',
		array(
			'taxonomy'   => 'tg_category',
			'hide_empty' => false,
			'number'     => -1,
		)
	);

	$categories = get_terms( $args );
	return $categories;
}

function tg_get_subcategories( $term ) {
	$term = get_term( $term );

	if ( ! term_exists( $term ) ) {
		return false;
	}

	$tg_subs = get_term_meta( $term, 'sfSubCategories', true );
	$args = array();

}

function tg_get_locations() {
	$args = array(
		'taxonomy'   => 'tg_location',
		'hide_empty' => false,
	);

	$locations = get_terms( $args );
	return $locations;
}

function tg_location_styles( $location_id = false ) {

	$styles = '';

	$location_settings = get_option( 'tg_location_settings', false );

	if ( false === $location_settings || ! is_array( $location_settings ) ) {
		tg_write_log( 'TG styles: no location settings' );
		return false;
	}

	foreach ( $location_settings as $location ) : ?>
		<?php
			$sf_settings = $location['storefrontSetting'];

			$button_style    = $sf_settings['buttonStyle'];
			$primary_color   = $sf_settings['primaryColor'];
			$light_font      = $sf_settings['lightFontColor'];
			$light_secondary = $sf_settings['lightSecondaryColor'];
			$dark_font       = $sf_settings['darkFontColor'];
			$dark_secondary  = $sf_settings['darkSecondaryColor'];

			ob_start();
		?>
	.location-<?php echo esc_html( $location['id'] ); ?> {
		--tg-color-primary: <?php echo esc_html( $primary_color ); ?>;
		--tg-light-font: <?php echo esc_html( $light_font ); ?>;
		--tg-dark-font: <?php echo esc_html( $dark_font ); ?>;
		--tg-light-secondary: <?php echo esc_html( $light_secondary ); ?>;
		--tg-dark-secondary: <?php echo esc_html( $dark_secondary ); ?>;
		<?php if ( 'rounded' === $button_style ) : ?>
		--tg-button-border: 18px;
		<?php else : ?>
		--tg-button-border: 2px;
		<?php endif; ?>
	}
	<?php endforeach; ?>
	<?php

	return ob_get_clean();

}

function tg_get_categories() {
	$terms = get_terms(
		array(
			'taxonomy'   => 'tg_category',
			'hide_empty' => false,
		)
	);
	return $terms;
}

function tg_get_tg_location_id( $post_id = false ) {

	if ( false === $post_id ) {
		$args = array(
			'taxonomy'   => 'tg_location',
			'hide_empty' => false,
			'number'     => 1,
			'fields'     => 'ids',
		);

		$locations = get_terms( $args );
	}

	if ( false !== $post_id ) {
		$locations = get_the_terms( $post_id, 'tg_location' );
	}

	if ( count( $locations ) > 0 ) {
		$tg_id = get_term_meta( current( $locations ), 'tg_id', true );
		return $tg_id;
	}

	return false;
}

function tg_get_wp_location_id( $post_id = false ) {

	if ( false === $post_id ) {
		$args = array(
			'taxonomy'   => 'tg_location',
			'hide_empty' => false,
			'number'     => 1,
			'fields'     => 'ids',
		);

		$locations = get_terms( $args );
	}

	if ( false !== $post_id ) {
		$locations = get_the_terms( $post_id, 'tg_location' );
	}

	if ( count( $locations ) > 0 ) {
		return current( $locations );
	}

	return false;
}

function tg_locate_template( $template = '' ) {

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

	return $template_path;
}

function tg_get_sf_url( $wp_location_id ) {
	return get_term_meta( $wp_location_id, 'tg_sf_url', true );
}

function tg_get_sf_domain( $wp_location_id ) {
	return get_term_meta( $wp_location_id, 'tg_subdomain', true );
}

function tg_get_cart_url( $wp_location_id ) {
	return get_term_meta( $wp_location_id, 'tg_cart_url', true );
}

function tg_get_sign_in_url() {
	return get_term_meta( $wp_location_id, 'tg_login_url', true );
}

function tg_get_sign_up_url() {
	return get_term_meta( $wp_location_id, 'tg_login_url', true );
}

function tg_get_add_to_cart_url( $wp_location_id ) {
	$url = get_term_meta( $wp_location_id, 'tg_add_to_cart', true );
	return $url;
}

function tg_get_product_add_to_cart_url( $product_id, $params = array() ) {

	$location = get_the_terms( $product_id, 'tg_location' );
	$location = current( $location );

	$base_url = tg_get_add_to_cart_url( $location->term_id );

	$tg_id = get_post_meta( $product_id, 'tg_id', true );
	$type  = get_post_meta( $product_id, 'tg_productType', true );

	$params = array_merge(
		array(
			'itemId'   => $tg_id,
			'itemType' => $type,
			'quantity' => 1,
		),
		$params
	);

	$url = add_query_arg( $params, $base_url );
	return $url;
}

function tg_get_template_part() {
	return false;
}

function tg_get_template() {
	return false;
}

function tg_get_template_html() {
	ob_start();
	tg_get_template();
	return ob_get_clean();
}

function tg_set_cookie() {
	return false;
}

function tg_get_page_id() {
	return false;
}

function tg_get_page_children() {
	return false;
}

function tg_flush_rewrites_on_shop_page_save() {
	return false;
}

function tg_fix_rewrite_rules() {
	return false;
}

function tg_get_user_agent() {
	return isset( $_SERVER['HTTP_USER_AGENT'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ) : '';
}
