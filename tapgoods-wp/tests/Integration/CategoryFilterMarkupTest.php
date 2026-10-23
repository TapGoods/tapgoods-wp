<?php
/**
 * Integration: the categories filter markup (WPB-179).
 *
 * The accordion must start closed on mobile, where it otherwise pushes the items
 * off screen. The first implementation branched on wp_is_mobile() while rendering,
 * which cannot work on a cached site: the HTML is stored per URL with no device
 * variance, so whichever device warms the cache decides what everyone else gets.
 * Reproduced on WP Engine in both directions on one URL, warmed from a phone
 * desktop visitors got the closed panel, warmed from a desktop phones got the open
 * one.
 *
 * The property that matters is therefore not "closed on mobile" but "the same
 * bytes for every device, closed, with the viewport deciding client side". That is
 * what these tests hold in place, and it needs real WordPress because the
 * regression is in a template that calls WordPress functions.
 *
 * @package Tapgoods\Tests\Integration
 */

namespace Tapgoods\Tests\Integration;

use WP_UnitTestCase;

final class CategoryFilterMarkupTest extends WP_UnitTestCase {

	/**
	 * Render public/partials/tg-filter.php and return its markup.
	 *
	 * @return string
	 */
	private function render_filter() {
		ob_start();
		include TAPGOODS_PLUGIN_PATH . 'public/partials/tg-filter.php';

		return (string) ob_get_clean();
	}

	/**
	 * Render with wp_is_mobile() forced to a given value.
	 *
	 * @param bool $is_mobile What wp_is_mobile() should report.
	 * @return string
	 */
	private function render_as( $is_mobile ) {
		$force = static function () use ( $is_mobile ) {
			return $is_mobile;
		};

		add_filter( 'wp_is_mobile', $force );
		$html = $this->render_filter();
		remove_filter( 'wp_is_mobile', $force );

		return $html;
	}

	public function test_markup_is_identical_for_mobile_and_desktop() {
		// The regression itself. If this fails, the rendered page has become
		// device-dependent again and page caching will serve it to the wrong device.
		$this->assertSame(
			$this->render_as( true ),
			$this->render_as( false ),
			'The filter markup must not depend on wp_is_mobile(), or a cached page will be served to the wrong device.'
		);
	}

	public function test_accordion_ships_closed() {
		$html = $this->render_filter();

		$this->assertStringContainsString( 'accordion-button collapsed', $html );
		$this->assertStringContainsString( 'aria-expanded="false"', $html );
	}

	public function test_accordion_panel_does_not_ship_open() {
		$html = $this->render_filter();

		// Bootstrap opens a collapse with the "show" class. Closed is the safe
		// default: on the wrong device it costs one tap, where wrongly open buries
		// the products.
		$this->assertMatchesRegularExpression(
			'/id="collapseOne"\s+class="accordion-collapse collapse(?! show)/',
			$html,
			'The panel must not carry Bootstrap\'s "show" class in the served HTML.'
		);
	}

	public function test_viewport_decides_client_side() {
		$html = $this->render_filter();

		// The other half of the fix: desktop still gets it open, decided in the
		// browser rather than from the User-Agent on the server.
		$this->assertStringContainsString( '(min-width: 768px)', $html );
		$this->assertStringContainsString( "getElementById( 'collapseOne' )", $html );
		$this->assertStringContainsString( "classList.add( 'show' )", $html );
	}

	public function test_the_opener_also_corrects_the_button_state() {
		// Leaving the button as "collapsed" while the panel is open desyncs the
		// caret and the accessibility state.
		$html = $this->render_filter();

		$this->assertStringContainsString( "classList.remove( 'collapsed' )", $html );
		$this->assertStringContainsString( "setAttribute( 'aria-expanded', 'true' )", $html );
	}

	public function test_both_devices_get_the_closed_accordion() {
		// Byte equality above proves the markup does not vary. This pins down which
		// of the two variants everyone gets, so a regression that made both renders
		// consistently OPEN could not pass silently.
		foreach ( array( true, false ) as $is_mobile ) {
			$html = $this->render_as( $is_mobile );
			$what = $is_mobile ? 'mobile' : 'desktop';

			$this->assertStringContainsString(
				'accordion-button collapsed',
				$html,
				"Rendered for {$what}, the accordion must still ship closed."
			);
			$this->assertStringNotContainsString(
				'accordion-collapse collapse show',
				$html,
				"Rendered for {$what}, the panel must not ship open."
			);
		}
	}
}
