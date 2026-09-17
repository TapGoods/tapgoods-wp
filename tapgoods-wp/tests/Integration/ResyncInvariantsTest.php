<?php
/**
 * Integration: what a resync must change, and what it must never touch.
 *
 * This covers the "Edit item in TapGoods and resync" block of the pre-release QA
 * checklist, which is entirely about a resync's effect on an item that already
 * exists in WordPress:
 *
 *   "Test a variety of edits (add price, update existing price, remove price,
 *    edit name, add image, add/edit dimensions, edit description)"
 *   "Edit the item in TapGoods and resync - does it keep the custom SEO"
 *   "Edit item in TapGoods and resync - does it keep the custom description"
 *
 * Worth automating rather than clicking, because the failure is silent and only
 * shows up on the next sync, by which time the edit is already gone. The resync
 * path deletes stale meta, so this is the code where "keeps" and "updates" are
 * decided.
 *
 * Real WordPress: the whole subject is post and meta state after the write.
 *
 * @package Tapgoods\Tests\Integration
 */

namespace Tapgoods\Tests\Integration;

use ReflectionClass;
use Tapgoods_Connection;
use WP_UnitTestCase;

final class ResyncInvariantsTest extends WP_UnitTestCase {

	/** @var int */
	private $post_id;

	protected function setUp(): void {
		parent::setUp();

		$ref  = new ReflectionClass( Tapgoods_Connection::class );
		$prop = $ref->getProperty( 'instance' );
		$prop->setAccessible( true );
		$prop->setValue( null, null );

		// An item as a first sync would have left it.
		$this->post_id = wp_insert_post(
			array(
				'post_type'   => 'tg_inventory',
				'post_status' => 'publish',
				'post_title'  => 'Folding Chair',
				'meta_input'  => array(
					'tg_id'          => 9001,
					'tg_name'        => 'Folding Chair',
					'tg_dailyPrice'  => '12.00',
					'tg_quantity'    => 40,
					'tg_description' => 'A chair.',
					'tg_locationId'  => '5001',
				),
			)
		);
	}

	/** The API payload for the same item, after an edit in TapGoods. */
	private function edited_item( array $overrides = array() ): array {
		return array_merge(
			array(
				'id'          => 9001,
				'name'        => 'Folding Chair',
				'dailyPrice'  => '12.00',
				'quantity'    => 40,
				'description' => 'A chair.',
				'locationId'  => '5001',
			),
			$overrides
		);
	}

	private function resync( array $overrides = array() ): void {
		Tapgoods_Connection::get_instance()->update_inventory_item( $this->post_id, $this->edited_item( $overrides ) );
	}

	private function meta( string $key ) {
		return get_post_meta( $this->post_id, $key, true );
	}

	// --- What a resync must apply --------------------------------------------

	public function test_a_renamed_item_updates_the_post_title() {
		$this->resync( array( 'name' => 'Folding Chair, Natural Wood' ) );

		$this->assertSame( 'Folding Chair, Natural Wood', get_post( $this->post_id )->post_title );
		$this->assertSame( 'Folding Chair, Natural Wood', $this->meta( 'tg_name' ) );
	}

	public function test_a_changed_price_is_applied() {
		$this->resync( array( 'dailyPrice' => '18.50' ) );

		$this->assertSame( '18.50', $this->meta( 'tg_dailyPrice' ) );
	}

	public function test_a_removed_price_is_removed_here_too() {
		// The API sends null for a field that no longer has a value, and
		// prepare_meta_input() drops nulls, so the key must not survive as a stale
		// price. This is the "remove price" line of the checklist, and the one most
		// likely to rot: a leftover price shows a number the business deleted.
		$this->resync( array( 'dailyPrice' => null ) );

		$this->assertSame( '', $this->meta( 'tg_dailyPrice' ) );
	}

	public function test_a_new_field_arrives() {
		$this->resync( array( 'dimensions' => '18x18x32' ) );

		$this->assertSame( '18x18x32', $this->meta( 'tg_dimensions' ) );
	}

