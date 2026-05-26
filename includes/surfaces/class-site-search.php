<?php
/**
 * Site search replacement surface.
 *
 * @package WPVDB_Smart_Search
 */

namespace WPVDB_Smart_Search\Surfaces;

use WPVDB_Smart_Search\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Replaces eligible front-end `?s=` main queries with wpvdb semantic results.
 */
class Site_Search {
	const QUERY_FLAG           = 'wpvdb_site_search';
	const CACHE_PREFIX         = 'wpvdb_site_search_';
	const EMBEDDINGS_TRANSIENT = 'wpvdb_site_search_has_embeddings';

	/**
	 * Register hooks.
	 */
	public static function init(): void {
		add_action( 'pre_get_posts', [ __CLASS__, 'mark_query' ] );
		add_filter( 'posts_pre_query', [ __CLASS__, 'pre_query' ], 10, 2 );
	}

	/**
	 * Mark eligible main search queries for semantic handling.
	 *
	 * @param \WP_Query $query Query object.
	 */
	public static function mark_query( \WP_Query $query ): void {
		if ( ! self::should_handle( $query ) ) {
			return;
		}

		$query->set( self::QUERY_FLAG, true ); // phpcs:ignore WordPressVIPMinimum.Hooks.PreGetPosts.PreGetPosts -- should_handle() already restricts to the front-end main query.
	}

	/**
	 * Short-circuit eligible search queries.
	 *
	 * @param mixed     $posts Previous posts value.
	 * @param \WP_Query $query Query object.
	 * @return mixed
	 */
	public static function pre_query( mixed $posts, \WP_Query $query ): mixed {
		if ( null !== $posts ) {
			return $posts;
		}

		if ( ! $query->get( self::QUERY_FLAG ) ) {
			return $posts;
		}

		if ( ! self::should_handle( $query ) ) {
			return null;
		}

		$search = trim( (string) $query->get( 's' ) );
		if ( '' === $search ) {
			return null;
		}

		$pool       = Settings::site_search_pool();
		$post_types = self::query_post_types( $query );
		$statuses   = self::query_post_status( $query );
		$cache_key  = self::cache_key( $search, $post_types, $pool );
		$candidates = get_transient( $cache_key );

		if ( ! is_array( $candidates ) ) {
			self::maybe_log_fulltext_mode_without_fulltext();
			$candidates = \WPVDB_Search\Search::post_ids(
				[
					'query'     => $search,
					'mode'      => Settings::site_search_mode(),
					'post_type' => $post_types,
				],
				$pool
			);

			if ( is_wp_error( $candidates ) ) {
				return self::empty_ranker_result( $query );
			}

			$candidates = array_values( array_filter( array_map( 'absint', $candidates ) ) );
			if ( empty( $candidates ) ) {
				return self::empty_ranker_result( $query );
			}

			set_transient( $cache_key, $candidates, 5 * MINUTE_IN_SECONDS );
		}

		$query->set( 'no_found_rows', true );

		$readable = self::readable_ids( $candidates, $post_types, $statuses );
		self::set_pagination( $query, count( $readable ) );

		if ( empty( $readable ) ) {
			return [];
		}

		$page     = max( 1, (int) $query->get( 'paged' ) );
		$per_page = self::posts_per_page( $query );
		$ids      = array_slice( $readable, ( $page - 1 ) * $per_page, $per_page );

		if ( empty( $ids ) ) {
			return [];
		}

		return get_posts(
			[
				'include'                => $ids,
				'post_type'              => self::wp_query_arg( $post_types ),
				'post_status'            => self::wp_query_arg( $statuses ),
				'numberposts'            => count( $ids ),
				'orderby'                => 'post__in',
				'perm'                   => 'readable',
				'no_found_rows'          => true,
				'update_post_term_cache' => false,
				'update_post_meta_cache' => false,
			]
		);
	}

