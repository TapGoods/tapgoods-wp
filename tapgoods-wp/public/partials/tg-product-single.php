<?php

global $post;

// Check if `nprice=true` is present in the URL
$hide_price = isset($_GET['nprice']) && $_GET['nprice'] === 'true';



$description = apply_filters(
    'tg_item_description',
    get_post_meta($post->ID, 'tg_description', true)
);

$custom_description = apply_filters(
    'tg_custom_description',
    get_post_meta($post->ID, 'tg_custom_description', true)
);

$tags = get_the_terms($post, 'tg_tags');
if (false !== $tags) {
    $tag_links = array();
    foreach ($tags as $tg_tag) {
        $tag_link = get_term_link($tg_tag);
        $tag_links[] = "<a href=\"{$tag_link}\">$tg_tag->name</a>";
    }
}

$tg_per_page = (isset($_COOKIE['tg-per-page'])) ? sanitize_text_field(wp_unslash($_COOKIE['tg-per-page'])) : get_option('tg_per_page', '12');

$tg_id = get_post_meta($post->ID, 'tg_id', true);

// Get the location ID from the user's Local Storage
$local_storage_location = isset($_COOKIE['tg_user_location']) ? sanitize_text_field(wp_unslash($_COOKIE['tg_user_location'])) : null;

// Use the default location if no value is present
$location_id = $local_storage_location ?: tg_get_wp_location_id();

$date_format = tg_date_format();
$today       = wp_date($date_format);

global $wp;
$current_page = trailingslashit(home_url($wp->request, 'raw'));

if (!empty($_SERVER['QUERY_STRING'])) {
    $query_string = isset($_SERVER['QUERY_STRING']) ? sanitize_text_field(wp_unslash($_SERVER['QUERY_STRING'])) : '';
    $query_string = sanitize_text_field($query_string); // 
    $current_page .= '?' . $query_string;
}

// Get the base URL without adding the redirectUrl parameter
$base_cart_url = tg_get_product_add_to_cart_url($post->ID);

// Build the full URL by manually adding the redirectUrl parameter
$cart_url = $base_cart_url . '&redirectUrl=' . urlencode($current_page);

?>
<div class="tapgoods">
    <?php do_action('tg_before_inventory_single_container'); ?>
    <div id="tg-single" class="inventory-single container-fluid">
        <!-- <?php do_action('tg_before_inventory_single_search'); ?>
        [tapgoods-search nos="true"]
        <?php do_action('tg_after_inventory_single_search'); ?> -->
        <section class="inventory-single-content row row-cols-1 row-cols-md-2 p-3">
            <?php do_action('tg_before_inventory_single_images'); ?>
            [tapgoods-image-carousel product="<?php echo esc_attr($post->ID); ?>"]
            <?php do_action('tg_before_inventory_single_summary'); ?>
            <section class="summary col">
                <div class="maginifier-preview" hidden></div>
                <span class="name"><?php the_title(); ?></span>
                <!-- Only show pricing if `nprice` is not present in the URL -->
                <?php if (!$hide_price) : ?>
                    <div class="pricing">
                        <?php $prices = tg_get_prices($post->ID); ?>
                        <?php foreach ($prices as $price_arr) : ?>
                            <span><?php echo '$' . wp_kses(current($price_arr), 'post'); ?></span>
                            <span><?php echo ' / ' . wp_kses(array_key_first($price_arr), 'post'); ?></span>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
                <div class="quantity-select mb-4">
                    <input type="text" placeholder="Qty" name="quantity" class="form-control qty-input">
                    <button data-location-id="<?php echo esc_attr($location_id); ?>" data-item-id="<?php echo esc_attr($tg_id); ?>" data-target="<?php echo esc_url($cart_url); ?>" class="add-cart btn btn-primary">Add Item</button>
                </div>
            </section>
            <section class="details col py-4 mt-2">
                <div class="description">
                    <?php echo esc_html( wp_strip_all_tags( $description ) ); ?>
                </div>
                <?php if (false !== $tags) : ?>
                <div class="tags">
                    <p class="label">Tags: </p><?php echo wp_kses(implode(', ', $tag_links), 'post'); ?>
                </div>
                <?php endif; ?>
            </section>
            <section class="misc col">
                <!-- <div class="date-range"  style="display: none;">
                    <p>Know your event date/time? Set it now.</p>
                    <div id="tg-dates-selector" class="dates-selector">
                        <div class="date-input-wrapper order-start">
                            <label><?php esc_html_e('Order Start', 'tapgoods'); ?></label>
                            <input type="date" name="eventStartDate" class="date-input form-control" value="<?php echo esc_attr(tg_get_start_date()); ?>" min="<?php echo esc_attr($today); ?>">
                            <input name="eventStartTime" type="time" class="time-input form-control" value="<?php echo esc_attr(tg_get_start_time()); ?>">
                        </div>
                        <div class="date-input-wrapper order-end">
                            <label><?php esc_html_e('Order End', 'tapgoods'); ?></label>
                            <input type="date" name="eventEndDate" class="date-input form-control" value="<?php echo esc_attr(tg_get_end_date()); ?>" min="<?php echo esc_attr($today); ?>">
                            <input name="eventEndTime" type="time" class="time-input form-control" value="<?php echo esc_attr(tg_get_end_time()); ?>">
                        </div>
                    </div>
                </div> -->
                <div class="additional-details">
                    <?php do_action('tg_product_additional_details'); ?>
                    <?php do_action('tg_product_dimensions'); ?>
                    <div class="row">
                        <div class="col"></div>
                        <div class="col">[tapgoods-dimensions]</div>
                    </div>
                </div>
            </section>
            <section class="linked-items col">
            <?php echo wp_kses_post($custom_description); ?>
               <!-- Additional content can go here -->
            </section>
        </section>
    </div>
