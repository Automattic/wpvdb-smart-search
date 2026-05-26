<?php
/**
 * Admin settings for wpvdb-smart-search surfaces.
 *
 * @package WPVDB_Smart_Search
 */

namespace WPVDB_Smart_Search;

defined( 'ABSPATH' ) || exit;

/**
 * Plugin settings.
 */
class Settings {
	const OPTION_NAME          = 'wpvdb_smart_search_settings';
	const CACHE_VERSION_OPTION = 'wpvdb_smart_search_native_cache_version';
	const PAGE_SLUG            = 'wpvdb-smart-search';

	/**
	 * Register hooks.
	 */
	public static function init(): void {
		add_action( 'admin_menu', [ __CLASS__, 'register_page' ] );
		add_action( 'admin_init', [ __CLASS__, 'register_settings' ] );
		add_action( 'admin_post_wpvdb_smart_search_clear_native_cache', [ __CLASS__, 'handle_clear_native_cache' ] );
	}

	/**
	 * Register the settings page.
	 */
	public static function register_page(): void {
		add_options_page(
			__( 'WPVDB Smart Search', 'wpvdb-smart-search' ),
			__( 'WPVDB Smart Search', 'wpvdb-smart-search' ),
			'manage_options',
			self::PAGE_SLUG,
			[ __CLASS__, 'render_page' ]
		);
	}

	/**
	 * Register settings with WordPress.
	 */
	public static function register_settings(): void {
		register_setting(
			self::PAGE_SLUG,
			self::OPTION_NAME,
			[
				'type'              => 'array',
				'sanitize_callback' => [ __CLASS__, 'sanitize' ],
				'default'           => self::defaults(),
			]
		);
	}

	/**
	 * Return default settings.
	 *
	 * @return array<string, mixed>
	 */
	public static function defaults(): array {
		return [
			'native_enabled'  => false,
			'native_mode'     => 'dense',
			'native_pool'     => 50,
			'native_fallback' => true,
			'native_types'    => [ 'post', 'page' ],
		];
	}

	/**
	 * Return normalized settings.
	 *
	 * @return array<string, mixed>
	 */
	public static function get(): array {
		$stored = get_option( self::OPTION_NAME, [] );
		if ( ! is_array( $stored ) ) {
			$stored = [];
		}

		return self::sanitize_values( $stored );
	}

	/**
	 * Whether native WordPress search replacement is enabled.
	 */
	public static function native_enabled(): bool {
		$settings = self::get();
		return ! empty( $settings['native_enabled'] );
	}

	/**
	 * Native search mode.
	 */
	public static function native_mode(): string {
		$settings = self::get();
		return (string) $settings['native_mode'];
	}

	/**
	 * Native search pool size.
	 */
	public static function native_pool(): int {
		$settings = self::get();
		return (int) $settings['native_pool'];
	}

	/**
	 * Whether native search should fall back to keyword search on empty ranker results.
	 */
	public static function native_fallback_enabled(): bool {
		$settings = self::get();
		return ! empty( $settings['native_fallback'] );
	}

	/**
	 * Post types configured for native search.
	 *
	 * @return list<string>
	 */
	public static function native_post_types(): array {
		$settings = self::get();
		$types    = isset( $settings['native_types'] ) && is_array( $settings['native_types'] ) ? $settings['native_types'] : [];
		$types    = array_values( array_filter( array_map( 'sanitize_key', $types ) ) );

		return empty( $types ) ? [ 'post', 'page' ] : $types;
	}

	/**
	 * Current native search cache version.
	 */
	public static function native_cache_version(): int {
		$version = (int) get_option( self::CACHE_VERSION_OPTION, 1 );
		return max( 1, $version );
	}

	/**
	 * Bump the native search cache version.
	 */
	public static function bump_native_cache_version(): void {
		update_option( self::CACHE_VERSION_OPTION, self::native_cache_version() + 1, false );
	}

	/**
	 * Sanitize and normalize settings before saving.
	 *
	 * @param mixed $value Raw settings value.
	 * @return array<string, mixed>
	 */
	public static function sanitize( mixed $value ): array {
		$old = self::get();
		$new = self::sanitize_values( is_array( $value ) ? $value : [], true );

		if ( $old !== $new ) {
			self::bump_native_cache_version();
		}

		return $new;
	}

	/**
	 * Normalize setting values.
	 *
	 * @param array<string, mixed> $value  Raw values.
	 * @param bool                 $saving Whether values come from a settings form save.
	 * @return array<string, mixed>
	 */
	private static function sanitize_values( array $value, bool $saving = false ): array {
		$defaults = self::defaults();
		$modes    = [ 'dense', 'sparse', 'hybrid' ];
		$mode     = isset( $value['native_mode'] ) ? sanitize_key( (string) $value['native_mode'] ) : (string) $defaults['native_mode'];
		$pool     = isset( $value['native_pool'] ) ? (int) $value['native_pool'] : (int) $defaults['native_pool'];
		$types    = isset( $value['native_types'] ) && is_array( $value['native_types'] ) ? $value['native_types'] : $defaults['native_types'];
		$types    = array_values( array_filter( array_map( 'sanitize_key', (array) $types ) ) );

		return [
			'native_enabled'  => array_key_exists( 'native_enabled', $value ) ? ! empty( $value['native_enabled'] ) : ( $saving ? false : (bool) $defaults['native_enabled'] ),
			'native_mode'     => in_array( $mode, $modes, true ) ? $mode : (string) $defaults['native_mode'],
			'native_pool'     => max( 10, min( 200, $pool ) ),
			'native_fallback' => array_key_exists( 'native_fallback', $value ) ? ! empty( $value['native_fallback'] ) : ( $saving ? false : (bool) $defaults['native_fallback'] ),
			'native_types'    => empty( $types ) ? $defaults['native_types'] : $types,
		];
	}

