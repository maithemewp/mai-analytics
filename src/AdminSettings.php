<?php

namespace Mai\Analytics;

class AdminSettings {

	/**
	 * Registers the settings page menu and WP Settings API.
	 */
	public function __construct() {
		add_action( 'admin_menu', [ $this, 'register_menu' ], 13 );
		add_action( 'admin_init', [ $this, 'register_settings' ] );
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_assets' ] );
		add_action( 'admin_notices', [ $this, 'maybe_show_provider_notice' ] );
	}

	/**
	 * Shows an admin notice if the selected provider is unavailable.
	 *
	 * @return void
	 */
	public function maybe_show_provider_notice(): void {
		$data_source = Settings::get( 'data_source' );

		if ( 'self_hosted' === $data_source ) {
			return;
		}

		$provider = ProviderSync::get_provider();

		if ( $provider && $provider->is_available() ) {
			return;
		}

		$label = $provider ? $provider->get_label() : $data_source;

		printf(
			'<div class="notice notice-warning"><p><strong>%s</strong> %s <a href="%s">%s</a></p></div>',
			esc_html__( 'Mai Analytics:', 'mai-analytics' ),
			sprintf(
				/* translators: %s: provider name */
				esc_html__( 'The selected analytics provider (%s) is not available. View syncing is paused — existing stats are preserved.', 'mai-analytics' ),
				esc_html( $label )
			),
			esc_url( admin_url( 'admin.php?page=mai-analytics-settings' ) ),
			esc_html__( 'Check settings', 'mai-analytics' )
		);
	}

	/**
	 * Registers the settings submenu page.
	 *
	 * @return void
	 */
	public function register_menu(): void {
		if ( class_exists( 'Mai_Engine' ) ) {
			add_submenu_page(
				'mai-theme',
				__( 'Analytics Settings', 'mai-analytics' ),
				__( 'Analytics Settings', 'mai-analytics' ),
				'manage_options',
				'mai-analytics-settings',
				[ $this, 'render_page' ]
			);
		} else {
			add_options_page(
				__( 'Analytics Settings', 'mai-analytics' ),
				__( 'Analytics Settings', 'mai-analytics' ),
				'manage_options',
				'mai-analytics-settings',
				[ $this, 'render_page' ]
			);
		}
	}

	/**
	 * Registers settings with the WP Settings API.
	 *
	 * @return void
	 */
	public function register_settings(): void {
		register_setting( 'mai_analytics_settings', 'mai_analytics_settings', [
			'sanitize_callback' => [ $this, 'sanitize' ],
		] );

		add_settings_section(
			'mai_analytics_data_source',
			__( 'Data Source', 'mai-analytics' ),
			'__return_null',
			'mai-analytics-settings'
		);

		add_settings_field(
			'data_source',
			__( 'View Tracking Source', 'mai-analytics' ),
			[ $this, 'render_data_source_field' ],
			'mai-analytics-settings',
			'mai_analytics_data_source'
		);

		add_settings_field(
			'provider_status',
			__( 'Provider Status', 'mai-analytics' ),
			[ $this, 'render_provider_status' ],
			'mai-analytics-settings',
			'mai_analytics_data_source'
		);

		// Matomo-specific settings section.
		add_settings_section(
			'mai_analytics_matomo',
			__( 'Matomo Settings', 'mai-analytics' ),
			'__return_null',
			'mai-analytics-settings'
		);

		add_settings_field(
			'matomo_url',
			__( 'Matomo URL', 'mai-analytics' ),
			[ $this, 'render_text_field' ],
			'mai-analytics-settings',
			'mai_analytics_matomo',
			[ 'key' => 'matomo_url', 'type' => 'url', 'description' => __( 'The URL of your Matomo instance.', 'mai-analytics' ) ]
		);

		add_settings_field(
			'matomo_site_id',
			__( 'Site ID', 'mai-analytics' ),
			[ $this, 'render_text_field' ],
			'mai-analytics-settings',
			'mai_analytics_matomo',
			[ 'key' => 'matomo_site_id', 'type' => 'number', 'description' => __( 'Matomo site/app ID.', 'mai-analytics' ) ]
		);

		add_settings_field(
			'matomo_token',
			__( 'Auth Token', 'mai-analytics' ),
			[ $this, 'render_text_field' ],
			'mai-analytics-settings',
			'mai_analytics_matomo',
			[ 'key' => 'matomo_token', 'type' => 'password', 'description' => __( 'Matomo API authentication token.', 'mai-analytics' ) ]
		);
	}

	/**
	 * Enqueues settings page assets.
	 *
	 * @param string $hook The current admin page hook suffix.
	 *
	 * @return void
	 */
	public function enqueue_assets( string $hook ): void {
		if ( ! str_contains( $hook, 'mai-analytics-settings' ) ) {
			return;
		}

		wp_enqueue_script(
			'mai-analytics-admin-settings',
			MAI_ANALYTICS_PLUGIN_URL . 'assets/js/admin-settings.js',
			[],
			MAI_ANALYTICS_VERSION,
			true
		);

		wp_localize_script( 'mai-analytics-admin-settings', 'maiAnalyticsSettings', [
			'restBase' => esc_url_raw( rest_url( 'mai-analytics/v1/admin/' ) ),
			'nonce'    => wp_create_nonce( 'wp_rest' ),
		] );
	}

