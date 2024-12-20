<?php
/**
 * Adds settings to the permalinks admin settings page
 *
 * @package     Tapgoods\Admin
 * @version     0.1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( class_exists( 'TG_Admin_Permalink_Settings', false ) ) {
	return new TG_Admin_Permalink_Settings();
}

/**
 * WC_Admin_Permalink_Settings Class.
 */
class TG_Admin_Permalink_Settings {

	/**
	 * Permalink settings.
	 *
	 * @var array
	 */
	private $permalinks = array();

	/**
	 * Hook in tabs.
	 */
	public function __construct() {
		$this->settings_init();
		$this->settings_save();
	}

	/**
	 * Init our settings.
	 */
	public function settings_init() {
		add_settings_section( 'tg-permalink', __( 'Inventory permalinks', 'tapgoods-wp' ), array( $this, 'settings' ), 'permalink' );

		add_settings_field(
			'tg_category_rewrite_slug',
			__( 'Inventory category base', 'tapgoods-wp' ),
			array( $this, 'tg_category_slug_input' ),
			'permalink',
			'optional'
		);
		add_settings_field(
			'tg_tags_rewrite_slug',
			__( 'Inventory tag base', 'tapgoods-wp' ),
			array( $this, 'tg_tag_slug_input' ),
			'permalink',
			'optional'
		);

		$this->permalinks = tg_get_permalink_structure();
	}

	public function tg_category_slug_input() {
		?>
		<input name="tapgoods_product_category_slug" type="text" class="regular-text code" value="<?php echo esc_attr( $this->permalinks['tg_category_base'] ); ?>" placeholder="<?php echo esc_attr_x( 'categories', 'slug', 'tapgoods-wp' ); ?>" />
		<?php
	}

	/**
	 * Show a slug input box.
	 */
	public function tg_tag_slug_input() {
		?>
		<input name="tapgoods_product_tag_slug" type="text" class="regular-text code" value="<?php echo esc_attr( $this->permalinks['tg_tag_base'] ); ?>" placeholder="<?php echo esc_attr_x( 'tags', 'slug', 'tapgoods-wp' ); ?>" />
		<?php
	}