	/**
	 * Handle the clear native search cache action.
	 */
	public static function handle_clear_native_cache(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to manage Smart Search settings.', 'wpvdb-smart-search' ) );
		}

		check_admin_referer( 'wpvdb_smart_search_clear_native_cache' );
		self::bump_native_cache_version();

		wp_safe_redirect(
			add_query_arg(
				[
					'page'                 => self::PAGE_SLUG,
					'native-cache-cleared' => '1',
				],
				admin_url( 'options-general.php' )
			)
		);
		exit;
	}

	/**
	 * Render the settings page.
	 */
	public static function render_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$settings       = self::get();
		$public_types   = get_post_types( [ 'public' => true ], 'objects' );
		$selected_types = self::native_post_types();
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'WPVDB Smart Search', 'wpvdb-smart-search' ); ?></h1>

			<?php if ( isset( $_GET['native-cache-cleared'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>
				<div class="notice notice-success is-dismissible">
					<p><?php esc_html_e( 'Native search cache cleared.', 'wpvdb-smart-search' ); ?></p>
				</div>
			<?php endif; ?>

			<?php if ( 'hybrid' === $settings['native_mode'] && class_exists( '\WPVDB_Search\Schema' ) && ! \WPVDB_Search\Schema::has_fulltext_index() ) : ?>
				<div class="notice notice-warning">
					<p><?php esc_html_e( 'Hybrid mode is selected, but the FULLTEXT index is not ready. Native search will behave like dense search until sparse search is ready.', 'wpvdb-smart-search' ); ?></p>
				</div>
			<?php endif; ?>

			<form method="post" action="options.php">
				<?php settings_fields( self::PAGE_SLUG ); ?>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e( 'Native site search', 'wpvdb-smart-search' ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="<?php echo esc_attr( self::OPTION_NAME ); ?>[native_enabled]" value="1" <?php checked( $settings['native_enabled'] ); ?> />
								<?php esc_html_e( 'Use semantic search for the main site search.', 'wpvdb-smart-search' ); ?>
							</label>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Mode', 'wpvdb-smart-search' ); ?></th>
						<td>
							<?php foreach ( [ 'hybrid', 'dense', 'sparse' ] as $mode ) : ?>
								<label>
									<input type="radio" name="<?php echo esc_attr( self::OPTION_NAME ); ?>[native_mode]" value="<?php echo esc_attr( $mode ); ?>" <?php checked( $settings['native_mode'], $mode ); ?> />
									<?php echo esc_html( ucfirst( $mode ) ); ?>
								</label><br />
							<?php endforeach; ?>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="wpvdb-smart-search-native-pool"><?php esc_html_e( 'Pool size', 'wpvdb-smart-search' ); ?></label></th>
						<td>
							<input id="wpvdb-smart-search-native-pool" type="number" min="10" max="200" name="<?php echo esc_attr( self::OPTION_NAME ); ?>[native_pool]" value="<?php echo esc_attr( (string) $settings['native_pool'] ); ?>" />
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Fallback', 'wpvdb-smart-search' ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="<?php echo esc_attr( self::OPTION_NAME ); ?>[native_fallback]" value="1" <?php checked( $settings['native_fallback'] ); ?> />
								<?php esc_html_e( 'Fall back to default keyword search when semantic search returns no results.', 'wpvdb-smart-search' ); ?>
							</label>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Post types', 'wpvdb-smart-search' ); ?></th>
						<td>
							<?php foreach ( $public_types as $type => $obj ) : ?>
								<label>
									<input type="checkbox" name="<?php echo esc_attr( self::OPTION_NAME ); ?>[native_types][]" value="<?php echo esc_attr( (string) $type ); ?>" <?php checked( in_array( (string) $type, $selected_types, true ) ); ?> />
									<?php echo esc_html( $obj->labels->singular_name ?? $type ); ?>
								</label><br />
							<?php endforeach; ?>
						</td>
					</tr>
				</table>
				<?php submit_button(); ?>
			</form>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<?php wp_nonce_field( 'wpvdb_smart_search_clear_native_cache' ); ?>
				<input type="hidden" name="action" value="wpvdb_smart_search_clear_native_cache" />
				<?php submit_button( __( 'Clear native search cache', 'wpvdb-smart-search' ), 'secondary', 'submit', false ); ?>
			</form>
		</div>
		<?php
	}
}
