<?php
/**
 * Unit tests for the pure helpers the tag route depends on (WPB-166).
 *
 * Each of these replaces a piece of code that silently produced an empty grid:
 *
 * - tapgrein_tag_slug_variants() replaces "always prepend tag-", which could
 *   never match a tag whose slug genuinely lacked the prefix.
 * - tapgrein_sanitize_slug_list() replaces esc_attr() on strings that are
 *   shortcode SOURCE rather than HTML attributes. esc_attr() turned the quotes
 *   into &quot;, the shortcode parser read the entity as part of the value, and
 *   the grid went looking for a term slug of tag-&quot;tag-champagne&quot;.
 *   It also replaces sanitize_text_field(), which strips percent octets and so
 *   erased a non-latin slug outright.
 * - tapgrein_query_arg_slug() keeps an already-percent-encoded slug from being
 *   encoded a second time on its way into the redirect URL.
 *
 * tapgrein_content_has_unscoped_inventory(), which keeps a curated,
 * category-scoped page from being mistaken for the shop, is exercised in
 * TagArchiveRoutingTest instead: it leans on WordPress's own shortcode parser
 * (get_shortcode_regex / shortcode_parse_atts), and stubbing those here would
 * test the stub rather than the parser the plugin actually runs against.
 *
 * @package Tapgoods\Tests
 */

namespace Tapgoods\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

final class TagSlugHelpersTest extends TestCase {

	/** sanitize_title( 'Japanese' ) written in kana, as WordPress stores it. */
	const ENCODED_SLUG = '%e6%97%a5%e6%9c%ac%e8%aa%9e';

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		// tapgoods-core-functions.php registers hooks at include time.
		Functions\when( 'add_action' )->justReturn( true );
		Functions\when( 'add_filter' )->justReturn( true );

		require_once ABSPATH . 'includes/tapgoods-core-functions.php';
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	// -- tapgrein_tag_slug_variants -------------------------------------------

	public function test_prefixed_slug_also_offers_the_stripped_form() {
		$this->assertSame(
			array( 'tag-champagne', 'champagne' ),
			tapgrein_tag_slug_variants( 'tag-champagne' )
		);
	}

	public function test_unprefixed_slug_also_offers_the_prefixed_form() {
		// The case the old code could not express: a tag whose slug never had the
		// sync's 'tag-' prefix. Prepending unconditionally produced
		// 'tag-champagne' and matched nothing.
		$this->assertSame(
			array( 'champagne', 'tag-champagne' ),
			tapgrein_tag_slug_variants( 'champagne' )
		);
	}

	public function test_a_list_is_expanded_and_deduplicated() {
		$variants = tapgrein_tag_slug_variants( array( 'tag-champagne', 'champagne', 'linens' ) );

		$this->assertSame( array( 'tag-champagne', 'champagne', 'linens', 'tag-linens' ), $variants );
	}

	public function test_empty_input_yields_no_candidates() {
		// An empty candidate list in a tax query would match every term, so this
		// must stay empty rather than degrade to array( '' ) or array( 'tag-' ).
		$this->assertSame( array(), tapgrein_tag_slug_variants( array( '', '   ' ) ) );
		$this->assertSame( array(), tapgrein_tag_slug_variants( '' ) );
	}

	// -- tapgrein_sanitize_slug_list ------------------------------------------

	public function test_sanitizer_keeps_an_ordinary_slug_list_intact() {
		$this->assertSame( 'tag-champagne,linens', tapgrein_sanitize_slug_list( 'tag-champagne,linens' ) );
	}

	public function test_sanitizer_keeps_only_what_a_slug_list_is_made_of() {
		// An allowlist, so safety does not depend on having guessed every
		// character that could hurt at whichever sink the value reaches next.
		$this->assertSame(
			'tag-champagneshow_pricingfalse',
			tapgrein_sanitize_slug_list( '"tag-champagne" show_pricing="false"]["' )
		);
		$this->assertSame( 'tag-xonloadalert1', tapgrein_sanitize_slug_list( "tag-x' onload='alert(1)" ) );
		$this->assertSame( 'tag-xscript', tapgrein_sanitize_slug_list( 'tag-x<script>' ) );
		// Curly quotes, which page builders insert, go as well.
		$this->assertSame( 'tag-x', tapgrein_sanitize_slug_list( '“tag-x”' ) );
	}