	/**
	 * Show the settings.
	 */
	public function settings() {
		/* translators: %s: Home URL */
		echo wp_kses_post( wpautop( sprintf( __( 'Customize URL structures for inventory here. For example, using <code>rentals</code> would make your product links like <code>%srendtals/sample-product/</code>. This setting affects item URLs only, not item categories or tags.', 'tapgoods-wp' ), esc_url( home_url( '/' ) ) ) ) );

		$shop_page_id = tg_get_page_id( 'shop' ); // TODO implement options for setting/getting default pages

		$base_slug      = urldecode( ( $shop_page_id > 0 && get_post( $shop_page_id ) ) ? get_page_uri( $shop_page_id ) : _x( 'shop', 'default-slug', 'tapgoods-wp' ) );
		$inventory_base = _x( 'products', 'default-slug', 'tapgoods-wp' );

		$structures = array(
			0 => '',
			1 => '/' . trailingslashit( $base_slug ),
			2 => '/' . trailingslashit( $base_slug ) . trailingslashit( '%tg_category%' ),
		);
		?>
		<table class="form-table tg-permalink-structure">
			<tbody>
				<tr>
					<th>
						<label>
							<input name="inventory_permalink" type="radio" value="<?php echo esc_attr( $structures[0] ); ?>" class="tg_tog" <?php checked( $structures[0], $this->permalinks['tg_inventory_base'] ); ?> /> <?php esc_html_e( 'Default', 'tapgoods-wp' ); ?>
						</label>
					</th>
					<td>
						<code class="default-example"><?php echo esc_html( home_url() ); ?>/?product=sample-product</code> <code class="non-default-example"><?php echo esc_html( home_url() ); ?>/<?php echo esc_html( $inventory_base ); ?>/sample-product/</code>
					</td>
				</tr>
				<tr>
					<th>
						<label>
							<input name="inventory_permalink" type="radio" value="<?php echo esc_attr( $structures[1] ); ?>" class="tg_tog" <?php checked( $structures[1], $this->permalinks['tg_inventory_base'] ); ?> /> <?php esc_html_e( 'Shop base', 'tapgoods-wp' ); ?>
						</label>
					</th>
					<td>
						<code><?php echo esc_html( home_url() ); ?>/<?php echo esc_html( $base_slug ); ?>/sample-product/</code>
					</td>
				</tr>
				<tr>
					<th><label><input name="inventory_permalink" type="radio" value="<?php echo esc_attr( $structures[2] ); ?>" class="tg_tog" <?php checked( $structures[2], $this->permalinks['tg_inventory_base'] ); ?> /> <?php esc_html_e( 'Shop base with category', 'tapgoods-wp' ); ?></label></th>
					<td><code><?php echo esc_html( home_url() ); ?>/<?php echo esc_html( $base_slug ); ?>/product-category/sample-product/</code></td>
				</tr>
				<tr>
					<th><label><input name="inventory_permalink" id="tapgoods_custom_selection" type="radio" value="custom" class="tog" <?php checked( in_array( $this->permalinks['tg_inventory_base'], $structures, true ), false ); ?> />
						<?php esc_html_e( 'Custom base', 'tapgoods-wp' ); ?></label></th>
					<td>
						<input name="inventory_permalink_structure" id="inventory_permalink_structure" type="text" value="<?php echo esc_attr( $this->permalinks['tg_inventory_base'] ? trailingslashit( $this->permalinks['tg_inventory_base'] ) : '' ); ?>" class="regular-text code"> <span class="description"><?php esc_html_e( 'Enter a custom base to use. A base must be set or WordPress will use default instead.', 'tapgoods-wp' ); ?></span>
					</td>
				</tr>
			</tbody>
		</table>
		<?php wp_nonce_field( 'tg-save-permalinks', '_tgnonce' ); ?>
		<script type="text/javascript">
			jQuery( function() {
				jQuery('input.tg_tog').on( 'change', function() {
					jQuery('#inventory_permalink_structure').val( jQuery( this ).val() );
				});
				jQuery('.permalink-structure input').on( 'change', function() {
					jQuery('.tg-permalink-structure').find('code.non-default-example, code.default-example').hide();
					if ( jQuery(this).val() ) {
						jQuery('.tg-permalink-structure code.non-default-example').show();
						jQuery('.tg-permalink-structure input').prop('disabled', false);
					} else {
						jQuery('.tg-permalink-structure code.default-example').show();
						jQuery('.tg-permalink-structure input:eq(0)').trigger( 'click' );
						jQuery('.tg-permalink-structure input').attr('disabled', 'disabled');
					}
				});
				jQuery('.permalink-structure input:checked').trigger( 'change' );
				jQuery('#inventory_permalink_structure').on( 'focus', function(){
					jQuery('#tapgoods_custom_selection').trigger( 'click' );
				} );
			} );
		</script>
		<?php
	}

	public function settings_save() {

		if ( ! is_admin() ) {
			return false;
		}

		if ( ! isset( $_POST['_tgnonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_tgnonce'] ) ), 'tg-save-permalinks' ) ) {
			return false;
		}

		// Need to save the options b/c settings API does not trigger on permalinks save
		$permalinks = get_option( 'tapgoods_permalinks', array() );

		$permalinks['tg_category_base'] = ( isset( $_POST['tapgoods_product_category_slug'] ) ) ? sanitize_text_field( wp_unslash( $_POST['tapgoods_product_category_slug'] ) ) : $permalinks['tg_category_base']; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$permalinks['tg_tag_base']      = ( isset( $_POST['tapgoods_product_tag_slug'] ) ) ? sanitize_text_field( wp_unslash( $_POST['tapgoods_product_tag_slug'] ) ) : $permalinks['tg_tag_base']; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized

		$product_base = isset( $_POST['inventory_permalink_structure'] ) ? sanitize_text_field( wp_unslash( $_POST['inventory_permalink_structure'] ) ) : '';

		$permalinks['tg_inventory_base'] = $product_base;

		$update = update_option( 'tapgoods_permalinks', $permalinks );
		Tapgoods_Helpers::tgqm( 'update option permalinks' );
		Tapgoods_Helpers::tgqm( $update );

		return false;
	}
}

return new TG_Admin_Permalink_Settings();
