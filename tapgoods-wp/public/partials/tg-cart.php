<?php

// get the cart link
// check for items in cart cookie
// render the link
global $post;

$location = tg_get_wp_location_id();
$url = tg_get_cart_url( $location );

?>
<button id="tg_cart" target="<?php echo esc_url( $url ); ?>" class="tapgoods tg-cart-button btn btn-primary"><span class="icon dashicons dashicons-cart"></span></button>