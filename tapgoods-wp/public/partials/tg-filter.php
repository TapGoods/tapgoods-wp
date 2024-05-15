<?php

$tg_category_filter_class = 'col-sm-4 col-xs-12 p-0 tg-filter';

$categories = tg_get_categories();

?>
<aside class="<?php echo esc_attr( apply_filters( 'tg_category_filter_class', $tg_category_filter_class ) ); ?>">
<?php do_action( 'tg_before_inventory_category_filter' ); ?>
<div class="breadcrumb"></div>
<div class="dates-selector px-4">
	<div class="date-input-wrapper order-start">
		<label><?php _e( 'Order Start', 'tapgoods' ); //phpcs:ignore ?></label>
		<input type="date" name="eventStartDate" class="date-input form-control round">
		<input name="eventStartTime" type="time" class="time-input form-control round">
	</div>
	<div class="date-input-wrapper order-end">
		<label><?php _e( 'Order End', 'tapgoods' ); //phpcs:ignore ?></label>
		<input type="date" name="eventEndDate" class="date-input form-control round">
		<input name="eventEndTime" type="time" class="time-input form-control round">
	</div>
</div>
<div class="quantity-filer"></div>
<div class="color-filter"></div>
<div class="sub-categories"></div>
<div class="categories">
	<h4><?php _e( 'All Categories', 'tapgoods' ); //phpcs:ignore ?></h4>
	<div class="accordion">
		<div class="accordion-item">
			<h2 class="accordion-header">
				<button class="accordion-button" type="button" data-bs-toggle="collapse" data-bs-target="#collapseOne" aria-expanded="true" aria-controls="collapseOne">
				<?php _e( 'Categories', 'tapgoods' ); //phpcs:ignore ?>
				</button>
			</h2>
			<div id="collapseOne" class="accordion-collapse collapse category-links">
				<?php foreach ( $categories as $category ) : ?>
					<a class="category-link" href="<?php echo esc_url( get_term_link( $category, 'tg_category' ) ); ?>">
					<?php echo esc_html( $category->name ); ?>
					</a>
				<?php endforeach; ?>
			</div>
		</div>
	</div>
</div>
<?php do_action( 'tg_after_inventory_category_filter' ); ?>
</aside>