	/**
	 * Sanitizes and validates settings on save.
	 *
	 * @param array $input The raw form input.
	 *
	 * @return array The sanitized settings.
	 */
	public function sanitize( array $input ): array {
		$valid_sources = [ 'self_hosted' ];

		// Add available provider slugs.
		$providers = apply_filters( 'mai_analytics_providers', [] );

		foreach ( $providers as $provider ) {
			$valid_sources[] = $provider->get_slug();
		}

		$sanitized = [];

		$sanitized['data_source']    = in_array( $input['data_source'] ?? '', $valid_sources, true )
			? $input['data_source']
			: 'self_hosted';
		$sanitized['sync_user']      = get_current_user_id();
		$sanitized['matomo_url']     = esc_url_raw( $input['matomo_url'] ?? '' );
		$sanitized['matomo_site_id'] = absint( $input['matomo_site_id'] ?? 0 ) ?: '';
		$sanitized['matomo_token']   = sanitize_text_field( $input['matomo_token'] ?? '' );

		return $sanitized;
	}

	/**
	 * Renders the settings page.
	 *
	 * @return void
	 */
	public function render_page(): void {
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Mai Analytics Settings', 'mai-analytics' ); ?></h1>

			<form method="post" action="options.php">
				<?php
				settings_fields( 'mai_analytics_settings' );
				do_settings_sections( 'mai-analytics-settings' );
				submit_button();
				?>
			</form>

			<?php if ( 'self_hosted' !== Settings::get( 'data_source' ) ) : ?>
			<hr>
			<h2><?php esc_html_e( 'Sync Tools', 'mai-analytics' ); ?></h2>
			<p>
				<button type="button" class="button" id="mai-analytics-sync-now">
					<?php esc_html_e( 'Sync Now', 'mai-analytics' ); ?>
				</button>
				<button type="button" class="button" id="mai-analytics-warm">
					<?php esc_html_e( 'Warm Stats', 'mai-analytics' ); ?>
				</button>
				<span id="mai-analytics-sync-status"></span>
			</p>
			<?php
				$last_sync = get_option( 'mai_analytics_provider_last_sync', 0 );

				if ( $last_sync ) {
					printf(
						'<p class="description">%s %s</p>',
						esc_html__( 'Last synced:', 'mai-analytics' ),
						esc_html( wp_date( 'M j, Y g:i a', $last_sync ) )
					);
				}
			?>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Renders the data source dropdown field.
	 *
	 * @return void
	 */
	public function render_data_source_field(): void {
		$current   = Settings::get( 'data_source' );
		$providers = apply_filters( 'mai_analytics_providers', [] );
		?>
		<select name="mai_analytics_settings[data_source]" id="mai-analytics-data-source">
			<option value="self_hosted" <?php selected( $current, 'self_hosted' ); ?>>
				<?php esc_html_e( 'Self-Hosted (built-in tracking)', 'mai-analytics' ); ?>
			</option>
			<?php foreach ( $providers as $provider ) : ?>
				<option value="<?php echo esc_attr( $provider->get_slug() ); ?>"
					<?php selected( $current, $provider->get_slug() ); ?>
					<?php disabled( ! $provider->is_available() ); ?>>
					<?php echo esc_html( $provider->get_label() ); ?>
					<?php if ( ! $provider->is_available() ) : ?>
						<?php esc_html_e( '(not available)', 'mai-analytics' ); ?>
					<?php endif; ?>
				</option>
			<?php endforeach; ?>
		</select>
		<?php
	}

	/**
	 * Renders the provider status card.
	 *
	 * @return void
	 */
	public function render_provider_status(): void {
		$providers = apply_filters( 'mai_analytics_providers', [] );

		if ( ! $providers ) {
			echo '<p class="description">' . esc_html__( 'No analytics providers detected.', 'mai-analytics' ) . '</p>';
			return;
		}

		echo '<ul>';

		foreach ( $providers as $provider ) {
			$status = $provider->is_available()
				? '<span style="color:green;">' . esc_html__( 'Connected', 'mai-analytics' ) . '</span>'
				: '<span style="color:#999;">' . esc_html__( 'Not configured', 'mai-analytics' ) . '</span>';

			printf( '<li><strong>%s</strong>: %s</li>', esc_html( $provider->get_label() ), $status );
		}

		echo '</ul>';
	}

	/**
	 * Renders a text/url/number/password settings field.
	 *
	 * @param array $args Field arguments: 'key', 'type', 'description'.
	 *
	 * @return void
	 */
	public function render_text_field( array $args ): void {
		$key   = $args['key'];
		$type  = $args['type'] ?? 'text';
		$desc  = $args['description'] ?? '';
		$value = Settings::get( $key );
		?>
		<input
			type="<?php echo esc_attr( $type ); ?>"
			name="mai_analytics_settings[<?php echo esc_attr( $key ); ?>]"
			value="<?php echo esc_attr( $value ); ?>"
			class="regular-text"
		>
		<?php if ( $desc ) : ?>
			<p class="description"><?php echo esc_html( $desc ); ?></p>
		<?php endif; ?>
		<?php
	}
}
