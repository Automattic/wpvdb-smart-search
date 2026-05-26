<?php
/**
 * Native WordPress search surface tests.
 *
 * @package WPVDB_Smart_Search
 */

declare(strict_types=1);

namespace WPVDB_Smart_Search\Tests\Unit;

use PHPUnit\Framework\TestCase;
use WP_Query;
use WPVDB_Smart_Search\Settings;
use WPVDB_Smart_Search\Surfaces\Native_Search;

/**
 * Tests native WordPress search replacement behavior.
 *
 * @covers \WPVDB_Smart_Search\Surfaces\Native_Search
 */
final class NativeSearchTest extends TestCase {
	/**
	 * Reset shared test state.
	 */
	protected function setUp(): void {
		$GLOBALS['wpvdb_smart_search_test']['is_admin']        = false;
		$GLOBALS['wpvdb_smart_search_test']['options']         = [
			Settings::OPTION_NAME => [
				'native_enabled'  => true,
				'native_mode'     => 'hybrid',
				'native_pool'     => 50,
				'native_fallback' => true,
				'native_types'    => [ 'post', 'page' ],
			],
		];
		$GLOBALS['wpvdb_smart_search_test']['transients']      = [
			Native_Search::EMBEDDINGS_TRANSIENT => '1',
		];
		$GLOBALS['wpvdb_smart_search_test']['posts']           = [];
		$GLOBALS['wpvdb_smart_search_test']['search_post_ids'] = [];
		$GLOBALS['wpvdb_smart_search_test']['search_calls']    = 0;
		$GLOBALS['wpvdb_smart_search_test']['fulltext_ready']  = true;
		$GLOBALS['wpvdb_smart_search_test']['filters']         = [];
		$GLOBALS['wpvdb_smart_search_test']['logs']            = [];
	}

	/**
	 * Test eligible main search queries are marked for native handling.
	 *
	 * @covers \WPVDB_Smart_Search\Surfaces\Native_Search::mark_query
	 */
	public function test_mark_query_flags_eligible_search_query(): void {
		$query = new WP_Query( [ 's' => 'markets' ] );

		Native_Search::mark_query( $query );

		self::assertTrue( (bool) $query->get( Native_Search::QUERY_FLAG ), 'Eligible search queries should be flagged.' );
	}

	/**
	 * Test non default fields requests bypass native handling.
	 *
	 * @covers \WPVDB_Smart_Search\Surfaces\Native_Search::mark_query
	 */
	public function test_mark_query_bypasses_non_default_fields(): void {
		$query = new WP_Query(
			[
				's'      => 'markets',
				'fields' => 'ids',
			]
		);

		Native_Search::mark_query( $query );

		self::assertSame( '', $query->get( Native_Search::QUERY_FLAG ), 'Native search should not alter fields=ids queries.' );
	}

	/**
	 * Test ranked pools are cached before per request visibility filtering.
	 *
	 * @covers \WPVDB_Smart_Search\Surfaces\Native_Search::pre_query
	 */
	public function test_pre_query_caches_pool_and_filters_readable_posts(): void {
		$GLOBALS['wpvdb_smart_search_test']['search_post_ids'] = [ 3, 1, 2 ];
		$GLOBALS['wpvdb_smart_search_test']['posts']           = [
			3 => [
				'post_type'   => 'post',
				'post_status' => 'publish',
				'readable'    => true,
			],
			1 => [
				'post_type'   => 'post',
				'post_status' => 'publish',
				'readable'    => false,
			],
			2 => [
				'post_type'   => 'post',
				'post_status' => 'publish',
				'readable'    => true,
			],
		];
		$query = new WP_Query(
			[
				's'              => 'markets',
				'paged'          => 1,
				'posts_per_page' => 1,
			]
		);
		$query->set( Native_Search::QUERY_FLAG, true );

		$page_one = Native_Search::pre_query( null, $query );

		self::assertIsArray( $page_one, 'Native search should return hydrated posts.' );
		self::assertCount( 1, $page_one, 'Page one should contain one post.' );
		self::assertSame( 3, $page_one[0]->ID, 'Readable results should preserve semantic rank order.' );
		self::assertSame( 2, $query->found_posts, 'Unreadable candidates should not count toward found posts.' );
		self::assertSame( 2, $query->max_num_pages, 'Pagination should be based on readable candidates.' );
		self::assertSame( 1, $GLOBALS['wpvdb_smart_search_test']['search_calls'], 'First page should call the search service.' );

		$page_two_query = new WP_Query(
			[
				's'              => 'markets',
				'paged'          => 2,
				'posts_per_page' => 1,
			]
		);
		$page_two_query->set( Native_Search::QUERY_FLAG, true );

		$page_two = Native_Search::pre_query( null, $page_two_query );

		self::assertIsArray( $page_two, 'Native search should return hydrated posts on cached pages.' );
		self::assertCount( 1, $page_two, 'Page two should contain one post.' );
		self::assertSame( 2, $page_two[0]->ID, 'Cached pools should still hydrate page two in rank order.' );
		self::assertSame( 1, $GLOBALS['wpvdb_smart_search_test']['search_calls'], 'Page two should reuse the cached ranked pool.' );
	}

