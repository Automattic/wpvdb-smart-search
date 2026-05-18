<?php
declare(strict_types=1);

namespace {
	define( 'ABSPATH', __DIR__ . '/' );
	define( 'WPVDB_SMART_SEARCH_DIR', dirname( __DIR__ ) );
	define( 'WPVDB_SMART_SEARCH_URL', 'https://example.test/wp-content/plugins/wpvdb-smart-search/' );
	define( 'WPVDB_SMART_SEARCH_FILE', dirname( __DIR__ ) . '/wpvdb-smart-search.php' );
	define( 'WPVDB_SMART_SEARCH_VERSION', '0.1.1' );

	$GLOBALS['wpvdb_smart_search_test'] = [
		'rewrite_rules'     => [],
		'removed_actions'   => [],
		'flush_count'       => 0,
		'query_vars'        => [],
	];

	if ( ! class_exists( 'WP_Rewrite' ) ) {
		class WP_Rewrite {
			/**
			 * @var array<string, string>
			 */
			public array $extra_rules_top = [];
		}
	}

	$GLOBALS['wp_rewrite'] = new \WP_Rewrite();

	function __( string $text, string $domain = 'default' ): string {
		unset( $domain );
		return $text;
	}

	function add_action( string $hook, callable $callback, int $priority = 10 ): void {
		unset( $hook, $callback, $priority );
	}

	function add_filter( string $hook, callable $callback, int $priority = 10 ): void {
		unset( $hook, $callback, $priority );
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

	function sanitize_text_field( mixed $value ): string {
		return trim( strip_tags( (string) $value ) );
	}

	function wp_unslash( mixed $value ): mixed {
		return $value;
	}
}

namespace {
	require_once dirname( __DIR__ ) . '/includes/class-template.php';
}