</div>



<script>
document.addEventListener("DOMContentLoaded", function () {
    const addButton = document.querySelector(".add-cart");
    const quantityInput = document.querySelector(".qty-input");
    
    const itemId = addButton ? addButton.getAttribute("data-item-id") : null;
    const locationId = addButton ? addButton.getAttribute("data-location-id") : null;
    const cartData = JSON.parse(localStorage.getItem("cartData")) || {};
    const savedLocation = localStorage.getItem('tg_user_location');
    if (savedLocation) {
        // configures the cookie to save the location
        document.cookie = `tg_user_location=${savedLocation}; path=/`;
        console.log('Location saved to cookie:', savedLocation);
    }

    if (!addButton || !quantityInput || !itemId || !locationId) {
        console.warn("Required elements or attributes are missing.");
        return;
    }





    // Load stored quantity for the current item and location
    if (cartData[locationId] && cartData[locationId][itemId]) {
        quantityInput.value = cartData[locationId][itemId];
        updateCartButton(addButton, true);
    }

    // Check cart icon status
    updateCartIcon(cartData, locationId);



    // Add item to cart on button click
addButton.addEventListener("click", function (event) {
    event.preventDefault();

    const url = this.getAttribute("data-target");
    const quantity = quantityInput.value || 1;

    // Ensure valid quantity input
    if (!quantity || isNaN(quantity) || quantity <= 0) {
        alert("Please enter a valid quantity.");
        return;
    }
    if (!locationId || !itemId) {
        console.error("Invalid locationId or itemId:", { locationId, itemId });
        return;
    }

    // Initialize location in cartData if it doesn't exist
    if (!cartData[locationId]) {
        cartData[locationId] = {};
    }

    // Add or update the item in localStorage
    cartData[locationId][itemId] = quantity;
    try {
        localStorage.setItem("cartData", JSON.stringify(cartData));
        console.log("Item added to localStorage:", cartData);
    } catch (e) {
        console.error("Error saving to localStorage:", e);
    }

    // Set cart status to active
    localStorage.setItem("cart", "1"); // Mark the cart as active

    updateCartButton(addButton, true);

    // Send a request to add the item to the cart
    fetch(url + `&quantity=${quantity}`, {
        method: "GET",
        credentials: "include",
    })
        .then((response) => {
            if (response.ok) {
                console.log("Item added successfully via fetch.");
                reloadData(); // Reload data after the item is added
            } else {
                console.error("Error adding item to cart via fetch:", response.status);
            }
        })
        .catch((error) => console.error("Fetch request error:", error));
});


// Update the appearance of the cart button
function updateCartButton(button, isAdded) {
    if (isAdded) {
        button.style.backgroundColor = "green";
        button.textContent = "Added";

        setTimeout(() => {
            // Reset button appearance
            button.style.backgroundColor = "";
            button.textContent = "Add";
            quantityInput.value = ""; // Clear quantity input

            // Remove item from cartData in localStorage
            const cartData = JSON.parse(localStorage.getItem("cartData")) || {};
            if (cartData[locationId] && cartData[locationId][itemId]) {
                delete cartData[locationId][itemId];

                // Remove location if no more items
                if (Object.keys(cartData[locationId]).length === 0) {
                    delete cartData[locationId];
                }

                localStorage.setItem("cartData", JSON.stringify(cartData)); // Update localStorage
                console.log(`Item ${itemId} removed from cartData.`);
            }
        }, 10000); // Clear after 10 seconds
    }
}


    // Helper function to update cart icon status
    function updateCartIcon(cartData, locationId) {
        const cartIcon = document.getElementById("tg_cart");
        const isCartActive = localStorage.getItem("cart") === "1"; // Check if cart is active
        if (cartIcon) {
            if (isCartActive) {
                cartIcon.classList.add("has-items"); // Change icon or style
            } else {
                cartIcon.classList.remove("has-items");
            }
        }
    }

    // Function to reload data dynamically
    function reloadData() {
        fetch(window.location.href, {
            method: "GET",
            headers: {
                "X-Requested-With": "XMLHttpRequest",
            },
        })
            .then((response) => response.text())
            .then((html) => {
                const parser = new DOMParser();
                const doc = parser.parseFromString(html, "text/html");

                // Update date selector and cart sections
                const newDatesSelector = doc.querySelector("#tg-dates-selector");
                const datesSelector = document.querySelector("#tg-dates-selector");
                if (newDatesSelector && datesSelector) {
                    datesSelector.innerHTML = newDatesSelector.innerHTML;
                }

                const newCartSection = doc.querySelector(".quantity-select");
                const cartSection = document.querySelector(".quantity-select");
                if (newCartSection && cartSection) {
                    cartSection.innerHTML = newCartSection.innerHTML;
                    reinitializeEventListeners(); // Reinitialize listeners after replacing HTML
                }
            })
            .catch((error) => console.error("Error reloading data:", error));
    }

    // Reinitialize event listeners after reloading content dynamically
    function reinitializeEventListeners() {
        const updatedAddButton = document.querySelector(".add-cart");
        const updatedQuantityInput = document.querySelector(".qty-input");

        if (updatedAddButton && updatedQuantityInput) {
            updatedAddButton.addEventListener("click", function (event) {
                event.preventDefault();
                const quantity = updatedQuantityInput.value || 1;
                if (!quantity || isNaN(quantity) || quantity <= 0) {
                    alert("Please enter a valid quantity.");
                    return;
                }
                // Reuse existing logic to add to cart
                updatedQuantityInput.value = "";
                alert("Item added to cart after reload!");
            });
        }
    }
});



</script>