	/**
	 * Test earlier posts_pre_query filters are respected.
	 *
	 * @covers \WPVDB_Smart_Search\Surfaces\Native_Search::pre_query
	 */
	public function test_pre_query_preserves_existing_posts_from_earlier_filter(): void {
		$existing = [ (object) [ 'ID' => 99 ] ];
		$query    = new WP_Query( [ 's' => 'markets' ] );
		$query->set( Native_Search::QUERY_FLAG, true );

		$posts = Native_Search::pre_query( $existing, $query );

		self::assertSame( $existing, $posts, 'Native search should not replace results supplied by an earlier posts_pre_query filter.' );
		self::assertSame( 0, $GLOBALS['wpvdb_smart_search_test']['search_calls'], 'Existing short-circuit results should not call the search service.' );
	}

	/**
	 * Test late query mutations are revalidated before semantic handling.
	 *
	 * @covers \WPVDB_Smart_Search\Surfaces\Native_Search::pre_query
	 */
	public function test_pre_query_revalidates_late_query_mutations(): void {
		$query = new WP_Query(
			[
				's'      => 'markets',
				'author' => 123,
			]
		);
		$query->set( Native_Search::QUERY_FLAG, true );

		$posts = Native_Search::pre_query( null, $query );

		self::assertNull( $posts, 'Unsupported constraints added after pre_get_posts should fall through to WordPress search.' );
		self::assertSame( 0, $GLOBALS['wpvdb_smart_search_test']['search_calls'], 'Unsupported late query mutations should not call the search service.' );
		self::assertSame( '', $query->get( 'no_found_rows' ), 'Queries that fall through should keep their original pagination behavior.' );
	}

	/**
	 * Test ranker errors fall back when fallback is enabled.
	 *
	 * @covers \WPVDB_Smart_Search\Surfaces\Native_Search::pre_query
	 */
	public function test_pre_query_falls_back_on_search_error_when_enabled(): void {
		$GLOBALS['wpvdb_smart_search_test']['search_post_ids'] = new \WP_Error( 'search_failed', 'Search failed.' );
		$query = new WP_Query( [ 's' => 'markets' ] );
		$query->set( Native_Search::QUERY_FLAG, true );

		$posts = Native_Search::pre_query( null, $query );

		self::assertNull( $posts, 'Search errors should fall through to keyword search when fallback is enabled.' );
		self::assertSame( 1, $GLOBALS['wpvdb_smart_search_test']['search_calls'], 'The search service should be called before the fallback decision.' );
		self::assertSame( '', $query->get( 'no_found_rows' ), 'Fallback queries should keep their original pagination behavior.' );
	}

	/**
	 * Test ranker errors return zero results when fallback is disabled.
	 *
	 * @covers \WPVDB_Smart_Search\Surfaces\Native_Search::pre_query
	 */
	public function test_pre_query_returns_zero_on_search_error_when_fallback_disabled(): void {
		$GLOBALS['wpvdb_smart_search_test']['options'][ Settings::OPTION_NAME ]['native_fallback'] = false;

		$GLOBALS['wpvdb_smart_search_test']['search_post_ids'] = new \WP_Error( 'search_failed', 'Search failed.' );
		$query = new WP_Query( [ 's' => 'markets' ] );
		$query->set( Native_Search::QUERY_FLAG, true );

		$posts = Native_Search::pre_query( null, $query );

		self::assertSame( [], $posts, 'Search errors should return zero results when fallback is disabled.' );
		self::assertTrue( $query->get( 'no_found_rows' ), 'Short-circuited error results should not ask WordPress for SQL found rows.' );
		self::assertSame( 0, $query->found_posts, 'Fallback-disabled search errors should set zero found posts.' );
		self::assertSame( 0, $query->max_num_pages, 'Fallback-disabled search errors should set zero pages.' );
	}

