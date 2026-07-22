<?php
/**
 * Unit tests for Tapgoods_Connection::prepare_meta_input().
 *
 * prepare_meta_input() is a pure transform (API item array -> WP meta_input
 * array) that makes no WordPress calls, so it is exercised in isolation. The
 * class file itself runs add_action()/get_instance() at include time, so it is
 * required after Brain\Monkey has stubbed those WP functions, mirroring
 * ConnectionMockFlowTest.
 *
 * @package Tapgoods\Tests
 */

namespace Tapgoods\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Tapgoods_Connection;

final class ConnectionMetaInputTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		Functions\when( 'add_action' )->justReturn( true );
		Functions\when( 'get_option' )->justReturn( false );

		require_once ABSPATH . 'includes/class-tapgoods-connection.php';

		$ref  = new ReflectionClass( Tapgoods_Connection::class );
		$prop = $ref->getProperty( 'instance' );
		$prop->setValue( null, null );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	private function connection(): Tapgoods_Connection {
		return Tapgoods_Connection::get_instance();
	}

	public function test_prefixes_keys_and_sets_tg_id() {
		$meta = $this->connection()->prepare_meta_input(
			array(
				'id'         => 11001,
				'name'       => '6ft Round Table',
				'dailyPrice' => '12.00',
			)
		);

		$this->assertSame( '6ft Round Table', $meta['tg_name'] );
		$this->assertSame( '12.00', $meta['tg_dailyPrice'] );
		$this->assertSame( 11001, $meta['tg_id'] );
	}

	public function test_excludes_structural_keys() {
		$meta = $this->connection()->prepare_meta_input(
			array(
				'id'           => 1,
				'slug'         => 'ignored-slug',
				'businessInfo' => array( 'x' => 1 ),
				'location'     => array( 'y' => 2 ),
				'suppliers'    => array( 'z' => 3 ),
				'keepMe'       => 'yes',
			)
		);

		$this->assertArrayNotHasKey( 'tg_slug', $meta );
		$this->assertArrayNotHasKey( 'tg_businessInfo', $meta );
		$this->assertArrayNotHasKey( 'tg_location', $meta );
		$this->assertArrayNotHasKey( 'tg_suppliers', $meta );
		$this->assertSame( 'yes', $meta['tg_keepMe'] );
	}

	public function test_skips_null_values_but_keeps_falsey_ones() {
		$meta = $this->connection()->prepare_meta_input(
			array(
				'id'       => 1,
				'nullable' => null,
				'zero'     => 0,
				'empty'    => '',
				'flag'     => false,
			)
		);

		// null is dropped entirely...
		$this->assertArrayNotHasKey( 'tg_nullable', $meta );
		// ...but other falsey values are preserved.
		$this->assertArrayHasKey( 'tg_zero', $meta );
		$this->assertSame( 0, $meta['tg_zero'] );
		$this->assertArrayHasKey( 'tg_empty', $meta );
		$this->assertArrayHasKey( 'tg_flag', $meta );
	}
}
