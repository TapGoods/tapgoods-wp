<?php
/**
 * Integration: every write to the saved API key reaches the sync log.
 *
 * The unit tests pin what each line says; this pins the wiring. The lines come
 * from WordPress's own option hooks (add_option_tg_key, update_option_tg_key,
 * delete_option), so the Connect button, Reset to Default and WP-CLI are all
 * covered without each of them having to remember to log.
 *
 * @package Tapgoods\Tests\Integration
 */

namespace Tapgoods\Tests\Integration;

use ReflectionClass;
use Tapgoods_Connection;
use Tapgoods_Encryption;
use Tapgoods_Sync_Log;
use WP_UnitTestCase;

final class KeyChangeLogTest extends WP_UnitTestCase {

	protected function setUp(): void {
		parent::setUp();
		$ref  = new ReflectionClass( Tapgoods_Sync_Log::class );
		$prop = $ref->getProperty( 'instance' );
		$prop->setAccessible( true );
		$prop->setValue( null, null );

		// Start from no saved key, then from an empty log.
		delete_option( 'tg_key' );
		$path = Tapgoods_Sync_Log::get_instance()->get_file_path();
		if ( '' !== $path && file_exists( $path ) ) {
			file_put_contents( $path, '' );
		}
	}

	private function log_contents(): string {
		$contents = Tapgoods_Sync_Log::get_instance()->read_all();
		return ( false === $contents ) ? '' : $contents;
	}

	public function test_adding_resaving_changing_and_removing_the_key_are_each_logged() {
		$user = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user );
		$encryption = new Tapgoods_Encryption();

		// What the Connect button does, three times, then what Reset to Default does.
		update_option( 'tg_key', $encryption->tapgrein_encrypt( 'integration-key-one' ) );
		update_option( 'tg_key', $encryption->tapgrein_encrypt( 'integration-key-one' ) );
		update_option( 'tg_key', $encryption->tapgrein_encrypt( 'integration-key-two' ) );
		delete_option( 'tg_key' );

		$fp1 = Tapgoods_Connection::key_fingerprint( 'integration-key-one' );
		$fp2 = Tapgoods_Connection::key_fingerprint( 'integration-key-two' );
		$log = $this->log_contents();

		$this->assertMatchesRegularExpression(
			"/INFO key\.added fp=$fp1 user=$user\n"
			. ".*INFO key\.resaved fp=$fp1 user=$user\n"
			. ".*INFO key\.changed fp=$fp2 previous_fp=$fp1 user=$user\n"
			. ".*INFO key\.removed previous_fp=$fp2 user=$user\n/",
			$log
		);
		$this->assertStringNotContainsString( 'integration-key', $log, 'Never the key itself.' );
	}
}
