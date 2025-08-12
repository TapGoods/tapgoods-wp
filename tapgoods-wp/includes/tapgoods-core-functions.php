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
//			error_log( 'tgwpdev: ' . print_r( $data, true ) );
		} else {
			ob_start();
//			var_dump( $data );
			$data = ob_get_clean();
//			error_log( 'tgwpdev: ' . $data );
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

	// Get the term link and check for errors
	$redirect = get_term_link( $wp_query->queried_object->term_id );
	if ( is_wp_error( $redirect ) ) {
		return; // Stop execution if there is an error
	}

	$surl = trim( $redirect, '/' );

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
    $location_id = isset($_COOKIE['tg_location_id']) ? sanitize_text_field( wp_unslash( $_COOKIE['tg_location_id'] ) ) : get_option('tg_default_location');

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
    $light_font      = $sf_settings['lightFontColor'] ?? '#ffffff';
    $light_secondary = $sf_settings['lightSecondaryColor'] ?? '#E5E8E9';
    $dark_font       = $sf_settings['darkFontColor'] ?? '#000000';
    $dark_secondary  = $sf_settings['darkSecondaryColor'] ?? '#9c9c9c';

    // Log the retrieved settings for debugging
    tg_write_log("Generating styles for Location ID: $location_id");
    tg_write_log("Primary Color: $primary_color, Button Style: $button_style");

    // Generate CSS using :root for global variables
	ob_start(); // Start output buffering
    ?>
    :root {
        --tg-color-primary: <?php echo esc_html($primary_color); ?>;
        --tg-light-font: <?php echo esc_html($light_font); ?>;
        --tg-dark-font: <?php echo esc_html($dark_font); ?>;
        --tg-light-secondary: <?php echo esc_html($light_secondary); ?>;
        --tg-dark-secondary: <?php echo esc_html($dark_secondary); ?>;
        <?php if ('rounded' === $button_style) : ?>
            --tg-button-border: 18px;
        <?php else : ?>
            --tg-button-border: 2px;
        <?php endif; ?>
    }
    <?php
    return ob_get_clean(); // Return the generated CSS instead of printing it
}


add_action('wp_head', 'tg_output_location_styles');

