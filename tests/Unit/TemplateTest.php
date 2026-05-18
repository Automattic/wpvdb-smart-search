<?php
declare(strict_types=1);

namespace WPVDB_Smart_Search\Tests\Unit;

use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use WPVDB_Smart_Search\Template;

final class TemplateTest extends TestCase {
	protected function setUp(): void {
		$GLOBALS['wpvdb_smart_search_test']['rewrite_rules']   = [];
		$GLOBALS['wpvdb_smart_search_test']['removed_actions'] = [];
		$GLOBALS['wpvdb_smart_search_test']['flush_count']     = 0;
		$GLOBALS['wpvdb_smart_search_test']['query_vars']      = [];
		$GLOBALS['wp_rewrite']->extra_rules_top                = [];
		$_GET                                                  = [];
	}

	public function test_register_query_var_adds_public_query_var_once(): void {
		$vars = Template::register_query_var( [ 'existing' ] );
		$vars = Template::register_query_var( $vars );

		self::assertSame( [ 'existing', Template::QUERY_VAR ], $vars );
	}

	public function test_register_rewrite_rule_targets_smart_search_path(): void {
		Template::register_rewrite_rule();

		self::assertSame(
			[
				[
					'^smart-search/?$',
					'index.php?wpvdb_smart_search=1',
					'top',
				],
			],
			$GLOBALS['wpvdb_smart_search_test']['rewrite_rules']
		);
	}

	public function test_deactivate_removes_rule_from_memory_before_flushing(): void {
		$GLOBALS['wp_rewrite']->extra_rules_top['^smart-search/?$'] = 'index.php?wpvdb_smart_search=1';

		Template::deactivate();

		self::assertArrayNotHasKey( '^smart-search/?$', $GLOBALS['wp_rewrite']->extra_rules_top );
		self::assertSame( 1, $GLOBALS['wpvdb_smart_search_test']['flush_count'] );
		self::assertSame( 'init', $GLOBALS['wpvdb_smart_search_test']['removed_actions'][0][0] );
	}

	public function test_maybe_render_ignores_non_matching_requests(): void {
		$GLOBALS['wpvdb_smart_search_test']['query_vars'][ Template::QUERY_VAR ] = '';

		ob_start();
		Template::maybe_render();
		$output = ob_get_clean();

		self::assertSame( '', $output );
	}

	public function test_requested_lang_accepts_locale_shaped_query_param(): void {
		$_GET['lang'] = '<b>es_ES</b>';

		$reflection = new ReflectionMethod( Template::class, 'requested_lang' );

		self::assertSame( 'es_ES', $reflection->invoke( null ) );
	}

	public function test_requested_lang_rejects_path_like_values(): void {
		$_GET['lang'] = '../es_ES';

		$reflection = new ReflectionMethod( Template::class, 'requested_lang' );

		self::assertSame( '', $reflection->invoke( null ) );
	}

	public function test_asset_url_uses_plugin_version_when_file_is_missing(): void {
		$reflection = new ReflectionMethod( Template::class, 'asset_url' );

		self::assertSame(
			'https://example.test/wp-content/plugins/wpvdb-smart-search/dist/missing.js?v=0.1.1',
			$reflection->invoke( null, 'dist/missing.js' )
		);
	}
}
