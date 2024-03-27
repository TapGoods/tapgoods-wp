<?php
/**
 * Tapgoods Formatting Functions
 *
 * @package Tapgoods\Functions
 * @version 0.1.0
 */

defined( 'ABSPATH' ) || exit;

/**
 * Converts a string (e.g. 'yes' or 'no') to a bool.
 *
 * @since 0.1.0
 * @param string|bool $str String to convert. If a bool is passed it will be returned as-is.
 * @return bool
 */
function tg_string_to_bool( $str ) {
	$str = $str ?? '';
	return is_bool( $str ) ? $str : ( 'yes' === strtolower( $str ) || 1 === $str || 'true' === strtolower( $str ) || '1' === $str );
}

/**
 * Converts a bool to a 'yes' or 'no'.
 *
 * @since 0.1.0
 * @param bool|string $boolean Bool to convert. If a string is passed it will first be converted to a bool.
 * @return string
 */
function tg_bool_to_string( $boolean ) {
	if ( ! is_bool( $boolean ) ) {
		$boolean = tg_string_to_bool( $boolean );
	}
	return true === $boolean ? 'yes' : 'no';
}

/**
 * Explode a string into an array by $delimiter and remove empty values.
 *
 * @since 0.1.0
 * @param string $str    String to convert.
 * @param string $delimiter Delimiter, defaults to ','.
 * @return array
 */
function tg_string_to_array( $str, $delimiter = ',' ) {
	$str = $str ?? '';
	return is_array( $str ) ? $str : array_filter( explode( $delimiter, $str ) );
}

/**
 * Sanitize permalink values before insertion into DB.
 *
 * @since  0.1.0
 * @param  string $value Permalink.
 * @return string
 */
function tg_sanitize_permalink( $value ) {
	global $wpdb;

	$value = $wpdb->strip_invalid_text_for_column( $wpdb->options, 'option_value', $value ?? '' );

	if ( is_wp_error( $value ) ) {
		$value = '';
	}

	$value = esc_url_raw( trim( $value ) );
	$value = str_replace( 'http://', '', $value );
	return untrailingslashit( $value );
}

/**
 * Convert a float to a string without locale formatting which PHP adds when changing floats to strings.
 *
 * @param  float $float Float value to format.
 * @return string
 */
function tg_float_to_string( $float ) {
	if ( ! is_float( $float ) ) {
		return $float;
	}

	$locale = localeconv();
	$string = strval( $float );
	$string = str_replace( $locale['decimal_point'], '.', $string );

	return $string;
}
