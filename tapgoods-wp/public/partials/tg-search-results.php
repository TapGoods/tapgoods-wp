<?php
// Autoloading wp-load.php from multiple possible paths
$wp_load_paths = [
    dirname(__DIR__, 3) . '/wp-load.php',
    dirname(__DIR__, 4) . '/wp-load.php',
    dirname(__DIR__, 5) . '/wp-load.php',
];

// Get the script filename dynamically
$script_filename = isset($_SERVER['SCRIPT_FILENAME']) 
    ? realpath(sanitize_text_field(wp_unslash($_SERVER['SCRIPT_FILENAME']))) 
    : '';

if ($script_filename) {
    $wp_load_paths[] = dirname($script_filename, 5) . '/wp-load.php';
    $wp_load_paths[] = dirname($script_filename, 6) . '/wp-load.php';
}

$wp_load_found = false;

// Try loading wp-load.php
foreach ($wp_load_paths as $path) {
    if (file_exists($path)) {
        require_once $path;
        $wp_load_found = true;
        break;
    }
}

if (!$wp_load_found) {
    // Display a critical error if wp-load.php cannot be loaded
    echo "<div style='color: red;'>Critical error: WordPress could not be loaded. Please contact support.</div>";
    exit;
}

// Verify WordPress is loaded
if (!defined('ABSPATH')) {
    echo "<div style='color: red;'>Critical error: WordPress environment not initialized.</div>";
    exit;
}

// Calculate eventStart and eventEnd dates
$event_start = new DateTime('tomorrow');  // Date of tomorrow
$event_end = (clone $event_start)->modify('+3 days');  // Three days after tomorrow

// Format dates to the desired format
$event_start_formatted = $event_start->format('Y-m-d\TH:i');
$event_end_formatted = $event_end->format('Y-m-d\TH:i');

// Retrieve query parameters
$paged = isset($_GET['paged']) ? intval($_GET['paged']) : 1;
$posts_per_page = isset($_GET['per-page']) ? intval($_GET['per-page']) : 12;
$search_query = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '';
$location_id = isset( $_GET['tg_location_id'] ) ? sanitize_text_field( wp_unslash( $_GET['tg_location_id'] ) ) : get_option( 'tg_default_location' );

// Retrieve location data based on location_id
$location_option = get_option("tg_location_{$location_id}");
if ($location_option && is_array($location_option)) {
    $cart_url = $location_option['cart_url'];
    $add_to_cart_base = $location_option['add_to_cart'];
} else {
    // If location data is missing, display an error and stop execution
    echo "<div style='color: red;'>" . esc_html__( 'Error: Configuration not found for location ID ', 'tapgoods-wp' ) . esc_html( $location_id ) . ".</div>";
    exit;
}

// Query arguments
$args = [
    'post_type' => 'tg_inventory',
    'posts_per_page' => $posts_per_page,
    'paged' => $paged,
    's' => $search_query,
];

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

// Check if results are found
if ($query->have_posts()) {
    ?>
    <div class="tapgoods tapgoods-inventory row row-cols-lg-3 row-cols-md-2 row-cols-sm-2">
        <?php while ($query->have_posts()) : $query->the_post(); ?>
            <?php
            $product_id = get_the_ID();
            $tg_id = get_post_meta($product_id, 'tg_id', true);
            $price = tg_get_single_display_price($product_id);

            // Build the complete base URL for adding to the cart
            $add_cart_base_url = "{$add_to_cart_base}?itemId={$tg_id}&itemType=items&quantity=1&redirectUrl={$cart_url}&eventStart={$event_start_formatted}&eventEnd={$event_end_formatted}";

            $pictures = get_post_meta($product_id, 'tg_pictures', true);
            $img_tag = (!empty($pictures) && count($pictures) > 0) ? Tapgoods_Public::get_img_tag($pictures[0]['imgixUrl'], '254', '150') : '';
            ?>
            <div id="tg-item-<?php echo esc_attr($tg_id); ?>" class="tapgoods-inventory col item" data-tgId="<?php echo esc_attr($tg_id); ?>">
                <div class="item-wrap">
                    <figure>
                        <a class="d-block" href="<?php the_permalink(); ?>">
                            <?php echo esc_html( $img_tag ); ?>
                        </a>
                    </figure>
                    <div class="price mb-2">
                        <?php echo esc_html($price); ?>
                    </div>
                    <a class="d-block item-name mb-2" href="<?php the_permalink(); ?>">
                        <strong><?php the_title(); ?></strong>
                    </a>
                    <form onsubmit="return updateQuantityAndSubmit(this);" data-base-url="<?php echo esc_url($add_cart_base_url); ?>">
                        <input class="qty-input form-control round" type="number" name="quantity" min="1" placeholder="Qty" value="1">
                        <button type="submit" class="add-cart btn btn-primary">Add</button>
                    </form>
                </div>
            </div>
        <?php endwhile; ?>
        <?php wp_reset_postdata(); ?>
    </div>
    <script>
    function updateQuantityAndSubmit(form) {
        const baseUrl = form.getAttribute('data-base-url');
        const quantity = form.querySelector('.qty-input').value;
        const addToCartUrl = baseUrl.replace("quantity=1", "quantity=" + quantity);

        window.location.href = addToCartUrl;
        return false;
    }
    </script>
    <?php
} else {
    // If no results are found, show a simple "No results" message
    echo "<div>No results found.</div>";
}
?>
