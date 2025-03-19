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
<div id="tg-dates-selector" class="dates-selector px-4"  style="display: none;">
	<div class="date-input-wrapper order-start">
        <label><?php esc_html_e( 'Order Start', 'tapgoods' ); ?></label>
		<input id="eventStartDate" type="date" name="eventStartDate" value="<?php echo esc_attr( tg_get_start_date() ); ?>" min="<?php echo esc_attr( $today ); ?>" class="date-input form-control round">
		<input id="eventStartTime" name="eventStartTime" type="time" value="<?php echo esc_attr( tg_get_start_time() ); ?>" class="time-input form-control">
	</div>
	<div class="date-input-wrapper order-end">
        <label><?php esc_html_e( 'Order End', 'tapgoods' ); ?></label>
		<input id="eventEndDate" type="date" name="eventEndDate" value="<?php echo esc_attr( tg_get_end_date() ); ?>" min="<?php echo esc_attr( $today ); ?>" class="date-input form-control round">
		<input id="eventEndTime" name="eventEndTime" type="time" value="<?php echo esc_attr( tg_get_end_time() ); ?>" class="time-input form-control">
	</div>
</div>
<?php do_action( 'tg_after_date_filter' ); ?>
<div class="categories">
	<div class="accordion">
		<div class="accordion-item">
			<h2 class="accordion-header">
				<button class="accordion-button" type="button" data-bs-toggle="collapse" data-bs-target="#collapseOne" aria-expanded="false" aria-controls="collapseOne">
                    <?php 
                        $categories_text = apply_filters( 'tg_categories_header_text', 'Categories' ); 
                        echo esc_html( $categories_text ); 
                    ?>
				</button>
			</h2>
			<div id="collapseOne" class="accordion-collapse collapse category-links">
                <a class="category-link" href="#" data-category-id="">
                    <?php esc_html_e( 'All Categories', 'tapgoods' ); ?>
                </a>
				<?php foreach ( $categories as $category ) : ?>
					<a class="category-link" href="#" data-category-id="<?php echo esc_attr( $category->slug ); ?>">
					<?php echo esc_html( $category->name ); ?>
					</a>
				<?php endforeach; ?>
			</div>
		</div>
	</div>
</div>
<?php do_action( 'tg_after_inventory_filter' ); ?>
</aside>

<script>
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
</script>
