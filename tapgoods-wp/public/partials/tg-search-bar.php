<?php
/**
 * [tapgoods-search-bar]: a search box for a header or menu (WPB-183).
 *
 * Unlike [tapgoods-search], which filters a grid on the same page over AJAX,
 * this is a plain GET form: it works on any page, needs no script, and sends the
 * visitor to the shop with ?tg_search=<term>. The shop's own search box picks
 * the term up from the URL (initSearchInstance in tapgoods-public-complete.js)
 * and runs the usual search, so results, filters and pagination behave exactly
 * as if the visitor had typed there.
 *
 * The markup does not depend on the visitor, so a page cache can serve it to
 * everyone.
 *
 * @package Tapgoods
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

$tg_results_url = ! empty( $atts['action_url'] ) ? esc_url_raw( tapgrein_search_bar_att( $atts['action_url'] ) ) : tapgrein_get_shop_url();

// No shop page and no URL given: there is nowhere to send a search, and a form
// that submits to the current page would look broken. Say so to editors only.
if ( '' === $tg_results_url ) {
	if ( current_user_can( 'edit_pages' ) ) {
		echo '<!-- [tapgoods-search-bar]: no page with [tapgoods-inventory] found. Set action_url="..." to choose the results page. -->';
	}
	return;
}

$tg_target      = tapgrein_search_bar_form_target( $tg_results_url );
$tg_placeholder = isset( $atts['placeholder'] ) ? tapgrein_search_bar_att( $atts['placeholder'] ) : '';
$tg_button_text = isset( $atts['button_text'] ) ? tapgrein_search_bar_att( $atts['button_text'] ) : '';
$tg_input_id    = wp_unique_id( 'tg-search-bar-' );
$tg_label       = '' !== $tg_placeholder ? $tg_placeholder : __( 'Search rentals', 'tapgoods' );
?>
<form class="tg-search-bar" role="search" method="get" action="<?php echo esc_url( $tg_target['action'] ); ?>">
	<?php foreach ( $tg_target['hidden'] as $tg_name => $tg_value ) : ?>
		<input type="hidden" name="<?php echo esc_attr( $tg_name ); ?>" value="<?php echo esc_attr( $tg_value ); ?>">
	<?php endforeach; ?>
	<label class="tg-search-bar__label screen-reader-text" for="<?php echo esc_attr( $tg_input_id ); ?>"><?php echo esc_html( $tg_label ); ?></label>
	<input id="<?php echo esc_attr( $tg_input_id ); ?>" class="tg-search-bar__input" type="search" name="tg_search" placeholder="<?php echo esc_attr( $tg_placeholder ); ?>" autocomplete="off" required>
	<button type="submit" class="tg-search-bar__button" aria-label="<?php echo esc_attr( $tg_label ); ?>">
		<?php if ( '' !== $tg_button_text ) : ?>
			<?php echo esc_html( $tg_button_text ); ?>
		<?php else : ?>
			<svg aria-hidden="true" focusable="false" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.25" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="7"></circle><line x1="16.5" y1="16.5" x2="21" y2="21"></line></svg>
		<?php endif; ?>
	</button>
</form>