	/**
	 * Whether a query should be handled by semantic site search.
	 *
	 * @param \WP_Query $query Query object.
	 */
	private static function should_handle( \WP_Query $query ): bool {
		if (
			is_admin()
			|| ! Settings::site_search_enabled()
			|| ! class_exists( '\WPVDB_Search\Search' )
			|| ! method_exists( '\WPVDB_Search\Search', 'post_ids' )
		) {
			return false;
		}

		if ( ! $query->is_search() || ! $query->is_main_query() ) {
			return false;
		}

		if ( '' === trim( (string) $query->get( 's' ) ) ) {
			return false;
		}

		if ( ( defined( 'REST_REQUEST' ) && REST_REQUEST ) || $query->is_feed() || $query->is_preview() ) {
			return false;
		}

		$fields = $query->get( 'fields' );
		if ( is_array( $fields ) || ( is_scalar( $fields ) && '' !== (string) $fields && 'all' !== (string) $fields ) ) {
			return false;
		}

		if ( defined( 'ICL_LANGUAGE_CODE' ) || function_exists( 'pll_current_language' ) ) {
			return false;
		}

		if ( self::has_unsupported_constraints( $query ) || ! self::has_embeddings() ) {
			return false;
		}

		return (bool) apply_filters( 'wpvdb_should_handle_site_search', true, $query );
	}

