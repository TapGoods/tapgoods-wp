<?php

if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly

global $post;


$product_post_id = ( isset( $atts['id'] ) ) ? $atts['id'] : $post->ID;
$dimensions = tapgrein_get_product_dimensions( $product_post_id );

?>
<?php if ( is_array( $dimensions ) && count( $dimensions ) > 0 ) : ?>
	<div id="tg-dimensions">
	<?php foreach ( $dimensions as $dim => $value ) : ?>
		<div class="row">
			<div class="col">
				<p class="label">
					<?php echo wp_kses( $dim, 'post' ); ?>
				</p>
			</div>
			<div class="col">
				<p class="value">
				<?php echo wp_kses( $value, 'post' ); ?>
				</p>
			</div>
		</div>
	<?php endforeach; ?>
	</div>
<?php endif; ?>
