<?php
/**
 * Integration: [tapgoods-search-bar], the header search box (WPB-183).
 *
 * The bar is a plain GET form that sends the visitor to the shop page with
 * ?tg_search=<term>; the shop's own search box picks the term up in the
 * browser. What can break on the PHP side, and is pinned here:
 *
 *  - the form must point at the page holding [tapgoods-inventory], found the
 *    same way the tag archive redirect finds it;
 *  - the term must travel as tg_search, never s: WordPress applies s to the
 *    page query itself, and the shop answers with a 404;
 *  - a shop on plain permalinks ("/?page_id=12") must keep its page id through
 *    the submit, since a browser drops the query string of a GET form's action;
 *  - the markup must not vary by visitor, because the header is page-cached.
 *
 * @package Tapgoods\Tests\Integration
 */

namespace Tapgoods\Tests\Integration;

use WP_UnitTestCase;

final class SearchBarShortcodeTest extends WP_UnitTestCase {

	/** @var int */
	private $shop_page;

	protected function setUp(): void {
		parent::setUp();

		wp_cache_delete( 'tg_shop_page_id', 'tapgoods' );
		delete_option( 'tg_shop_page_id' );

		$this->shop_page = (int) self::factory()->post->create(
			array(
				'post_type'    => 'page',
				'post_status'  => 'publish',
				'post_title'   => 'Catalog',
				'post_content' => '[tapgoods-inventory]',
			)
		);
	}

	protected function tearDown(): void {
		wp_cache_delete( 'tg_shop_page_id', 'tapgoods' );
		$this->set_permalink_structure( '' );
		parent::tearDown();
	}

	private function render( string $shortcode ): string {
		return (string) do_shortcode( $shortcode );
	}

	private function form_action( string $html ): string {
		$this->assertSame( 1, preg_match( '/<form[^>]*action="([^"]*)"/', $html, $m ), 'No form rendered: ' . $html );
		return html_entity_decode( $m[1] );
	}

	public function test_the_shortcode_is_registered() {
		$this->assertTrue( shortcode_exists( 'tapgoods-search-bar' ) );
	}

	public function test_it_submits_the_term_as_tg_search_to_the_shop_page() {
		$this->set_permalink_structure( '/%postname%/' );

		$html = $this->render( '[tapgoods-search-bar]' );

		$this->assertSame( get_permalink( $this->shop_page ), $this->form_action( $html ) );
		$this->assertStringContainsString( 'method="get"', $html );
		$this->assertStringContainsString( 'name="tg_search"', $html );
		$this->assertStringNotContainsString( 'name="s"', $html );
	}

	public function test_a_plain_permalink_shop_keeps_its_page_id() {
		$this->set_permalink_structure( '' );

		$html = $this->render( '[tapgoods-search-bar]' );

		$this->assertSame( home_url( '/' ), $this->form_action( $html ) );
		$this->assertMatchesRegularExpression(
			'/<input type="hidden" name="page_id" value="' . $this->shop_page . '">/',
			$html
		);
	}

	public function test_action_url_overrides_the_discovered_shop() {
		$html = $this->render( '[tapgoods-search-bar action_url="https://example.com/rentals/"]' );

		$this->assertSame( 'https://example.com/rentals/', $this->form_action( $html ) );
	}

	public function test_placeholder_and_button_text_are_used_and_escaped() {
		$html = $this->render( '[tapgoods-search-bar placeholder="Find <b>tents</b>" button_text="Go"]' );

		$this->assertStringContainsString( 'placeholder="Find &lt;b&gt;tents&lt;/b&gt;"', $html );
		$this->assertMatchesRegularExpression( '/<button[^>]*>\s*Go\s*<\/button>/', $html );
		$this->assertStringNotContainsString( '<svg', $html );
	}

	public function test_without_a_shop_page_it_renders_no_form() {
		wp_delete_post( $this->shop_page, true );
		wp_cache_delete( 'tg_shop_page_id', 'tapgoods' );

		$this->assertStringNotContainsString( '<form', $this->render( '[tapgoods-search-bar]' ) );
	}

	public function test_the_markup_does_not_depend_on_the_visitor() {
		// The header is page-cached per URL: whichever visitor warms the cache
		// decides the markup for everyone, so a term or a cookie must not leak in.
		$plain = $this->render( '[tapgoods-search-bar]' );

		$_GET['tg_search']           = 'chairs';
		$_COOKIE['tg_user_location'] = '999';
		$with_state                  = $this->render( '[tapgoods-search-bar]' );
		unset( $_GET['tg_search'], $_COOKIE['tg_user_location'] );

		// wp_unique_id() counts up per render; the id is the only expected difference.
		$strip = static function ( $html ) {
			return preg_replace( '/tg-search-bar-\d+/', 'tg-search-bar-N', $html );
		};
		$this->assertSame( $strip( $plain ), $strip( $with_state ) );
	}

	public function test_the_shop_page_still_resolves_when_it_carries_tg_search() {
		// The reason the term is not sent as s: with s, WordPress searches the
		// page query itself and the shop 404s. tg_search must leave it alone.
		$this->set_permalink_structure( '/%postname%/' );

		$this->go_to( add_query_arg( 'tg_search', 'chair', get_permalink( $this->shop_page ) ) );

		$this->assertFalse( is_404() );
		$this->assertTrue( is_page( $this->shop_page ) );
	}
}
