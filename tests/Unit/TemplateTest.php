<?php
/**
 * Smart Search template tests.
 *
 * @package WPVDB_Smart_Search
 */

declare(strict_types=1);

namespace WPVDB_Smart_Search\Tests\Unit;

use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use WPVDB_Smart_Search\Template;

/**
 * Tests Smart Search template behavior.
 *
 * @covers \WPVDB_Smart_Search\Template
 */
final class TemplateTest extends TestCase {
	/**
	 * Reset shared test state.
	 */
	protected function setUp(): void {
		$GLOBALS['wpvdb_smart_search_test']['rewrite_rules']   = [];
		$GLOBALS['wpvdb_smart_search_test']['removed_actions'] = [];
		$GLOBALS['wpvdb_smart_search_test']['flush_count']     = 0;
		$GLOBALS['wpvdb_smart_search_test']['query_vars']      = [];
		$GLOBALS['wp_rewrite']->extra_rules_top                = [];
		$_GET = [];
	}

	/**
	 * Test the public route query var is registered once.
	 *
	 * @covers \WPVDB_Smart_Search\Template::register_query_var
	 */
	public function test_register_query_var_adds_public_query_var_once(): void {
		$vars = Template::register_query_var( [ 'existing' ] );
		$vars = Template::register_query_var( $vars );

		self::assertSame(
			[ 'existing', Template::QUERY_VAR ],
			$vars,
			'Query vars should include the Smart Search route var once.'
		);
	}

	/**
	 * Test the rewrite rule points at the Smart Search route.
	 *
	 * @covers \WPVDB_Smart_Search\Template::register_rewrite_rule
	 */
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
			$GLOBALS['wpvdb_smart_search_test']['rewrite_rules'],
			'Rewrite rules should route /smart-search/ to the Smart Search query var.'
		);
	}

	/**
	 * Test deactivation removes the rewrite rule before flushing.
	 *
	 * @covers \WPVDB_Smart_Search\Template::deactivate
	 * @covers \WPVDB_Smart_Search\Template::remove_rewrite_rule
	 */
	public function test_deactivate_removes_rule_from_memory_before_flushing(): void {
		$GLOBALS['wp_rewrite']->extra_rules_top['^smart-search/?$'] = 'index.php?wpvdb_smart_search=1';

		Template::deactivate();

		self::assertArrayNotHasKey(
			'^smart-search/?$',
			$GLOBALS['wp_rewrite']->extra_rules_top,
			'Deactivation should remove the in memory rewrite rule.'
		);
		self::assertSame(
			1,
			$GLOBALS['wpvdb_smart_search_test']['flush_count'],
			'Deactivation should flush rewrite rules once.'
		);
		self::assertSame(
			'init',
			$GLOBALS['wpvdb_smart_search_test']['removed_actions'][0][0],
			'Deactivation should unregister the init hook.'
		);
	}

	/**
	 * Test non matching requests are ignored.
	 *
	 * @covers \WPVDB_Smart_Search\Template::maybe_render
	 */
	public function test_maybe_render_ignores_non_matching_requests(): void {
		$GLOBALS['wpvdb_smart_search_test']['query_vars'][ Template::QUERY_VAR ] = '';

		ob_start();
		Template::maybe_render();
		$output = ob_get_clean();

		self::assertSame( '', $output, 'Non matching requests should not render output.' );
	}

	/**
	 * Test locale shaped query params are accepted.
	 *
	 * @covers \WPVDB_Smart_Search\Template::requested_lang
	 */
	public function test_requested_lang_accepts_locale_shaped_query_param(): void {
		$_GET['lang'] = '<b>es_ES</b>';

		$reflection = new ReflectionMethod( Template::class, 'requested_lang' );

		self::assertSame(
			'es_ES',
			$reflection->invoke( null ),
			'Locale shaped query params should be sanitized and accepted.'
		);
	}

	/**
	 * Test path like locale query params are rejected.
	 *
	 * @covers \WPVDB_Smart_Search\Template::requested_lang
	 */
	public function test_requested_lang_rejects_path_like_values(): void {
		$_GET['lang'] = '../es_ES';

		$reflection = new ReflectionMethod( Template::class, 'requested_lang' );

		self::assertSame(
			'',
			$reflection->invoke( null ),
			'Path like locale query params should be rejected.'
		);
	}

	/**
	 * Test asset URLs fall back to the plugin version.
	 *
	 * @covers \WPVDB_Smart_Search\Template::asset_url
	 */
	public function test_asset_url_uses_plugin_version_when_file_is_missing(): void {
		$reflection = new ReflectionMethod( Template::class, 'asset_url' );

		self::assertSame(
			'https://example.test/wp-content/plugins/wpvdb-smart-search/dist/missing.js?v=0.1.1',
			$reflection->invoke( null, 'dist/missing.js' ),
			'Missing assets should use the plugin version cache buster.'
		);
	}
}