function tg_output_location_styles() {
    // Re-enabled to ensure styles load when needed
    if (!defined('DOING_AJAX') || !DOING_AJAX) {
        echo '<style>';
        echo wp_kses_post( tg_location_styles() );
        echo '</style>';
    }
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

//function tg_get_wp_location_id( $post_id = false ) {
    function tg_get_wp_location_id() {
        // Check if the value is provided via a cookie (set by JavaScript)
        if (isset($_COOKIE['tg_user_location']) && !empty($_COOKIE['tg_user_location'])) {
            return sanitize_text_field( wp_unslash( $_COOKIE['tg_user_location'] ) );
        }
    
        // Check session value (optional)
        if (isset($_SESSION['tg_user_location']) && !empty($_SESSION['tg_user_location'])) {
            return sanitize_text_field($_SESSION['tg_user_location']);
        }
    
        // Fallback to WordPress option
        $default_location = get_option('tg_default_location');
        return $default_location ?: null;
    }
    
    
    

function tg_locate_template( $template = '' ) {

	if ( empty( $template ) ) {
		return null;
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

	return null;
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
   //     error_log("tg_cart_url not found for location_id: " . $wp_location_id);
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
//    error_log("Add to Cart URL: " . $url);
    
    return $url;
}


function tg_get_product_add_to_cart_url( $product_id, $params = array() ) {
    // First, try to get the user's location from cookie/localStorage
    $user_location_id = null;
    
    // Check if it comes in the parameters
    if ( isset( $params['location_id'] ) ) {
        $user_location_id = $params['location_id'];
        unset( $params['location_id'] ); // Remove to avoid passing it in the URL
    }
    // If not, check the cookie
    elseif ( isset( $_COOKIE['tg_user_location'] ) ) {
        $user_location_id = sanitize_text_field( wp_unslash( $_COOKIE['tg_user_location'] ) );
    }
    // If not, check GET parameter
    elseif ( isset( $_GET['local_storage_location'] ) ) {
        $user_location_id = sanitize_text_field( wp_unslash( $_GET['local_storage_location'] ) );
    }
    
    // If we have a user location, use it
    if ( $user_location_id ) {
        $location_id = $user_location_id;
    } else {
        // If not, get the product's location from post meta
        $location_id = get_post_meta( $product_id, 'tg_locationId', true );
        
        // If the product has no location, use the default location
        if ( ! $location_id ) {
            $location_id = get_option('tg_default_location');
            if ( ! $location_id ) {
                error_log("Location not found for product_id: $product_id and no default location set.");
                return '#';
            }
        }
    }
    
    // Get the base URLs using the location_id
    $base_url = tg_get_add_to_cart_url( $location_id );
    $cart_url = tg_get_cart_url( $location_id );
    
    // Verify that URLs are valid
    if ( $base_url === '#' || ! $cart_url ) {
        error_log("Add to Cart URL or Cart URL not found for location_id: " . $location_id);
        return '#';
    }
    
    // Get the product's itemId and itemType
    $tg_id = get_post_meta( $product_id, 'tg_id', true );
    $type  = get_post_meta( $product_id, 'tg_productType', true );
    
    // Merge additional parameters
    $params = array_merge(
        array(
            'itemId'   => $tg_id,
            'itemType' => $type,
            'quantity' => 1,
            // 'redirectUrl' => $cart_url  // Commented out to allow external setting
        ),
        $params
    );
    
    // Add parameters to the base URL
    $url = add_query_arg( $params, $base_url );
    
    error_log("Final Add to Cart URL for location $location_id: " . $url);
    
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
    $start_date = isset($_COOKIE['tg-eventStart']) ? sanitize_text_field( wp_unslash( $_COOKIE['tg-eventStart'] ) ) : '';

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
    $end_date = isset($_COOKIE['tg-eventEnd']) ? sanitize_text_field( wp_unslash( $_COOKIE['tg-eventEnd'] ) ) : '';

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
            plugins_url( 'public/js/disable-gutenberg-editing.js', dirname( __FILE__ ) ), // Mejor manejo de la URL
            array( 'wp-blocks', 'wp-dom' ),
            filemtime( plugin_dir_path( __FILE__ ) . '../public/js/disable-gutenberg-editing.js' ), // Usa el timestamp del archivo como versión
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

            // Execute the query to find duplicate posts
        $duplicates = $wpdb->get_results( $wpdb->prepare(
            "SELECT MIN(p1.ID) as keep_id, p2.ID as delete_id
            FROM {$wpdb->posts} p1
            INNER JOIN {$wpdb->posts} p2 ON p1.post_title = p2.post_title 
            AND p1.ID < p2.ID
            INNER JOIN {$wpdb->postmeta} pm1 ON pm1.post_id = p1.ID AND pm1.meta_key = %s
            INNER JOIN {$wpdb->postmeta} pm2 ON pm2.post_id = p2.ID AND pm1.meta_value = pm2.meta_value
            WHERE p1.post_type = %s 
            AND p2.post_type = %s
            AND p1.post_status = %s
            GROUP BY p2.ID",
            'tg_id',       // Placeholder meta_key
            'tg_inventory', // Placeholder post_type p1
            'tg_inventory', // Placeholder post_type p2
            'publish'      // Placeholder post_status
        ) );

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
            echo "<script>console.log('Duplicate items cleanup completed. Total deleted: " . esc_js( $deleted_count ) . "');</script>";
        });
    }
}

// Hook to clean duplicates when the admin page loads
add_action('admin_init', 'tg_clean_duplicate_items');


