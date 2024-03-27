<?php

class Tapgoods_Helpers {
	public static function tgdd( $data, $pre = true ) {
		if ( defined( 'TAPGOODS_DEV' ) && TAPGOODS_DEV ) {
			echo ( $pre ) ? '<pre>' : '';
			var_dump( $data );
			die();
		}
	}
	public static function tgqm( $data ) {
		if ( defined( 'TAPGOODS_DEV' ) && TAPGOODS_DEV ) {
			do_action( 'qm/debug', $data );
		}
	}

	public static function tg_delete_inventory() {
		$items = get_pages( 'post_type=tg_inventory' );
		self::tgdd( $items );
		foreach ( $items as $item ) {
			wp_delete_post( $item->ID, false ); // Set to False if you want to send them to Trash.
		}
	}
}
