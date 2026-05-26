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
	const CACHE_VERSION_OPTION = 'wpvdb_smart_search_site_search_cache_version';
	const PAGE_SLUG            = 'wpvdb-smart-search';

	/**
	 * Register hooks.
	 */
	public static function init(): void {
		add_filter( 'wpvdb_admin_tabs', [ __CLASS__, 'register_wpvdb_tab' ] );
		add_action( 'admin_init', [ __CLASS__, 'register_settings' ] );
		add_action( 'admin_post_wpvdb_smart_search_clear_site_search_cache', [ __CLASS__, 'handle_clear_site_search_cache' ] );
	}

	/**
	 * Register the Smart Search tab in the Vector DB admin layout.
	 *
	 * @param array<string, mixed> $tabs Admin tabs.
	 * @return array<string, mixed>
	 */
	public static function register_wpvdb_tab( array $tabs ): array {
		$tabs['smart-search'] = [
			'label'           => __( 'Smart Search', 'wpvdb-smart-search' ),
			'menu_label'      => __( 'Smart Search', 'wpvdb-smart-search' ),
			'page'            => self::PAGE_SLUG,
			'capability'      => 'manage_options',
			'position'        => 50,
			'render_callback' => [ __CLASS__, 'render_page' ],
		];

		return $tabs;
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
			'site_search_enabled'  => false,
			'site_search_mode'     => 'dense',
			'site_search_pool'     => 50,
			'site_search_fallback' => true,
			'site_search_types'    => self::indexed_post_type_keys(),
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
	 * Whether site search replacement is enabled.
	 */
	public static function site_search_enabled(): bool {
		$settings = self::get();
		return ! empty( $settings['site_search_enabled'] );
	}

	/**
	 * Site search mode.
	 */
	public static function site_search_mode(): string {
		$settings = self::get();
		return (string) $settings['site_search_mode'];
	}

	/**
	 * Site search pool size.
	 */
	public static function site_search_pool(): int {
		$settings = self::get();
		return (int) $settings['site_search_pool'];
	}

	/**
	 * Whether site search should fall back to keyword search on empty ranker results.
	 */
	public static function site_search_fallback_enabled(): bool {
		$settings = self::get();
		return ! empty( $settings['site_search_fallback'] );
	}

	/**
	 * Post types configured for site search.
	 *
	 * @return list<string>
	 */
	public static function site_search_post_types(): array {
		$settings = self::get();
		$allowed  = self::indexed_post_type_keys();
		$types    = isset( $settings['site_search_types'] ) && is_array( $settings['site_search_types'] ) ? $settings['site_search_types'] : $allowed;
		$types    = array_values( array_filter( array_map( 'sanitize_key', $types ) ) );
		$types    = array_values( array_intersect( $types, $allowed ) );

		return empty( $types ) ? $allowed : $types;
	}

	/**
	 * Current site search cache version.
	 */
	public static function site_search_cache_version(): int {
		$version = (int) get_option( self::CACHE_VERSION_OPTION, 1 );
		return max( 1, $version );
	}

	/**
	 * Bump the site search cache version.
	 */
	public static function bump_site_search_cache_version(): void {
		update_option( self::CACHE_VERSION_OPTION, self::site_search_cache_version() + 1, false );
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
			self::bump_site_search_cache_version();
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
		$mode     = isset( $value['site_search_mode'] ) ? sanitize_key( (string) $value['site_search_mode'] ) : (string) $defaults['site_search_mode'];
		$pool     = isset( $value['site_search_pool'] ) ? (int) $value['site_search_pool'] : (int) $defaults['site_search_pool'];
		$types    = isset( $value['site_search_types'] ) && is_array( $value['site_search_types'] ) ? $value['site_search_types'] : $defaults['site_search_types'];
		$types    = array_values( array_filter( array_map( 'sanitize_key', (array) $types ) ) );
		$types    = array_values( array_intersect( $types, self::indexed_post_type_keys() ) );

		return [
			'site_search_enabled'  => array_key_exists( 'site_search_enabled', $value ) ? ! empty( $value['site_search_enabled'] ) : ( $saving ? false : (bool) $defaults['site_search_enabled'] ),
			'site_search_mode'     => in_array( $mode, $modes, true ) ? $mode : (string) $defaults['site_search_mode'],
			'site_search_pool'     => max( 10, min( 200, $pool ) ),
			'site_search_fallback' => array_key_exists( 'site_search_fallback', $value ) ? ! empty( $value['site_search_fallback'] ) : ( $saving ? false : (bool) $defaults['site_search_fallback'] ),
			'site_search_types'    => empty( $types ) ? $defaults['site_search_types'] : $types,
		];
	}

	/**
	 * Return public post type keys allowed for site search.
	 *
	 * @return list<string>
	 */
	private static function public_post_type_keys(): array {
		$types = get_post_types( [ 'public' => true ], 'names' );
		if ( ! is_array( $types ) ) {
			return [ 'post', 'page' ];
		}

		$types = array_values( array_filter( array_map( 'sanitize_key', $types ) ) );

		return empty( $types ) ? [ 'post', 'page' ] : $types;
	}

	/**
	 * Return public post types currently configured for wpvdb embedding.
	 *
	 * @return list<string>
	 */
	private static function indexed_post_type_keys(): array {
		$public_types = self::public_post_type_keys();
		$wpvdb_types  = self::wpvdb_post_type_keys();

		if ( null === $wpvdb_types ) {
			$wpvdb_types = [ 'post', 'page' ];
		}

		$types = array_values( array_intersect( $wpvdb_types, $public_types ) );

		return $types;
	}

	/**
	 * Return post types from wpvdb's content settings.
	 *
	 * @return list<string>|null
	 */
	private static function wpvdb_post_type_keys(): ?array {
		$settings = get_option( 'wpvdb_settings', null );
		if ( is_array( $settings ) ) {
			if ( array_key_exists( 'post_types', $settings ) && is_array( $settings['post_types'] ) ) {
				return array_values( array_filter( array_map( 'sanitize_key', $settings['post_types'] ) ) );
			}

			return [ 'post' ];
		}

		return null;
	}

	/**
	 * Handle the clear site search cache action.
	 */
	public static function handle_clear_site_search_cache(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to manage Smart Search settings.', 'wpvdb-smart-search' ) );
		}

		check_admin_referer( 'wpvdb_smart_search_clear_site_search_cache' );
		self::bump_site_search_cache_version();

		wp_safe_redirect(
			add_query_arg(
				[
					'page'                      => self::PAGE_SLUG,
					'site-search-cache-cleared' => '1',
				],
				admin_url( 'admin.php' )
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

		$settings            = self::get();
		$public_types        = get_post_types( [ 'public' => true ], 'objects' );
		$indexed_types       = self::indexed_post_type_keys();
		$selected_types      = self::site_search_post_types();
		$indexed_type_labels = array_intersect_key( is_array( $public_types ) ? $public_types : [], array_flip( $indexed_types ) );
		?>
		<?php if ( isset( $_GET['site-search-cache-cleared'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>
			<div class="notice notice-success is-dismissible">
				<p><?php esc_html_e( 'Site search cache cleared.', 'wpvdb-smart-search' ); ?></p>
			</div>
		<?php endif; ?>

		<?php if ( in_array( $settings['site_search_mode'], [ 'hybrid', 'sparse' ], true ) && class_exists( '\WPVDB_Search\Schema' ) && ! \WPVDB_Search\Schema::has_fulltext_index() ) : ?>
			<div class="notice notice-warning">
				<p><?php esc_html_e( 'Sparse or hybrid mode is selected, but the FULLTEXT index is not ready. Sparse ranking will be unavailable until the index is ready.', 'wpvdb-smart-search' ); ?></p>
			</div>
		<?php endif; ?>

		<form id="wpvdb-smart-search-settings-form" method="post" action="options.php">
			<?php settings_fields( self::PAGE_SLUG ); ?>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><?php esc_html_e( 'Site search', 'wpvdb-smart-search' ); ?></th>
					<td>
						<label>
							<input type="checkbox" name="<?php echo esc_attr( self::OPTION_NAME ); ?>[site_search_enabled]" value="1" <?php checked( $settings['site_search_enabled'] ); ?> />
							<?php esc_html_e( 'Use semantic search for the main site search.', 'wpvdb-smart-search' ); ?>
						</label>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Post types', 'wpvdb-smart-search' ); ?></th>
					<td>
						<?php foreach ( $indexed_type_labels as $type => $obj ) : ?>
							<label>
								<input type="checkbox" name="<?php echo esc_attr( self::OPTION_NAME ); ?>[site_search_types][]" value="<?php echo esc_attr( (string) $type ); ?>" <?php checked( in_array( (string) $type, $selected_types, true ) ); ?> />
								<?php echo esc_html( $obj->labels->singular_name ?? $type ); ?>
							</label><br />
						<?php endforeach; ?>
						<?php if ( empty( $indexed_type_labels ) ) : ?>
							<p><?php esc_html_e( 'No public post types are currently enabled in Vector DB content settings.', 'wpvdb-smart-search' ); ?></p>
						<?php endif; ?>
						<p class="description">
							<?php esc_html_e( 'Only post types enabled in Vector DB content settings can be used for semantic site search.', 'wpvdb-smart-search' ); ?>
							<?php if ( class_exists( '\WPVDB\Admin' ) ) : ?>
								<a href="<?php echo esc_url( self::wpvdb_content_settings_url() ); ?>"><?php esc_html_e( 'Open Vector DB content settings.', 'wpvdb-smart-search' ); ?></a>
							<?php endif; ?>
						</p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Mode', 'wpvdb-smart-search' ); ?></th>
					<td>
						<?php
						$modes = [
							'dense'  => __( 'Dense', 'wpvdb-smart-search' ),
							'hybrid' => __( 'Hybrid', 'wpvdb-smart-search' ),
							'sparse' => __( 'Sparse', 'wpvdb-smart-search' ),
						];
						foreach ( $modes as $mode => $label ) :
							?>
							<label>
								<input type="radio" name="<?php echo esc_attr( self::OPTION_NAME ); ?>[site_search_mode]" value="<?php echo esc_attr( $mode ); ?>" <?php checked( $settings['site_search_mode'], $mode ); ?> />
								<?php echo esc_html( $label ); ?>
							</label><br />
						<?php endforeach; ?>
						<p class="description"><?php esc_html_e( 'Dense is the fastest default. Hybrid and sparse add keyword ranking when the FULLTEXT index is ready.', 'wpvdb-smart-search' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="wpvdb-smart-search-site-search-pool"><?php esc_html_e( 'Pool size', 'wpvdb-smart-search' ); ?></label></th>
					<td>
						<input id="wpvdb-smart-search-site-search-pool" type="number" min="10" max="200" name="<?php echo esc_attr( self::OPTION_NAME ); ?>[site_search_pool]" value="<?php echo esc_attr( (string) $settings['site_search_pool'] ); ?>" />
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Fallback', 'wpvdb-smart-search' ); ?></th>
					<td>
						<label>
							<input type="checkbox" name="<?php echo esc_attr( self::OPTION_NAME ); ?>[site_search_fallback]" value="1" <?php checked( $settings['site_search_fallback'] ); ?> />
							<?php esc_html_e( 'Fall back to default keyword search when semantic search returns no results.', 'wpvdb-smart-search' ); ?>
						</label>
					</td>
				</tr>
			</table>
		</form>

		<div style="display: flex; gap: 8px; align-items: center; margin-top: 20px;">
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<?php wp_nonce_field( 'wpvdb_smart_search_clear_site_search_cache' ); ?>
				<input type="hidden" name="action" value="wpvdb_smart_search_clear_site_search_cache" />
				<?php submit_button( __( 'Clear site search cache', 'wpvdb-smart-search' ), 'secondary', 'submit', false ); ?>
			</form>
			<?php submit_button( __( 'Save Changes', 'wpvdb-smart-search' ), 'primary', 'submit', false, [ 'form' => 'wpvdb-smart-search-settings-form' ] ); ?>
		</div>
		<?php
	}

	/**
	 * Return the Vector DB content settings URL.
	 */
	private static function wpvdb_content_settings_url(): string {
		return add_query_arg(
			[
				'page'    => 'wpvdb-settings',
				'section' => 'content',
			],
			admin_url( 'admin.php' )
		);
	}
}
