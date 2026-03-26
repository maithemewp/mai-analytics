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
		add_filter( 'plugin_action_links_' . MAI_ANALYTICS_BASENAME . '/mai-analytics.php', [ $this, 'add_action_links' ] );
	}

	/**
	 * Adds a Settings link to the plugin action links on the Plugins page.
	 *
	 * @param array $links Existing plugin action links.
	 *
	 * @return array Modified links with Settings prepended.
	 */
	public function add_action_links( array $links ): array {
		$settings_link = sprintf(
			'<a href="%s">%s</a>',
			admin_url( 'admin.php?page=mai-analytics-settings' ),
			__( 'Settings', 'mai-analytics' )
		);

		array_unshift( $links, $settings_link );

		return $links;
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

		// Show notice if provider is unavailable OR if there's a recent sync error.
		$last_error = ( $provider && method_exists( $provider, 'get_last_error' ) ) ? $provider::get_last_error() : '';

		if ( $provider && $provider->is_available() && ! $last_error ) {
			return;
		}

		$label = $provider ? $provider->get_label() : $data_source;

		if ( $provider && $provider->is_available() && $last_error ) {
			// Provider is available but had a sync error.
			$message = sprintf(
				/* translators: %s: provider name */
				esc_html__( '%s sync error:', 'mai-analytics' ),
				esc_html( $label )
			) . ' ' . esc_html( $last_error );
		} else {
			// Provider is unavailable.
			$reason = ( $provider && method_exists( $provider, 'get_unavailable_reason' ) )
				? $provider->get_unavailable_reason()
				: '';

			$message = sprintf(
				/* translators: %s: provider name */
				esc_html__( 'The selected analytics provider (%s) is not available.', 'mai-analytics' ),
				esc_html( $label )
			);

			if ( $reason ) {
				$message .= ' ' . esc_html( $reason );
			}

			$message .= ' ' . esc_html__( 'View syncing is paused — existing stats are preserved.', 'mai-analytics' );
		}

		printf(
			'<div class="notice notice-warning"><p><strong>%s</strong> %s <a href="%s">%s</a></p></div>',
			esc_html__( 'Mai Analytics:', 'mai-analytics' ),
			$message,
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
		// Hidden submenu page — navigated to via tabs on the dashboard page.
		$parent = class_exists( 'Mai_Engine' ) ? 'mai-theme' : 'options-general.php';

		add_submenu_page(
			$parent,
			__( 'Mai Analytics', 'mai-analytics' ),
			'', // Hidden from menu — accessed via tab.
			'manage_options',
			'mai-analytics-settings',
			[ $this, 'render_page' ]
		);
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
			'',
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
			'mai_analytics_data_source',
			[ 'class' => 'mai-analytics-provider-status' ]
		);

		// Matomo-specific settings fields (same section, toggled via CSS).
		add_settings_field(
			'matomo_url',
			__( 'Matomo URL', 'mai-analytics' ),
			[ $this, 'render_text_field' ],
			'mai-analytics-settings',
			'mai_analytics_data_source',
			[ 'key' => 'matomo_url', 'type' => 'url', 'description' => __( 'The URL of your Matomo instance.', 'mai-analytics' ), 'class' => 'mai-analytics-provider-matomo' ]
		);

		add_settings_field(
			'matomo_site_id',
			__( 'Site ID', 'mai-analytics' ),
			[ $this, 'render_text_field' ],
			'mai-analytics-settings',
			'mai_analytics_data_source',
			[ 'key' => 'matomo_site_id', 'type' => 'number', 'description' => __( 'Matomo site/app ID.', 'mai-analytics' ), 'class' => 'mai-analytics-provider-matomo' ]
		);

		add_settings_field(
			'matomo_token',
			__( 'Auth Token', 'mai-analytics' ),
			[ $this, 'render_text_field' ],
			'mai-analytics-settings',
			'mai_analytics_data_source',
			[ 'key' => 'matomo_token', 'type' => 'password', 'description' => __( 'Matomo API authentication token.', 'mai-analytics' ), 'class' => 'mai-analytics-provider-matomo' ]
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

		// CSS-only visibility for provider sections using :has().
		wp_add_inline_style( 'wp-admin', '
			/* Hide provider-specific rows by default */
			.mai-analytics-provider-status,
			.mai-analytics-provider-matomo { display: none; }

			/* Show status row for whichever provider is selected */
			:has(#mai-analytics-data-source option[value="site_kit"]:checked) .mai-analytics-provider-site_kit,
			:has(#mai-analytics-data-source option[value="matomo"]:checked) .mai-analytics-provider-matomo,
			:has(#mai-analytics-data-source option[value="matomo"]:checked) .mai-analytics-provider-status,
			:has(#mai-analytics-data-source option[value="site_kit"]:checked) .mai-analytics-provider-status { display: table-row; }
		' );

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
			<h1><?php esc_html_e( 'Mai Analytics', 'mai-analytics' ); ?></h1>

			<nav class="nav-tab-wrapper" style="margin-bottom:20px;">
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=mai-analytics' ) ); ?>" class="nav-tab"><?php esc_html_e( 'Dashboard', 'mai-analytics' ); ?></a>
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=mai-analytics-settings' ) ); ?>" class="nav-tab nav-tab-active"><?php esc_html_e( 'Settings', 'mai-analytics' ); ?></a>
			</nav>

			<form method="post" action="options.php">
				<?php
				settings_fields( 'mai_analytics_settings' );
				do_settings_sections( 'mai-analytics-settings' );
				submit_button();
				?>
			</form>

			<?php if ( 'self_hosted' !== Settings::get( 'data_source' ) ) : ?>
			<?php $last_sync = get_option( 'mai_analytics_provider_last_sync', 0 ); ?>
			<hr>
			<h2><?php esc_html_e( 'Sync Tools', 'mai-analytics' ); ?></h2>
			<?php if ( $last_sync ) : ?>
				<p class="description" style="margin-bottom:16px;">
					<?php
					printf(
						/* translators: %s: formatted date/time */
						esc_html__( 'Last synced: %s', 'mai-analytics' ),
						esc_html( wp_date( 'M j, Y g:i a', $last_sync ) )
					);
					?>
				</p>
			<?php endif; ?>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><?php esc_html_e( 'Sync Now', 'mai-analytics' ); ?></th>
					<td>
						<button type="button" class="button" id="mai-analytics-sync-now">
							<?php esc_html_e( 'Sync Now', 'mai-analytics' ); ?>
						</button>
						<p class="mai-analytics-btn-status" style="display:none; margin:8px 0 0; font-weight:600;"></p>
						<p class="description">
							<?php esc_html_e( 'Process any pages that have received traffic since the last sync. This normally runs automatically every 15 minutes.', 'mai-analytics' ); ?>
						</p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Warm All Stats', 'mai-analytics' ); ?></th>
					<td>
						<button type="button" class="button" id="mai-analytics-warm">
							<?php esc_html_e( 'Warm Stats', 'mai-analytics' ); ?>
						</button>
						<p class="mai-analytics-btn-status" style="display:none; margin:8px 0 0; font-weight:600;"></p>
						<p class="description">
							<?php esc_html_e( 'Fetch stats from the provider for all posts, terms, and authors — not just ones with recent traffic. Use this after switching providers, or to populate stats for pages that haven\'t been visited yet. This may take a while on large sites.', 'mai-analytics' ); ?>
						</p>
					</td>
				</tr>
			</table>
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
		$provider = ProviderSync::get_provider();

		if ( ! $provider ) {
			echo '<p class="description">' . esc_html__( 'Select a provider to see its status.', 'mai-analytics' ) . '</p>';
			return;
		}

		if ( $provider->is_available() ) {
			printf( '<span style="color:green;">&#10003; %s</span>', esc_html__( 'Connected', 'mai-analytics' ) );
		} else {
			$reason = method_exists( $provider, 'get_unavailable_reason' ) ? $provider->get_unavailable_reason() : '';
			printf( '<span style="color:#d63638;">&#10007; %s</span>', esc_html( $reason ?: __( 'Not configured', 'mai-analytics' ) ) );
		}

		// Show last sync error if one exists.
		$last_error = method_exists( $provider, 'get_last_error' ) ? $provider::get_last_error() : '';

		if ( $last_error ) {
			printf(
				'<p style="color:#d63638; margin-top:8px;"><strong>%s</strong> %s</p>',
				esc_html__( 'Last error:', 'mai-analytics' ),
				esc_html( $last_error )
			);
		}
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
