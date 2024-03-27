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

function tg_parse_request( $wp ) {
	Tapgoods_Helpers::tgqm( $wp );

	// Check if the request is looking for a TapGoods Category term
	if ( count( $wp->query_vars ) === 1 && array_key_exists( 'tg_category', $wp->query_vars ) ) {
		Tapgoods_Helpers::tgqm( 'tg_category pre query!' );

		// Do a term query before the main query to see if the term is found
		$slug      = $wp->query_vars['tg_category'];
		$args      = array(
			'taxonomy' => array( 'tg_category' ),
			'slug'     => $slug,
			'count'    => 1,
		);
		$pre_query = get_terms( $args );
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

/**
 * Hooked when a tg_category query is modified to check tg_inventory, Pages and Posts
 * Checks the parsed query for a permalink and redirects to the permalink if different
 */
function tg_template_redirect() {
	if ( ! is_single() ) {
		return;
	}

	Tapgoods_Helpers::tgqm( 'tg_template_redirect' );

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

	Tapgoods_Helpers::tgqm( 'tg_term_template_redirect' );

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

function tg_get_sf_url() {
	return $url;
}

function tg_get_sf_domain() {
	return $domain;
}

function tg_get_cart_url() {
	return false;
}

function tg_get_checkout_url() {
	return false;
}

function tg_get_sign_in_url() {
	return false;
}

function tg_get_sign_up_url() {
	return false;
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

function tg_locate_template() {
	return false;
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
