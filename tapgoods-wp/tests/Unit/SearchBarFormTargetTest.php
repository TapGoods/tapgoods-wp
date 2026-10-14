<?php
/**
 * Unit tests for tapgrein_search_bar_form_target() (WPB-183).
 *
 * The header search bar is a GET form, and a browser submitting a GET form
 * discards the query string of its action. These pin that whatever the results
 * URL carries comes back as hidden fields, so a shop on plain permalinks
 * ("/?page_id=12") still gets the visitor to the shop rather than the home page.
 *
 * @package Tapgoods\Tests
 */

namespace Tapgoods\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

final class SearchBarFormTargetTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		// tapgoods-core-functions.php registers hooks at include time.
		Functions\when( 'add_action' )->justReturn( true );
		Functions\when( 'add_filter' )->justReturn( true );

		require_once ABSPATH . 'includes/tapgoods-core-functions.php';
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_a_pretty_permalink_needs_no_hidden_fields() {
		$this->assertSame(
			array(
				'action' => 'https://example.com/catalog/',
				'hidden' => array(),
			),
			tapgrein_search_bar_form_target( 'https://example.com/catalog/' )
		);
	}

	public function test_a_plain_permalink_keeps_its_page_id_through_the_submit() {
		$this->assertSame(
			array(
				'action' => 'https://example.com/',
				'hidden' => array( 'page_id' => '12' ),
			),
			tapgrein_search_bar_form_target( 'https://example.com/?page_id=12' )
		);
	}

	public function test_every_query_param_survives_and_the_fragment_is_dropped_from_it() {
		$target = tapgrein_search_bar_form_target( 'https://example.com/shop/?lang=fr&category=chairs#grid' );

		$this->assertSame( 'https://example.com/shop/', $target['action'] );
		$this->assertSame(
			array(
				'lang'     => 'fr',
				'category' => 'chairs',
			),
			$target['hidden']
		);
	}

	public function test_a_search_term_already_in_the_url_is_not_sent_twice() {
		// The form supplies tg_search itself; s would turn the page query into
		// a WordPress search and 404 the shop.
		$target = tapgrein_search_bar_form_target( 'https://example.com/shop/?tg_search=old&s=old&page_id=3' );

		$this->assertSame( array( 'page_id' => '3' ), $target['hidden'] );
	}

	public function test_curly_quotes_from_an_editor_are_stripped_from_attributes() {
		$this->assertSame( 'Search tents', tapgrein_search_bar_att( '“Search tents”' ) );
		$this->assertSame( 'https://example.com/shop/', tapgrein_search_bar_att( ' "https://example.com/shop/" ' ) );
	}

	public function test_attribute_cleanup_keeps_multibyte_text_intact() {
		// trim( $s, '“”' ) works on bytes and would eat the lead bytes of these.
		$this->assertSame( '— Search —', tapgrein_search_bar_att( '— Search —' ) );
		$this->assertSame( '…search', tapgrein_search_bar_att( '…search' ) );
	}

	public function test_array_params_are_skipped_rather_than_flattened() {
		$target = tapgrein_search_bar_form_target( 'https://example.com/shop/?ids[]=1&ids[]=2&x=y' );

		$this->assertSame( array( 'x' => 'y' ), $target['hidden'] );
	}
}
