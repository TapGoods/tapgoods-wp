<?php

global $wp;
$current_url = home_url($wp->request);
$tg_inventory_pagination_class = 'foo';

$tg_per_page = isset($_GET['tg-per-page']) && in_array($_GET['tg-per-page'], array(12, 24, 48))
    ? (int) sanitize_text_field($_GET['tg-per-page'])
    : (int) get_option('tg_per_page', 12);

$tg_page = (get_query_var('paged')) ? get_query_var('paged') : 1;

// Get default or selected location
$location_id = tg_get_wp_location_id();

$args = array(
    'post_type'      => 'tg_inventory',
    'post_status'    => 'publish',
    'posts_per_page' => $tg_per_page,
    'order_by'       => 'menu_order',
    'paged'          => $tg_page,
    'meta_query'     => array(
        array(
            'key'     => 'tg_locationId',
            'value'   => $location_id,
            'compare' => '=',
        ),
    ),
);

$tg_search = get_query_var('s', false);
if (false !== $tg_search) {
    $args['s'] = $tg_search;
}

$categories = get_query_var('tg_category', false);
$tg_tags    = get_query_var('tg_tags', false);

if (!empty($atts['category'])) {
    $categories = explode(',', $atts['category']);
}

if (!empty($atts['tags'])) {
    $tg_tags = explode(',', $atts['tags']);
}

$tax_args = array();
if (false !== $categories) {
    $tax_args[] = array(
        'taxonomy' => 'tg_category',
        'terms'    => $categories,
        'field'    => 'slug',
        'operator' => 'IN',
    );
}

if (false !== $tg_tags) {
    $tax_args[] = array(
        'taxonomy' => 'tg_tags',
        'terms'    => $tg_tags,
        'field'    => 'slug',
        'operator' => 'IN',
    );
}

if (count($tax_args) === 1) {
    $args['tax_query'] = $tax_args;
}

if (count($tax_args) > 1) {
    $args['tax_query'] = array(
        'relation' => 'OR',
        $tax_args,
    );
}

$query = new WP_Query($args);

$tg_pages = $query->max_num_pages;

?>

<div class="tapgoods tapgoods-inventory row row-cols-lg-3 row-cols-md-1 row-cols-sm-1">
<?php if ($query->have_posts()) : ?>
    <?php while ($query->have_posts()) : ?>
        <?php $query->the_post(); ?>
        <?php

        $product_id = get_the_ID();
        $tg_id      = get_post_meta($product_id, 'tg_id', true);
        $price      = tg_get_single_display_price($product_id);

        $url_params = array(
            'redirectUrl' => $current_url,
        );

        $add_cart_url = tg_get_product_add_to_cart_url($product_id, $url_params);

        $pictures = get_post_meta(get_the_ID(), 'tg_pictures', true);

        if (empty($pictures)) {
            $pictures = false;
        }

        $img_tag = '';
        if (!empty($pictures) && count($pictures) > 0) {
            $img_tag = Tapgoods_Public::get_img_tag($pictures[0]['imgixUrl'], '254', '150');
        }

        ?>
        <div id="tg-item-<?php echo esc_attr($tg_id); ?>" class="tapgoods-inventory col item" data-tgId="<?php echo esc_attr($tg_id); ?>" data-location-id="<?php echo esc_attr($location_id); ?>">
            <div class="item-wrap">
                <figure>
                    <a class="d-block" href="<?php the_permalink(); ?>">
                        <?php if (!empty($pictures)) : ?>
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
                        <?php if (false !== get_option('tg_show_item_pricing', false)) : ?>
                            <div class="pricing"></div>
                        <?php endif; ?>
                    </a>
                </figure>
                <div class="price mb-2">
                    <?php echo esc_html($price); ?>
                </div>
                <a class="d-block item-name mb-2" href="<?php the_permalink(); ?>">
                    <strong><?php the_title(); ?></strong>
                </a>
                <?php if (!empty($add_cart_url)) : ?>
                <div class="add-to-cart item-<?php the_ID(); ?>">
                    <!-- Quantity input field -->
                    <input class="qty-input form-control round" type="text" placeholder="Qty" id="qty-<?php echo esc_attr($tg_id); ?>">
                    
                    <!-- Add button -->
                    <button type="button" data-target="<?php echo esc_url($add_cart_url); ?>" data-item-id="<?php echo esc_attr($tg_id); ?>" class="add-cart btn btn-primary">Add</button>
                </div>
                <?php endif; ?>
            </div>
        </div>
    <?php endwhile; ?>
    <?php do_action('tg_inventory_after_grid'); ?>
    </div>
    <?php if ($tg_pages > 1) : ?>
    <?php
    $is_plain_permalink = get_option('permalink_structure') == ''; // Check if permalink is Plain
    $base_url = $is_plain_permalink ? add_query_arg(null, null) : ''; // Current URL base if Plain
    ?>
    <div class="<?php echo esc_attr(apply_filters('tg_inventory_pagination_class', $tg_inventory_pagination_class)); ?>">
        <?php do_action('tg_before_inventory_pagination'); ?>
        <nav aria-label="Page navigation">
            <ul class="pagination justify-content-center align-items-center">
                <!-- First Page -->
                <li class="page-item <?php echo ($query->query['paged'] <= 1) ? 'disabled' : ''; ?>">
                    <a class="page-link" href="<?php echo ($query->query['paged'] > 1) ? ($is_plain_permalink ? $base_url . '&paged=1' : '?paged=1') : '#'; ?>">
                        <span class="dashicons dashicons-controls-skipback"></span>
                    </a>
                </li>
                <!-- Previous Page -->
                <li class="page-item <?php echo ($query->query['paged'] <= 1) ? 'disabled' : ''; ?>">
                    <a class="page-link" href="<?php echo ($query->query['paged'] > 1) ? ($is_plain_permalink ? $base_url . '&paged=' . ($query->query['paged'] - 1) : '?paged=' . ($query->query['paged'] - 1)) : '#'; ?>">
                        <span class="dashicons dashicons-controls-back"></span>
                    </a>
                </li>
                <!-- Current Page -->
                <li class="page-item current-page">
                    <a class="page-link"><?php echo esc_html($query->query['paged']); ?></a>
                </li>
                <li class="page-item disabled">
                    <a>of</a>
                </li>
                <!-- Total Pages -->
                <li class="page-item disabled">
                    <a class="page-link"><?php echo esc_html($tg_pages); ?></a>
                </li>
                <!-- Next Page -->
                <li class="page-item <?php echo ($query->query['paged'] >= $tg_pages) ? 'disabled' : ''; ?>">
                    <a class="page-link" href="<?php echo ($query->query['paged'] < $tg_pages) ? ($is_plain_permalink ? $base_url . '&paged=' . ($query->query['paged'] + 1) : '?paged=' . ($query->query['paged'] + 1)) : '#'; ?>">
                        <span class="dashicons dashicons-controls-forward"></span>
                    </a>
                </li>
                <!-- Last Page -->
                <li class="page-item <?php echo ($query->query['paged'] >= $tg_pages) ? 'disabled' : ''; ?>">
                    <a class="page-link" href="<?php echo ($query->query['paged'] < $tg_pages) ? ($is_plain_permalink ? $base_url . '&paged=' . $tg_pages : '?paged=' . $tg_pages) : '#'; ?>">
                        <span class="dashicons dashicons-controls-skipforward"></span>
                    </a>
                </li>
            </ul>
        </nav>
        <?php do_action('tg_after_inventory_pagination'); ?>
    </div>
