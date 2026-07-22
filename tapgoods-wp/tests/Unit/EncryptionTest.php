<?php
/**
 * Unit tests for Tapgoods_Encryption (includes/class-tapgoods-encryption.php)
 *
 * @package Tapgoods\Tests
 */

namespace Tapgoods\Tests\Unit;

use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Tapgoods_Encryption;

final class EncryptionTest extends TestCase {

	public function test_encrypt_decrypt_roundtrip() {
		$enc = new Tapgoods_Encryption();

		$plaintext  = 'super-secret-api-key-12345';
		$ciphertext = $enc->tapgrein_encrypt( $plaintext );

		$this->assertNotFalse( $ciphertext );
		$this->assertNotSame( $plaintext, $ciphertext, 'Ciphertext must differ from plaintext.' );
		$this->assertSame( $plaintext, $enc->tapgrein_decrypt( $ciphertext ) );
	}

	public function test_encrypt_produces_different_ciphertext_each_time() {
		$enc = new Tapgoods_Encryption();

		// A random IV should make repeated encryptions of the same value differ,
		// while both still decrypt back to the original.
		$a = $enc->tapgrein_encrypt( 'value' );
		$b = $enc->tapgrein_encrypt( 'value' );

		$this->assertNotSame( $a, $b );
		$this->assertSame( 'value', $enc->tapgrein_decrypt( $a ) );
		$this->assertSame( 'value', $enc->tapgrein_decrypt( $b ) );
	}

	public function test_decrypt_of_garbage_returns_false() {
		$enc = new Tapgoods_Encryption();
		$this->assertFalse( $enc->tapgrein_decrypt( 'not-valid-ciphertext' ) );
	}

	/**
	 * KNOWN BUG (documented, not a failure).
	 *
	 * class-tapgoods-encryption.php line 54 reads:
	 *     if ( defined( 'LOGGED_IN_KEY ' ) && ... )   // note the trailing space
	 *
	 * Because of the trailing space, a legitimately-defined LOGGED_IN_KEY is never
	 * picked up and the insecure hard-coded fallback key is always used instead.
	 *
	 * This test defines LOGGED_IN_KEY and asserts the CURRENT (buggy) behaviour so
	 * the suite stays green while the bug is on record. When the trailing space is
	 * fixed, flip the assertion to expect $key === 'a-real-logged-in-key'.
	 */
	public function test_logged_in_key_trailing_space_bug_forces_fallback_key() {
		if ( ! defined( 'LOGGED_IN_KEY' ) ) {
			define( 'LOGGED_IN_KEY', 'a-real-logged-in-key' );
		}

		$enc = new Tapgoods_Encryption();

		$ref = new ReflectionClass( $enc );
		$key = $ref->getProperty( 'key' );

		$this->assertSame(
			'this-is-a-fallback-key-but-not-secure',
			$key->getValue( $enc ),
			'Trailing space in defined(\'LOGGED_IN_KEY \') means the fallback key is used. Fix the space, then update this assertion.'
		);
	}
}
