<?php

// Variables passed to template: $tag, $content, $atts

$tg_category_filter_class      = 'col-2 col-sm-12';
$tg_inventory_grid_class       = 'col-10 col-sm-12';
$tg_inventory_pagination_class = '';

do_action( 'tg_before_inventory', $atts );
?>
<div class="tapgoods tapgoods-inventory container-fluid">
	<?php if ( false !== $atts['show_search'] ) : ?>
		<?php do_action( 'tg_before_inventory_search' ); ?>
		[tapgoods-search]
		<?php do_action( 'tg_after_inventory_search' ); ?>
	<?php endif; ?>
	<div class="container">
		<div class="<?php esc_attr( apply_filters( 'tg_category_filter_class', $tg_category_filter_atts ) ); ?>">
			<?php do_action( 'tg_before_inventory_category_filter' ); ?>
			[tapgoods-category-filter]
			<?php do_action( 'tg_after_inventory_category_filter' ); ?>
		</div>
		<div class="<?php esc_attr( apply_filters( 'tg_inventory_grid_class', $tg_inventory_grid_class ) ); ?>">
			<?php do_action( 'tg_before_inventory_grid' ); ?>		
			[tapgoods-inventory-grid]
			<?php do_action( 'tg_after_inventory_grid' ); ?>
			<div class="<?php esc_attr( apply_filters( 'tg_inventory_pagination_class', $tg_inventory_pagination_class ) ); ?>">
			<?php if ( false !== $atts['show_pagenav'] ) : ?>	
				<?php do_action( 'tg_before_inventory_pagination' ); ?>		
				[tg-inventory-pagination]
				<?php do_action( 'tg_after_inventory_pagination' ); ?>		
			<?php endif; ?>
			</div>
		</div>
	</div>
</div>
<?php do_action( 'tg_after_inventory', $atts ); ?>
