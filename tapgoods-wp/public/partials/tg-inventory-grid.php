<?php

global $wp;
$current_url = home_url(add_query_arg(array(), $wp->request)); // Get the current URL
$tg_inventory_pagination_class = 'foo';

// Get the value of show_pricing from the shortcode attributes
$show_pricing = isset($atts['show_pricing']) && $atts['show_pricing'] === "false" ? false : true;

// Get the 'per_page_default' attribute or set it to '12' if not present
$per_page_default = isset($atts['per_page_default']) 
    ? (int) preg_replace('/[^0-9]/', '', trim($atts['per_page_default'], '“”"')) // Clean the value by removing non-numeric characters and quotes, then convert to integer
    : 12; // Default value is 12 if the attribute is not set

$tg_per_page = isset($_GET['tg-per-page']) && in_array($_GET['tg-per-page'], array(12, 24, 48))
    ? (int) sanitize_text_field($_GET['tg-per-page'])
    : $per_page_default;

$tg_page = (get_query_var('paged')) ? get_query_var('paged') : 1;

// Get the default or selected location
$location_id = tg_get_wp_location_id();

// Prepare query arguments
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

// Log the number of results
error_log('Total number of results: ' . $query->found_posts);


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

        // Add nprice to the item's title link if show_pricing is false
        $item_permalink = get_permalink();
        if (!$show_pricing) {
            $item_permalink = add_query_arg('nprice', 'true', $item_permalink);
        }

        ?>
        <div id="tg-item-<?php echo esc_attr($tg_id); ?>" class="tapgoods-inventory col item" data-tgId="<?php echo esc_attr($tg_id); ?>" data-location-id="<?php echo esc_attr($location_id); ?>">
            <div class="item-wrap">
                <figure>
                    <a class="d-block" href="<?php echo esc_url($item_permalink); ?>">
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
                    </a>
                </figure>
                <!-- Show price only if show_pricing is true -->
                <?php if ($show_pricing) : ?>
                    <div class="price mb-2">
                        <?php echo esc_html($price); ?>
                    </div>
                <?php endif; ?>
                <a class="d-block item-name mb-2" href="<?php echo esc_url($item_permalink); ?>">
                    <strong><?php the_title(); ?></strong>
                </a>
                <?php if (!empty($add_cart_url)) : ?>
                <div class="add-to-cart item-<?php the_ID(); ?>">
                    <input class="qty-input form-control round" type="text" placeholder="Qty" id="qty-<?php echo esc_attr($tg_id); ?>">
                    <button type="button" data-target="<?php echo esc_url($add_cart_url); ?>" data-item-id="<?php echo esc_attr($tg_id); ?>" class="add-cart btn btn-primary">Add</button>
                </div>
                <?php endif; ?>
            </div>
        </div>
    <?php endwhile; ?>
    <?php do_action('tg_inventory_after_grid'); ?>
<?php endif; ?> <!-- Cierra if ($query->have_posts()) -->
</div>

<?php if ($tg_pages > 1) : ?>
    <div class="<?php echo esc_attr(apply_filters('tg_inventory_pagination_class', $tg_inventory_pagination_class)); ?>">
        <?php do_action('tg_before_inventory_pagination'); ?>
        <nav aria-label="Page navigation">
            <ul class="pagination justify-content-center align-items-center">
                <!-- First Page -->
                <li class="page-item <?php echo ($tg_page <= 1) ? 'disabled' : ''; ?>">
                    <a class="page-link" href="<?php echo esc_url(add_query_arg('paged', 1, $current_url)); ?>">
                        <span class="dashicons dashicons-controls-skipback"></span>
                    </a>
                </li>
                <!-- Previous Page -->
                <li class="page-item <?php echo ($tg_page <= 1) ? 'disabled' : ''; ?>">
                    <a class="page-link" href="<?php echo esc_url(add_query_arg('paged', max(1, $tg_page - 1), $current_url)); ?>">
                        <span class="dashicons dashicons-controls-back"></span>
                    </a>
                </li>
                <!-- Current Page -->
                <li class="page-item current-page">
                    <a class="page-link"><?php echo esc_html($tg_page); ?></a>
                </li>
                <li class="page-item disabled">
                    <a>of</a>
                </li>
                <!-- Total Pages -->
                <li class="page-item disabled">
                    <a class="page-link"><?php echo esc_html($tg_pages); ?></a>
                </li>
                <!-- Next Page -->
                <li class="page-item <?php echo ($tg_page >= $tg_pages) ? 'disabled' : ''; ?>">
                    <a class="page-link" href="<?php echo esc_url(add_query_arg('paged', min($tg_pages, $tg_page + 1), $current_url)); ?>">
                        <span class="dashicons dashicons-controls-forward"></span>
                    </a>
                </li>
                <!-- Last Page -->
                <li class="page-item <?php echo ($tg_page >= $tg_pages) ? 'disabled' : ''; ?>">
                    <a class="page-link" href="<?php echo esc_url(add_query_arg('paged', $tg_pages, $current_url)); ?>">
                        <span class="dashicons dashicons-controls-skipforward"></span>
                    </a>
                </li>
            </ul>
        </nav>
        <?php do_action('tg_after_inventory_pagination'); ?>
    </div>
<?php endif; ?>

<?php wp_reset_postdata(); ?>





