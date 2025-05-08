<?php

if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly

global $wp;

$current_url = trailingslashit(home_url($wp->request, 'raw'));

if (!empty($_SERVER['QUERY_STRING'])) {
    $query_string = isset($_SERVER['QUERY_STRING']) ? sanitize_text_field(wp_unslash($_SERVER['QUERY_STRING'])) : '';
    $query_string = sanitize_text_field($query_string); // 
    $current_url .= '?' . $query_string;
}
 // Current page URL
$tg_inventory_pagination_class = 'foo';

// Get the value of show_pricing from the shortcode attributes
$show_pricing = true; // 

if (isset($atts['show_pricing'])) {
    $normalized_value = str_replace(['“', '”', '"'], '', $atts['show_pricing']);
    $show_pricing = filter_var($normalized_value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? true;
}

// Get the 'per_page_default' attribute or set it to '12' if not present
$per_page_default = isset($atts['per_page_default']) 
    ? (int) preg_replace('/[^0-9]/', '', trim($atts['per_page_default'], '“”"')) // Clean the value by removing non-numeric characters and quotes, then convert to integer
    : 12; // Default value is 12 if the attribute is not set

$tg_per_page = isset( $_GET['tg-per-page'] ) ? absint( wp_unslash( $_GET['tg-per-page'] ) ) : 0;

$tg_per_page = in_array( $tg_per_page, array( 12, 24, 48 ), true ) ? (int) sanitize_text_field( $tg_per_page ) : $per_page_default;


$tg_page = (get_query_var('paged')) ? get_query_var('paged') : 1;

// Check if 'local_storage_location' is present in the URL
$local_storage_location = isset( $_GET['local_storage_location'] ) ? sanitize_text_field( wp_unslash( $_GET['local_storage_location'] ) ) : null;

// Check the cookie 'tg_user_location'
$cookie_location = isset( $_COOKIE['tg_user_location'] ) ? sanitize_text_field( wp_unslash( $_COOKIE['tg_user_location'] ) ) : null;
$location_id = $cookie_location ?: ($local_storage_location ?: tg_get_wp_location_id());


// Prepare query arguments
$args = array(
    'post_type'      => 'tg_inventory',
    'post_status'    => 'publish',
    'posts_per_page' => $tg_per_page,
    'orderby'        => 'title', // Order by title
    'order'          => 'ASC',   // Order in asc
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
// error_log('Total number of results: ' . $query->found_posts);


$tg_pages = $query->max_num_pages;

?>
<!-- <div class="tapgoods tapgoods-inventory row row-cols-lg-3 row-cols-md-2 row-cols-sm-2"> -->
<div class="tapgoods tapgoods-inventory row row-cols-1 row-cols-sm-2 row-cols-lg-3 g-3">
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
<?php endif; ?> <!-- Close if ($query->have_posts()) -->
</div>

<?php

add_action( 'template_redirect', function() {
    if ( ! is_admin() && get_query_var('paged') ) {
        remove_action( 'template_redirect', 'redirect_canonical' );
    }
}, 1 );


if ( $tg_pages > 1 ) : ?>
    <div class="<?php echo esc_attr( apply_filters( 'tg_inventory_pagination_class', $tg_inventory_pagination_class ) ); ?>">
        <?php do_action( 'tg_before_inventory_pagination' ); ?>
        <nav aria-label="Page navigation">
            <ul class="pagination justify-content-center align-items-center">
                <!-- First Page -->
                <li class="page-item <?php echo ( $tg_page <= 1 ) ? 'disabled' : ''; ?>">
                    <a class="page-link" href="<?php echo esc_url( add_query_arg( 'paged', 1, $current_url ) ); ?>">
                        <span class="dashicons dashicons-controls-skipback"></span>
                    </a>
                </li>
                <!-- Previous Page -->
                <li class="page-item <?php echo ( $tg_page <= 1 ) ? 'disabled' : ''; ?>">
                    <a class="page-link" href="<?php echo esc_url( add_query_arg( 'paged', max( 1, $tg_page - 1 ), $current_url ) ); ?>">
                        <span class="dashicons dashicons-controls-back"></span>
                    </a>
                </li>
                <!-- Current Page -->
                <li class="page-item current-page">
                    <a class="page-link"><?php echo esc_html( $tg_page ); ?></a>
                </li>
                <li class="page-item disabled">
                    <a>of</a>
                </li>
                <!-- Total Pages -->
                <li class="page-item disabled">
                    <a class="page-link"><?php echo esc_html( $tg_pages ); ?></a>
                </li>
                <!-- Next Page -->
                <li class="page-item <?php echo ( $tg_page >= $tg_pages ) ? 'disabled' : ''; ?>">
                    <a class="page-link" href="<?php echo esc_url( add_query_arg( 'paged', min( $tg_pages, $tg_page + 1 ), $current_url ) ); ?>">
                        <span class="dashicons dashicons-controls-forward"></span>
                    </a>
                </li>
                <!-- Last Page -->
                <li class="page-item <?php echo ( $tg_page >= $tg_pages ) ? 'disabled' : ''; ?>">
                    <a class="page-link" href="<?php echo esc_url( add_query_arg( 'paged', $tg_pages, $current_url ) ); ?>">
                        <span class="dashicons dashicons-controls-skipforward"></span>
                    </a>
                </li>
            </ul>
        </nav>
        <?php do_action( 'tg_after_inventory_pagination' ); ?>
    </div>
<?php endif; ?>


<?php wp_reset_postdata(); ?>





<script>
document.addEventListener("DOMContentLoaded", function () {
    const locationId = "<?php echo esc_js($location_id); ?>"; // Current location ID
    const savedLocation = localStorage.getItem('tg_user_location');
    if (savedLocation) {
        document.cookie = `tg_user_location=${savedLocation}; path=/;`;
    }

    /**
     * Load cart data from localStorage and update UI on page load
     */
    function updateCartItemsOnLoad(container) {
        const cartData = JSON.parse(localStorage.getItem("cartData")) || {};
        if (cartData[locationId]) {
            Object.keys(cartData[locationId]).forEach(itemId => {
                const quantity = cartData[locationId][itemId];

                // Update quantity inputs
                const qtyInput = container.querySelector(`#qty-${itemId}`);
                if (qtyInput) {
                    qtyInput.value = quantity;
                }

                // Update button to "Added" with green color
                const button = container.querySelector(`.add-cart[data-item-id="${itemId}"]`);
                if (button) {
                    button.textContent = "Added";
                    button.style.setProperty("background-color", "green", "important");
                    button.disabled = true;

                    // Reset button and remove item from localStorage after 10 seconds
                    setTimeout(() => {
                        delete cartData[locationId][itemId];
                        if (Object.keys(cartData[locationId]).length === 0) {
                            delete cartData[locationId];
                        }
                        localStorage.setItem("cartData", JSON.stringify(cartData));

                        button.textContent = "Add";
                        button.style.removeProperty("background-color");
                        button.disabled = false;

                        if (qtyInput) {
                            qtyInput.value = "";
                        }
                    }, 10000);
                }
            });
        }
    }

    /**
     * Handle "Add to Cart" button click
     */
    function handleAddToCart(event) {
        event.preventDefault();

        const button = event.currentTarget;
        const itemId = button.getAttribute("data-item-id");
        const container = button.closest(".tapgoods-inventory");
        const qtyInput = container.querySelector(`#qty-${itemId}`);

        if (!qtyInput) {
            alert("Quantity input field is missing.");
            return;
        }

        const quantityValue = qtyInput.value.trim();
        if (!quantityValue || isNaN(quantityValue) || parseInt(quantityValue, 10) <= 0) {
            alert("Please enter a valid quantity.");
            return;
        }

        const quantity = parseInt(quantityValue, 10);

        // Update localStorage with cart data
        const cartData = JSON.parse(localStorage.getItem("cartData")) || {};
        if (!cartData[locationId]) {
            cartData[locationId] = {};
        }
        cartData[locationId][itemId] = quantity;
        localStorage.setItem("cartData", JSON.stringify(cartData));

        // Set cart status to active
        localStorage.setItem("cart", "1");

        // Update button to "Added" with green color
        button.textContent = "Added";
        button.style.setProperty("background-color", "green", "important");
        button.disabled = true;

        // Reset after 10 seconds
        setTimeout(() => {
            delete cartData[locationId][itemId];
            if (Object.keys(cartData[locationId]).length === 0) {
                delete cartData[locationId];
            }
            localStorage.setItem("cartData", JSON.stringify(cartData));

            button.textContent = "Add";
            button.style.removeProperty("background-color");
            button.disabled = false;

            qtyInput.value = "";
        }, 10000);

        // Optional: Send a request to the server
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

    /**
     * Attach event listeners to all "Add to Cart" buttons
     */
    document.querySelectorAll(".tapgoods-inventory").forEach(container => {
        updateCartItemsOnLoad(container);

        // Add click event listeners to all "Add to Cart" buttons
        container.querySelectorAll(".add-cart").forEach(button => {
            button.addEventListener("click", handleAddToCart);
        });
    });

    /**
     * Handling search, categories, and pagination
     */
    const searchForm = document.querySelector(".tapgoods-search-form");
    const categoryLinks = document.querySelectorAll(".category-link");
    const paginationLinks = document.querySelectorAll(".pagination a");

    // Handle category clicks
    categoryLinks.forEach(link => {
        link.addEventListener("click", function(event) {
            event.preventDefault();

            const selectedCategory = this.getAttribute("data-category-id");
            if (!selectedCategory) {
                console.error("Category ID is missing.");
                return;
            }

            const urlParams = new URLSearchParams(window.location.search);
            urlParams.set('category', selectedCategory);
            urlParams.delete('paged'); // Reset pagination

            window.location.search = urlParams.toString();
        });
    });

    // Handle pagination clicks
    paginationLinks.forEach(link => {
        link.addEventListener("click", function(event) {
            event.preventDefault();

            const url = new URL(this.href);
            const paged = url.searchParams.get('paged');

            const urlParams = new URLSearchParams(window.location.search);
            if (paged) {
                urlParams.set('paged', paged);
            }

            // Keep category and tags in pagination
            const currentCategory = urlParams.get('category');
            const currentTags = urlParams.get('tg_tags');
            if (currentCategory) urlParams.set('category', currentCategory);
            if (currentTags) urlParams.set('tg_tags', currentTags);

            window.location.search = urlParams.toString();
        });
    });

    // Handle search submission
    if (searchForm) {
        searchForm.addEventListener("submit", function(event) {
            event.preventDefault();

            const urlParams = new URLSearchParams(window.location.search);

            // Get search input value
            const searchInput = searchForm.querySelector("input[name='s']");
            if (searchInput && searchInput.value.trim() !== '') {
                urlParams.set('s', searchInput.value.trim());
            } else {
                urlParams.delete('s');
            }

            // Keep category and tags
            const currentCategory = urlParams.get('category');
            const currentTags = urlParams.get('tg_tags');
            if (currentCategory) urlParams.set('category', currentCategory);
            if (currentTags) urlParams.set('tg_tags', currentTags);

            window.location.search = urlParams.toString();
        });
    }

    // Handle search input changes
    const searchInput = document.querySelector("#tg-search");
    if (searchInput) {
        searchInput.addEventListener("input", function () {
            const query = searchInput.value.trim();
            if (query) {
                fetchResults(query);
            }
        });
    }

    // Handle pagination click events
    document.addEventListener("click", function (e) {
        if (e.target.matches(".pagination a")) {
            e.preventDefault();
            const page = e.target.getAttribute("data-page");
            const query = searchInput ? searchInput.value.trim() : "";
            fetchResults(query, page);
        }
    });

});







</script>