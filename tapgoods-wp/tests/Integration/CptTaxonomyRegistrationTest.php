<?php
/**
 * Integration: custom post type + taxonomy registration.
 *
 * Verifies the things the isolated unit layer cannot check faithfully, because
 * they depend on WordPress actually running register_post_type()/
 * register_taxonomy() on the `init` hook.
 *
 * @package Tapgoods\Tests\Integration
 */

namespace Tapgoods\Tests\Integration;

use WP_UnitTestCase;

final class CptTaxonomyRegistrationTest extends WP_UnitTestCase {

	public function test_tg_inventory_post_type_is_registered() {
		$this->assertTrue( post_type_exists( 'tg_inventory' ) );

		$pt = get_post_type_object( 'tg_inventory' );
		$this->assertNotNull( $pt );
		$this->assertTrue( (bool) $pt->public );
		$this->assertTrue( (bool) $pt->has_archive );
	}

	public function test_inventory_rewrite_slug_defaults_to_products() {
		$pt = get_post_type_object( 'tg_inventory' );
		$this->assertIsArray( $pt->rewrite );
		$this->assertSame( 'products', $pt->rewrite['slug'] );
	}

	public function test_bundle_and_accessory_are_not_real_post_types() {
		// CLAUDE.md documents that tg_bundle / tg_accessory are only taxonomy
		// object-types, never registered as post types.
		$this->assertFalse( post_type_exists( 'tg_bundle' ) );
		$this->assertFalse( post_type_exists( 'tg_accessory' ) );
	}

	public function test_core_taxonomies_are_registered() {
		$this->assertTrue( taxonomy_exists( 'tg_category' ) );
		$this->assertTrue( taxonomy_exists( 'tg_tags' ) );
		$this->assertTrue( taxonomy_exists( 'tg_location' ) );
	}

	public function test_category_taxonomy_is_attached_to_inventory() {
		$tax = get_taxonomy( 'tg_category' );
		$this->assertContains( 'tg_inventory', $tax->object_type );
		$this->assertTrue( (bool) $tax->hierarchical );
		$this->assertSame( 'categories', $tax->rewrite['slug'] );
	}

	public function test_tags_taxonomy_is_non_hierarchical() {
		$tax = get_taxonomy( 'tg_tags' );
		$this->assertContains( 'tg_inventory', $tax->object_type );
		$this->assertFalse( (bool) $tax->hierarchical );
	}

	public function test_location_taxonomy_is_private() {
		$tax = get_taxonomy( 'tg_location' );
		$this->assertFalse( (bool) $tax->public );
	}
}
