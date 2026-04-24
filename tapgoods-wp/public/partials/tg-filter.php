<?php

if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly
$tg_category_filter_class = 'col-sm-4 col-xs-12 p-0 tg-filter';

$categories  = tapgrein_get_categories();
$date_format = tapgrein_date_format();
$today       = wp_date( $date_format );

?>
<aside class="<?php echo esc_attr( apply_filters( 'tg_category_filter_class', $tg_category_filter_class ) ); ?>">
<?php do_action( 'tg_before_inventory_filter' ); ?>
<div class="breadcrumb"></div>
<?php do_action( 'tg_before_date_filter' ); ?>
<div id="tg-dates-selector" class="dates-selector px-4"  style="display: none;">
	<div class="date-input-wrapper order-start">
        <label><?php esc_html_e( 'Order Start', 'tapgoods' ); ?></label>
		<input id="eventStartDate" type="date" name="eventStartDate" value="<?php echo esc_attr( tapgrein_get_start_date() ); ?>" min="<?php echo esc_attr( $today ); ?>" class="date-input form-control round">
		<input id="eventStartTime" name="eventStartTime" type="time" value="<?php echo esc_attr( tapgrein_get_start_time() ); ?>" class="time-input form-control">
	</div>
	<div class="date-input-wrapper order-end">
        <label><?php esc_html_e( 'Order End', 'tapgoods' ); ?></label>
		<input id="eventEndDate" type="date" name="eventEndDate" value="<?php echo esc_attr( tapgrein_get_end_date() ); ?>" min="<?php echo esc_attr( $today ); ?>" class="date-input form-control round">
		<input id="eventEndTime" name="eventEndTime" type="time" value="<?php echo esc_attr( tapgrein_get_end_time() ); ?>" class="time-input form-control">
	</div>
</div>
<?php do_action( 'tg_after_date_filter' ); ?>

<?php
$is_mobile        = wp_is_mobile();
$button_classes   = 'accordion-button' . ( $is_mobile ? ' collapsed' : '' );
$aria_expanded    = $is_mobile ? 'false' : 'true';
$collapse_classes = 'accordion-collapse collapse' . ( $is_mobile ? '' : ' show' );
?>

<div class="categories">
	<div class="accordion">
		<div class="accordion-item">
			<h2 class="accordion-header">
				<button
					class="<?php echo esc_attr( $button_classes ); ?>"
					type="button"
					data-bs-toggle="collapse"
					data-bs-target="#collapseOne"
					aria-expanded="<?php echo esc_attr( $aria_expanded ); ?>"
					aria-controls="collapseOne"
				>
					<?php
					$categories_text = apply_filters( 'tg_categories_header_text', 'Categories' );
					echo esc_html( $categories_text );
					?>
				</button>
			</h2>

			<div id="collapseOne" class="<?php echo esc_attr( $collapse_classes ); ?> category-links">
				<a class="category-link" href="#" data-category-id="">
					<?php esc_html_e( 'All Categories', 'tapgoods' ); ?>
				</a>

				<?php foreach ( $categories as $category ) :
					// COMMENTED: Subcategories temporarily disabled per client request
					// Get subcategories (tags) for this category based on parent relationship
					// $subcategories = get_terms( array(
					// 	'taxonomy'   => 'tg_tags',
					// 	'hide_empty' => false,
					// 	'meta_query' => array(
					// 		array(
					// 			'key'     => 'tg_parent_category',
					// 			'value'   => $category->term_id,
					// 			'compare' => '='
					// 		)
					// 	)
					// ) );

					// $has_subcategories = !empty($subcategories) && !is_wp_error($subcategories);
				?>
					<a class="category-link" href="#" data-category-id="<?php echo esc_attr( $category->slug ); ?>">
						<?php echo esc_html( $category->name ); ?>
					</a>
					<?php
					// COMMENTED: Subcategory display temporarily disabled
					// if ( $has_subcategories ) :
					?>
						<!-- <div class="subcategory-list" data-parent-category="<?php echo esc_attr( $category->term_id ); ?>"> -->
							<?php // foreach ( $subcategories as $subcategory ) : ?>
								<!-- <a class="subcategory-link" href="#" data-tag-id="<?php echo esc_attr( $subcategory->slug ); ?>"> -->
									<?php // echo esc_html( $subcategory->name ); ?>
								<!-- </a> -->
							<?php // endforeach; ?>
						<!-- </div> -->
					<?php // endif; ?>
				<?php endforeach; ?>
			</div>
		</div>
	</div>
</div>

<?php do_action( 'tg_after_inventory_filter' ); ?>
</aside>

<script>
document.addEventListener('DOMContentLoaded', function() {
    // COMMENTED: Subcategory handlers temporarily disabled
    // const subcategoryLinks = document.querySelectorAll(".subcategory-link");
    const categoryLinks = document.querySelectorAll(".category-link");

    // COMMENTED: Handle subcategory clicks - temporarily disabled
    // subcategoryLinks.forEach(function(link) {
    //     link.addEventListener("click", function(event) {
    //         event.preventDefault();
    //         const selectedTag = this.getAttribute("data-tag-id");
    //         if (!selectedTag) return;
    //         const urlParams = new URLSearchParams(window.location.search);
    //         const cleanTag = selectedTag.startsWith('tag-') ? selectedTag.substring(4) : selectedTag;
    //         urlParams.set('tags', cleanTag);
    //         urlParams.delete('category');
    //         urlParams.delete('paged');
    //         window.location.search = urlParams.toString();
    //     });
    // });

    // Handle category clicks
    categoryLinks.forEach(function(link) {
        link.addEventListener("click", function(event) {
            event.preventDefault();

            const selectedCategory = this.getAttribute("data-category-id");

            if (selectedCategory === null || selectedCategory === "") {
                const urlParams = new URLSearchParams(window.location.search);
                urlParams.delete('category');
                urlParams.delete('tags');
                urlParams.delete('paged');
                window.location.search = urlParams.toString();
                return;
            }

            const urlParams = new URLSearchParams(window.location.search);
            urlParams.set('category', selectedCategory);
            urlParams.delete('tags');
            urlParams.delete('paged');

            window.location.search = urlParams.toString();
        });
    });
});
</script>
