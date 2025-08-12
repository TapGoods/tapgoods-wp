<?php

if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly

// get id location and url cart
global $post;
// Capture the location ID from the URL if present
$local_storage_location = isset( $_GET['local_storage_location'] ) ? sanitize_text_field( wp_unslash( $_GET['local_storage_location'] ) ) : null;

// Retrieve the current location ID
$location_id = $local_storage_location ?: tg_get_wp_location_id();
//echo esc_html($location_id);


// Check if 'local_storage_location' is present in the URL
$local_storage_location = isset( $_GET['local_storage_location'] ) ? sanitize_text_field( wp_unslash( $_GET['local_storage_location'] ) ) : null;

// Check the cookie 'tg_user_location'
$cookie_location = isset( $_COOKIE['tg_user_location'] ) ? sanitize_text_field( wp_unslash( $_COOKIE['tg_user_location'] ) ) : null;
$location = $cookie_location ?: ($local_storage_location ?: tg_get_wp_location_id());



$url = tg_get_cart_url($location);


?>

<button id="tg_cart" data-target="<?php echo esc_url($url); ?>" class="tapgoods tg-cart-button btn btn-primary">
<span class="icon full-cart-icon" style="display: none;">
    <!-- SVG full cart icon -->
    <svg class="tg-primary" width="30" height="23" viewBox="0 0 30 23" xmlns="http://www.w3.org/2000/svg">
        <path fill-rule="evenodd" clip-rule="evenodd" d="M10.0006 20.447C10.0299 19.0666 11.1727 17.9713 12.5531 18.0006L12.5798 18.0007C13.9311 18.0154 15.0146 19.1227 14.9998 20.474C15.0002 20.5003 15 20.5267 14.9995 20.5531C14.9701 21.9335 13.8273 23.0287 12.447 22.9994C11.0666 22.9701 9.97129 21.8274 10.0006 20.447ZM19.0013 20.4203C19.0453 19.0403 20.1996 17.9573 21.5797 18.0013H21.58C22.9312 18.0161 24.0146 19.1234 23.9999 20.4747C24.0002 20.5097 23.9998 20.5447 23.9987 20.5796C23.9547 21.9597 22.8004 23.0427 21.4204 22.9987C20.0403 22.9547 18.9573 21.8003 19.0013 20.4203Z" fill="currentColor" />
        <path fill-rule="evenodd" clip-rule="evenodd" d="M7.03594 3.85529L6.07816 0.807775C5.94916 0.342272 5.53101 0.0147633 5.04672 0H0V2.20302H4.19946L8.5831 16.2289C8.71503 16.6871 9.13636 17.0021 9.61455 17H25.5651C26.0492 16.9947 26.4773 16.6856 26.6334 16.2289L29.9488 5.28725C30.1319 4.70693 29.8084 4.08852 29.2262 3.90597C29.1144 3.87089 28.9977 3.8538 28.8805 3.85529H7.03594ZM18 14.25C17.5858 14.25 17.25 13.9142 17.25 13.5V11.25H15C14.5858 11.25 14.25 10.9142 14.25 10.5C14.25 10.0858 14.5858 9.75 15 9.75H17.25V7.5C17.25 7.08579 17.5858 6.75 18 6.75C18.4142 6.75 18.75 7.08579 18.75 7.5V9.75H21C21.4142 9.75 21.75 10.0858 21.75 10.5C21.75 10.9142 21.4142 11.25 21 11.25H18.75V13.5C18.75 13.9142 18.4142 14.25 18 14.25Z" fill="currentColor" />
    </svg>
</span>
<span class="icon empty-cart-icon" style="display: inline-block;">
    <!-- SVG empty cart icon -->
    <svg class="tg-primary" width="30" height="23" viewBox="0 0 30 23" xmlns="http://www.w3.org/2000/svg">
        <path fill-rule="evenodd" clip-rule="evenodd" d="M6.07816 0.807775L7.03594 3.85529H28.8805C28.9977 3.8538 29.1144 3.87089 29.2262 3.90597C29.8084 4.08852 30.1319 4.70693 29.9488 5.28725L26.6334 16.2289C26.4773 16.6856 26.0492 16.9947 25.5651 17H9.61455C9.13636 17.0021 8.71503 16.6871 8.5831 16.2289L4.19946 2.20302H0V0H5.04672C5.53101 0.0147633 5.94916 0.342272 6.07816 0.807775ZM12.5531 18.0006C11.1727 17.9713 10.0299 19.0666 10.0006 20.447C9.97129 21.8274 11.0666 22.9701 12.447 22.9994C13.8273 23.0287 14.9701 21.9335 14.9995 20.5531C15 20.5267 15.0002 20.5003 14.9998 20.474C15.0146 19.1227 13.9311 18.0154 12.5798 18.0007L12.5531 18.0006ZM21.5797 18.0013C20.1996 17.9573 19.0453 19.0403 19.0013 20.4203C18.9573 21.8003 20.0403 22.9547 21.4204 22.9987C22.8004 23.0427 23.9547 21.9597 23.9987 20.5796C23.9998 20.5447 24.0002 20.5097 23.9999 20.4747C24.0146 19.1234 22.9312 18.0161 21.58 18.0013H21.5797Z" fill="currentColor" />
    </svg>
</span>

</button>

<?php
// Cart initialization script is enqueued via class-tapgoods-shortcodes.php
// Cart URL data is passed via wp_localize_script
?>
