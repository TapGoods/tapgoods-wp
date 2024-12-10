<?php

// Variables passed to template: $tag, $content, $atts

$tg_inventory_grid_class = 'col-sm-8 col-xs-12';
$category               = ! empty( $atts['category'] ) ? "category={$atts['category']}" : '';
$tags                   = ! empty( $atts['tags'] ) ? "tags={$atts['tags']}" : '';
$per_page_default       = isset( $atts['per_page_default'] ) ? "per_page_default={$atts['per_page_default']}" : '';
$show_search            = filter_var( $atts['show_search'], FILTER_VALIDATE_BOOLEAN );
$show_filters           = filter_var( $atts['show_filters'], FILTER_VALIDATE_BOOLEAN );
$show_pricing = 'show_pricing=true'; 

if (isset($atts['show_pricing']) && $atts['show_pricing'] !== '') {
    $normalized_value = str_replace(['“', '”', '"'], '', $atts['show_pricing']);
    $boolean_value = filter_var($normalized_value, FILTER_VALIDATE_BOOLEAN) ? 'true' : 'false';
    $show_pricing = 'show_pricing=' . $boolean_value;
}

do_action( 'tg_before_inventory', $atts );
ob_start();
?>
<div id="tg-shop" class="tapgoods tapgoods-inventory container-fluid">
    <?php if ( false !== $show_search ) : ?>
        <?php do_action( 'tg_before_inventory_search' ); ?>
        <?php 
echo do_shortcode( 
    '[tapgoods-search nos="true" category="' . esc_attr($category) . '" ' . 
    'show_pricing="' . esc_attr($show_pricing) . '" ' . 
    'tags="' . esc_attr($tags) . '" ' . 
    'per_page_default="' . esc_attr($per_page_default) . '"]' 
); 
?>
        <?php do_action( 'tg_after_inventory_search' ); ?>
    <?php endif; ?>
    <div class="container shop">
        <div class="row align-items-start">
            <?php if ( false !== $show_filters ) : ?>
                [tapgoods-filter]
            <?php endif; ?>
            <section class="<?php echo esc_attr( apply_filters( 'tg_inventory_grid_class', $tg_inventory_grid_class ) ); ?>">
                <?php do_action( 'tg_before_inventory_grid' ); ?>        
                [tapgoods-inventory-grid <?php echo $category; ?> <?php echo $tags; ?> <?php echo $show_pricing; ?> <?php echo $per_page_default; ?>]
                <?php do_action( 'tg_after_inventory_grid' ); ?>
            </section>
        </div>
    </div>
</div>
<?php
do_action( 'tg_after_inventory', $atts );
echo do_shortcode( ob_get_clean() );


