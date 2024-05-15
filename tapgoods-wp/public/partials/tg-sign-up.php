<?php

$location = tg_get_wp_location_id();
$url      = tg_get_sign_up_url( $location );
?>
<a href="<?php echo esc_url( $url ); ?>" target="_self"><?php esc_html_e( 'Sign Up', 'tapgoods' ); ?></a>