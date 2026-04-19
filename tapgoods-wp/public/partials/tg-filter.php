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
					// Get subcategories (tags) for this category
					// First try to get tags with parent relationship
					$subcategories = get_terms( array(
						'taxonomy'   => 'tg_tags',
						'hide_empty' => false,
						'meta_query' => array(
							array(
								'key'     => 'tg_parent_category',
								'value'   => $category->term_id,
								'compare' => '='
							)
						)
					) );

					// TEMPORARY: If no subcategories found with parent relationship,
					// get ALL tags (this will be removed after sync is working)
					if (empty($subcategories) || is_wp_error($subcategories)) {
						// Get location ID to filter tags
						$location_id = tapgrein_get_wp_location_id();

						// For now, just get first 5 tags to test functionality
						$subcategories = get_terms( array(
							'taxonomy'   => 'tg_tags',
							'hide_empty' => false,
							'number'     => 5,
						) );

						error_log("TEMP: Category {$category->name} - Using all tags temporarily. Found: " . count($subcategories));
					}

					$has_subcategories = !empty($subcategories) && !is_wp_error($subcategories);

					// Debug: Log subcategories found
					error_log("Category: {$category->name} (ID: {$category->term_id}) - Subcategories found: " . (is_array($subcategories) ? count($subcategories) : 0));
					if (!empty($subcategories) && is_array($subcategories)) {
						foreach ($subcategories as $sub) {
							error_log("  - Subcategory: {$sub->name} (slug: {$sub->slug})");
						}
					}
				?>
					<div class="category-item">
						<div class="category-header">
							<a class="category-link" href="#" data-category-id="<?php echo esc_attr( $category->slug ); ?>">
								<?php echo esc_html( $category->name ); ?>
							</a>
							<?php if ( $has_subcategories ) : ?>
								<button class="subcategory-toggle" data-category-id="<?php echo esc_attr( $category->term_id ); ?>" aria-label="Toggle subcategories">
									<span class="toggle-icon">▶</span>
								</button>
							<?php endif; ?>
						</div>
						<?php if ( $has_subcategories ) : ?>
							<div class="subcategory-list" data-parent-category="<?php echo esc_attr( $category->term_id ); ?>" style="display: none;">
								<?php foreach ( $subcategories as $subcategory ) : ?>
									<a class="subcategory-link" href="#" data-tag-id="<?php echo esc_attr( $subcategory->slug ); ?>">
										<?php echo esc_html( $subcategory->name ); ?>
									</a>
								<?php endforeach; ?>
							</div>
						<?php endif; ?>
					</div>
				<?php endforeach; ?>
			</div>
		</div>
	</div>
</div>

<?php do_action( 'tg_after_inventory_filter' ); ?>
</aside>

<script>
// TEMPORARY: Inline script to test subcategory functionality
// This will be moved back to tapgoods-public-complete.js once working
console.log('TapGoods Filter: Inline script loaded');

document.addEventListener('DOMContentLoaded', function() {
    console.log('TapGoods Filter: DOM ready, initializing subcategory toggles');

    const subcategoryToggles = document.querySelectorAll(".subcategory-toggle");
    const subcategoryLinks = document.querySelectorAll(".subcategory-link");
    const categoryLinks = document.querySelectorAll(".category-link");

    console.log('TapGoods Filter: Found', subcategoryToggles.length, 'toggles');
    console.log('TapGoods Filter: Found', subcategoryLinks.length, 'subcategory links');
    console.log('TapGoods Filter: Found', categoryLinks.length, 'category links');

    // Handle subcategory toggle clicks
    subcategoryToggles.forEach(function(toggle) {
        toggle.addEventListener("click", function(event) {
            event.preventDefault();
            event.stopPropagation();

            const categoryId = this.getAttribute("data-category-id");
            const subcategoryList = document.querySelector('.subcategory-list[data-parent-category="' + categoryId + '"]');
            const toggleIcon = this.querySelector(".toggle-icon");

            console.log('TapGoods Filter: Toggle clicked for category', categoryId);

            if (subcategoryList) {
                const isVisible = subcategoryList.style.display !== "none" && subcategoryList.style.display !== "";
                subcategoryList.style.display = isVisible ? "none" : "block";
                toggleIcon.textContent = isVisible ? "▶" : "▼";
                console.log('TapGoods Filter: Subcategory list toggled to', subcategoryList.style.display);
            } else {
                console.log('TapGoods Filter: No subcategory list found for category', categoryId);
            }
        });
    });

    // Handle subcategory clicks
    subcategoryLinks.forEach(function(link) {
        link.addEventListener("click", function(event) {
            event.preventDefault();

            const selectedTag = this.getAttribute("data-tag-id");
            if (!selectedTag) return;

            console.log('TapGoods Filter: Subcategory clicked:', selectedTag);

            const urlParams = new URLSearchParams(window.location.search);
            const cleanTag = selectedTag.startsWith('tag-') ? selectedTag.substring(4) : selectedTag;
            urlParams.set('tags', cleanTag);
            urlParams.delete('category');
            urlParams.delete('paged');

            window.location.search = urlParams.toString();
        });
    });

    // Handle category clicks
    categoryLinks.forEach(function(link) {
        link.addEventListener("click", function(event) {
            event.preventDefault();

            const selectedCategory = this.getAttribute("data-category-id");
            console.log('TapGoods Filter: Category clicked:', selectedCategory);

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

<?php
// Original script removed - now using inline script above temporarily
/*<script>
document.addEventListener("DOMContentLoaded", function() {
    var accordionCollapse = document.getElementById("collapseOne");
    var accordionButton = document.querySelector(".accordion-button");

    // Toggle accordion
    function toggleAccordion() {
        if (window.innerWidth >= 1200) {
            accordionCollapse.classList.add("show");
            accordionButton.classList.remove("collapsed");
            accordionButton.setAttribute("aria-expanded", "true");
        } else {
            accordionCollapse.classList.remove("show");
            accordionButton.classList.add("collapsed");
            accordionButton.setAttribute("aria-expanded", "false");
        }
    }

    // Run function on page load and on window resize
    toggleAccordion();
    window.addEventListener("resize", toggleAccordion);

    // Handle category clicks
    const categoryLinks = document.querySelectorAll('.category-link');

    categoryLinks.forEach(link => {
        link.addEventListener('click', function(event) {
            event.preventDefault();

            const selectedCategory = this.getAttribute('data-category-id');

            // If All Categories or empty category is clicked, reload to the current base URL
            if (!selectedCategory) {
                const baseUrl = window.location.origin + window.location.pathname;
                window.location.href = baseUrl;
                return;
            }

            // Update URL with selected category
            const urlParams = new URLSearchParams(window.location.search);
            urlParams.set('category', selectedCategory);
            urlParams.delete('paged'); // Reset pagination

            window.location.search = urlParams.toString();
        });
    });
});
</script>*/
?>
