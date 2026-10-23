<?php
/**
 * Integration: the taxonomy contract the QA checklist asserts before a release.
 *
 * Two lines of that checklist are the reason this file exists:
 *
 *   "Subcategories should not show up under categories"
 *   "Only categories that have items should sync over - no empty categories"
 *
 * Both were failing on two independent live sites at the same time, ~195 empty
 * tg_category terms each, every one of them named after a sub-category that also
 * existed as a tag holding all the items ("Accent Chairs": 0 as a category, 367 as
 * a tag). The cause is that getStorefrontCagetories returns a FLAT list, so each
 * sub-category arrives twice, and the category pass imported the second copy.
 *
 * Encoded here so the expectation lives next to the code instead of in a manual
 * checklist, and so a regression is caught before a customer sees it. Needs real
 * WordPress: the whole point is what ends up in the term tables after a sync.
 *
 * @package Tapgoods\Tests\Integration
 */

namespace Tapgoods\Tests\Integration;

use ReflectionClass;
use Tapgoods_Connection;
use Tapgoods_Sync_Log;
use Tapgoods_Sync_State;
use WP_UnitTestCase;

final class CategoryTaxonomyContractTest extends WP_UnitTestCase {

	protected function setUp(): void {
		parent::setUp();

		foreach ( array( Tapgoods_Connection::class, Tapgoods_Sync_State::class, Tapgoods_Sync_Log::class ) as $class ) {
			$ref  = new ReflectionClass( $class );
			$prop = $ref->getProperty( 'instance' );
			$prop->setAccessible( true );
			$prop->setValue( null, null );
		}
	}

	/** Drive a whole sync against the offline mock API. */
	private function run_full_sync(): void {
		$conn = Tapgoods_Connection::get_instance();

		// Slices until the run completes, with a hard stop so a regression fails as a
		// failed assertion rather than a hung suite.
		for ( $i = 0; $i < 40; $i++ ) {
			$conn->sync_from_api( 'test' );

			if ( Tapgoods_Sync_State::STATE_COMPLETED === Tapgoods_Sync_State::get_instance()->get_state() ) {
				return;
			}
		}

		$this->fail( 'The sync did not reach COMPLETED within 40 slices.' );
	}

	/** @return array<string,int> term name => item count */
	private function terms( string $taxonomy ): array {
		$out   = array();
		$terms = get_terms(
			array(
				'taxonomy'   => $taxonomy,
				'hide_empty' => false,
			)
		);

		foreach ( (array) $terms as $term ) {
			$out[ $term->name ] = (int) $term->count;
		}

		return $out;
	}

	public function test_no_category_is_left_without_items() {
		$this->run_full_sync();

		$empty = array_keys(
			array_filter(
				$this->terms( 'tg_category' ),
				static function ( $count ) {
					return 0 === $count;
				}
			)
		);

		$this->assertSame(
			array(),
			$empty,
			'A completed sync must not leave categories with no items: ' . implode( ', ', $empty )
		);
	}

	public function test_a_sub_category_never_becomes_a_category() {
		$this->run_full_sync();

		$categories = array_keys( $this->terms( 'tg_category' ) );
		$tags       = array_keys( $this->terms( 'tg_tags' ) );
		$both       = array_intersect( $categories, $tags );

		// A name in both taxonomies is the signature of the flat-list duplicate: the
		// tag carries the items and the category copy sits empty forever.
		$this->assertSame(
			array(),
			array_values( $both ),
			'These names exist as BOTH a category and a tag: ' . implode( ', ', $both )
		);
	}

	public function test_the_categories_that_did_sync_are_the_visible_roots() {
		$this->run_full_sync();

		// From tests/fixtures/get-storefront-categories.json: Tables and Chairs are
		// visible roots; "Linens (Uncategorized)" is a hidden bucket and "Round Tables"
		// arrives as both a nested sub-category and a top-level duplicate.
		$this->assertSame(
			array( 'Chairs', 'Tables' ),
			$this->sorted_names( 'tg_category' )
		);
	}

	public function test_a_sub_category_with_items_still_arrives_as_a_tag() {
		$this->run_full_sync();

		$tags = $this->terms( 'tg_tags' );

		// The property that matters: dropping the flat duplicates must not cost us the
		// tag. "Round Tables" is nested under Tables in the fixture and has items, so
		// it has to be here, carrying them.
		$this->assertArrayHasKey( 'Round Tables', $tags );
		$this->assertGreaterThan( 0, $tags['Round Tables'] );

		// "Banquet Tables" is nested too but no fixture item references it, so the same
		// no-empty-terms cleanup that prunes categories prunes it. Asserted rather than
		// left implicit, because the first version of this test expected it to survive
		// and the cleanup was right.
		$this->assertArrayNotHasKey( 'Banquet Tables', $tags );
	}

	/** @return string[] */
	private function sorted_names( string $taxonomy ): array {
		$names = array_keys( $this->terms( $taxonomy ) );
		sort( $names );

		return $names;
	}
}
