<?php

global $wp;
$current_url = home_url( $wp->request );
$tg_inventory_pagination_class = 'foo';

$tg_per_page = ( isset( $_COOKIE['tg-per-page'] ) ) ? sanitize_text_field( wp_unslash( $_COOKIE['tg-per-page'] ) ) : get_option( 'tg_per_page', 12 );

$tg_page = ( get_query_var( 'paged' ) ) ? get_query_var( 'paged' ) : 1;

$args = array(
	'post_type'      => 'tg_inventory',
	'post_status'    => 'publish',
	'posts_per_page' => $tg_per_page,
	'order_by'       => 'menu_order',
	'paged'          => $tg_page,
);

$tg_search = get_query_var( 's', false );
if ( false !== $tg_search ) {
	$args['s'] = $tg_search;
}

$categories = get_query_var( 'tg_category', false );
$tg_tags    = get_query_var( 'tg_tags', false );

$tax_args = array();
if ( false !== $categories ) {
	$tax_args[] = array(
		'taxonomy' => 'tg_category',
		'terms'    => $categories,
		'field'    => 'slug',
		'operator' => 'IN',
	);
}

if ( false !== $tg_tags ) {
	$tax_args[] = array(
		'taxonomy' => 'tg_tags',
		'terms'    => $tg_tags,
		'field'    => 'slug',
		'operator' => 'IN',
	);
}

if ( count( $tax_args ) === 1 ) {
	$args['tax_query'] = $tax_args;
}

if ( count( $tax_args ) > 1 ) {
	$args['tax_query'] = array(
		'relation' => 'AND',
		$tax_args,
	);
}

// tg_write_log( $args );
$query = new WP_Query( $args );

$tg_pages = $query->max_num_pages;

// tg_write_log( $query );
?>
<div class="tapgoods tapgoods-inventory row row-cols-md-3 gx-3 gy-5 row-cols-sm-1">
<?php if ( $query->have_posts() ) : ?>
	<?php while ( $query->have_posts() ) : ?>
		<?php $query->the_post(); ?>
		<?php

		$product_id = get_the_ID();
		$tg_id      = get_post_meta( $product_id, 'tg_id', true );
		$price      = tg_get_single_display_price( $product_id );

		$url_params = array(
			'redirectUrl' => $current_url,
		);

		$add_cart_url = tg_get_product_add_to_cart_url( $product_id, $url_params );

		$pictures = get_post_meta( get_the_ID(), 'tg_pictures', true );

		if ( empty( $pictures ) ) {
			$pictures = false;
		}

		$img_tag = '';
		if ( ! empty( $pictures ) && count( $pictures ) > 0 ) {
			$img_tag = Tapgoods_Public::get_img_tag( $pictures[0]['imgixUrl'], '254', '150' );
		}
		?>
		<div id="tg-item-<?php echo esc_attr( $tg_id ); ?>" class="tapgoods-inventory col item" data-tgId="<?php echo esc_attr( $tg_id ); ?>">
			<div class="item-wrap">
				<figure>
					<a class="d-block" style="text-align: center;" href="<?php the_permalink(); ?>">
						<?php if ( ! empty( $pictures ) ) : ?>
							<?php
							echo wp_kses(
								$img_tag,
								[
									'img' => [
										'src'      => true,
										'srcset'   => true,
										'sizes'    => true,
										'class'    => true,
										'id'       => true,
										'width'    => true,
										'height'   => true,
										'alt'      => true,
										'loading'  => true,
										'decoding' => true,
									],
								]
							);
							?>
						<?php endif; ?>
						<?php if ( false !== get_option( 'tg_show_item_pricing', false ) ) : ?>
							<div class="pricing"></div>
						<?php endif; ?>
					</a>
				</figure>
				<div class="price mb-2">
					<?php echo esc_html( $price ); ?>
				</div>
				<a class="d-block item-name mb-2" href="<?php the_permalink(); ?>">
					<strong><?php the_title(); ?></strong>
				</a>
				<div class="add-to-cart item-<?php the_ID(); ?>">
					<input class="qty-input form-control round" type="text" placeholder="Qty">
					<button data-target="<?php echo esc_url( $add_cart_url ); ?>" class="add-cart btn btn-primary">Add</button>
				</div>
			</div>
		</div>
	<?php endwhile; ?>
	<?php do_action( 'tg_inventory_after_grid' ); ?>
	<?php if ( $tg_pages > 1 ) : ?>
	<div class="<?php esc_attr( apply_filters( 'tg_inventory_pagination_class', $tg_inventory_pagination_class ) ); ?>">
		<?php do_action( 'tg_before_inventory_pagination' ); ?>
		<nav aria-label="Page navigation">
			<ul class="pagination justify-content-center align-items-center">
				<li class="page-item disabled">
					<a class="page-link"><span class="dashicons dashicons-controls-skipback"></span></a>
				</li>
				<li class="page-item disabled">
					<a class="page-link"><span class="dashicons dashicons-controls-back"></span></a>
				</li>
				<li class="page-item current-page">
					<a class="page-link"><?php echo esc_html( $query->query['paged'] ); ?></a>
				</li>
				<li class="page-item disabled">
					<a>of</a>
				</li>
				<li class="page-item disabled">
					<a class="page-link"><?php echo esc_html( $tg_pages ); ?></a>
				</li>
				<li class="page-item disabled">
					<a href="<?php echo '?paged=' . ++$tg_page; ?>" class="page-link"><span class="dashicons dashicons-controls-forward"></span></a>
				</li>
				<li class="page-item disabled">
					<a class="page-link"><span class="dashicons dashicons-controls-skipforward"></span></a>
				</li>
			</ul>
		</nav>
		<?php do_action( 'tg_after_inventory_pagination' ); ?>
	</div>
	<?php endif; ?>
<?php endif; ?>
<?php wp_reset_postdata(); ?>
</div>