<?php endif; ?>







<?php endif; ?>
<?php wp_reset_postdata(); ?>


<script>
document.addEventListener("DOMContentLoaded", function () {
    // Retrieve cart data from localStorage
    const cartData = JSON.parse(localStorage.getItem("cartData")) || {};
    const locationId = "<?php echo esc_js($location_id); ?>"; // Current location ID

    // Function to update buttons and quantity inputs based on cart data
    function updateCartItems(shortcodeContainer) {
        if (cartData[locationId]) {
            Object.keys(cartData[locationId]).forEach(itemId => {
                const quantity = cartData[locationId][itemId];

                // Update all buttons and inputs for this item ID in the current container
                const quantityInputs = shortcodeContainer.querySelectorAll(`#qty-${itemId}`);
                const buttons = shortcodeContainer.querySelectorAll(`.add-cart[data-item-id="${itemId}"]`);

                // If buttons or inputs exist, update them
                if (buttons.length > 0) {
                    buttons.forEach(button => {
                        button.style.setProperty("background-color", "green", "important");
                        button.textContent = "Added";

                        // Restore to original state after 10 seconds
                        setTimeout(() => {
                            button.style.removeProperty("background-color");
                            button.textContent = "Add";
                        }, 10000);
                    });
                }

                if (quantityInputs.length > 0) {
                    quantityInputs.forEach(input => {
                        input.value = quantity;
                    });
                }
            });
        }
    }

    // Function to handle adding items to the cart
    function handleAddToCart(event) {
        event.preventDefault();

        const button = event.currentTarget;
        const itemId = button.getAttribute("data-item-id");
        const quantityInput = button.closest('.tapgoods-inventory').querySelector(`#qty-${itemId}`);

        if (!quantityInput) {
            alert("Quantity input field is missing.");
            return;
        }

        let quantityValue = quantityInput.value.trim();
        if (quantityValue === "" || isNaN(parseInt(quantityValue, 10)) || parseInt(quantityValue, 10) <= 0) {
            alert("Please enter a valid quantity.");
            return;
        }

        const quantity = parseInt(quantityValue, 10);

        // Update cart data in localStorage
        if (!cartData[locationId]) {
            cartData[locationId] = {};
        }

        cartData[locationId][itemId] = quantity;
        localStorage.setItem("cartData", JSON.stringify(cartData));

        // Update button and quantity inputs in the current container
        const currentContainer = button.closest('.tapgoods-inventory');
        const quantityInputs = currentContainer.querySelectorAll(`#qty-${itemId}`);
        const buttons = currentContainer.querySelectorAll(`.add-cart[data-item-id="${itemId}"]`);

        if (buttons.length > 0) {
            buttons.forEach(btn => {
                btn.style.setProperty("background-color", "green", "important");
                btn.textContent = "Added";

                // Restore to original state after 10 seconds
                setTimeout(() => {
                    btn.style.removeProperty("background-color");
                    btn.textContent = "Add";
                }, 10000);
            });
        }

        if (quantityInputs.length > 0) {
            quantityInputs.forEach(input => {
                input.value = quantity;
            });
        }

        // Send request to add item to cart
        const url = button.getAttribute("data-target");
        const addToCartUrl = `${url}&quantity=${quantity}`;
        fetch(addToCartUrl, { method: "GET", credentials: "include" })
            .then(response => {
                if (!response.ok) {
                    console.error("Error adding item to cart.");
                }
            })
            .catch(error => console.error("Request error:", error));
    }

    // Iterate over each instance of the shortcode container to set up event listeners and update UI
    document.querySelectorAll(".tapgoods-inventory").forEach(shortcodeContainer => {
        updateCartItems(shortcodeContainer);

        // Set up event listeners for all add-to-cart buttons within the current container
        shortcodeContainer.querySelectorAll(".add-cart").forEach(button => {
            button.removeEventListener("click", handleAddToCart);
            button.addEventListener("click", handleAddToCart);
        });
    });
});










</script>
