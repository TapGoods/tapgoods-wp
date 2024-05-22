<?php

$tg_category_filter_class = 'col-sm-4 col-xs-12 p-0 tg-filter';

$categories  = tg_get_categories();
$date_format = tg_date_format();
$today       = wp_date( $date_format );

?>
<aside class="<?php echo esc_attr( apply_filters( 'tg_category_filter_class', $tg_category_filter_class ) ); ?>">
<?php do_action( 'tg_before_inventory_filter' ); ?>
<div class="breadcrumb"></div>
<?php do_action( 'tg_before_date_filter' ); ?>
<div id="tg-dates-selector" class="dates-selector px-4">
	<div class="date-input-wrapper order-start">
		<label><?php _e( 'Order Start', 'tapgoods' ); //phpcs:ignore ?></label>
		<input type="date" name="eventStartDate" value="<?php echo esc_attr( tg_get_start_date() ); ?>" min="<?php echo esc_attr( $today ); ?>" class="date-input form-control round">
		<input name="eventStartTime" type="time" value="<?php echo esc_attr( tg_get_start_time() ); ?>" class="time-input form-control">
	</div>
	<div class="date-input-wrapper order-end">
		<label><?php _e( 'Order End', 'tapgoods' ); //phpcs:ignore ?></label>
		<input type="date" name="eventEndDate" value="<?php echo esc_attr( tg_get_end_date() ); ?>" min="<?php echo esc_attr( $today ); ?>" class="date-input form-control round">
		<input name="eventEndTime" type="time" value="<?php echo esc_attr( tg_get_end_time() ); ?>" class="time-input form-control">
	</div>
</div>
<?php do_action( 'tg_after_date_filter' ); ?>
<?php if ( is_archive() ) : ?>
<?php do_action( 'tg_before_qty_filter' ); ?>
<div class="quantity-filer"></div>
<?php do_action( 'tg_before_color_filter' ); ?>
<div class="color-filter"></div>
<?php do_action( 'tg_before_tag_filter' ); ?>
<div class="sub-categories"></div>
<?php endif; ?>
<?php do_action( 'tg_before_category_filter' ); ?>
<div class="categories">
	<h4><?php _e( apply_filters( 'tg_all_categories_header_text', 'All Categories' ), 'tapgoods' ); //phpcs:ignore ?></h4>
	<div class="accordion">
		<div class="accordion-item">
			<h2 class="accordion-header">
				<button class="accordion-button" type="button" data-bs-toggle="collapse" data-bs-target="#collapseOne" aria-expanded="true" aria-controls="collapseOne">
				<?php _e( apply_filters( 'tg_categories_header_text', 'Categories' ), 'tapgoods' ); //phpcs:ignore ?>
				</button>
			</h2>
			<div id="collapseOne" class="<?php echo esc_attr( apply_filters( 'tg_category_accordion_collapse_class', 'accordion-collapse collapse category-links show' ) ); ?>">
				<?php foreach ( $categories as $category ) : ?>
					<?php do_action( 'tg_before_category_link' ); ?>
					<a class="category-link" href="<?php echo esc_url( get_term_link( $category, 'tg_category' ) ); ?>">
					<?php do_action( 'tg_before_category_link_text' ); ?>
					<?php echo esc_html( $category->name ); ?>
					<?php do_action( 'tg_after_category_link_text' ); ?>
					</a>
					<?php do_action( 'tg_after_category_link' ); ?>
				<?php endforeach; ?>
			</div>
		</div>
	</div>
</div>
<?php do_action( 'tg_after_inventory_filter' ); ?>
</aside>
