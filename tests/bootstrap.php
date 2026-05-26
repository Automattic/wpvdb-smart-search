<?php
declare(strict_types=1);

namespace {
	define( 'ABSPATH', __DIR__ . '/' );
	define( 'WPVDB_SMART_SEARCH_DIR', dirname( __DIR__ ) );
	define( 'WPVDB_SMART_SEARCH_URL', 'https://example.test/wp-content/plugins/wpvdb-smart-search/' );
	define( 'WPVDB_SMART_SEARCH_FILE', dirname( __DIR__ ) . '/wpvdb-smart-search.php' );
	define( 'WPVDB_SMART_SEARCH_VERSION', '0.1.1' );
	define( 'MINUTE_IN_SECONDS', 60 );
	define( 'HOUR_IN_SECONDS', 3600 );

	$GLOBALS['wpvdb_smart_search_test'] = [
		'rewrite_rules'     => [],
		'removed_actions'   => [],
		'flush_count'       => 0,
		'query_vars'        => [],
		'is_admin'          => false,
		'options'           => [],
		'transients'        => [],
		'posts'             => [],
		'search_post_ids'   => [],
		'search_calls'      => 0,
		'fulltext_ready'    => true,
		'public_post_types' => [ 'post', 'page', 'book' ],
		'filters'           => [],
		'logs'              => [],
	];

	if ( ! class_exists( 'WP_Rewrite' ) ) {
		class WP_Rewrite {
			/**
			 * @var array<string, string>
			 */
			public array $extra_rules_top = [];
		}
	}

	if ( ! class_exists( 'WP_Query' ) ) {
		class WP_Query {
			/**
			 * @param array<string, mixed> $query_vars Query vars.
			 */
			public function __construct(
				private array $query_vars = [],
				private readonly bool $is_search = true,
				private readonly bool $is_main_query = true
			) {}

			public int $found_posts = 0;
			public int $max_num_pages = 0;

			public function get( string $key ): mixed {
				return $this->query_vars[ $key ] ?? '';
			}

			public function set( string $key, mixed $value ): void {
				$this->query_vars[ $key ] = $value;
			}

			public function is_search(): bool {
				return $this->is_search;
			}

			public function is_main_query(): bool {
				return $this->is_main_query;
			}

			public function is_feed(): bool {
				return ! empty( $this->query_vars['feed'] );
			}

			public function is_preview(): bool {
				return ! empty( $this->query_vars['preview'] );
			}
		}
	}

	if ( ! class_exists( 'WP_Error' ) ) {
		class WP_Error {
			public function __construct(
				private readonly string $code = '',
				private readonly string $message = ''
			) {}

			public function get_error_code(): string {
				return $this->code;
			}

			public function get_error_message(): string {
				return $this->message;
			}
		}
	}

	$GLOBALS['wp_rewrite'] = new \WP_Rewrite();

	function __( string $text, string $domain = 'default' ): string {
		unset( $domain );
		return $text;
	}

	function esc_html__( string $text, string $domain = 'default' ): string {
		unset( $domain );
		return $text;
	}

	function add_action( string $hook, callable $callback, int $priority = 10 ): void {
		unset( $hook, $callback, $priority );
	}

	function add_filter( string $hook, callable $callback, int $priority = 10 ): void {
		unset( $hook, $callback, $priority );
	}

	function apply_filters( string $hook, mixed $value, mixed ...$args ): mixed {
		$GLOBALS['wpvdb_smart_search_test']['filters'][] = [ $hook, $value, $args ];
		return $value;
	}

	function remove_action( string $hook, callable $callback ): void {
		$GLOBALS['wpvdb_smart_search_test']['removed_actions'][] = [ $hook, $callback ];
	}

	function add_rewrite_rule( string $regex, string $query, string $after = 'bottom' ): void {
		$GLOBALS['wpvdb_smart_search_test']['rewrite_rules'][] = [ $regex, $query, $after ];
		$GLOBALS['wp_rewrite']->extra_rules_top[ $regex ]      = $query;
	}

	function flush_rewrite_rules(): void {
		$GLOBALS['wpvdb_smart_search_test']['flush_count']++;
	}

	function get_query_var( string $name ): mixed {
		return $GLOBALS['wpvdb_smart_search_test']['query_vars'][ $name ] ?? '';
	}

	function is_admin(): bool {
		return (bool) $GLOBALS['wpvdb_smart_search_test']['is_admin'];
	}

	function is_wp_error( mixed $value ): bool {
		return $value instanceof \WP_Error;
	}

