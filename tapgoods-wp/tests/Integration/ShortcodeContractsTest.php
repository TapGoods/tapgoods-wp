<?php
/**
 * Integration: the shortcode attributes the checklist promises to customers.
 *
 * From the pre-release QA checklist:
 *
 *   "Hide Item Pricing shortcode: no price when searching, no price in the
 *    inventory grid, no price on the item"
 *   "Subcategory & Tags Shortcodes: subcategory modifier, tag modifier"
 *
 * These are contracts a site owner configures once and then trusts. Hide-pricing
 * in particular is the kind of thing a business turns on deliberately, so a
 * regression leaks numbers they chose not to publish.
 *
 * @package Tapgoods\Tests\Integration
 */

namespace Tapgoods\Tests\Integration;

use WP_UnitTestCase;

final class ShortcodeContractsTest extends WP_UnitTestCase {

	const LOCATION = '5001';

	/** @var int[] */
	private $items = array();

	protected function setUp(): void {
		parent::setUp();

		update_option( 'tapgreino_default_location', self::LOCATION );
		// Without this the grid logs "Cart URL not found" for every render, which is
		// just noise in the suite output.
		update_option(
			'tg_location_' . self::LOCATION,
			array(
				'cart_url'    => 'https://shop.example.com/cart?externalRedirect=true',
				'add_to_cart' => 'https://shop.example.com/addToCart',
			)
		);

		$chairs = wp_insert_term( 'Chairs', 'tg_category' );
		$linen  = wp_insert_term( 'Linen', 'tg_category' );
		$gold   = wp_insert_term( 'Gold', 'tg_tags', array( 'slug' => 'tag-gold' ) );

		$this->items['chair'] = $this->make_item( 'Folding Chair', 12.5, array( (int) $chairs['term_id'] ), array( (int) $gold['term_id'] ) );
		$this->items['cloth'] = $this->make_item( 'Table Cloth', 30.0, array( (int) $linen['term_id'] ), array() );
	}

	/**
	 * @param int[] $category_ids
	 * @param int[] $tag_ids
	 */
	private function make_item( string $title, float $price, array $category_ids, array $tag_ids ): int {
		$post_id = wp_insert_post(
			array(
				'post_type'   => 'tg_inventory',
				'post_status' => 'publish',
				'post_title'  => $title,
				'meta_input'  => array(
					'tg_locationId' => self::LOCATION,
					'tg_dailyPrice' => $price,
					'tg_id'         => wp_rand( 1000, 9999 ),
				),
			)
		);

		if ( $category_ids ) {
			wp_set_object_terms( $post_id, $category_ids, 'tg_category' );
		}
		if ( $tag_ids ) {
			wp_set_object_terms( $post_id, $tag_ids, 'tg_tags' );
		}

		return $post_id;
	}

	private function render( string $shortcode ): string {
		return (string) do_shortcode( $shortcode );
	}

	public function test_the_grid_shows_prices_by_default() {
		$html = $this->render( '[tapgoods-inventory-grid]' );

		$this->assertStringContainsString( 'class="price', $html, 'Default behaviour must not change.' );
	}

	public function test_hide_pricing_removes_the_price_from_the_grid() {
		$html = $this->render( '[tapgoods-inventory-grid show_pricing="false"]' );

		$this->assertStringNotContainsString( 'class="price', $html );
		$this->assertStringContainsString( 'Folding Chair', $html, 'The items themselves still render.' );
	}

	public function test_hide_pricing_survives_the_curly_quotes_a_page_builder_inserts() {
		// Elementor and the block editor both like to smarten quotes, and the partial
		// strips them on purpose. If that stops working, pricing silently comes back.
		$html = $this->render( '[tapgoods-inventory-grid show_pricing="“false”"]' );

		$this->assertStringNotContainsString( 'class="price', $html );
	}

	public function test_hide_pricing_carries_through_to_the_item_link() {
		// The grid appends nprice=true so the item page it links to also hides pricing.
		$html = $this->render( '[tapgoods-inventory-grid show_pricing="false"]' );

		$this->assertStringContainsString( 'nprice=true', $html );
	}

	public function test_the_category_modifier_filters_the_grid() {
		$html = $this->render( '[tapgoods-inventory-grid category="chairs"]' );

		$this->assertStringContainsString( 'Folding Chair', $html );
		$this->assertStringNotContainsString( 'Table Cloth', $html );
	}

	public function test_the_tag_modifier_filters_the_grid() {
		// Tags are stored with a "tag-" prefix and the shortcode accepts the bare slug,
		// which is the part a site owner gets wrong if the prefixing breaks.
		$html = $this->render( '[tapgoods-inventory-grid tags="gold"]' );

		$this->assertStringContainsString( 'Folding Chair', $html );
		$this->assertStringNotContainsString( 'Table Cloth', $html );
	}

	public function test_an_unknown_category_returns_nothing_rather_than_everything() {
		// The failure that matters: a filter that silently stops filtering shows the
		// whole catalog on a page meant for one category.
		$html = $this->render( '[tapgoods-inventory-grid category="no-such-category"]' );

		$this->assertStringNotContainsString( 'Folding Chair', $html );
		$this->assertStringNotContainsString( 'Table Cloth', $html );
	}
}
