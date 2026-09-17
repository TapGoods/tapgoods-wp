<?php
/**
 * Unit tests for the item-cleanup safety guard in Tapgoods_Connection.
 *
 * remove_items_not_in_run() deletes permanently (wp_delete_post with force) and
 * at scale: the first successful sync of a store that had been failing for months
 * removes everything the run did not stamp, measured at 8,408 posts out of 26,596
 * on a live site. That is right when the run saw the whole catalog and destructive
 * when it did not, so the guard requires the stamped set to be a plausible share
 * of what is stored before anything is deleted.
 *
 * Isolated (no WordPress) via Brain\Monkey, with a fake $wpdb that answers the two
 * COUNT queries the guard asks.
 *
 * @package Tapgoods\Tests
 */

namespace Tapgoods\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Tapgoods_Connection;

final class ConnectionCleanupGuardTest extends TestCase {

	/** @var mixed Saved global $wpdb. */
	private $wpdb_backup;

	/** @var int[] Post ids passed to wp_delete_post(). */
	private $deleted = array();

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		$this->deleted = array();

		Functions\when( 'add_action' )->justReturn( true );
		Functions\when( 'get_option' )->justReturn( false );
		Functions\when( 'update_option' )->justReturn( true );
		Functions\when( 'current_time' )->justReturn( 1000 );

		$deleted = &$this->deleted;
		Functions\when( 'wp_delete_post' )->alias(
			static function ( $post_id ) use ( &$deleted ) {
				$deleted[] = (int) $post_id;
				return true;
			}
		);

		require_once ABSPATH . 'includes/class-tapgoods-sync-state.php';
		require_once ABSPATH . 'includes/class-tapgoods-connection.php';

		global $wpdb;
		$this->wpdb_backup = $wpdb;

		$ref  = new ReflectionClass( Tapgoods_Connection::class );
		$prop = $ref->getProperty( 'instance' );
		$prop->setValue( null, null );
	}

	protected function tearDown(): void {
		global $wpdb;
		$wpdb = $this->wpdb_backup;

		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * Install a fake $wpdb that reports a stored total and a stamped count, and
	 * hands back $delete_ids for the deletion query.
	 *
	 * @param int   $total      tg_inventory posts stored.
	 * @param int   $stamped    Of those, stamped with the run token.
	 * @param int[] $delete_ids Ids the deletion query should return.
	 */
	private function fake_wpdb( $total, $stamped, array $delete_ids = array() ) {
		global $wpdb;

		$wpdb = new class( $total, $stamped, $delete_ids ) {
			public $posts     = 'wp_posts';
			public $postmeta  = 'wp_postmeta';
			public $total;
			public $stamped;
			public $delete_ids;
			/** @var string[] Every query this fake was asked to run. */
			public $seen = array();

			public function __construct( $total, $stamped, $delete_ids ) {
				$this->total      = $total;
				$this->stamped    = $stamped;
				$this->delete_ids = $delete_ids;
			}

			public function prepare( $query, ...$args ) {
				return $query;
			}

			public function get_var( $query ) {
				$this->seen[] = $query;

				// The stamped count is the one that joins postmeta.
				return ( false !== strpos( $query, 'INNER JOIN' ) ) ? $this->stamped : $this->total;
			}

			public function get_col( $query ) {
				$this->seen[] = $query;
				return $this->delete_ids;
			}
		};

		return $wpdb;
	}

	public function test_default_minimum_ratio_is_half() {
		$this->assertSame( 0.5, Tapgoods_Connection::cleanup_min_stamped_ratio() );
	}

	public function test_a_run_that_stamped_almost_nothing_deletes_nothing() {
		// The scenario the guard exists for: the run reached finalize having seen
		// 500 of 18,000 items. Without the guard this deletes the other 17,500.
		$wpdb = $this->fake_wpdb( 18000, 500, range( 1, 100 ) );

		$removed = Tapgoods_Connection::get_instance()->remove_items_not_in_run( 'run-abc', 100 );

		$this->assertSame( 0, $removed );
		$this->assertSame( array(), $this->deleted, 'Not a single post may be deleted.' );

		$ran_delete_query = false;
		foreach ( $wpdb->seen as $query ) {
			if ( false !== strpos( $query, 'SELECT p.ID' ) ) {
				$ran_delete_query = true;
			}
		}
		$this->assertFalse( $ran_delete_query, 'The guard must short-circuit before selecting ids to delete.' );
	}

	public function test_a_real_backlog_is_still_cleaned_up() {
		// The live site: 18,188 stamped of 26,596 stored (ratio 0.68). This is a
		// genuine backlog of obsolete posts and must be allowed through.
		$this->fake_wpdb( 26596, 18188, array( 11, 22, 33 ) );

		$removed = Tapgoods_Connection::get_instance()->remove_items_not_in_run( 'run-abc', 3 );

		$this->assertSame( 3, $removed );
		$this->assertSame( array( 11, 22, 33 ), $this->deleted );
	}

	public function test_a_fully_stamped_run_cleans_up() {
		$this->fake_wpdb( 100, 100, array( 7 ) );

		$this->assertSame( 1, Tapgoods_Connection::get_instance()->remove_items_not_in_run( 'run-abc', 10 ) );
	}

	public function test_ratio_exactly_at_the_minimum_is_allowed() {
		$this->fake_wpdb( 1000, 500, array( 5 ) );

		$this->assertSame(
			1,
			Tapgoods_Connection::get_instance()->remove_items_not_in_run( 'run-abc', 10 ),
			'The minimum is inclusive; only below it blocks.'
		);
	}

	public function test_ratio_just_below_the_minimum_is_blocked() {
		$this->fake_wpdb( 1000, 499, array( 5 ) );

		$this->assertSame( 0, Tapgoods_Connection::get_instance()->remove_items_not_in_run( 'run-abc', 10 ) );
		$this->assertSame( array(), $this->deleted );
	}

	public function test_an_empty_store_is_not_treated_as_a_failed_run() {
		// Nothing stored means nothing to reconcile; the guard must not divide by
		// zero or latch on a fresh install.
		$this->fake_wpdb( 0, 0, array() );

		$this->assertSame( 0, Tapgoods_Connection::get_instance()->remove_items_not_in_run( 'run-abc', 10 ) );
	}

	public function test_a_blank_token_deletes_nothing_and_asks_nothing() {
		$wpdb = $this->fake_wpdb( 26596, 18188, array( 1, 2, 3 ) );

		$this->assertSame( 0, Tapgoods_Connection::get_instance()->remove_items_not_in_run( '', 10 ) );
		$this->assertSame( array(), $this->deleted );
		$this->assertSame( array(), $wpdb->seen, 'A blank token must not even count.' );
	}
}