	function absint( mixed $value ): int {
		return max( 0, (int) $value );
	}

	function get_current_blog_id(): int {
		return 1;
	}

	/**
	 * @return array<int|string, mixed>
	 */
	function get_post_types( array $args = [], string $output = 'names' ): array {
		unset( $args );
		$types = $GLOBALS['wpvdb_smart_search_test']['public_post_types'];
		if ( 'objects' === $output ) {
			$objects = [];
			foreach ( $types as $type ) {
				$objects[ $type ] = (object) [
					'labels' => (object) [
						'singular_name' => ucfirst( (string) $type ),
					],
				];
			}
			return $objects;
		}

		return $types;
	}

	function wp_json_encode( mixed $value, int $flags = 0, int $depth = 512 ): string|false {
		return json_encode( $value, $flags, $depth );
	}

	function get_option( string $name, mixed $default = false ): mixed {
		return $GLOBALS['wpvdb_smart_search_test']['options'][ $name ] ?? $default;
	}

	function update_option( string $name, mixed $value, mixed $autoload = null ): bool {
		unset( $autoload );
		$GLOBALS['wpvdb_smart_search_test']['options'][ $name ] = $value;
		return true;
	}

	function get_transient( string $name ): mixed {
		return $GLOBALS['wpvdb_smart_search_test']['transients'][ $name ] ?? false;
	}

	function set_transient( string $name, mixed $value, int $expiration = 0 ): bool {
		unset( $expiration );
		$GLOBALS['wpvdb_smart_search_test']['transients'][ $name ] = $value;
		return true;
	}

	/**
	 * @param array<string, mixed> $args Query args.
	 * @return list<int|object>
	 */
	function get_posts( array $args ): array {
		$include = array_map( 'intval', (array) ( $args['include'] ?? [] ) );
		$fields  = $args['fields'] ?? '';
		$out     = [];

		foreach ( $include as $post_id ) {
			$post = $GLOBALS['wpvdb_smart_search_test']['posts'][ $post_id ] ?? null;
			if ( ! is_array( $post ) ) {
				continue;
			}

			$post_type_arg = $args['post_type'] ?? [ 'post' ];
			$post_types    = 'any' === $post_type_arg ? [ (string) ( $post['post_type'] ?? 'post' ) ] : (array) $post_type_arg;
			if ( ! in_array( (string) ( $post['post_type'] ?? 'post' ), $post_types, true ) ) {
				continue;
			}

			$status_arg = $args['post_status'] ?? [ 'publish' ];
			$statuses   = 'any' === $status_arg ? [ (string) ( $post['post_status'] ?? 'publish' ) ] : (array) $status_arg;
			if ( ! in_array( (string) ( $post['post_status'] ?? 'publish' ), $statuses, true ) ) {
				continue;
			}

			if ( 'readable' === ( $args['perm'] ?? '' ) && empty( $post['readable'] ) ) {
				continue;
			}

			$out[] = 'ids' === $fields ? $post_id : (object) [ 'ID' => $post_id ];
		}

		return $out;
	}

	function sanitize_text_field( mixed $value ): string {
		return trim( strip_tags( (string) $value ) );
	}

	function sanitize_key( mixed $key ): string {
		return strtolower( preg_replace( '/[^a-zA-Z0-9_\-]/', '', (string) $key ) ?? '' );
	}

	function wp_unslash( mixed $value ): mixed {
		return $value;
	}
}

namespace WPVDB_Search {
	class Search {
		/**
		 * @param array<string, mixed> $args Search args.
		 * @return list<int>|\WP_Error
		 */
		public static function post_ids( array $args, int $pool = 50 ): array|\WP_Error {
			unset( $args, $pool );
			$GLOBALS['wpvdb_smart_search_test']['search_calls']++;
			return $GLOBALS['wpvdb_smart_search_test']['search_post_ids'];
		}
	}

	class Schema {
		public static function has_fulltext_index(): bool {
			return (bool) $GLOBALS['wpvdb_smart_search_test']['fulltext_ready'];
		}
	}
}

namespace WPVDB {
	class Logger {
		public static function warning( string $message ): void {
			$GLOBALS['wpvdb_smart_search_test']['logs'][] = $message;
		}
	}
}

namespace {
	require_once dirname( __DIR__ ) . '/includes/class-template.php';
	require_once dirname( __DIR__ ) . '/includes/class-settings.php';
	require_once dirname( __DIR__ ) . '/includes/surfaces/class-native-search.php';
}