	public function test_sanitizer_survives_the_entity_esc_attr_used_to_produce() {
		// The literal WPB-166 payload: esc_attr( 'tags="tag-champagne"' ) reached
		// the shortcode parser as tags=&quot;tag-champagne&quot;.
		$this->assertSame( 'quottag-champagnequot', tapgrein_sanitize_slug_list( '&quot;tag-champagne&quot;' ) );
	}

	public function test_the_slug_list_is_capped() {
		// Unbounded IN(): ?tags=a,b,c...x5000, or a POST to the logged-out
		// tg_search_grid action, became a tax_query carrying thousands of slugs
		// -- twice that once the variants helper had doubled it. Capped here, so
		// every caller inherits the bound instead of having to remember it.
		$capped = explode( ',', tapgrein_sanitize_slug_list( $this->many_slugs( 5000 ) ) );

		$this->assertCount( TAPGOODS_MAX_SLUG_FILTER, $capped );
		$this->assertSame( 'tag-1', $capped[0], 'Kept from the front, so a short list is untouched.' );
	}

	public function test_the_cap_survives_the_variants_helper() {
		// The helper doubles the list, so what reaches the query is at most twice
		// the cap however long the input was.
		$variants = tapgrein_tag_slug_variants(
			explode( ',', tapgrein_sanitize_slug_list( $this->many_slugs( 5000 ) ) )
		);

		$this->assertCount( TAPGOODS_MAX_SLUG_FILTER * 2, $variants );
	}

	public function test_empty_and_comma_only_input_is_empty() {
		$this->assertSame( '', tapgrein_sanitize_slug_list( ',,,' ) );
		$this->assertSame( '', tapgrein_sanitize_slug_list( '' ) );
		$this->assertSame( 'a,b', tapgrein_sanitize_slug_list( ',a,,b,' ), 'Blanks between commas are dropped.' );
	}

	/** A comma-separated slug list of the given length. */
	private function many_slugs( int $count ): string {
		$slugs = array();

		for ( $i = 1; $i <= $count; $i++ ) {
			$slugs[] = 'tag-' . $i;
		}

		return implode( ',', $slugs );
	}

	public function test_sanitizer_keeps_a_percent_encoded_non_latin_slug_whole() {
		// The rule that matters for a non-latin catalog. An [A-Za-z0-9-]
		// allowlist ate the % and left a slug that matches no term, so the
		// filter resolved to "everything".
		$this->assertSame( self::ENCODED_SLUG, tapgrein_sanitize_slug_list( self::ENCODED_SLUG ) );
	}

	public function test_sanitizer_keeps_a_raw_utf8_slug_whole() {
		// A visitor may also arrive with the slug un-encoded in the URL; the tax
		// query runs it through sanitize_title() and resolves the same term.
		$raw = "\xe6\x97\xa5\xe6\x9c\xac\xe8\xaa\x9e";

		$this->assertSame( $raw, tapgrein_sanitize_slug_list( $raw ) );
	}

	public function test_sanitizer_returns_a_string_for_invalid_utf8() {
		// Bytewise on purpose, so a broken byte sequence cannot make
		// preg_replace() answer null and turn the filter into "no filter".
		$this->assertSame( "tag-\xff\xfe", tapgrein_sanitize_slug_list( "tag-\xff\xfe" ) );
	}

	// -- tapgrein_query_arg_slug ----------------------------------------------

	public function test_an_already_encoded_slug_is_not_encoded_again() {
		// rawurlencode() here produced ?tags=%25e6%2597%25a5..., which matches no
		// term, so every non-latin tag landed on an empty grid.
		$this->assertSame( self::ENCODED_SLUG, tapgrein_query_arg_slug( self::ENCODED_SLUG ) );
	}

	public function test_a_plain_slug_passes_through_unchanged() {
		$this->assertSame( 'tag-champagne', tapgrein_query_arg_slug( 'tag-champagne' ) );
	}

	public function test_a_slug_holding_a_reserved_character_is_encoded() {
		$this->assertSame( 'tag%20x', tapgrein_query_arg_slug( 'tag x' ) );
	}

}
