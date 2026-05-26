<?php
/**
 * Smart Search settings tests.
 *
 * @package WPVDB_Smart_Search
 */

declare(strict_types=1);

namespace WPVDB_Smart_Search\Tests\Unit;

use PHPUnit\Framework\TestCase;
use WPVDB_Smart_Search\Settings;

/**
 * Tests Smart Search settings normalization.
 *
 * @covers \WPVDB_Smart_Search\Settings
 */
final class SettingsTest extends TestCase {
	/**
	 * Reset shared test state.
	 */
	protected function setUp(): void {
		$GLOBALS['wpvdb_smart_search_test']['options']           = [];
		$GLOBALS['wpvdb_smart_search_test']['public_post_types'] = [ 'post', 'page', 'book' ];
	}

	/**
	 * Test unsaved settings use documented defaults.
	 *
	 * @covers \WPVDB_Smart_Search\Settings::get
	 */
	public function test_get_uses_defaults_when_option_is_missing(): void {
		$settings = Settings::get();

		self::assertFalse( $settings['site_search_enabled'], 'Site search should be disabled by default.' );
		self::assertSame( 'dense', $settings['site_search_mode'], 'Dense should be the default mode.' );
		self::assertSame( 50, $settings['site_search_pool'], 'Default pool size should be 50.' );
		self::assertTrue( $settings['site_search_fallback'], 'Keyword fallback should default to enabled.' );
		self::assertSame( [ 'post', 'page' ], $settings['site_search_types'], 'Default post types should be posts and pages.' );
	}

	/**
	 * Test missing checkbox values are false when saving settings.
	 *
	 * @covers \WPVDB_Smart_Search\Settings::sanitize
	 */
	public function test_sanitize_treats_missing_checkboxes_as_false_on_save(): void {
		$settings = Settings::sanitize(
			[
				'site_search_mode'  => 'dense',
				'site_search_pool'  => 999,
				'site_search_types' => [ 'post' ],
			]
		);

		self::assertFalse( $settings['site_search_enabled'], 'Missing enabled checkbox should save as false.' );
		self::assertFalse( $settings['site_search_fallback'], 'Missing fallback checkbox should save as false.' );
		self::assertSame( 200, $settings['site_search_pool'], 'Pool size should be clamped to the maximum.' );
		self::assertSame( [ 'post' ], $settings['site_search_types'], 'Selected post types should be preserved.' );
	}

	/**
	 * Test fallback post types are limited to default wpvdb post types.
	 *
	 * @covers \WPVDB_Smart_Search\Settings::sanitize
	 */
	public function test_sanitize_filters_site_search_types_to_public_post_types(): void {
		$settings = Settings::sanitize(
			[
				'site_search_enabled'  => '1',
				'site_search_fallback' => '1',
				'site_search_mode'     => 'dense',
				'site_search_pool'     => 50,
				'site_search_types'    => [ 'post', 'secret', 'book' ],
			]
		);

		self::assertSame( [ 'post' ], $settings['site_search_types'], 'Unknown, non-public, or non-indexed post types should not be saved for site search.' );
	}

	/**
	 * Test site search post types are limited to wpvdb indexed post types.
	 *
	 * @covers \WPVDB_Smart_Search\Settings::sanitize
	 */
	public function test_sanitize_filters_site_search_types_to_wpvdb_post_types(): void {
		$GLOBALS['wpvdb_smart_search_test']['options']['wpvdb_settings'] = [
			'post_types' => [ 'post' ],
		];

		$settings = Settings::sanitize(
			[
				'site_search_enabled'  => '1',
				'site_search_fallback' => '1',
				'site_search_mode'     => 'dense',
				'site_search_pool'     => 50,
				'site_search_types'    => [ 'post', 'page' ],
			]
		);

		self::assertSame( [ 'post' ], $settings['site_search_types'], 'Post types disabled in Vector DB content settings should not be saved for site search.' );
	}

	/**
	 * Test empty wpvdb post type settings are respected.
	 *
	 * @covers \WPVDB_Smart_Search\Settings::sanitize
	 */
	public function test_sanitize_respects_empty_wpvdb_post_types(): void {
		$GLOBALS['wpvdb_smart_search_test']['options']['wpvdb_settings'] = [
			'post_types' => [],
		];

		$settings = Settings::sanitize(
			[
				'site_search_enabled'  => '1',
				'site_search_fallback' => '1',
				'site_search_mode'     => 'dense',
				'site_search_pool'     => 50,
				'site_search_types'    => [ 'post', 'page' ],
			]
		);

		self::assertSame( [], $settings['site_search_types'], 'Site search should not select post types when Vector DB indexes none.' );
	}

	/**
	 * Test legacy wpvdb settings without post_types match the Vector DB UI fallback.
	 *
	 * @covers \WPVDB_Smart_Search\Settings::sanitize
	 */
	public function test_sanitize_uses_wpvdb_ui_fallback_for_legacy_post_type_settings(): void {
		$GLOBALS['wpvdb_smart_search_test']['options']['wpvdb_settings'] = [
			'auto_embed_post_types' => [ 'post', 'page' ],
		];

		$settings = Settings::sanitize(
			[
				'site_search_enabled'  => '1',
				'site_search_fallback' => '1',
				'site_search_mode'     => 'dense',
				'site_search_pool'     => 50,
				'site_search_types'    => [ 'post', 'page' ],
			]
		);

		self::assertSame( [ 'post' ], $settings['site_search_types'], 'Legacy Vector DB settings without post_types should match the content settings UI fallback.' );
	}
}
