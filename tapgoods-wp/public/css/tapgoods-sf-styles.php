<?php

// example for sending dynamic CSS via php endpoint
// deprecated using wp inline style with functions

$bg = get_option( 'background-color', false );
if ( $bg ) {
	echo 'body { background: ' . $bg . '; !important }'; //phpcs:ignore
}

die();
