<?php
/**
 * Integration: pretty-URL routing via tapgrein_parse_request().
 *
 * This is routing logic that queries real taxonomy terms (get_terms) and
 * mutates the WP request, so it needs a real WordPress + database. It cannot be
 * faithfully unit-tested in isolation.
 *
 * @package Tapgoods\Tests\Integration
 */

namespace Tapgoods\Tests\Integration;

use WP_UnitTestCase;

final class ParseRequestRoutingTest extends WP_UnitTestCase {

	protected function setUp(): void {
		parent::setUp();
		// Start each test with no lingering template_redirect routing hooks.
		remove_action( 'template_redirect', 'tapgrein_term_template_redirect' );
		remove_action( 'template_redirect', 'tapgrein_template_redirect' );
	}

	protected function tearDown(): void {
		remove_action( 'template_redirect', 'tapgrein_term_template_redirect' );
		remove_action( 'template_redirect', 'tapgrein_template_redirect' );
		parent::tearDown();
	}

	/** Build the minimal WP-like object tapgrein_parse_request() inspects. */
	private function wp_with_category( string $slug ): object {
		$wp             = new \stdClass();
		$wp->query_vars = array( 'tg_category' => $slug );
		return $wp;
	}

	public function test_known_category_slug_routes_to_term_redirect() {
		$term = self::factory()->term->create(
			array(
				'taxonomy' => 'tg_category',
				'name'     => 'Lounge Furniture',
				'slug'     => 'lounge-furniture',
			)
		);
		$this->assertIsInt( $term );

		$wp = $this->wp_with_category( 'lounge-furniture' );
		tapgrein_parse_request( $wp );

		// A matching term hooks the term-permalink redirect and leaves the
		// query vars untouched.
		$this->assertNotFalse(
			has_action( 'template_redirect', 'tapgrein_term_template_redirect' )
		);
		$this->assertArrayHasKey( 'tg_category', $wp->query_vars );
	}

	public function test_unknown_slug_is_rewritten_to_singular_lookup() {
		$wp = $this->wp_with_category( 'no-such-term-xyz' );
		tapgrein_parse_request( $wp );

		// No term found: query vars are rewritten to search inventory/pages/posts
		// by name, and the singular redirect is hooked instead.
		$this->assertArrayHasKey( 'post_type', $wp->query_vars );
		$this->assertContains( 'tg_inventory', $wp->query_vars['post_type'] );
		$this->assertSame( 'no-such-term-xyz', $wp->query_vars['name'] );
		$this->assertNotFalse(
			has_action( 'template_redirect', 'tapgrein_template_redirect' )
		);
	}
}
