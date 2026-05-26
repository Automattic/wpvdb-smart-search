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

		self::assertFalse( $settings['native_enabled'], 'Native search should be disabled by default.' );
		self::assertSame( 'dense', $settings['native_mode'], 'Dense should be the default mode.' );
		self::assertSame( 50, $settings['native_pool'], 'Default pool size should be 50.' );
		self::assertTrue( $settings['native_fallback'], 'Keyword fallback should default to enabled.' );
		self::assertSame( [ 'post', 'page' ], $settings['native_types'], 'Default post types should be posts and pages.' );
	}

	/**
	 * Test missing checkbox values are false when saving settings.
	 *
	 * @covers \WPVDB_Smart_Search\Settings::sanitize
	 */
	public function test_sanitize_treats_missing_checkboxes_as_false_on_save(): void {
		$settings = Settings::sanitize(
			[
				'native_mode'  => 'dense',
				'native_pool'  => 999,
				'native_types' => [ 'post' ],
			]
		);

		self::assertFalse( $settings['native_enabled'], 'Missing enabled checkbox should save as false.' );
		self::assertFalse( $settings['native_fallback'], 'Missing fallback checkbox should save as false.' );
		self::assertSame( 200, $settings['native_pool'], 'Pool size should be clamped to the maximum.' );
		self::assertSame( [ 'post' ], $settings['native_types'], 'Selected post types should be preserved.' );
	}

	/**
	 * Test saved native post types are limited to public post types.
	 *
	 * @covers \WPVDB_Smart_Search\Settings::sanitize
	 */
	public function test_sanitize_filters_native_types_to_public_post_types(): void {
		$settings = Settings::sanitize(
			[
				'native_enabled'  => '1',
				'native_fallback' => '1',
				'native_mode'     => 'dense',
				'native_pool'     => 50,
				'native_types'    => [ 'post', 'secret', 'book' ],
			]
		);

		self::assertSame( [ 'post', 'book' ], $settings['native_types'], 'Unknown or non-public post types should not be saved for native search.' );
	}
}
