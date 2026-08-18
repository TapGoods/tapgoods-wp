<?php
/**
 * Unit tests for Tapgoods_Sync_State::get_activity_label().
 *
 * The reason this exists: "Syncing" plus a page counter was reported as confusing
 * from the admin screen, because a run sits at 0/N for the whole category pass and
 * at N/N for the whole finalize pass. Both read as "nothing is happening". These
 * tests pin the copy to the phase the run is actually in.
 *
 * Pure function of state plus cursor, so it is exercised in isolation with the
 * options layer stubbed via Brain\Monkey.
 *
 * @package Tapgoods\Tests
 */

namespace Tapgoods\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Tapgoods_Sync_State;

final class SyncStateActivityLabelTest extends TestCase {

	/** @var array In-memory option store. */
	private $store = array();

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		$this->store = array();
		$store       = &$this->store;

		Functions\when( 'add_action' )->justReturn( true );
		Functions\when( 'current_time' )->justReturn( 1000 );
		Functions\when( 'get_option' )->alias(
			static function ( $key, $default = false ) use ( &$store ) {
				return array_key_exists( $key, $store ) ? $store[ $key ] : $default;
			}
		);
		Functions\when( 'update_option' )->alias(
			static function ( $key, $value ) use ( &$store ) {
				$store[ $key ] = $value;
				return true;
			}
		);

		require_once ABSPATH . 'includes/class-tapgoods-sync-state.php';

		$ref  = new ReflectionClass( Tapgoods_Sync_State::class );
		$prop = $ref->getProperty( 'instance' );
		$prop->setValue( null, null );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	private function state(): Tapgoods_Sync_State {
		return Tapgoods_Sync_State::get_instance();
	}

	/** Put an ACTIVE run into a specific cursor shape. */
	private function active_with( array $cursor ): Tapgoods_Sync_State {
		$state = $this->state();
		$state->begin_prep()->mark_active();
		$state->save_cursor( array_merge( Tapgoods_Sync_State::cursor_defaults(), $cursor ) );

		return $state;
	}

	public function test_idle_and_completed_are_plain() {
		$this->assertSame( 'Idle', $this->state()->get_activity_label() );

		$this->state()->begin_prep()->mark_active();
		$this->state()->mark_completed();
		$this->assertSame( 'Completed', $this->state()->get_activity_label() );
	}

	public function test_prep_says_it_is_planning() {
		$this->state()->begin_prep();

		$this->assertSame(
			'Preparing: counting locations and pages',
			$this->state()->get_activity_label()
		);
	}

	public function test_category_pass_is_named_and_not_reported_as_items() {
		// The confusing case: paging counter is 0/N because paging has not started.
		$state = $this->active_with(
			array(
				'location_ids'       => array( 1, 2, 3, 4 ),
				'categories_done'    => false,
				'cat_location_index' => 1,
				'phase'              => 'paging',
			)
		);

		$this->assertSame( 'Syncing categories and tags (location 2 of 4)', $state->get_activity_label() );
	}

	public function test_category_pass_without_known_locations_still_reads_sensibly() {
		$state = $this->active_with( array( 'categories_done' => false, 'location_ids' => array() ) );

		$this->assertSame( 'Syncing categories and tags', $state->get_activity_label() );
	}

	public function test_item_paging_reports_the_page_in_progress() {
		$state = $this->active_with(
			array(
				'location_ids'    => array( 1 ),
				'categories_done' => true,
				'phase'           => 'paging',
			)
		);
		$state->set_total_pages( 734 );
		for ( $i = 0; $i < 411; $i++ ) {
			$state->increment_pages_completed();
		}

		$this->assertSame( 'Syncing items (page 412 of 734)', $state->get_activity_label() );
	}

	public function test_page_counter_never_reads_past_the_total() {
		$state = $this->active_with( array( 'categories_done' => true ) );
		$state->set_total_pages( 734 );
		for ( $i = 0; $i < 734; $i++ ) {
			$state->increment_pages_completed();
		}

		$this->assertSame(
			'Syncing items (page 734 of 734)',
			$state->get_activity_label(),
			'A finished paging phase must not advertise page 735 of 734.'
		);
	}

	/**
	 * @dataProvider finalize_steps
	 */
	public function test_each_finalize_step_says_what_it_is_doing( string $step, string $expected ) {
		// The 16-minute window that read as "stuck at 734 / 734".
		$state = $this->active_with(
			array(
				'categories_done' => true,
				'phase'           => 'finalize',
				'finalize_step'   => $step,
			)
		);

		$this->assertSame( $expected, $state->get_activity_label() );
	}

	public static function finalize_steps(): array {
		return array(
			'items'             => array( 'items', 'Finishing up: removing items no longer in TapGoods' ),
			'obsolete_cat'      => array( 'obsolete_cat', 'Finishing up: removing categories no longer in TapGoods' ),
			'obsolete_tag'      => array( 'obsolete_tag', 'Finishing up: removing tags no longer in TapGoods' ),
			'cleanup_terms_cat' => array( 'cleanup_terms_cat', 'Finishing up: clearing out categories with no items' ),
			'cleanup_terms_tag' => array( 'cleanup_terms_tag', 'Finishing up: clearing out tags with no items' ),
			'cleanup_dupes'     => array( 'cleanup_dupes', 'Finishing up: removing duplicate items' ),
			'unknown step'      => array( 'something_new', 'Finishing up' ),
			'empty step'        => array( '', 'Finishing up' ),
		);
	}

	public function test_summary_carries_the_activity_and_the_phase() {
		// The admin partial reads only the summary, so the new fields have to be in it.
		$state = $this->active_with(
			array(
				'categories_done' => true,
				'phase'           => 'finalize',
				'finalize_step'   => 'cleanup_dupes',
			)
		);

		$summary = $state->get_summary();

		$this->assertSame( 'Finishing up: removing duplicate items', $summary['activity'] );
		$this->assertSame( 'finalize', $summary['phase'] );
		$this->assertSame( 'cleanup_dupes', $summary['finalize_step'] );
		$this->assertTrue( $summary['categories_done'] );
		$this->assertSame( 'Syncing', $summary['label'], 'The state label itself must not change.' );
	}
}
