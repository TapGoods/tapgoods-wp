<?php
/**
 * Integration: which location the front end uses, and the cart links that follow.
 *
 * Three lines of the pre-release QA checklist:
 *
 *   "Changing the location in WordPress Admin should not change the location
 *    selected on the front end"
 *   "Changing the selected location on the front end should update the cart link"
 *   "Links redirect to correct location" (sign in / sign up / cart)
 *
 * All three are one question underneath: the precedence between the visitor's own
 * choice and the site default, and whether the per-location URLs follow it. Easy
 * to break without noticing, because a wrong cart link still looks like a link.
 *
 * @package Tapgoods\Tests\Integration
 */

namespace Tapgoods\Tests\Integration;

use WP_UnitTestCase;

final class LocationAndCartUrlTest extends WP_UnitTestCase {

	const ADMIN_DEFAULT = '5001';
	const VISITOR_PICK  = '5002';

	protected function setUp(): void {
		parent::setUp();

		update_option( 'tapgreino_default_location', self::ADMIN_DEFAULT );

		// Two locations as sync_location_settings() would have stored them.
		update_option(
			'tg_location_' . self::ADMIN_DEFAULT,
			array(
				'fullName'    => 'Downtown Warehouse (DTW)',
				'cart_url'    => 'https://downtown.example.com/cart?externalRedirect=true',
				'add_to_cart' => 'https://downtown.example.com/addToCart',
				'signup_url'  => 'https://downtown.example.com/signup',
				'login_url'   => 'https://downtown.example.com/login',
			)
		);
		update_option(
			'tg_location_' . self::VISITOR_PICK,
			array(
				'fullName'    => 'Airport Depot (APT)',
				'cart_url'    => 'https://airport.example.com/cart?externalRedirect=true',
				'add_to_cart' => 'https://airport.example.com/addToCart',
				'signup_url'  => 'https://airport.example.com/signup',
				'login_url'   => 'https://airport.example.com/login',
			)
		);

		unset( $_COOKIE['tg_user_location'] );
	}

	protected function tearDown(): void {
		unset( $_COOKIE['tg_user_location'] );
		parent::tearDown();
	}

	public function test_with_no_visitor_choice_the_admin_default_is_used() {
		$this->assertSame( self::ADMIN_DEFAULT, tapgrein_get_wp_location_id() );
	}

	public function test_a_visitors_choice_wins_over_the_admin_default() {
		// The checklist phrases this from the other side: changing the location in
		// wp-admin must NOT move a visitor who has already chosen one.
		$_COOKIE['tg_user_location'] = self::VISITOR_PICK;

		$this->assertSame( self::VISITOR_PICK, tapgrein_get_wp_location_id() );

		// Now the admin changes the site default. The visitor must not follow.
		update_option( 'tapgreino_default_location', '5999' );

		$this->assertSame( self::VISITOR_PICK, tapgrein_get_wp_location_id() );
	}

	public function test_a_visitor_with_no_choice_does_follow_the_admin_default() {
		update_option( 'tapgreino_default_location', self::VISITOR_PICK );

		$this->assertSame( self::VISITOR_PICK, tapgrein_get_wp_location_id() );
	}

	public function test_the_cart_link_follows_the_selected_location() {
		$this->assertStringStartsWith(
			'https://downtown.example.com/cart',
			tapgrein_get_cart_url( tapgrein_get_wp_location_id() )
		);

		$_COOKIE['tg_user_location'] = self::VISITOR_PICK;

		$this->assertStringStartsWith(
			'https://airport.example.com/cart',
			tapgrein_get_cart_url( tapgrein_get_wp_location_id() ),
			'Choosing a location on the front end has to move the cart link with it.'
		);
	}

	public function test_the_add_to_cart_link_follows_the_selected_location() {
		$this->assertSame(
			'https://downtown.example.com/addToCart',
			tapgrein_get_add_to_cart_url( self::ADMIN_DEFAULT )
		);
		$this->assertSame(
			'https://airport.example.com/addToCart',
			tapgrein_get_add_to_cart_url( self::VISITOR_PICK )
		);
	}

	public function test_the_cart_link_carries_the_event_window() {
		// The cart is useless without the dates, so a change to the URL builder that
		// drops them should fail here rather than on a customer's storefront.
		$url = tapgrein_get_cart_url( self::ADMIN_DEFAULT );

		$this->assertStringContainsString( 'eventStart=', $url );
		$this->assertStringContainsString( 'eventEnd=', $url );
	}

	public function test_an_unknown_location_degrades_to_a_harmless_link() {
		// Better a dead anchor than a URL pointing at another business's storefront.
		$this->assertSame( '#', tapgrein_get_cart_url( '404404' ) );
		$this->assertSame( '#', tapgrein_get_add_to_cart_url( '404404' ) );
	}
}
