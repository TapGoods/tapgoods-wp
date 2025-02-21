<?php
$plugin_url = str_replace('/partials', '', plugin_dir_url(__FILE__)) . 'css/';
global $wp;

// Get the tag from url
$request_uri = isset($_SERVER['REQUEST_URI']) ? sanitize_text_field(wp_unslash($_SERVER['REQUEST_URI'])) : '';
$segments = explode('/', $request_uri);
$tag = '';

if (count($segments) > 1 && $segments[0] === 'tags') {
    $tag = sanitize_text_field($segments[1]);
}

// Generate shortcode with tag from url
$shortcode = '[tapgoods-inventory per_page_default="14"';
if (!empty($tag)) {
    $shortcode .= ' tags="' . esc_attr($tag) . '"';
}
$shortcode .= ']';

?>

<link rel="stylesheet" href="<?php echo esc_url($plugin_url); ?>tapgoods-public.css?ver=0.1.0" media="all">
<link rel="stylesheet" href="<?php echo esc_url($plugin_url); ?>tapgoods-custom.css?ver=0.1.0" media="all">
<link rel="stylesheet" href="<?php echo esc_url($plugin_url); ?>global-styles.css?ver=0.1.0" media="all">

<br />
<div id="tg-shop" class="tapgoods tapgoods-inventory container-fluid">
    <div class="container shop">
        <div class="row align-items-start">
            <section class="col-sm-12 col-xs-12" id="tg-inventory-grid-container">
                <div id="tg-inventory-grid">
                    <?php echo do_shortcode($shortcode); ?>
                </div>
            </section>
        </div>
    </div>
</div>

