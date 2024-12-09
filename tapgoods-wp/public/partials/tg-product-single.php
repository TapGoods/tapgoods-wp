<?php

global $post;

// Check if `nprice=true` is present in the URL
$hide_price = isset($_GET['nprice']) && $_GET['nprice'] === 'true';

$description = apply_filters(
    'tg_item_description',
    get_post_meta($post->ID, 'tg_description', true)
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
$location_id = tg_get_wp_location_id(); // Retrieve the current location ID

$date_format = tg_date_format();
$today       = wp_date($date_format);

global $wp;
$current_page = home_url(add_query_arg(array(), $wp->request)); // Current page URL

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
                    <?php echo $description; ?>
                </div>
                <?php if (false !== $tags) : ?>
                <div class="tags">
                    <p class="label">Tags: </p><?php echo wp_kses(implode(', ', $tag_links), 'post'); ?>
                </div>
                <?php endif; ?>
            </section>
            <section class="misc col">
                <div class="date-range">
                    <p>Know your event date/time? Set it now.</p>
                    <div id="tg-dates-selector" class="dates-selector">
                        <div class="date-input-wrapper order-start">
                            <label><?php _e('Order Start', 'tapgoods'); ?></label>
                            <input type="date" name="eventStartDate" class="date-input form-control" value="<?php echo esc_attr(tg_get_start_date()); ?>" min="<?php echo esc_attr($today); ?>">
                            <input name="eventStartTime" type="time" class="time-input form-control" value="<?php echo esc_attr(tg_get_start_time()); ?>">
                        </div>
                        <div class="date-input-wrapper order-end">
                            <label><?php _e('Order End', 'tapgoods'); ?></label>
                            <input type="date" name="eventEndDate" class="date-input form-control" value="<?php echo esc_attr(tg_get_end_date()); ?>" min="<?php echo esc_attr($today); ?>">
                            <input name="eventEndTime" type="time" class="time-input form-control" value="<?php echo esc_attr(tg_get_end_time()); ?>">
                        </div>
                    </div>
                </div>
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
                <!-- Additional content can go here -->
            </section>
        </section>
    </div>
</div>



<script>
document.addEventListener("DOMContentLoaded", function () {
    const addButton = document.querySelector(".add-cart");
    const quantityInput = document.querySelector(".qty-input");
    const startDateInput = document.querySelector("input[name='eventStartDate']");
    const startTimeInput = document.querySelector("input[name='eventStartTime']");
    const endDateInput = document.querySelector("input[name='eventEndDate']");
    const endTimeInput = document.querySelector("input[name='eventEndTime']");
    const itemId = addButton ? addButton.getAttribute("data-item-id") : null;
    const locationId = addButton ? addButton.getAttribute("data-location-id") : null;

    if (!addButton || !quantityInput || !itemId || !locationId) {
        console.warn("Required elements or attributes are missing.");
        return;
    }

    // Retrieve cart data and date/time inputs from localStorage
    let cartData = JSON.parse(localStorage.getItem("cartData")) || {};
    const storedStartDate = localStorage.getItem("tg_eventStartDate");
    const storedStartTime = localStorage.getItem("tg_eventStartTime");
    const storedEndDate = localStorage.getItem("tg_eventEndDate");
    const storedEndTime = localStorage.getItem("tg_eventEndTime");

    // Apply stored date/time values to inputs
    if (storedStartDate) startDateInput.value = storedStartDate;
    if (storedStartTime) startTimeInput.value = storedStartTime;
    if (storedEndDate) endDateInput.value = storedEndDate;
    if (storedEndTime) endTimeInput.value = storedEndTime;

    // Load stored quantity for the current item and location
    if (cartData[locationId] && cartData[locationId][itemId]) {
        quantityInput.value = cartData[locationId][itemId];
        updateCartButton(addButton, true);
    }

    // Check cart icon status
    updateCartIcon(cartData, locationId);

    // Save date and time inputs to localStorage and reload data
    function saveDateTimeToLocalStorage() {
        localStorage.setItem("tg_eventStartDate", startDateInput.value || "");
        localStorage.setItem("tg_eventStartTime", startTimeInput.value || "");
        localStorage.setItem("tg_eventEndDate", endDateInput.value || "");
        localStorage.setItem("tg_eventEndTime", endTimeInput.value || "");

        // Reload data dynamically
        reloadData();
    }

    startDateInput.addEventListener("change", saveDateTimeToLocalStorage);
    startTimeInput.addEventListener("change", saveDateTimeToLocalStorage);
    endDateInput.addEventListener("change", saveDateTimeToLocalStorage);
    endTimeInput.addEventListener("change", saveDateTimeToLocalStorage);

    // Add item to cart on button click
    addButton.addEventListener("click", function (event) {
        event.preventDefault();

        const url = this.getAttribute("data-target");
        const quantity = quantityInput.value || 1;

        if (!quantity || isNaN(quantity) || quantity <= 0) {
            alert("Please enter a valid quantity.");
            return;
        }

        if (!cartData[locationId]) {
            cartData[locationId] = {};
        }

        cartData[locationId][itemId] = quantity;
        localStorage.setItem("cartData", JSON.stringify(cartData));

        // Set cart status to active
        localStorage.setItem("cart", "1"); // Ensure "cart" key is always 1 when an item is added

        updateCartButton(addButton, true);

        // Send request to add item to cart
        fetch(url + `&quantity=${quantity}`, {
            method: "GET",
            credentials: "include",
        })
            .then((response) => {
                if (response.ok) {
                    alert("Item added to cart!");
                    reloadData(); // Reload data after item is added
                } else {
                    console.error("Error adding item to cart.");
                }
            })
            .catch((error) => console.error("Request error:", error));
    });

    // Helper function to update cart button appearance
    function updateCartButton(button, isAdded) {
        if (isAdded) {
            button.style.backgroundColor = "green";
            button.textContent = "Added";
            setTimeout(() => {
                button.style.backgroundColor = ""; // Reset color
                button.textContent = "Add";
                quantityInput.value = ""; // Clear quantity after 10 seconds
            }, 10000);
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
