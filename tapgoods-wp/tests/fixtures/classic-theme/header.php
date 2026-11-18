<?php
/**
 * Test fixture: the header of a typical classic theme (WPB-188).
 *
 * The WordPress test suite's own default theme prints a bare "Header" with no
 * <head> and no wp_head(), so a test rendered through it measures the stub, not
 * the plugin. TagArchiveRoutingTest points the theme directory here instead.
 */
?><!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
<meta charset="<?php bloginfo( 'charset' ); ?>">
<?php wp_head(); ?>
</head>
<body <?php body_class(); ?>>
<?php wp_body_open(); ?>
<header id="tg-fixture-site-header">Fixture site header</header>