<script>
document.addEventListener("DOMContentLoaded", function () {
    console.log("DOM fully loaded and parsed");

    const locationId = "<?php echo esc_js($location_id); ?>"; // Current location ID
    console.log("Location ID:", locationId);

    // Function to fetch results from the server
    function fetchResults(query, page = 1) {
        console.log(`Fetching results for query: "${query}" on page: ${page}`);

        fetch("<?php echo esc_url(admin_url('admin-ajax.php')); ?>", {
            method: "POST",
            headers: { "Content-Type": "application/x-www-form-urlencoded" },
            body: new URLSearchParams({
                action: "tg_search", // The AJAX action defined in WordPress
                s: query,
                tg_location_id: locationId,
                tg_tags: "<?php echo esc_js($tg_tags ?? ''); ?>",
                tg_categories: "<?php echo esc_js($categories ?? ''); ?>",
                per_page_default: <?php echo esc_js($tg_per_page); ?>,
                paged: page,
            }),
        })
            .then(response => response.text()) // Parse response as HTML
            .then(html => {
                // Log the full HTML for debugging
                console.log("HTML Response:", html);

                // Create a temporary DOM element to parse the HTML
                const tempDiv = document.createElement("div");
                tempDiv.innerHTML = html;

                // Count the number of result items (assuming they have a specific class, e.g., "result-item")
                const resultItems = tempDiv.querySelectorAll(".item-wrap");
                console.log(`Results: ${resultItems.length}`);

                // Log each result (optional, for debugging purposes)
                resultItems.forEach((item, index) => {
                    console.log(`Result ${index + 1}:`, item.textContent.trim());
                });

                // Pagination handling can also be added here if required
            })
            .catch(error => {
                console.error("Fetch error:", error);
            });
    }

    // Function to update cart data in localStorage
    function updateCartItems(shortcodeContainer) {
        const cartData = JSON.parse(localStorage.getItem("cartData")) || {};
        if (cartData[locationId]) {
            Object.keys(cartData[locationId]).forEach(itemId => {
                const quantity = cartData[locationId][itemId];

                // Update quantity inputs
                shortcodeContainer.querySelectorAll(`#qty-${itemId}`).forEach(input => {
                    input.value = quantity;
                });

                // Update buttons
                shortcodeContainer.querySelectorAll(`.add-cart[data-item-id="${itemId}"]`).forEach(button => {
                    button.style.setProperty("background-color", "green", "important");
                    button.textContent = "Added";

                    // Reset UI after 10 seconds
                    setTimeout(() => {
                        delete cartData[locationId][itemId];
                        if (Object.keys(cartData[locationId]).length === 0) {
                            delete cartData[locationId];
                        }
                        localStorage.setItem("cartData", JSON.stringify(cartData));

                        button.style.removeProperty("background-color");
                        button.textContent = "Add";

                        shortcodeContainer.querySelectorAll(`#qty-${itemId}`).forEach(input => {
                            input.value = "";
                        });
                    }, 10000);
                });
            });
        }
    }

    // Function to handle "Add to Cart" functionality
    function handleAddToCart(event) {
        event.preventDefault();

        const button = event.currentTarget;
        const itemId = button.getAttribute("data-item-id");
        const container = button.closest(".tapgoods-inventory");
        const quantityInput = container.querySelector(`#qty-${itemId}`);

        if (!quantityInput) {
            alert("Quantity input field is missing.");
            return;
        }

        const quantityValue = quantityInput.value.trim();
        if (!quantityValue || isNaN(quantityValue) || parseInt(quantityValue, 10) <= 0) {
            alert("Please enter a valid quantity.");
            return;
        }

        const quantity = parseInt(quantityValue, 10);
        const cartData = JSON.parse(localStorage.getItem("cartData")) || {};

        if (!cartData[locationId]) {
            cartData[locationId] = {};
        }
        cartData[locationId][itemId] = quantity;
        localStorage.setItem("cartData", JSON.stringify(cartData));

        button.style.setProperty("background-color", "green", "important");
        button.textContent = "Added";

        setTimeout(() => {
            delete cartData[locationId][itemId];
            if (Object.keys(cartData[locationId]).length === 0) {
                delete cartData[locationId];
            }
            localStorage.setItem("cartData", JSON.stringify(cartData));

            button.style.removeProperty("background-color");
            button.textContent = "Add";

            container.querySelectorAll(`#qty-${itemId}`).forEach(input => {
                input.value = "";
            });
        }, 10000);

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

    // Attach event listeners for cart buttons
    document.querySelectorAll(".tapgoods-inventory").forEach(shortcodeContainer => {
        updateCartItems(shortcodeContainer);

        shortcodeContainer.querySelectorAll(".add-cart").forEach(button => {
            button.addEventListener("click", handleAddToCart);
        });
    });

    // Attach event listener to the search input
    const searchInput = document.querySelector("#tg-search");
    if (searchInput) {
        searchInput.addEventListener("input", function () {
            const query = searchInput.value.trim();
            console.log("Search input changed. Query:", query);

            if (query) {
                fetchResults(query); // Fetch results as user types
            } else {
                console.log("Empty query. No search performed.");
            }
        });
    } else {
        console.error("Search input not found.");
    }

    // Attach event listener for pagination links
    document.addEventListener("click", function (e) {
        if (e.target.matches(".pagination a")) {
            e.preventDefault();
            const page = e.target.getAttribute("data-page");
            const query = searchInput ? searchInput.value.trim() : "";
            console.log(`Pagination clicked. Page: ${page}, Query: "${query}"`);
            fetchResults(query, page);
        }
    });
});










</script>