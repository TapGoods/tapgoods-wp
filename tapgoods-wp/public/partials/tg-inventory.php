<?php

// Variables passed to template: $tag, $content, $atts

$tg_inventory_grid_class = 'col-sm-8 col-xs-12';

// Priority the value from the URL over $atts['category']
$category = isset($_GET['category']) ? sanitize_text_field($_GET['category']) : (! empty( $atts['category'] ) ? $atts['category'] : '');
$category_attribute = $category ? "category=\"{$category}\"" : '';

$tags                   = ! empty( $atts['tags'] ) ? "tags=\"{$atts['tags']}\"" : '';
$per_page_default       = isset( $atts['per_page_default'] ) ? "per_page_default=\"{$atts['per_page_default']}\"" : '';
$show_search            = filter_var( $atts['show_search'], FILTER_VALIDATE_BOOLEAN );
$show_filters           = filter_var( $atts['show_filters'], FILTER_VALIDATE_BOOLEAN );
$show_pricing = 'show_pricing="true"'; 

if (isset($atts['show_pricing']) && $atts['show_pricing'] !== '') {
    $normalized_value = str_replace(['“', '”', '"'], '', $atts['show_pricing']);
    $boolean_value = filter_var($normalized_value, FILTER_VALIDATE_BOOLEAN) ? 'true' : 'false';
    $show_pricing = 'show_pricing="' . $boolean_value . '"';
}

$paged = isset($_GET['paged']) ? absint($_GET['paged']) : 1;

do_action( 'tg_before_inventory', $atts );
ob_start();
?>
<div id="tg-shop" class="tapgoods tapgoods-inventory container-fluid">
    <?php if ( false !== $show_search ) : ?>
        <?php do_action( 'tg_before_inventory_search' ); ?>
        <?php 
echo do_shortcode( 
    '[tapgoods-search nos="true" ' . esc_attr($category_attribute) . ' ' . 
    esc_attr($show_pricing) . ' ' . 
    esc_attr($tags) . ' ' . 
    esc_attr($per_page_default) . ']' 
); 
?>
        <?php do_action( 'tg_after_inventory_search' ); ?>
    <?php endif; ?>
    <div class="container shop">
        <div class="row align-items-start">
            <?php if ( false !== $show_filters ) : ?>
                [tapgoods-filter]
            <?php endif; ?>
            <section class="<?php echo esc_attr( apply_filters( 'tg_inventory_grid_class', $tg_inventory_grid_class ) ); ?>" id="tg-inventory-grid-container">
                <?php do_action( 'tg_before_inventory_grid' ); ?>        
                <div id="tg-inventory-grid">
    <?php 
$dynamic_shortcode = "[tapgoods-inventory-grid {$category_attribute} {$tags} {$show_pricing} {$per_page_default} paged=\"{$paged}\"]";
error_log("Shortcode generated dynamically: " . $dynamic_shortcode);
echo do_shortcode($dynamic_shortcode);

    ?>
</div>

                <?php do_action( 'tg_after_inventory_grid' ); ?>
            </section>
        </div>
    </div>
</div>
<?php
do_action( 'tg_after_inventory', $atts );
echo do_shortcode( ob_get_clean() );

?>
<script>
document.addEventListener("DOMContentLoaded", function() {
    const categoryLinks = document.querySelectorAll(".category-link");
    const paginationLinks = document.querySelectorAll(".pagination a");

    // Manejo de las categorías
    categoryLinks.forEach(link => {
        link.addEventListener("click", function(event) {
            event.preventDefault();

            const selectedCategory = this.getAttribute("data-category-id");
            console.log("Event triggered. Selected category:", selectedCategory);

            if (!selectedCategory) {
                console.error("Category ID is missing in the link.");
                return;
            }

            // Reload the current page
            const urlParams = new URLSearchParams(window.location.search);
            urlParams.set('category', selectedCategory);
            urlParams.delete('paged'); // Reset pagination
            window.location.search = urlParams.toString();
        });
    });

    // Manage the pagination links
    paginationLinks.forEach(link => {
        link.addEventListener("click", function(event) {
            event.preventDefault();

            const url = new URL(this.href);
            const paged = url.searchParams.get('paged');

            const urlParams = new URLSearchParams(window.location.search);
            if (paged) {
                urlParams.set('paged', paged);
            }

            const currentCategory = urlParams.get('category');
            if (currentCategory) {
                urlParams.set('category', currentCategory);
            }

            window.location.search = urlParams.toString();
        });
    });
});
</script>
