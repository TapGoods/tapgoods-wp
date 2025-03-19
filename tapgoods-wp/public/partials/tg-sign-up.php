<?php

// Check if 'local_storage_location' is present in the URL
$local_storage_location = isset( $_GET['local_storage_location'] ) ? sanitize_text_field( wp_unslash( $_GET['local_storage_location'] ) ) : null;

// Check the cookie 'tg_user_location'
$cookie_location = isset( $_COOKIE['tg_user_location'] ) ? sanitize_text_field( wp_unslash( $_COOKIE['tg_user_location'] ) ) : null;
$location = $cookie_location ?: ($local_storage_location ?: tg_get_wp_location_id());


// Generate the sign-up URL
$url = tg_get_sign_up_url( $location );
?>
<a href="<?php echo esc_url( $url ); ?>" target="_self"><?php esc_html_e( 'Sign Up', 'tapgoods' ); ?></a>
<script>
document.addEventListener("DOMContentLoaded", function () {
    const savedLocation = localStorage.getItem('tg_user_location');
    if (savedLocation) {
        document.cookie = `tg_user_location=${savedLocation}; path=/;`;
    }
});
</script>
