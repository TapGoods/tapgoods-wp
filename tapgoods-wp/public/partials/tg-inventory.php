<?php

// Variables passed to template: $tag, $content, $atts

$tg_inventory_grid_class       = 'col-sm-8 col-xs-12';
$tg_inventory_pagination_class = '';

$category = '';
$tags     = '';

if ( ! empty( $atts['category'] ) ) {
	$category = "category={$atts['category']}";
}

if ( ! empty( $atts['tags'] ) ) {
	$tags = "tags={$atts['tags']}";
}

do_action( 'tg_before_inventory', $atts );
ob_start();

?>
<div class="tapgoods tapgoods-inventory container-fluid">
	<?php if ( false !== $atts['show_search'] ) : ?>
		<?php do_action( 'tg_before_inventory_search' ); ?>
		<?php echo do_shortcode( '[tapgoods-search]' ) ?>
		<?php do_action( 'tg_after_inventory_search' ); ?>
	<?php endif; ?>
	<div class="container shop">
		<div class="row align-items-start">
				[tapgoods-filter]
			<section class="<?php echo esc_attr( apply_filters( 'tg_inventory_grid_class', $tg_inventory_grid_class ) ); ?>">
				<?php do_action( 'tg_before_inventory_grid' ); ?>		
				[tapgoods-inventory-grid <?php echo $category; ?> <?php echo $tags; ?>]
				<?php do_action( 'tg_after_inventory_grid' ); ?>
			</section>
		</div>
	</div>
</div>
<?php do_action( 'tg_after_inventory', $atts ); ?>
<?php
echo do_shortcode( ob_get_clean() );

