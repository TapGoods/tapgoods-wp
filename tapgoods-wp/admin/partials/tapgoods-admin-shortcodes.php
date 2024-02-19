<?php


$shortcodes_info = Tapgoods_Shortcodes::get_shortcodes();

?>
<h2>Shortcodes</h2>
<div class="container">
<?php foreach($shortcodes_info as $shortcode => $info) : ?>
<div class="row justify-content-start align-items-center mb-3">
    <div class="col-2">
        <label><?php echo $info['name'] ?></label>
    </div>
    <div class="col-8">
        <div class="input-group mb3">
            <input type="text" id="<?php echo $shortcode ?>-input" class="from-control" disabled value="[<?php echo $shortcode ?>]">
            <button type="button" class="btn btn-outline-secondary"><span class="dashicons dashicons-admin-generic"></span></button>
            <button type="button" id="copy-<?php echo $shortcode?>" onClick="copyText(this)" data-target="<?php echo $shortcode ?>-input" data-bs-toggle="tooltip" data-bs-placement="bottom" data-bs-title="Copy to clipboard" class="btn btn-outline-secondary"><span class="dashicons dashicons-admin-page"></span></button>
        </div>
    </div>
</div>
<?php endforeach ?>
</div>
<?php
