<?php
// Autoloading wp-load.php
$wp_load_paths = [
    dirname(__DIR__, 3) . '/wp-load.php',
    dirname(__DIR__, 4) . '/wp-load.php',
    dirname(__DIR__, 5) . '/wp-load.php'
];

$wp_load_found = false;

foreach ($wp_load_paths as $path) {
    if (file_exists($path)) {
        require_once $path;
        $wp_load_found = true;
        break;
    }
}

if (!$wp_load_found) {
    error_log("Critical error: wp-load.php not found in expected paths.");
    exit("Critical error: WordPress could not be loaded.");
}

// Query Parameters
$paged = (get_query_var('paged')) ? get_query_var('paged') : 1;
$posts_per_page = isset($_GET['per-page']) ? intval($_GET['per-page']) : 12;
$search_query = isset($_GET['s']) ? sanitize_text_field($_GET['s']) : '';
$location_id = isset($_COOKIE['tg_location_id']) ? sanitize_text_field($_COOKIE['tg_location_id']) : get_option('tg_default_location');

// Debugging logs
error_log("Search parameters:");
error_log(" - Pages: $paged");
error_log(" - Posts per pages: $posts_per_page");
error_log(" - Search term: $search_query");
error_log(" - Location ID: $location_id");

// Arguments of the query
$args = [
    'post_type' => 'tg_inventory',
    'posts_per_page' => $posts_per_page,
    'paged' => $paged,
    's' => $search_query,
];

// Only add meta_query if $location_id is not empty
if (!empty($location_id)) {
    $args['meta_query'] = [
        [
            'key' => 'tg_locationId',
            'value' => $location_id,
            'compare' => '='
        ]
    ];
}

// Execute the query
$query = new WP_Query($args);

// Log to view the generated SQL query
error_log("Generated SQL query: " . $query->request);

?>
<div class="tapgoods tapgoods-inventory row row-cols-lg-3 row-cols-md-1 row-cols-sm-1">
    <h1>Results </h1>
    <?php if ($query->have_posts()) : ?>
        <?php while ($query->have_posts()) : $query->the_post(); ?>
            <?php
            $product_id = get_the_ID();
            $tg_id = get_post_meta($product_id, 'tg_id', true);
            $price = tg_get_single_display_price($product_id);

            // Cart URL
            $cart_url = 'https://hooli2.preprod.tapgoods.dev/cart?externalRedirect=true';

            // Building the "Add to Cart" URL without `esc_url()`
            $add_cart_base_url = "https://hooli2.preprod.tapgoods.dev/addToCart?itemId={$tg_id}&itemType=items&redirectUrl=" . urlencode($cart_url) . "&eventStart=2024-10-27T10:00T10:00&eventEnd=2024-10-31T15:00T15:00";

            $pictures = get_post_meta($product_id, 'tg_pictures', true);
            $img_tag = (!empty($pictures) && count($pictures) > 0) ? Tapgoods_Public::get_img_tag($pictures[0]['imgixUrl'], '254', '150') : '';
            ?>
            
            <div id="tg-item-<?php echo esc_attr($tg_id); ?>" class="tapgoods-inventory col item" data-tgId="<?php echo esc_attr($tg_id); ?>">
                <div class="item-wrap">
                    <figure>
                        <a class="d-block" href="<?php the_permalink(); ?>">
                            <?php echo $img_tag; ?>
                        </a>
                    </figure>
                    <div class="price mb-2">
                        <?php echo esc_html($price); ?>
                    </div>
                    <a class="d-block item-name mb-2" href="<?php the_permalink(); ?>">
                        <strong><?php the_title(); ?></strong>
                    </a>
                    <div class="add-to-cart item-<?php the_ID(); ?>">
                        <input class="qty-input form-control round" type="number" min="1" placeholder="Qty" value="1">
                        <button 
                            class="add-cart btn btn-primary" 
                            onclick="addToCart(<?php echo esc_js($tg_id); ?>, '<?php echo $add_cart_base_url; ?>')"
                        >
                            Add
                        </button>
                    </div>
                </div>
            </div>
        <?php endwhile; ?>
    <?php else : ?>
        <p>No results were found for your search.</p>
    <?php endif; ?>
    <?php wp_reset_postdata(); ?>
</div>

<script>
function addToCart(tgId, baseUrl) {
    const quantityInput = document.querySelector(`#tg-item-${tgId} .qty-input`);
    const quantity = quantityInput ? quantityInput.value : 1;

    const addToCartUrl = `${baseUrl}&quantity=${quantity}`;
    window.location.href = addToCartUrl; // Redirects to the "Add to Cart" URL
}
</script>


