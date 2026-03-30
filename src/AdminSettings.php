<?php

namespace Mai\Views;

class AdminSettings {

	/**
	 * Registers the WP Settings API, admin notices, and plugin action links.
	 */
	public function __construct() {
		add_action( 'admin_init', [ $this, 'register_settings' ] );
		add_action( 'admin_notices', [ $this, 'maybe_show_provider_notice' ] );
		add_filter( 'plugin_action_links_' . MAI_VIEWS_BASENAME . '/mai-views.php', [ $this, 'add_action_links' ] );
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
			admin_url( 'admin.php?page=mai-views&tab=settings' ),
			__( 'Settings', 'mai-views' )
		);

		array_unshift( $links, $settings_link );

		return $links;
	}

	/**
	 * Shows an admin notice if the selected provider is unavailable or has errors.
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
				esc_html__( '%s sync error:', 'mai-views' ),
				esc_html( $label )
			) . ' ' . esc_html( $last_error );
		} else {
			// Provider is unavailable.
			$reason = ( $provider && method_exists( $provider, 'get_unavailable_reason' ) )
				? $provider->get_unavailable_reason()
				: '';

			$message = sprintf(
				/* translators: %s: provider name */
				esc_html__( 'The selected analytics provider (%s) is not available.', 'mai-views' ),
				esc_html( $label )
			);

			if ( $reason ) {
				$message .= ' ' . esc_html( $reason );
			}

			$message .= ' ' . esc_html__( 'View syncing is paused — existing stats are preserved.', 'mai-views' );
		}

		printf(
			'<div class="notice notice-warning"><p><strong>%s</strong> %s <a href="%s">%s</a></p></div>',
			esc_html__( 'Mai Views:', 'mai-views' ),
			$message,
			esc_url( admin_url( 'admin.php?page=mai-views&tab=settings' ) ),
			esc_html__( 'Check settings', 'mai-views' )
		);
	}

	/**
	 * Registers settings with the WP Settings API.
	 *
	 * @return void
	 */
	public function register_settings(): void {
		register_setting( 'mai_views_settings', 'mai_views_settings', [
			'sanitize_callback' => [ $this, 'sanitize' ],
		] );

		add_settings_section(
			'mai_views_data_source',
			'',
			'__return_null',
			'mai-views-settings'
		);

		add_settings_field(
			'data_source',
			__( 'View Tracking Source', 'mai-views' ),
			[ $this, 'render_data_source_field' ],
			'mai-views-settings',
			'mai_views_data_source'
		);

		add_settings_field(
			'provider_status',
			__( 'Provider Status', 'mai-views' ),
			[ $this, 'render_provider_status' ],
			'mai-views-settings',
			'mai_views_data_source',
			[ 'class' => 'mai-views-provider-status' ]
		);

		// Matomo-specific settings fields (toggled via CSS).
		add_settings_field(
			'matomo_url',
			__( 'Matomo URL', 'mai-views' ),
			[ $this, 'render_text_field' ],
			'mai-views-settings',
			'mai_views_data_source',
			[ 'key' => 'matomo_url', 'type' => 'url', 'description' => __( 'The URL of your Matomo instance.', 'mai-views' ), 'class' => 'mai-views-provider-matomo' ]
		);

		add_settings_field(
			'matomo_site_id',
			__( 'Site ID', 'mai-views' ),
			[ $this, 'render_text_field' ],
			'mai-views-settings',
			'mai_views_data_source',
			[ 'key' => 'matomo_site_id', 'type' => 'number', 'description' => __( 'Matomo site/app ID.', 'mai-views' ), 'class' => 'mai-views-provider-matomo' ]
		);

		add_settings_field(
			'matomo_token',
			__( 'Auth Token', 'mai-views' ),
			[ $this, 'render_text_field' ],
			'mai-views-settings',
			'mai_views_data_source',
			[ 'key' => 'matomo_token', 'type' => 'password', 'description' => __( 'Matomo API authentication token.', 'mai-views' ), 'class' => 'mai-views-provider-matomo' ]
		);

		add_settings_field(
			'trending_window',
			__( 'Trending Window', 'mai-views' ),
			[ $this, 'render_text_field' ],
			'mai-views-settings',
			'mai_views_data_source',
			[ 'key' => 'trending_window', 'type' => 'number', 'description' => __( 'Number of days used to calculate trending views.', 'mai-views' ) ]
		);

		// Redirect back to our tab after settings save.
		add_filter( 'wp_redirect', function( string $location ): string {
			if ( str_contains( $location, 'page=mai-views-settings' ) ) {
				return admin_url( 'admin.php?page=mai-views&tab=settings&settings-updated=true' );
			}

			if ( str_contains( $location, 'settings-updated=true' ) && str_contains( $location, 'options.php' ) ) {
				return admin_url( 'admin.php?page=mai-views&tab=settings&settings-updated=true' );
			}

			return $location;
		} );
	}

	/**
	 * Sanitizes and validates settings on save.
	 *
	 * @param array $input The raw form input.
	 *
	 * @return array The sanitized settings.
	 */
	public function sanitize( array $input ): array {
		$valid_sources = [ 'disabled', 'self_hosted' ];

		$providers = apply_filters( 'mai_views_providers', [] );

		foreach ( $providers as $provider ) {
			$valid_sources[] = $provider->get_slug();
		}

		$sanitized = [];

		$sanitized['data_source']    = in_array( $input['data_source'] ?? '', $valid_sources, true )
			? $input['data_source']
			: 'self_hosted';
		$sanitized['sync_user']        = get_current_user_id();
		$sanitized['trending_window'] = max( 1, absint( $input['trending_window'] ?? 7 ) );
		$sanitized['matomo_url']      = esc_url_raw( $input['matomo_url'] ?? '' );
		$sanitized['matomo_site_id'] = absint( $input['matomo_site_id'] ?? 0 ) ?: '';
		$sanitized['matomo_token']   = sanitize_text_field( $input['matomo_token'] ?? '' );

		return $sanitized;
	}

	/**
	 * Renders the data source dropdown field.
	 *
	 * @return void
	 */
	public function render_data_source_field(): void {
		$current   = Settings::get( 'data_source' );
		$providers = apply_filters( 'mai_views_providers', [] );
		?>
		<select name="mai_views_settings[data_source]" id="mai-views-data-source">
			<option value="disabled" <?php selected( $current, 'disabled' ); ?>>
				<?php esc_html_e( 'Disabled', 'mai-views' ); ?>
			</option>
			<option value="self_hosted" <?php selected( $current, 'self_hosted' ); ?>>
				<?php esc_html_e( 'Self-Hosted (built-in tracking)', 'mai-views' ); ?>
			</option>
			<?php foreach ( $providers as $provider ) : ?>
				<option value="<?php echo esc_attr( $provider->get_slug() ); ?>"
					<?php selected( $current, $provider->get_slug() ); ?>
					<?php disabled( ! $provider->is_available() ); ?>>
					<?php echo esc_html( $provider->get_label() ); ?>
					<?php if ( ! $provider->is_available() ) : ?>
						<?php esc_html_e( '(not available)', 'mai-views' ); ?>
					<?php endif; ?>
				</option>
			<?php endforeach; ?>
		</select>
		<?php
	}

	/**
	 * Renders the provider status indicator.
	 *
	 * @return void
	 */
	public function render_provider_status(): void {
		$provider = ProviderSync::get_provider();

		if ( ! $provider ) {
			echo '<p class="description">' . esc_html__( 'Select a provider to see its status.', 'mai-views' ) . '</p>';
			return;
		}

		if ( $provider->is_available() ) {
			printf( '<span style="color:green;">&#10003; %s</span>', esc_html__( 'Connected', 'mai-views' ) );
		} else {
			$reason = method_exists( $provider, 'get_unavailable_reason' ) ? $provider->get_unavailable_reason() : '';
			printf( '<span style="color:#d63638;">&#10007; %s</span>', esc_html( $reason ?: __( 'Not configured', 'mai-views' ) ) );
		}

		$last_error = method_exists( $provider, 'get_last_error' ) ? $provider::get_last_error() : '';

		if ( $last_error ) {
			printf(
				'<p style="color:#d63638; margin-top:8px;"><strong>%s</strong> %s</p>',
				esc_html__( 'Last error:', 'mai-views' ),
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
			name="mai_views_settings[<?php echo esc_attr( $key ); ?>]"
			value="<?php echo esc_attr( $value ); ?>"
			class="regular-text"
		>
		<?php if ( $desc ) : ?>
			<p class="description"><?php echo esc_html( $desc ); ?></p>
		<?php endif; ?>
		<?php
	}
}
