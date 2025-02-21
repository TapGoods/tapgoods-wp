<?php


// Priority the value from the URL over $atts['category']
$category = isset( $_GET['category'] ) ? sanitize_text_field( wp_unslash( $_GET['category'] ) ) : ( ! empty( $atts['category'] ) ? sanitize_text_field( $atts['category'] ) : '' );
$category_attribute = $category ? "category=\"{$category}\"" : '';

$tags                   = ! empty( $atts['tags'] ) ? "tags=\"{$atts['tags']}\"" : '';
$per_page_default       = isset( $atts['per_page_default'] ) ? "per_page_default=\"{$atts['per_page_default']}\"" : '';
$show_search            = filter_var( $atts['show_search'], FILTER_VALIDATE_BOOLEAN );
$show_filters           = filter_var( $atts['show_filters'], FILTER_VALIDATE_BOOLEAN );
$show_pricing = 'show_pricing="true"'; 

// Variables passed to template: $tag, $content, $atts

$tg_inventory_grid_class = $show_filters 
    ? 'col-sm-8 col-xs-12' 
    : 'col-sm-12 col-xs-12';

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
// error_log("Shortcode generated dynamically: " . $dynamic_shortcode);
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
    const searchForm = document.querySelector(".tapgoods-search-form"); 
    const searchInput = document.querySelector("#tg-search");

    // Handle category selection
    categoryLinks.forEach(link => {
        link.addEventListener("click", function(event) {
            event.preventDefault();

            const selectedCategory = this.getAttribute("data-category-id");
            if (!selectedCategory) {
                console.error("Category ID is missing in the link.");
                return;
            }

            // Preserve other URL parameters
            const urlParams = new URLSearchParams(window.location.search);
            urlParams.set('category', selectedCategory);
            urlParams.delete('paged'); // Reset pagination

            window.location.search = urlParams.toString();
        });
    });

    // Handle pagination
    paginationLinks.forEach(link => {
        link.addEventListener("click", function(event) {
            event.preventDefault();

            const url = new URL(this.href);
            const paged = url.searchParams.get('paged');

            const urlParams = new URLSearchParams(window.location.search);
            if (paged) {
                urlParams.set('paged', paged);
            }

            // Preserve category and tags in pagination
            const currentCategory = urlParams.get('category');
            const currentTags = urlParams.get('tg_tags');
            if (currentCategory) urlParams.set('category', currentCategory);
            if (currentTags) urlParams.set('tg_tags', currentTags);

            window.location.search = urlParams.toString();
        });
    });

    // Handle search form submission
    if (searchForm && searchInput) {
        searchForm.addEventListener("submit", function(event) {
            event.preventDefault();

            const urlParams = new URLSearchParams(window.location.search);

            // Get the search query from the input field
            const searchQuery = searchInput.value.trim();
            if (searchQuery !== '') {
                urlParams.set('s', searchQuery);
            } else {
                urlParams.delete('s');
            }

            // Preserve existing parameters (category and tags)
            const currentCategory = urlParams.get('category');
            const currentTags = urlParams.get('tg_tags');
            if (currentCategory) urlParams.set('category', currentCategory);
            if (currentTags) urlParams.set('tg_tags', currentTags);

            window.location.search = urlParams.toString();
        });
    }
});


</script>
