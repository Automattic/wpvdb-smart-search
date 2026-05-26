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
		$GLOBALS['wpvdb_smart_search_test']['filters']         = [];
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
}
