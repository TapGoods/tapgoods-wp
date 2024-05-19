<?php

global $post;

$description = apply_filters(
	'tg_item_description',
	get_post_meta( $post->ID, 'tg_description', true )
);

$tags = get_the_terms( $post, 'tg_tags' );
if ( false !== $tags ) {
	$tag_links = array();
	foreach ( $tags as $tg_tag ) {
		$tag_link    = get_term_link( $tg_tag );
		$tag_links[] = "<a href=\"{$tag_link}\">$tg_tag->name</a>";
	}
}

$tg_per_page = ( isset( $_COOKIE['tg-per-page'] ) ) ? sanitize_text_field( wp_unslash( $_COOKIE['tg-per-page'] ) ) : get_option( 'tg_per_page', 12 );

$tg_id = get_post_meta( $post->ID, 'tg_id', true );

$date_format = tg_date_format();
$today       = wp_date( $date_format );

global $wp;
$current_page = home_url( $wp->request );
$cart_url = tg_get_product_add_to_cart_url( $post->ID, array( 'redirectUrl' => $current_page ) );


?>
<div class="tapgoods">
	<?php do_action( 'tg_before_inventory_single_container' ); ?>
	<div class="inventory-single container-fluid">
		<?php do_action( 'tg_before_inventory_single_search' ); ?>
		[tapgoods-search]
		<?php do_action( 'tg_after_inventory_single_search' ); ?>
		<section class="inventory-single-content row row-cols-1 row-cols-md-2 p-3">
			<?php do_action( 'tg_before_inventory_single_images' ); ?>
			[tapgoods-image-carousel product="<?php echo $post->ID; ?>"]
			<?php do_action( 'tg_before_inventory_single_summary' ); ?>
			<section class="summary col">
				<div class="maginifier-preview" hidden></div>
				<span class="name"><?php the_title(); ?></span>
				<div class="pricing">
					<?php $prices = tg_get_prices( $post->ID ); ?>
					<?php foreach ( $prices as $price_arr ) : ?>
						<span><?php echo '$' . wp_kses( current( $price_arr ), 'post' ); ?></span>
						<span><?php echo ' / ' . wp_kses( array_key_first( $price_arr ), 'post' ); ?></span>
					<?php endforeach; ?>
				</div>
				<div class="quantity-select mb-4">
					<input type="text" placeholder="Qty" name="quantity" class="form-control qty-input">
					<button data-target="<?php echo esc_url( $cart_url ); ?>" class="add-cart btn btn-primary">Add Item</button>
				</div>
			</section>
			<section class="details col py-4 mt-2">
				<div class="description">
					<?php echo $description; ?>
				</div>
				<?php if ( false !== $tags ) : ?>
				<div class="tags">
					<p class="label">Tags: </p><?php echo wp_kses( implode( ', ', $tag_links ), 'post' ); ?>
				</div>
				<?php endif; ?>
			</section>
			<section class="misc col">
				<div class="date-range">
					<p>Know your event date/time? Set it now.</p>
					<div id="tg-dates-selector" class="dates-selector">
						<div class="date-input-wrapper order-start">
							<label><?php _e( 'Order Start', 'tapgoods' ); //phpcs:ignore ?></label>
							<input type="date" name="eventStartDate" class="date-input form-control" value="<?php echo esc_attr( tg_get_start_date() ); ?>" min="<?php echo esc_attr( $today ); ?>">
							<input name="eventStartTime" type="time" class="time-input form-control" value="<?php echo esc_attr( tg_get_start_time() ); ?>">
						</div>
						<div class="date-input-wrapper order-end">
							<label><?php _e( 'Order End', 'tapgoods' ); //phpcs:ignore ?></label>
							<input type="date" name="eventEndDate" class="date-input form-control" value="<?php echo esc_attr( tg_get_end_date() ); ?>" min="<?php echo esc_attr( $today ); ?>">
							<input name="eventEndTime" type="time" class="time-input form-control" value="<?php echo esc_attr( tg_get_end_time() ); ?>">
						</div>
					</div>
				</div>
				<div class="additional-details">
					<?php do_action( 'tg_product_additional_details' ); ?>
					<?php do_action( 'tg_product_dimensions' ); ?>
					<div class="row">
						<div class="col"></div>
						<div class="col">[tapgoods-dimensions]</div>
					</div>
				</div>
			</section>
			<section class="linked-items col">

			</section>
		</seconion>
	</div>
</div>

<?php
//Tapgoods_Helpers::tgpp( $tags );

