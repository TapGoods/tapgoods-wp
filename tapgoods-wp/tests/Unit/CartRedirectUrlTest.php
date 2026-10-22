<?php
/**
 * Unit tests for the cart redirect URL setting (WPB-186):
 * tapgrein_validate_redirect_url(), tapgrein_get_cart_redirect_url() and the
 * redirectUrl parameter appended by tapgrein_get_cart_url().
 *
 * The CART_URL_WITH_REDIRECT constant path is not covered here: a constant
 * cannot be undefined again, so defining it would leak into every later test.
 *
 * @package Tapgoods\Tests
 */

namespace Tapgoods\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

final class CartRedirectUrlTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		// tapgoods-core-functions.php registers hooks at include time;
		// Brain\Monkey stubs add_action()/add_filter() once set up.
		require_once dirname( __DIR__, 2 ) . '/includes/tapgoods-core-functions.php';

		Functions\when( 'wp_parse_url' )->alias( 'parse_url' );
		Functions\when( 'wp_date' )->alias(
			function ( $format, $timestamp = null ) {
				return gmdate( $format, null === $timestamp ? time() : $timestamp );
			}
		);
		Functions\when( 'add_query_arg' )->alias(
			function ( $params, $url ) {
				return $url . ( false === strpos( $url, '?' ) ? '?' : '&' ) . implode(
					'&',
					array_map(
						function ( $k, $v ) {
							return $k . '=' . $v;
						},
						array_keys( $params ),
						$params
					)
				);
			}
		);
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	private function stub_options( $redirect ) {
		Functions\when( 'get_option' )->alias(
			function ( $name, $default = false ) use ( $redirect ) {
				if ( 'tg_location_7' === $name ) {
					return array( 'cart_url' => 'https://shop.example.com/cart' );
				}
				if ( 'tg_cart_url_with_redirect' === $name ) {
					return $redirect;
				}
				return $default;
			}
		);
	}

	public function test_validate_accepts_http_and_https_urls() {
		$this->assertSame( 'https://example.com/thanks', tapgrein_validate_redirect_url( 'https://example.com/thanks' ) );
		$this->assertSame( 'http://example.com/a?b=c', tapgrein_validate_redirect_url( '  http://example.com/a?b=c ' ) );
	}

	public function test_validate_rejects_invalid_values() {
		$this->assertSame( '', tapgrein_validate_redirect_url( '' ) );
		$this->assertSame( '', tapgrein_validate_redirect_url( 'not a url' ) );
		$this->assertSame( '', tapgrein_validate_redirect_url( 'example.com/thanks' ) );
		$this->assertSame( '', tapgrein_validate_redirect_url( 'javascript:alert(1)' ) );
		$this->assertSame( '', tapgrein_validate_redirect_url( 'ftp://example.com/file' ) );
		$this->assertSame( '', tapgrein_validate_redirect_url( false ) );
		$this->assertSame( '', tapgrein_validate_redirect_url( array( 'https://example.com' ) ) );
	}

	public function test_get_cart_redirect_url_reads_option() {
		$this->stub_options( 'https://example.com/thanks' );
		$this->assertSame( 'https://example.com/thanks', tapgrein_get_cart_redirect_url() );

		$this->stub_options( 'garbage' );
		$this->assertSame( '', tapgrein_get_cart_redirect_url() );
	}

	public function test_cart_url_appends_encoded_redirect_url_when_set() {
		$this->stub_options( 'https://example.com/thanks?x=1&y=2' );

		$url = tapgrein_get_cart_url( 7 );

		$this->assertStringStartsWith( 'https://shop.example.com/cart?', $url );
		$this->assertStringContainsString( 'eventStart=', $url );
		$this->assertStringContainsString( 'redirectUrl=' . rawurlencode( 'https://example.com/thanks?x=1&y=2' ), $url );
	}

	public function test_cart_url_has_no_redirect_url_when_unset_or_invalid() {
		$this->stub_options( '' );
		$this->assertStringNotContainsString( 'redirectUrl', tapgrein_get_cart_url( 7 ) );

		$this->stub_options( 'not a url' );
		$this->assertStringNotContainsString( 'redirectUrl', tapgrein_get_cart_url( 7 ) );
	}
}