	public function test_an_added_image_arrives() {
		$pictures = array( array( 'id' => 1, 'imageUrl' => 'https://example.com/chair.jpg' ) );

		$this->resync( array( 'pictures' => $pictures ) );

		$this->assertSame( $pictures, $this->meta( 'tg_pictures' ) );
	}

	public function test_an_edited_description_is_applied() {
		$this->resync( array( 'description' => 'A very good chair.' ) );

		$this->assertSame( 'A very good chair.', $this->meta( 'tg_description' ) );
	}

	// --- What a resync must never touch --------------------------------------

	public function test_the_editors_custom_description_survives() {
		update_post_meta( $this->post_id, 'tg_custom_description', '<p>Hand written copy.</p>' );

		$this->resync( array( 'description' => 'API copy.' ) );

		$this->assertSame( '<p>Hand written copy.</p>', $this->meta( 'tg_custom_description' ) );
		$this->assertSame( 'API copy.', $this->meta( 'tg_description' ), 'The API field still updates alongside it.' );
	}

	public function test_per_item_yoast_seo_survives() {
		update_post_meta( $this->post_id, '_yoast_wpseo_title', 'Rent a folding chair' );
		update_post_meta( $this->post_id, '_yoast_wpseo_metadesc', 'Chairs for your event.' );
		update_post_meta( $this->post_id, '_yoast_wpseo_focuskw', 'folding chair' );

		$this->resync( array( 'name' => 'Renamed' ) );

		$this->assertSame( 'Rent a folding chair', $this->meta( '_yoast_wpseo_title' ) );
		$this->assertSame( 'Chairs for your event.', $this->meta( '_yoast_wpseo_metadesc' ) );
		$this->assertSame( 'folding chair', $this->meta( '_yoast_wpseo_focuskw' ) );
	}

	public function test_meta_belonging_to_the_site_or_other_plugins_survives() {
		// The sync owns the tg_ namespace and nothing else. Before this was scoped,
		// every resync deleted anything not on a hand-maintained allowlist, which on
		// an item post means a featured image, a page builder's data, or a different
		// SEO plugin's fields, silently and on every run.
		$attachment = self::factory()->attachment->create_object(
			array( 'file' => 'chair.jpg', 'post_parent' => 0, 'post_mime_type' => 'image/jpeg' )
		);
		update_post_meta( $this->post_id, '_thumbnail_id', $attachment );
		update_post_meta( $this->post_id, '_elementor_data', '[{"id":"abc"}]' );
		update_post_meta( $this->post_id, 'rank_math_description', 'Another SEO plugin.' );
		update_post_meta( $this->post_id, 'some_theme_option', 'keep me' );

		$this->resync();

		$this->assertSame( (string) $attachment, (string) $this->meta( '_thumbnail_id' ), 'Featured image must survive a resync.' );
		$this->assertSame( '[{"id":"abc"}]', $this->meta( '_elementor_data' ) );
		$this->assertSame( 'Another SEO plugin.', $this->meta( 'rank_math_description' ) );
		$this->assertSame( 'keep me', $this->meta( 'some_theme_option' ) );
	}

	public function test_our_own_stale_fields_are_still_cleaned_up() {
		// The flip side: a tg_ key the API stopped sending IS ours to remove, or the
		// item keeps showing data the business deleted.
		update_post_meta( $this->post_id, 'tg_retiredField', 'old value' );

		$this->resync();

		$this->assertSame( '', $this->meta( 'tg_retiredField' ) );
	}

	public function test_the_run_token_stamp_is_left_alone() {
		update_post_meta( $this->post_id, Tapgoods_Connection::SYNC_RUN_META, 'token-abc' );

		$this->resync();

		$this->assertSame( 'token-abc', $this->meta( Tapgoods_Connection::SYNC_RUN_META ) );
	}
}
