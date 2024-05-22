<?php

$locations = tg_get_locations();
$current_location = tg_get_wp_location_id();
?>
<div class="tapgoods location-select container">
	<div class="wrapper row row-cols-auto align-items-center">
		<span class="icon dashicons dashicons-location col"></span>
		<select class="form-select col pe-5">
			<?php foreach ( $locations as $location ) : ?>
				<option><?php echo esc_html( get_term_meta( $location->term_id, 'tg_display_locale', true ) ); ?></option>
			<?php endforeach; ?>
		</select>
	</div>
</div>