	/**
	 * Test a non-empty semantic pool emptied by visibility does not fall back.
	 *
	 * @covers \WPVDB_Smart_Search\Surfaces\Native_Search::pre_query
	 */
	public function test_pre_query_returns_zero_when_visibility_filters_all_candidates(): void {
		$GLOBALS['wpvdb_smart_search_test']['search_post_ids'] = [ 10 ];
		$GLOBALS['wpvdb_smart_search_test']['posts']           = [
			10 => [
				'post_type'   => 'post',
				'post_status' => 'publish',
				'readable'    => false,
			],
		];
		$query = new WP_Query( [ 's' => 'private match' ] );
		$query->set( Native_Search::QUERY_FLAG, true );

		$posts = Native_Search::pre_query( null, $query );

		self::assertSame( [], $posts, 'Unreadable semantic matches should produce zero visible results without keyword fallback.' );
		self::assertSame( 0, $query->found_posts, 'Found posts should count only readable results.' );
		self::assertSame( 0, $query->max_num_pages, 'No readable results should produce no pages.' );
	}

	/**
	 * Test pagination past the ranked pool boundary returns an empty page.
	 *
	 * @covers \WPVDB_Smart_Search\Surfaces\Native_Search::pre_query
	 */
	public function test_pre_query_returns_empty_page_past_pool_boundary(): void {
		$GLOBALS['wpvdb_smart_search_test']['search_post_ids'] = [ 1, 2 ];
		$GLOBALS['wpvdb_smart_search_test']['posts']           = [
			1 => [
				'post_type'   => 'post',
				'post_status' => 'publish',
				'readable'    => true,
			],
			2 => [
				'post_type'   => 'post',
				'post_status' => 'publish',
				'readable'    => true,
			],
		];
		$query = new WP_Query(
			[
				's'              => 'markets',
				'paged'          => 3,
				'posts_per_page' => 1,
			]
		);
		$query->set( Native_Search::QUERY_FLAG, true );

		$posts = Native_Search::pre_query( null, $query );

		self::assertSame( [], $posts, 'Pages beyond the readable semantic pool should be empty.' );
		self::assertSame( 2, $query->found_posts, 'Found posts should remain capped to the readable semantic pool.' );
		self::assertSame( 2, $query->max_num_pages, 'Max pages should remain capped to the readable semantic pool.' );
	}

	/**
	 * Test cached pools are reused across status-specific visibility passes.
	 *
	 * @covers \WPVDB_Smart_Search\Surfaces\Native_Search::pre_query
	 */
	public function test_pre_query_reuses_cached_pool_across_status_filters(): void {
		$GLOBALS['wpvdb_smart_search_test']['search_post_ids'] = [ 1, 2 ];
		$GLOBALS['wpvdb_smart_search_test']['posts']           = [
			1 => [
				'post_type'   => 'post',
				'post_status' => 'publish',
				'readable'    => true,
			],
			2 => [
				'post_type'   => 'post',
				'post_status' => 'private',
				'readable'    => true,
			],
		];
		$publish_query = new WP_Query(
			[
				's'           => 'markets',
				'post_status' => 'publish',
			]
		);
		$publish_query->set( Native_Search::QUERY_FLAG, true );
		Native_Search::pre_query( null, $publish_query );

		$private_query = new WP_Query(
			[
				's'           => 'markets',
				'post_status' => 'private',
			]
		);
		$private_query->set( Native_Search::QUERY_FLAG, true );
		$posts = Native_Search::pre_query( null, $private_query );

		self::assertIsArray( $posts, 'Status-specific passes should hydrate from the cached pre-visibility pool.' );
		self::assertCount( 1, $posts, 'Private status filtering should happen after cache lookup.' );
		self::assertSame( 2, $posts[0]->ID, 'The private query should see the private readable result.' );
		self::assertSame( 1, $GLOBALS['wpvdb_smart_search_test']['search_calls'], 'Post status should not fragment the cached semantic pool.' );
	}
}