	/**
	 * Whether query vars include constraints site search cannot honor yet.
	 *
	 * @param \WP_Query $query Query object.
	 */
	private static function has_unsupported_constraints( \WP_Query $query ): bool {
		if ( $query->get( 'nopaging' ) || -1 === (int) $query->get( 'posts_per_page' ) ) {
			return true;
		}

		$unsupported = [
			'attachment_id',
			'author',
			'author__in',
			'author__not_in',
			'author_name',
			'cat',
			'category__and',
			'category__in',
			'category__not_in',
			'category_name',
			'date_query',
			'day',
			'exact',
			'has_password',
			'hour',
			'm',
			'meta_query',
			'minute',
			'monthnum',
			'name',
			'offset',
			'p',
			'page_id',
			'pagename',
			'post__in',
			'post__not_in',
			'post_name__in',
			'post_parent',
			'post_parent__in',
			'post_parent__not_in',
			'post_password',
			'search_columns',
			'second',
			'sentence',
			'tag',
			'tag__and',
			'tag__in',
			'tag__not_in',
			'tag_id',
			'tag_slug__and',
			'tag_slug__in',
			'tax_query',
			'w',
			'year',
		];

		foreach ( $unsupported as $key ) {
			if ( ! empty( $query->get( $key ) ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Whether the embeddings table has at least one row.
	 */
	private static function has_embeddings(): bool {
		$cached = get_transient( self::EMBEDDINGS_TRANSIENT );
		if ( false !== $cached ) {
			return (bool) $cached;
		}

		global $wpdb;
		$table = $wpdb->prefix . 'wpvdb_embeddings';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Fast table-existence probe cached below.
		$table_exists = $table === $wpdb->get_var(
			$wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) )
		);

		if ( ! $table_exists ) {
			set_transient( self::EMBEDDINGS_TRANSIENT, '0', 5 * MINUTE_IN_SECONDS );
			return false;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Trusted wpdb-prefixed table name; fast row-existence probe cached above.
		$has_rows = (bool) $wpdb->get_var( "SELECT id FROM {$wpdb->prefix}wpvdb_embeddings LIMIT 1" );
		set_transient( self::EMBEDDINGS_TRANSIENT, $has_rows ? '1' : '0', 5 * MINUTE_IN_SECONDS );

		return $has_rows;
	}

	/**
	 * Return post types for the query.
	 *
	 * @param \WP_Query $query Query object.
	 * @return list<string>
	 */
	private static function query_post_types( \WP_Query $query ): array {
		$post_type = $query->get( 'post_type' );
		if ( empty( $post_type ) ) {
			return Settings::site_search_post_types();
		}

		if ( 'any' === $post_type ) {
			return [ 'any' ];
		}

		$types = is_array( $post_type ) ? $post_type : [ $post_type ];
		$types = array_values( array_filter( array_map( 'sanitize_key', $types ) ) );

		return empty( $types ) ? Settings::site_search_post_types() : $types;
	}

	/**
	 * Return post statuses for the query.
	 *
	 * @param \WP_Query $query Query object.
	 * @return list<string>
	 */
	private static function query_post_status( \WP_Query $query ): array {
		$post_status = $query->get( 'post_status' );
		if ( empty( $post_status ) ) {
			return [ 'publish' ];
		}

		$statuses = is_array( $post_status ) ? $post_status : [ $post_status ];
		$statuses = array_values( array_filter( array_map( 'sanitize_key', $statuses ) ) );

		return empty( $statuses ) ? [ 'publish' ] : $statuses;
	}

	/**
	 * Build the ranked pool transient key.
	 *
	 * @param string $search     Search query.
	 * @param array  $post_types Post types.
	 * @param int    $pool       Pool size.
	 * @phpstan-param list<string> $post_types
	 */
	private static function cache_key( string $search, array $post_types, int $pool ): string {
		sort( $post_types );
		$context = [
			'blog_id'    => get_current_blog_id(),
			'mode'       => Settings::site_search_mode(),
			'pool'       => $pool,
			'post_types' => $post_types,
			'search'     => $search,
			'version'    => Settings::site_search_cache_version(),
		];
		$json    = wp_json_encode( $context );
		$hash    = hash( 'sha256', is_string( $json ) ? $json : $search );

		return self::CACHE_PREFIX . $hash;
	}

	/**
	 * Return candidate IDs readable by the current request.
	 *
	 * @param array $candidate_ids Ranked candidate IDs.
	 * @param array $post_types    Query post types.
	 * @param array $statuses      Query post statuses.
	 * @phpstan-param list<int> $candidate_ids
	 * @phpstan-param list<string> $post_types
	 * @phpstan-param list<string> $statuses
	 * @return list<int>
	 */
	private static function readable_ids( array $candidate_ids, array $post_types, array $statuses ): array {
		$ids = get_posts(
			[
				'include'                => $candidate_ids,
				'post_type'              => self::wp_query_arg( $post_types ),
				'post_status'            => self::wp_query_arg( $statuses ),
				'numberposts'            => count( $candidate_ids ),
				'orderby'                => 'post__in',
				'fields'                 => 'ids',
				'perm'                   => 'readable',
				'no_found_rows'          => true,
				'update_post_term_cache' => false,
				'update_post_meta_cache' => false,
			]
		);

		return array_values( array_filter( array_map( 'absint', (array) $ids ) ) );
	}

	/**
	 * Return a WP_Query-compatible arg for list values that may include "any".
	 *
	 * @param array $values Query values.
	 * @phpstan-param list<string> $values
	 * @return list<string>|string
	 */
	private static function wp_query_arg( array $values ): array|string {
		return in_array( 'any', $values, true ) ? 'any' : $values;
	}

	/**
	 * Return post count per page for the query.
	 *
	 * @param \WP_Query $query Query object.
	 */
	private static function posts_per_page( \WP_Query $query ): int {
		$per_page = (int) $query->get( 'posts_per_page' );
		if ( $per_page <= 0 ) {
			$per_page = (int) get_option( 'posts_per_page', 10 );
		}

		return max( 1, $per_page );
	}

	/**
	 * Set pagination fields expected by WP_Query consumers.
	 *
	 * @param \WP_Query $query Query object.
	 * @param int       $found Found post count.
	 */
	private static function set_pagination( \WP_Query $query, int $found ): void {
		$query->found_posts   = $found;
		$query->max_num_pages = (int) ceil( $found / self::posts_per_page( $query ) );
	}

	/**
	 * Handle ranker-empty results.
	 *
	 * @param \WP_Query $query Query object.
	 * @return array<int, \WP_Post>|null
	 */
	private static function empty_ranker_result( \WP_Query $query ): ?array {
		if ( Settings::site_search_fallback_enabled() ) {
			return null;
		}

		$query->set( 'no_found_rows', true );
		self::set_pagination( $query, 0 );

		return [];
	}

	/**
	 * Log a throttled diagnostic when a sparse-capable mode runs without FULLTEXT.
	 */
	private static function maybe_log_fulltext_mode_without_fulltext(): void {
		if ( ! in_array( Settings::site_search_mode(), [ 'hybrid', 'sparse' ], true ) || ! class_exists( '\WPVDB_Search\Schema' ) || \WPVDB_Search\Schema::has_fulltext_index() ) {
			return;
		}

		if ( get_transient( 'wpvdb_site_search_fulltext_warning' ) ) {
			return;
		}

		set_transient( 'wpvdb_site_search_fulltext_warning', '1', HOUR_IN_SECONDS );

		if ( class_exists( '\WPVDB\Logger' ) ) {
			\WPVDB\Logger::warning( 'WPVDB Smart Search site search is running a sparse-capable mode without a FULLTEXT index; sparse ranking is unavailable until the index is ready.' );
		}
	}
}
