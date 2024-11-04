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
			ob_start();
			var_dump( $data );
			$data = ob_get_clean();
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
			'taxonomy' => array( 'tg_category', 'tg_tags' ),
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
	return $prices;
}

function tg_get_single_display_price( $id ) {
	$prices = tg_get_prices( $id );
	if ( is_array( $prices ) ) {
		$price = reset( $prices );
		if ( false == $price ) {
			return 'Pricing Unavailable';
		}
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

function tg_location_styles() {
    // Obtener el location_id dinámicamente, por ejemplo, desde una cookie o configuración
    $location_id = isset($_COOKIE['tg_location_id']) ? sanitize_text_field($_COOKIE['tg_location_id']) : get_option('tg_default_location');

    // Registrar un log si no se encontró el location_id
    if ( !$location_id ) {
        tg_write_log('No location_id found. Skipping style generation.');
        return '';
    }

    // Obtener las configuraciones de ubicación desde la base de datos
    $location_settings = get_option( 'tg_location_settings', false );

    if ( false === $location_settings || ! isset( $location_settings[$location_id] ) ) {
        tg_write_log( "TG styles: no location settings found for location ID $location_id" );
        return '';
    }

    // Obtener configuraciones de storefront para el location_id especificado
    $sf_settings = $location_settings[$location_id];
    $button_style    = $sf_settings['buttonStyle'] ?? 'default';
    $primary_color   = $sf_settings['primaryColor'] ?? '#527390';
    $light_font      = $sf_settings['lightFontColor'] ?? '#000000';
    $light_secondary = $sf_settings['lightSecondaryColor'] ?? '#E5E8E9';
    $dark_font       = $sf_settings['darkFontColor'] ?? '#ffffff';
    $dark_secondary  = $sf_settings['darkSecondaryColor'] ?? '#9c9c9c';

    // Log the retrieved settings for debugging
    tg_write_log("Generating styles for Location ID: $location_id");
    tg_write_log("Primary Color: $primary_color, Button Style: $button_style");

    // Generate CSS using :root for global variables
    ob_start(); ?>
    
    :root {
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

    <?php
    $generated_css = ob_get_clean();
    tg_write_log("TG styles: Generated CSS for location ID $location_id: " . $generated_css);
    return $generated_css;
}

// Insertar el CSS generado en el HTML
echo '<style>';
echo tg_location_styles(); // Llamar a la función sin pasar un ID fijo
echo '</style>';







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

//function tg_get_wp_location_id( $post_id = false ) {
function tg_get_wp_location_id() {
    $default_location = get_option('tg_default_location');
    error_log("Default Location: " . print_r($default_location, true)); // Add for debugging
    return $default_location ?: null;

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

function tg_get_sf_url($wp_location_id) {
    $location_data = get_option('tg_location_' . $wp_location_id);
    return isset($location_data['sf_url']) ? $location_data['sf_url'] : '#';
}


function tg_get_sf_domain( $wp_location_id ) {
	return get_term_meta( $wp_location_id, 'tg_subdomain', true );
}

function tg_get_cart_url($wp_location_id) {
    // Get location data from tg_location_{ID} option
    $location_data = get_option('tg_location_' . $wp_location_id);

    if (empty($location_data) || !isset($location_data['cart_url'])) {
        error_log("tg_cart_url not found for location_id: " . $wp_location_id);
        return '#'; // Fallback value if no URL
    }

    // Constructing the URL with the event parameters
    $url = $location_data['cart_url'];
    $event_start = tg_get_start_date() . 'T' . tg_get_start_time();
    $event_end = tg_get_end_date() . 'T' . tg_get_end_time();
    $params = array(
        'eventStart' => $event_start,
        'eventEnd'   => $event_end,
    );

    return add_query_arg($params, $url);
}


function tg_get_sign_in_url($wp_location_id) {
    $location_data = get_option('tg_location_' . $wp_location_id);
    return isset($location_data['login_url']) ? $location_data['login_url'] : '#';
}

function tg_get_sign_up_url($wp_location_id) {
    $location_data = get_option('tg_location_' . $wp_location_id);
    return isset($location_data['signup_url']) ? $location_data['signup_url'] : '#';
}


function tg_get_add_to_cart_url($wp_location_id) {
    $location_data = get_option('tg_location_' . $wp_location_id);
    $url = isset($location_data['add_to_cart']) ? $location_data['add_to_cart'] : '#';
    
    // Log to verify the URL value
    error_log("Add to Cart URL: " . $url);
    
    return $url;
}


function tg_get_product_add_to_cart_url( $product_id, $params = array() ) {
    // Gets the specific location of the product
    $location = get_the_terms( $product_id, 'tg_location' );

    // If the specific location is not found, use the default location
    if ( ! is_array( $location ) || empty( $location ) ) {
        $default_location_id = get_option('tg_default_location');
        if ( !$default_location_id ) {
            error_log("Location not found for product_id: $product_id and no default location set.");
            return '#';
        }
        $base_url = tg_get_add_to_cart_url( $default_location_id );
        $cart_url = tg_get_cart_url( $default_location_id ); // Cart URL
    } else {
        $location = current( $location );
        $base_url = tg_get_add_to_cart_url( $location->term_id );
        $cart_url = tg_get_cart_url( $location->term_id ); // Cart URL
    }

    // Verify that `base_url` and `cart_url` are valid
    if ( $base_url === '#' || !$cart_url ) {
        error_log("Add to Cart URL or Cart URL not found for location_id: " . ( $location->term_id ?? 'Default' ));
        return '#';
    }

    // Get the `itemId` and `itemType` of the product
    $tg_id = get_post_meta( $product_id, 'tg_id', true );
    $type  = get_post_meta( $product_id, 'tg_productType', true );

    // Combine additional parameters, using `$cart_url` directly in `redirectUrl`
    $params = array_merge(
        array(
            'itemId'      => $tg_id,
            'itemType'    => $type,
            'quantity'    => 1,
            'redirectUrl' => $cart_url  // Directly assigns `cart_url`
        ),
        $params
    );

    // Ensure that `redirectUrl` is correctly set as `cart_url`
    $params['redirectUrl'] = $cart_url;

    // Add parameters to the base URL
    $url = add_query_arg( $params, $base_url );

    error_log("Final Add to Cart URL: " . $url); // Log for verification

    return $url;
}


function tg_date_format() {
	return 'Y-m-d';
}

function tg_time_format() {
	return 'H:i';
}

function tg_get_page_id( $page ) {
	$page_id = get_option( $page, false );
	return $page_id;
}

function tg_get_start_date() {
    // Get the start date of the cookie, if it exists
    $start_date = isset($_COOKIE['tg-eventStart']) ? sanitize_text_field($_COOKIE['tg-eventStart']) : '';

    // If the cookie date is invalid or older than today, set the start date to today
    $today = wp_date('Y-m-d');
    if (empty($start_date) || wp_date('Y-m-d', strtotime($start_date)) < $today) {
        $start_date = $today;
    }

    return $start_date;
}


function tg_get_start_time() {
	return ( isset( $_COOKIE['tg-eventStart'] ) ) ? wp_date( tg_time_format(), strtotime( sanitize_text_field( wp_unslash( $_COOKIE['tg-eventStart'] ) ) ) ) : wp_date( tg_time_format(), strtotime( 'tomorrow 10:00 AM' ) );
}

// function tg_get_end_date() {
// 	return ( isset( $_COOKIE['tg-eventEnd'] ) ) ? wp_date( tg_date_format(), strtotime( sanitize_text_field( wp_unslash( $_COOKIE['tg-eventEnd'] ) ) ) ) : wp_date( tg_date_format(), strtotime( '+1 day' ) );
// }

function tg_get_end_date() {
    // Get the start date
    $start_date = tg_get_start_date();

    // Get the cookie expiration date, if any
    $end_date = isset($_COOKIE['tg-eventEnd']) ? sanitize_text_field($_COOKIE['tg-eventEnd']) : '';

    // If the cookie date is invalid or older than three days after the start date, set to three days after the start
    $min_end_date = wp_date('Y-m-d', strtotime($start_date . ' +3 days'));
    if (empty($end_date) || wp_date('Y-m-d', strtotime($end_date)) < $min_end_date) {
        $end_date = $min_end_date;
    }

    return $end_date;
}


function tg_get_end_time() {
	return ( isset( $_COOKIE['tg-eventEnd'] ) ) ? wp_date( tg_time_format(), strtotime( sanitize_text_field( wp_unslash( $_COOKIE['tg-eventEnd'] ) ) ) ) : wp_date( tg_time_format(), strtotime( 'tomorrow 15:00' ) );
}

function tg_get_user_agent() {
	return isset( $_SERVER['HTTP_USER_AGENT'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ) : '';
}





// Function to enqueue a script that disables editing in the Gutenberg editor
function tapgoods_disable_gutenberg_editing() {
    global $post;
    
    // Check if the post type is 'tg_inventory' and if we are on the edit screen
    if ( isset( $post->post_type ) && $post->post_type === 'tg_inventory' && is_admin() && get_current_screen()->is_block_editor() ) {
        wp_enqueue_script(
            'disable-gutenberg-editing',
            plugin_dir_url( __FILE__ ) . '../public/js/disable-gutenberg-editing.js', // Correct URL
            array( 'wp-blocks', 'wp-dom' ),
            false,
            true
        );
    }
}
add_action( 'admin_enqueue_scripts', 'tapgoods_disable_gutenberg_editing' );

// Function to remove the 'Quick Edit' option from the list view
function remove_quick_edit( $actions, $post ) {
    // Check if the post type is 'tg_inventory'
    if ( $post->post_type == 'tg_inventory' ) {
        // Remove the 'Quick Edit' option
        unset( $actions['inline hide-if-no-js'] );
    }
    return $actions;
}
add_filter( 'post_row_actions', 'remove_quick_edit', 10, 2 );


// Function to clean up duplicate items
function tg_clean_duplicate_items() {
    // Check if the 'clean_duplicates' parameter is in the URL
    if (isset($_GET['clean_duplicates']) && $_GET['clean_duplicates'] === 'true') {
        global $wpdb;

        // Add a script to display the start status in the browser console
        add_action('admin_footer', function() {
            echo "<script>console.log('Duplicate items cleanup started...');</script>";
        });

        // Query to find the IDs of duplicate posts based on 'post_title' and 'tg_id'
        $query = "
            SELECT MIN(p1.ID) as keep_id, p2.ID as delete_id
            FROM {$wpdb->posts} p1
            INNER JOIN {$wpdb->posts} p2 ON p1.post_title = p2.post_title 
            AND p1.ID < p2.ID
            INNER JOIN {$wpdb->postmeta} pm1 ON pm1.post_id = p1.ID AND pm1.meta_key = 'tg_id'
            INNER JOIN {$wpdb->postmeta} pm2 ON pm2.post_id = p2.ID AND pm1.meta_value = pm2.meta_value
            WHERE p1.post_type = 'tg_inventory' 
            AND p2.post_type = 'tg_inventory'
            AND p1.post_status = 'publish'
            GROUP BY p2.ID
        ";

        // Execute the query to find duplicate posts
        $duplicates = $wpdb->get_results($query);

        // Counter for deleted posts
        $deleted_count = 0;

        // Delete the duplicate posts
        foreach ($duplicates as $dup) {
            // Permanently delete the duplicate post
            wp_delete_post($dup->delete_id, true);
            $deleted_count++;
        }

        // Add a script to display the completion status in the browser console
        add_action('admin_footer', function() use ($deleted_count) {
            echo "<script>console.log('Duplicate items cleanup completed. Total deleted: {$deleted_count}');</script>";
        });
    }
}

// Hook to clean duplicates when the admin page loads
add_action('admin_init', 'tg_clean_duplicate_items');


add_action('pre_get_posts', function($query) {
    if (!is_admin() && $query->is_main_query() && $query->is_search && isset($_GET['post_type']) && $_GET['post_type'] === 'tg_inventory') {
        
        // Make sure `tg_location_id` is in the request
        if (!empty($_GET['tg_location_id'])) {
            $location_id = sanitize_text_field($_GET['tg_location_id']);
            
            // Add a meta_query relation to filter by `location_id`
            $query->set('meta_query', array(
                array(
                    'key'     => 'tg_locationId',
                    'value'   => $location_id,
                    'compare' => '=',
                ),
            ));
        }
    }
});