<?php

global $post;

$description = apply_filters(
    'tg_item_description',
    get_post_meta( $post->ID, 'tg_description', true )
);

$tags = get_the_terms( $post, 'tg_tags' );
if ( false !== $tags ) {
    $tag_links = array();
    foreach ( $tags as $tg_tag ) {
        $tag_link    = get_term_link( $tg_tag );
        $tag_links[] = "<a href=\"{$tag_link}\">$tg_tag->name</a>";
    }
}

$tg_per_page = ( isset( $_COOKIE['tg-per-page'] ) ) ? sanitize_text_field( wp_unslash( $_COOKIE['tg-per-page'] ) ) : get_option( 'tg_per_page', 12 );

$tg_id = get_post_meta( $post->ID, 'tg_id', true );
$location_id = tg_get_wp_location_id(); // Retrieve the current location ID

$date_format = tg_date_format();
$today       = wp_date( $date_format );

global $wp;
$current_page = home_url( add_query_arg( array(), $wp->request ) ); // Current page URL

// Get the base URL without adding the redirectUrl parameter
$base_cart_url = tg_get_product_add_to_cart_url( $post->ID );

// Build the full URL by manually adding the redirectUrl parameter
$cart_url = $base_cart_url . '&redirectUrl=' . urlencode( $current_page );

?>
<div class="tapgoods">
    <?php do_action( 'tg_before_inventory_single_container' ); ?>
    <div id="tg-single" class="inventory-single container-fluid">
        <?php do_action( 'tg_before_inventory_single_search' ); ?>
        [tapgoods-search]
        <?php do_action( 'tg_after_inventory_single_search' ); ?>
        <section class="inventory-single-content row row-cols-1 row-cols-md-2 p-3">
            <?php do_action( 'tg_before_inventory_single_images' ); ?>
            [tapgoods-image-carousel product="<?php echo esc_attr($post->ID); ?>"]
            <?php do_action( 'tg_before_inventory_single_summary' ); ?>
            <section class="summary col">
                <div class="maginifier-preview" hidden></div>
                <span class="name"><?php the_title(); ?></span>
                <div class="pricing">
                    <?php $prices = tg_get_prices( $post->ID ); ?>
                    <?php foreach ( $prices as $price_arr ) : ?>
                        <span><?php echo '$' . wp_kses( current( $price_arr ), 'post' ); ?></span>
                        <span><?php echo ' / ' . wp_kses( array_key_first( $price_arr ), 'post' ); ?></span>
                    <?php endforeach; ?>
                </div>
                <div class="quantity-select mb-4">
                    <input type="text" placeholder="Qty" name="quantity" class="form-control qty-input">
                    <button data-location-id="<?php echo esc_attr($location_id); ?>" data-item-id="<?php echo esc_attr($tg_id); ?>" data-target="<?php echo esc_url( $cart_url ); ?>" class="add-cart btn btn-primary">Add Item</button>
                </div>
            </section>
            <section class="details col py-4 mt-2">
                <div class="description">
                    <?php echo $description; ?>
                </div>
                <?php if ( false !== $tags ) : ?>
                <div class="tags">
                    <p class="label">Tags: </p><?php echo wp_kses( implode( ', ', $tag_links ), 'post' ); ?>
                </div>
                <?php endif; ?>
            </section>
            <section class="misc col">
                <div class="date-range">
                    <p>Know your event date/time? Set it now.</p>
                    <div id="tg-dates-selector" class="dates-selector">
                        <div class="date-input-wrapper order-start">
                            <label><?php _e( 'Order Start', 'tapgoods' ); ?></label>
                            <input type="date" name="eventStartDate" class="date-input form-control" value="<?php echo esc_attr( tg_get_start_date() ); ?>" min="<?php echo esc_attr( $today ); ?>">
                            <input name="eventStartTime" type="time" class="time-input form-control" value="<?php echo esc_attr( tg_get_start_time() ); ?>">
                        </div>
                        <div class="date-input-wrapper order-end">
                            <label><?php _e( 'Order End', 'tapgoods' ); ?></label>
                            <input type="date" name="eventEndDate" class="date-input form-control" value="<?php echo esc_attr( tg_get_end_date() ); ?>" min="<?php echo esc_attr( $today ); ?>">
                            <input name="eventEndTime" type="time" class="time-input form-control" value="<?php echo esc_attr( tg_get_end_time() ); ?>">
                        </div>
                    </div>
                </div>
                <div class="additional-details">
                    <?php do_action( 'tg_product_additional_details' ); ?>
                    <?php do_action( 'tg_product_dimensions' ); ?>
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
document.addEventListener("DOMContentLoaded", function() {
    const addButton = document.querySelector(".add-cart");
    const quantityInput = document.querySelector(".qty-input");
    const itemId = addButton ? addButton.getAttribute("data-item-id") : null;
    const locationId = addButton ? addButton.getAttribute("data-location-id") : null;

    if (!addButton || !quantityInput || !itemId || !locationId) {
        console.warn("Required elements or attributes are missing.");
        return;
    }

    // Retrieve cart data from localStorage
    let cartData = JSON.parse(localStorage.getItem("cartData")) || {};

    // Check if location data exists in cartData, and update quantity input and button if so
    if (cartData[locationId] && cartData[locationId][itemId]) {
        quantityInput.value = cartData[locationId][itemId];
        addButton.style.backgroundColor = "green";
        addButton.textContent = "Added";
    }

    // Check if the cart icon exists and update based on location data
    const cartIcon = document.getElementById("tg_cart");
    if (cartIcon) {
        if (cartData[locationId] && Object.keys(cartData[locationId]).length > 0) {
            cartIcon.classList.add("has-items");
        } else {
            cartIcon.classList.remove("has-items");
        }
    }

    addButton.addEventListener("click", function(event) {
        event.preventDefault();

        const url = this.getAttribute("data-target");
        const quantity = quantityInput.value || 1;

        if (!quantity || isNaN(quantity) || quantity <= 0) {
            alert("Please enter a valid quantity.");
            return;
        }

        // Initialize location data if it does not exist
        if (!cartData[locationId]) {
            cartData[locationId] = {};
        }

        // Update item quantity under the location
        cartData[locationId][itemId] = quantity;
        localStorage.setItem("cartData", JSON.stringify(cartData));

        // Change button to green and update text to "Added"
        this.style.backgroundColor = "green";
        this.textContent = "Added";

        // Send request to add item to cart without redirection
        fetch(url + `&quantity=${quantity}`, {
            method: "GET",
            credentials: "include"
        })
        .then(response => {
            if (response.ok) {
                alert("Item added to cart!");
            } else {
                console.error("Error adding item to cart.");
            }
        })
        .catch(error => console.error("Request error:", error));
    });
});
</script>