add_action('pre_get_posts', function($query) {
    if (!is_admin() && $query->is_main_query() && $query->is_search && isset($_GET['post_type']) && $_GET['post_type'] === 'tg_inventory') {
        
        // Make sure `tg_location_id` is in the request
        if (!empty($_GET['tg_location_id'])) {
            $location_id = sanitize_text_field( wp_unslash( $_GET['tg_location_id'] ) );
            
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

add_action('template_redirect', function () {
    if (isset($_GET['thankyou'])) {
        include TAPGOODS_PLUGIN_PATH . '/public/partials/tg-thankyou.php';
        exit;
    }
});


// Register the AJAX handlers for logged-in and logged-out users
add_action('wp_ajax_tg_search_grid', 'handle_tg_search');
add_action('wp_ajax_nopriv_tg_search_grid', 'handle_tg_search');

/**
 * Handles the AJAX search request for tg_inventory.
 *
 * This function receives the search term and location ID from the client,
 * queries the database for matching tg_inventory posts, and returns the
 * results in JSON format.
 */
 

 function handle_tg_search() {
   // error_log("TG_SEARCH_GRID handle_tg_search called with action: " . ($_POST['action'] ?? 'NO_ACTION') . " and data: " . print_r($_POST, true));

    $search_term = isset($_POST['s']) && $_POST['s'] !== '' ? sanitize_text_field( wp_unslash( $_POST['s'] ) ) : null;
    $location_id = isset($_POST['tg_location_id']) ? sanitize_text_field( wp_unslash( $_POST['tg_location_id'] ) ) : '';
    $tags = isset($_POST['tg_tags']) && !empty($_POST['tg_tags']) ? explode(',', sanitize_text_field( wp_unslash( $_POST['tg_tags'] ) ) ) : [];
    $categories = isset($_POST['tg_categories']) && !empty($_POST['tg_categories']) ? explode(',', sanitize_text_field( wp_unslash( $_POST['tg_categories'] ) ) ) : [];
    $per_page = isset($_POST['per_page_default']) ? (int) sanitize_text_field( wp_unslash( $_POST['per_page_default'] ) ) : 12;
    $paged = isset($_POST['paged']) ? (int) sanitize_text_field( wp_unslash( $_POST['paged'] ) ) : 1;
    $is_default  = isset($_POST['default']) && $_POST['default'] === 'true';
    $show_pricing = isset($_POST['show_pricing']) ? $_POST['show_pricing'] === 'true' : true;
    

    $categories = array_map(function($category) {
        return htmlspecialchars_decode($category);
    }, $categories);

    $args = [
        'post_type'      => 'tg_inventory',
        'post_status'    => 'publish',
        'posts_per_page' => $per_page,
        'paged'          => $paged,
        'orderby'        => 'title',
        'order'          => 'ASC',
        'meta_query'     => [
            [
                'key'     => 'tg_locationId',
                'value'   => $location_id,
                'compare' => '=',
            ],
        ],
        'tax_query'      => [],
    ];

    // Apply filters based on whether it's a default load or a search
    if (!$is_default && !empty($search_term)) {
        $args['s'] = $search_term;
    }

    if (!empty($categories)) {
        $args['tax_query'][] = [
            'taxonomy' => 'tg_category',
            'field'    => 'slug',
            'terms'    => $categories,
            'operator' => 'IN',
        ];
    }

    if (!empty($tags)) {
        $args['tax_query'][] = [
            'taxonomy' => 'tg_tags',
            'field'    => 'slug',
            'terms'    => $tags,
            'operator' => 'IN',
        ];
    }

    if (count($args['tax_query']) > 1) {
        $args['tax_query']['relation'] = 'AND';
    }

    $query = new WP_Query($args);

    $results = [];
    // Include row wrapper to preserve exact structure used by templates
    $row_open  = '<div class="tapgoods tapgoods-inventory row row-cols-2 row-cols-sm-2 row-cols-lg-3 g-3">';
    $row_close = '</div>';
    $items_html = '';
    $base_url = tg_get_add_to_cart_url( $location_id );
    // Build current URL for proper redirect, can be overridden by POST
    $redirect_url = null;
    if ( isset($_POST['redirect_url']) && $_POST['redirect_url'] !== '' ) {
        $redirect_url = esc_url_raw( wp_unslash( $_POST['redirect_url'] ) );
    } else {
        // Fallback to current URL logic mirroring tg-inventory-grid.php
        $current_url = home_url( add_query_arg( array(), $wp->request ) );
        if ( ! empty( $_SERVER['QUERY_STRING'] ) ) {
            $query_string = isset( $_SERVER['QUERY_STRING'] ) ? sanitize_text_field( wp_unslash( $_SERVER['QUERY_STRING'] ) ) : '';
            $current_url .= '?' . $query_string;
        }
        $redirect_url = $current_url;
    }
    if ($query->have_posts()) {
        while ($query->have_posts()) {
            $query->the_post();
            $result_item = [
                'title'   => get_the_title(),
                'excerpt' => wp_trim_words(get_the_content(), 15),
                'url'     => get_permalink(),
                'img_url' => get_the_post_thumbnail_url(get_the_ID(), 'medium'),
                'tg_id'   => get_post_meta(get_the_ID(), 'tg_id', true),
            ];
            
            if ($show_pricing) {
                $result_item['price'] = tg_get_single_display_price(get_the_ID());
            }
            
            $results[] = $result_item;

            // Build minimal, styled item card HTML to allow client-side direct replacement of grid only
            $tg_id    = get_post_meta(get_the_ID(), 'tg_id', true);
            // Prefer imgixUrl like the template
            $pictures = get_post_meta(get_the_ID(), 'tg_pictures', true);
            $img_tag  = '';
            if (!empty($pictures) && is_array($pictures) && isset($pictures[0]['imgixUrl'])) {
                $img_tag = Tapgoods_Public::get_img_tag($pictures[0]['imgixUrl'], '254', '150');
            }
            $price    = $show_pricing ? tg_get_single_display_price(get_the_ID()) : '';
            $item_url = get_permalink();
            
            // Add nprice=true parameter to URL when pricing is disabled
            if (!$show_pricing) {
                $separator = strpos($item_url, '?') !== false ? '&' : '?';
                $item_url .= $separator . 'nprice=true';
            }
            
            // Build add to cart URL using current page as redirect (like original template)
            $add_url  = tg_get_product_add_to_cart_url(
                get_the_ID(),
                array('redirectUrl' => $redirect_url)
            );
            $items_html .= '<div id="tg-item-' . esc_attr($tg_id) . '" class="col item">'
                . '<div class="item-wrap">'
                . '<figure>'
                . '<a class="d-block" href="' . esc_url( $item_url ) . '">'
                . ( ! empty( $img_tag ) ? wp_kses( $img_tag, [ 'img' => [ 'src'=>true,'srcset'=>true,'sizes'=>true,'width'=>true,'height'=>true,'alt'=>true,'loading'=>true,'decoding'=>true,'class'=>true,'id'=>true ] ] ) : '' )
                . '</a>'
                . '</figure>'
                . ($show_pricing && ! empty( $price ) ? '<div class="price mb-2">' . esc_html( $price ) . '</div>' : '')
                . '<a class="d-block item-name mb-2" href="' . esc_url( $item_url ) . '"><strong>' . esc_html( get_the_title() ) . '</strong></a>'
                . '<div class="add-to-cart">'
                . '<input class="qty-input form-control round" type="text" placeholder="Qty" id="qty-' . esc_attr( $tg_id ) . '">'
                . '<button type="button" data-target="' . esc_url( $add_url ) . '" data-item-id="' . esc_attr( $tg_id ) . '" class="add-cart btn btn-primary">' . esc_html__( 'Add', 'tapgoods' ) . '</button>'
                . '</div>'
                . '</div>'
                . '</div>';
        }
    } else {
        // No results. If a search term was provided, return a friendly message in the grid
        if (!empty($search_term)) {
            $safe_term = esc_html($search_term);
            $items_html = '<div class="col-12"><p>No results found for "' . $safe_term . '". Please try a different search.</p></div>';
        }
        // else leave empty to show an empty grid for default load with zero items
    }

    wp_reset_postdata();

    wp_send_json_success([
        'results'      => $results,
        'html'         => $row_open . $items_html . $row_close,
        'total_pages'  => $query->max_num_pages,
        'current_page' => $paged,
    ]);
}


add_action('wp_ajax_load_status_tab_content', function () {
    ob_start();

    $api_connected = get_option('tg_api_connected');
    $key_defined = defined('TAPGOODS_KEY');
    $location_settings = maybe_unserialize(get_option('tg_location_settings'));
    $reset_done = get_option('tg_reset_done');

    ?>
    <div class="container">
    <!-- Status Information -->
    <h2 class="mb-4">Status Information</h2>
    <ul class="list-unstyled mb-4">
        <li class="d-flex align-items-center mb-2">
            <strong class="me-2">API Connected:</strong>
            <span class="<?php echo $api_connected ? 'text-success fw-bold' : 'text-danger fw-bold'; ?>">
                <?php echo $api_connected ? 'Yes' : 'No'; ?>
            </span>
        </li>
        <li class="d-flex align-items-center mb-2">
            <strong class="me-2">TAPGOODS_KEY Defined:</strong>
            <span class="<?php echo $key_defined ? 'text-success fw-bold' : 'text-danger fw-bold'; ?>">
                <?php echo $key_defined ? 'Yes' : 'No'; ?>
            </span>
        </li>
        <li class="d-flex align-items-center">
            <strong class="me-2">Reset Done:</strong>
            <span class="<?php echo $reset_done ? 'text-success fw-bold' : 'text-danger fw-bold'; ?>">
                <?php echo $reset_done ? 'Yes' : 'No'; ?>
            </span>
        </li>
    </ul>

    <div class="position-absolute start-0 end-0" style="height: 16px; background-color: #f0f0f1;"></div>

    <!-- Location Settings -->

    <h2 class="mb-4 pt-4 mt-5">Location Settings</h2>
    <div class="accordion" id="locationSettingsAccordion">
        <?php foreach ($location_settings as $location_id => $settings): ?>
            <div class="accordion-item mb-3">
                <h2 class="accordion-header" id="heading<?php echo esc_attr($location_id); ?>">
                    <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#collapse<?php echo esc_attr($location_id); ?>" aria-expanded="false" aria-controls="collapse<?php echo esc_attr($location_id); ?>">
                        <?php echo esc_html($location_id); ?>
                    </button>
                </h2>
                <div id="collapse<?php echo esc_attr($location_id); ?>" class="accordion-collapse collapse" aria-labelledby="heading<?php echo esc_attr($location_id); ?>" data-bs-parent="#locationSettingsAccordion">
                    <div class="accordion-body">
                        <ul class="list-unstyled">
                            <?php foreach ($settings as $key => $value): ?>
                                <li class="mb-2">
                                    <strong><?php echo esc_html($key); ?>:</strong> 
                                    <span>
                                        <?php echo esc_html(is_array($value) ? json_encode($value, JSON_PRETTY_PRINT) : $value); ?>
                                    </span>
                                </li>
                                
                            <?php endforeach; ?>
                        </ul>
                    </div>
                </div>
            </div>
            <hr class="my-4">
        <?php endforeach; ?>
    </div>
</div>

    <?php

    echo wp_kses_post( ob_get_clean() );
    wp_die();
});


function tg_get_default_location() {
    // Check if the value is provided via AJAX
    if (isset($_POST['local_storage_location']) && !empty($_POST['local_storage_location'])) {
        // Sanitize and return the value from localStorage
        return sanitize_text_field( wp_unslash( $_POST['local_storage_location'] ) );
    }

    // Otherwise, return the value from the WordPress options
    $default_location = get_option('tg_default_location');
    return $default_location ?: null;
}

add_action('wp_ajax_tg_get_default_location', 'tg_get_default_location_ajax');
add_action('wp_ajax_nopriv_tg_get_default_location', 'tg_get_default_location_ajax');

function tg_get_default_location_ajax() {
    // Use the tg_get_default_location function to determine the value
    $default_location = tg_get_default_location();

    // Return the value as JSON
    wp_send_json_success($default_location);
    wp_die(); // Required to terminate properly
}

add_action('wp_ajax_tg_set_local_storage_location', 'tg_set_local_storage_location');
add_action('wp_ajax_nopriv_tg_set_local_storage_location', 'tg_set_local_storage_location');

function tg_set_local_storage_location() {
    if (isset($_POST['local_storage_location']) && !empty($_POST['local_storage_location'])) {
        // Optionally store in a session or other server-side variable
        $_SESSION['tg_default_location'] = isset($_POST['local_storage_location']) ? sanitize_text_field( wp_unslash( $_POST['local_storage_location'] ) ) : '';
        wp_send_json_success( isset($_SESSION['tg_default_location']) ? sanitize_text_field( wp_unslash( $_SESSION['tg_default_location'] ) ) : '' );
    } else {
        wp_send_json_error('No location provided');
    }

    wp_die();
}




add_action('wp_ajax_get_item_image', 'get_item_image');
add_action('wp_ajax_nopriv_get_item_image', 'get_item_image'); // For non-logged-in users

function get_item_image() {
//    error_log('AJAX call received in get_item_image');
//    error_log('POST Data: ' . print_r($_POST, true));

    if (!isset($_POST['item_id']) || empty($_POST['item_id'])) {
//        error_log('Item ID is missing or invalid.');
        wp_send_json_error(['message' => 'Item ID is missing or invalid.']);
        return;
    }

    $item_id = intval($_POST['item_id']);
//    error_log('Item ID: ' . $item_id);

    if ($item_id <= 0) {
//        error_log('Invalid Item ID.');
        wp_send_json_error(['message' => 'Invalid Item ID.']);
        return;
    }

    $pictures = get_post_meta($item_id, 'tg_pictures', true);
//    error_log('Pictures Meta: ' . print_r($pictures, true));

    if (!empty($pictures) && isset($pictures[0]['imgixUrl'])) {
        $img_url = $pictures[0]['imgixUrl'];
//        error_log("Image found for Item ID $item_id: $img_url");
        wp_send_json_success(['img_url' => $img_url]);
    } else {
        $placeholder_url = esc_url(plugin_dir_url(__FILE__) . 'assets/img/placeholder.png');
//        error_log("No image found for Item ID $item_id. Using placeholder: $placeholder_url");
        wp_send_json_success(['img_url' => $placeholder_url]);
    }
}

add_action('wp_ajax_get_image_by_item_id', 'get_image_by_item_id');
add_action('wp_ajax_nopriv_get_image_by_item_id', 'get_image_by_item_id');

function get_image_by_item_id() {
    if (!isset($_POST['item_id']) || empty($_POST['item_id'])) {
        wp_send_json_error(['message' => 'Invalid item_id provided.']);
    }

    $item_id = isset($_POST['item_id']) ? sanitize_text_field( wp_unslash( $_POST['item_id'] ) ) : '';

    global $wpdb;

    // Get the post_id from the postmeta table
    $post_id = $wpdb->get_var($wpdb->prepare(
        "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = 'tg_id' AND meta_value = %s",
        $item_id
    ));

    if (!$post_id) {
        wp_send_json_error(['message' => "No post found for item_id $item_id."]);
    }

    // Get the images from meta_key 'tg_pictures'
    $pictures = get_post_meta($post_id, 'tg_pictures', true);

    if (empty($pictures) || !is_array($pictures)) {
        wp_send_json_error(['message' => "No images found for post_id $post_id."]);
    }

    // Send the first image
    $image_url = $pictures[0]['imgixUrl'] ?? null;

    if (!$image_url) {
        wp_send_json_error(['message' => "Image URL missing for post_id $post_id."]);
    }

    wp_send_json_success(['image_url' => $image_url]);
}


function tg_update_inventory_grid() {
    if (isset($_POST['category'])) {
        $category = isset($_POST['category']) ? sanitize_text_field( wp_unslash( $_POST['category'] ) ) : '';
//        error_log("AJAX received category: " . $category);

        // Generate the shortcode dynamically
        $shortcode = "[tapgoods-inventory-grid category=\"$category\"]";
//        error_log("Shortcode generated dynamically: " . $shortcode);

        echo do_shortcode($shortcode);
    } else {
 //       error_log("AJAX missing category parameter.");
        echo "Error: Missing category.";
    }
    wp_die();
}
add_action( 'wp_ajax_update_inventory_grid', 'tg_update_inventory_grid' );
add_action( 'wp_ajax_nopriv_update_inventory_grid', 'tg_update_inventory_grid' );

add_filter('template_include', 'tg_custom_tax_template');
function tg_custom_tax_template($template) {
    // Check if this is a taxonomy archive page for a custom taxonomy
    if (is_tax('tg_tags')) {
        // Define the correct custom template path
        $custom_template = TAPGOODS_PLUGIN_DIR . 'public/partials/tg-tag-results.php';

        // Check if the custom template file exists
        if (file_exists($custom_template)) {
            return $custom_template; // Return the custom template
        }
    }

    return $template; // Return the default template if conditions are not met
}

// Force enqueue scripts for tag pages - using wp_head to ensure proper timing
add_action('wp_head', 'tg_enqueue_tag_page_scripts', 1);
function tg_enqueue_tag_page_scripts() {
    global $wp_query;
    
    // Debug: Check what WordPress thinks we are
    error_log('TapGoods: tg_enqueue_tag_page_scripts called');
    error_log('TapGoods: is_tax(): ' . (is_tax() ? 'true' : 'false'));
    error_log('TapGoods: is_tax(tg_tags): ' . (is_tax('tg_tags') ? 'true' : 'false'));
    error_log('TapGoods: get_queried_object: ' . print_r(get_queried_object(), true));
    
    // Debug URL check
    $request_uri = isset($_SERVER['REQUEST_URI']) ? $_SERVER['REQUEST_URI'] : '';
    $is_tag_url = strpos($request_uri, '/tags/') !== false;
    error_log('TapGoods: REQUEST_URI: ' . $request_uri);
    error_log('TapGoods: is_tag_url: ' . ($is_tag_url ? 'true' : 'false'));
    
    // Check multiple conditions to catch tag pages
    if (is_tax('tg_tags') || 
        (is_tax() && isset($wp_query->queried_object->taxonomy) && $wp_query->queried_object->taxonomy === 'tg_tags') ||
        $is_tag_url) {
        
        error_log('TapGoods: Tag page detected, enqueuing scripts');
        
        // Enqueue main JavaScript file
        wp_enqueue_script(
            'tapgoods-public-complete',
            plugin_dir_url(dirname(__FILE__)) . 'public/js/tapgoods-public-complete.js',
            array('jquery'),
            '0.1.124-tag-fix',
            true
        );
        
        // Localize script with necessary data
        wp_localize_script('tapgoods-public-complete', 'tg_public_vars', array(
            'ajaxurl' => admin_url('admin-ajax.php'),
            'default_location' => get_option('tg_default_location'),
            'plugin_url' => plugin_dir_url(dirname(__FILE__))
        ));
        
        error_log('TapGoods: Scripts enqueued for tag page');
    } else {
        error_log('TapGoods: Not a tag page, skipping script enqueue');
    }
}


// Ensure Yoast SEO metabox is added to tg_inventory
function enable_yoast_seo_for_tg_inventory() {
    // Verificar si Yoast SEO está activo antes de habilitar el soporte
    if (defined('WPSEO_VERSION')) {
        add_post_type_support('tg_inventory', 'wpseo-meta'); // Enable Yoast SEO support
    }
}
add_action('init', 'enable_yoast_seo_for_tg_inventory', 20);

// Ensure Yoast metabox is added to the post edit screen
function force_yoast_seo_metabox_on_tg_inventory() {
    // Verificar si Yoast SEO está activo antes de agregar el metabox
    if (defined('WPSEO_VERSION') && function_exists('wpseo_meta_box')) {
        add_meta_box('wpseo_meta', __('Yoast SEO', 'tapgoods'), 'wpseo_meta_box', 'tg_inventory', 'normal', 'high');
    }
}
add_action('add_meta_boxes', 'force_yoast_seo_metabox_on_tg_inventory');

// Ensure Yoast SEO scripts are properly enqueued
function enqueue_yoast_seo_assets($hook) {
    global $post;

    // Solo verificar que estamos en la página correcta
    // Yoast SEO maneja sus propios scripts automáticamente
    if ('post.php' === $hook && isset($post) && 'tg_inventory' === get_post_type($post)) {
        // Verificar si Yoast SEO está activo antes de hacer cualquier cosa
        if (defined('WPSEO_VERSION')) {
            // Yoast SEO está activo y manejará sus propios scripts
            // No necesitamos encolar scripts adicionales
        }
    }
}
add_action('admin_enqueue_scripts', 'enqueue_yoast_seo_assets');

// Ensure REST API compatibility for Yoast SEO
function enable_rest_api_for_tg_inventory($args, $post_type) {
    if ('tg_inventory' === $post_type) {
        $args['show_in_rest'] = true; // Ensure the post type supports REST API
    }
    return $args;
}
add_filter('register_post_type_args', 'enable_rest_api_for_tg_inventory', 10, 2);


// Register the cron event if it does not exist
function register_sync_cron() {
    if (!wp_next_scheduled('tg_auto_sync_event')) {
        wp_schedule_event(time(), 'daily', 'tg_auto_sync_event'); // Now runs every 24 hours
    }
}
add_action('wp', 'register_sync_cron');

// Add a custom interval of 24 hours
function add_custom_sync_interval($schedules) {
    $schedules['daily'] = array(
        'interval' => DAY_IN_SECONDS, // 24 hours
        'display'  => __('Every 24 Hours')
    );
    return $schedules;
}
add_filter('cron_schedules', 'add_custom_sync_interval');

// Hook to execute synchronization automatically when the event is triggered
add_action('tg_auto_sync_event', 'execute_auto_sync');

/**
 * Executes the automatic synchronization process.
 */
function execute_auto_sync() {
    $tg_api = Tapgoods_Connection::get_instance();

    if (method_exists($tg_api, 'sync_inventory_in_batches')) {
        $tg_api->sync_inventory_in_batches();
        error_log('Auto-sync executed.');
    } else {
        error_log('Error: No valid sync function found in Tapgoods_Connection.');
    }
}

// AJAX action to manually execute synchronization when clicking the SYNC button
function execute_manual_sync() {
    $tg_api = Tapgoods_Connection::get_instance();

    if (method_exists($tg_api, 'sync_inventory_in_batches')) {
        $tg_api->sync_inventory_in_batches();
        wp_send_json_success('Sync executed successfully.');
    } else {
        error_log('Error: No valid sync function found in Tapgoods_Connection.');
        wp_send_json_error('Sync function not found.');
    }
}

// Hook to allow manual synchronization via AJAX (without nonce validation)
add_action('wp_ajax_execute_manual_sync', 'execute_manual_sync');
add_action('wp_ajax_nopriv_execute_manual_sync', 'execute_manual_sync'); // Allows execution without authentication

// Ensure the sync cron is scheduled and trigger it on frontend visits
add_action('init', function() {
    if (!wp_next_scheduled('tg_auto_sync_event')) {
        wp_schedule_event(time(), 'daily', 'tg_auto_sync_event');
    }

    // Check if the last sync was more than 24 hours ago and execute it if necessary
    if (!is_admin()) { // Execute only on frontend visits
        $last_run = get_option('tg_last_sync_time', 0);
        if (time() - $last_run >= DAY_IN_SECONDS) { // 86400 seconds = 24 hours
            update_option('tg_last_sync_time', time());
            do_action('tg_auto_sync_event'); // Manually trigger the sync process
        }
    }
});













// Build up to 150 chars of your own “tg_description” or fallback
function tapgoods_get_default_meta_description() {
    if ( ! is_singular( 'tg_inventory' ) ) {
        return '';
    }
    global $post;
    $desc = get_post_meta( $post->ID, 'tg_description', true );
    if ( empty( $desc ) ) {
        $desc = sprintf( 'Rent %s today.', get_the_title( $post ) );
    }
    $desc = wp_strip_all_tags( $desc );
    return trim( mb_substr( $desc, 0, 150 ) );
}

// Only if Yoast is NOT active, print your fallback in <head>
add_action( 'wp_head', 'tapgoods_print_fallback_meta_description', 1 );
function tapgoods_print_fallback_meta_description() {
    // Bail if Yoast is active or not on a single inventory item
    if ( defined( 'WPSEO_VERSION' ) || ! is_singular( 'tg_inventory' ) ) {
        return;
    }
    $desc = tapgoods_get_default_meta_description();
    if ( $desc ) {
        echo '<meta name="description" content="' . esc_attr( $desc ) . "\" />\n";
    }
}


/**
 * Fix pagination issues for TapGoods Inventory Grid
 * Add this to your plugin's main file or functions.php
 */

// Fix pagination URL format and query handling
function tg_fix_pagination_urls() {
    // Only run on frontend
    if (is_admin()) {
        return;
    }
    
    // Fix paged query var detection
    add_action('init', 'tg_fix_paged_query_var');
    
    // Fix pagination links format
    add_filter('paginate_links', 'tg_force_pagination_format');
    
    // Handle URL redirects for problematic formats
    add_action('template_redirect', 'tg_redirect_pagination_urls');
    
    // Ensure paged parameter is recognized
    add_action('pre_get_posts', 'tg_fix_main_query_paged');
}
add_action('wp', 'tg_fix_pagination_urls');

/**
 * Fix paged query var detection
 */
function tg_fix_paged_query_var() {
    global $wp;
    
    // Add 'paged' to public query vars if not already present
    if (!in_array('paged', $wp->public_query_vars)) {
        $wp->add_query_var('paged');
    }
    
    // Ensure paged parameter from URL is captured
    if (isset($_GET['paged']) && !empty($_GET['paged'])) {
        set_query_var('paged', intval($_GET['paged']));
    }
}

/**
 * Force pagination links to use ?paged=X format
 */
function tg_force_pagination_format($link) {
    // Check if this is a pagination link with /page/X/ format
    if (preg_match('/\/page\/(\d+)\/?(\?.*)?$/', $link, $matches)) {
        $page_num = $matches[1];
        $query_string = isset($matches[2]) ? $matches[2] : '';
        
        // Remove the /page/X/ part and add ?paged=X instead
        $base_url = preg_replace('/\/page\/\d+\/?/', '', $link);
        $base_url = rtrim($base_url, '/');
        
        // Parse existing query string
        $query_params = array();
        if (!empty($query_string)) {
            parse_str(ltrim($query_string, '?'), $query_params);
        }
        
        // Add paged parameter
        $query_params['paged'] = $page_num;
        
        // Rebuild URL
        $link = $base_url . '?' . http_build_query($query_params);
    }
    
    return $link;
}

/**
 * Redirect problematic pagination URLs to correct format
 */
function tg_redirect_pagination_urls() {
    if (isset($_SERVER['REQUEST_URI'])) {
        $request_uri = $_SERVER['REQUEST_URI'];
        
        // Check if URL contains /page/X/ format
        if (preg_match('/\/page\/(\d+)\/?/', $request_uri, $matches)) {
            $page_num = $matches[1];
            
            // Get current URL without the /page/X/ part
            $clean_url = preg_replace('/\/page\/\d+\/?/', '', $request_uri);
            
            // Parse existing query string
            $url_parts = parse_url($clean_url);
            $query_params = array();
            
            if (isset($url_parts['query'])) {
                parse_str($url_parts['query'], $query_params);
            }
            
            // Add paged parameter
            $query_params['paged'] = $page_num;
            
            // Build correct URL
            $base_url = $url_parts['path'];
            $correct_url = home_url($base_url . '?' . http_build_query($query_params));
            
            // Redirect with 301 status
            wp_redirect($correct_url, 301);
            exit;
        }
    }
}

/**
 * Fix main query to properly handle paged parameter
 */
function tg_fix_main_query_paged($query) {
    // Only modify main query on frontend
    if (is_admin() || !$query->is_main_query()) {
        return;
    }
    
    // Check for paged parameter in URL
    $paged = 0;
    
    // First check $_GET
    if (isset($_GET['paged']) && !empty($_GET['paged'])) {
        $paged = intval($_GET['paged']);
    }
    
    // Then check query var
    if (!$paged && get_query_var('paged')) {
        $paged = intval(get_query_var('paged'));
    }
    
    // Set the paged parameter for the query
    if ($paged > 0) {
        $query->set('paged', $paged);
    }
}

/**
 * Fix canonical redirect that might interfere with pagination
 */
function tg_disable_canonical_redirect_for_pagination() {
    // Check if we have paged parameter
    if (get_query_var('paged') || (isset($_GET['paged']) && !empty($_GET['paged']))) {
        remove_action('template_redirect', 'redirect_canonical');
    }
}
add_action('template_redirect', 'tg_disable_canonical_redirect_for_pagination', 1);

/**
 * Ensure WordPress recognizes custom pagination parameters
 */
function tg_add_pagination_rewrite_rules() {
    // Add rewrite rule for pages with paged parameter
    add_rewrite_rule(
        '(.+?)/page/([0-9]{1,})/?$',
        'index.php?pagename=$matches[1]&paged=$matches[2]',
        'top'
    );
}
add_action('init', 'tg_add_pagination_rewrite_rules');

/**
 * Add custom query vars for pagination
 */
function tg_add_pagination_query_vars($vars) {
    $vars[] = 'paged';
    return $vars;
}
add_filter('query_vars', 'tg_add_pagination_query_vars');

/**
 * Debug function
 * Uncomment to debug pagination issues
 */
/*
function tg_debug_pagination() {
    if (isset($_GET['debug_pagination'])) {
        echo '<div class="tapgoods-debug-info">';
        echo '<strong>Pagination Debug Info:</strong><br>';
        echo 'get_query_var("paged"): ' . get_query_var('paged') . '<br>';
        echo '$_GET["paged"]: ' . (isset($_GET['paged']) ? $_GET['paged'] : 'not set') . '<br>';
        echo 'Current URL: ' . $_SERVER['REQUEST_URI'] . '<br>';
        echo 'is_paged(): ' . (is_paged() ? 'true' : 'false') . '<br>';
        echo '</div>';
    }
}
add_action('wp_head', 'tg_debug_pagination');
*/