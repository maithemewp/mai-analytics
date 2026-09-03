<?php

namespace Mai\Analytics;

class AdminSettings {

	/**
	 * Registers the WP Settings API, admin notices, and plugin action links.
	 */
	public function __construct() {
		add_action( 'admin_init', [ $this, 'register_settings' ] );
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
			menu_page_url( 'mai-analytics', false ) . '&tab=settings',
			__( 'Settings', 'mai-analytics' )
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

		if ( in_array( $data_source, [ 'disabled', 'self_hosted' ], true ) ) {
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

			$message .= ' ' . esc_html__( 'View syncing is paused; existing stats are preserved.', 'mai-analytics' );
		}

		printf(
			'<div class="notice notice-warning"><p><strong>%s</strong> %s <a href="%s">%s</a></p></div>',
			esc_html__( 'Mai Analytics:', 'mai-analytics' ),
			$message,
			esc_url( menu_page_url( 'mai-analytics', false ) . '&tab=settings' ),
			esc_html__( 'Check settings', 'mai-analytics' )
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

		// One status row per provider, revealed by CSS to match whatever is
		// selected in the dropdown right now. A single shared row would report the
		// saved provider's status while the dropdown showed an unsaved selection.
		foreach ( apply_filters( 'mai_analytics_providers', [] ) as $provider ) {
			add_settings_field(
				'provider_status_' . $provider->get_slug(),
				__( 'Provider Status', 'mai-analytics' ),
				[ $this, 'render_provider_status' ],
				'mai-analytics-settings',
				'mai_analytics_data_source',
				[
					'class'    => 'mai-analytics-provider-status-' . sanitize_html_class( $provider->get_slug() ),
					'provider' => $provider,
				]
			);
		}

		// Matomo-specific settings fields (toggled via CSS). The copy row only
		// exists when copying would change something, so it disappears once the
		// two plugins are in sync rather than sitting there doing nothing.
		if ( Publisher::get_copyable_matomo_settings() ) {
			add_settings_field(
				'copy_from_publisher',
				__( 'Copy from Mai Publisher', 'mai-analytics' ),
				[ $this, 'render_copy_from_publisher' ],
				'mai-analytics-settings',
				'mai_analytics_data_source',
				[ 'class' => 'mai-analytics-provider-matomo' ]
			);
		}

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

		add_settings_field(
			'matomo_bulk_chunk',
			__( 'Bulk Chunk Size', 'mai-analytics' ),
			[ $this, 'render_text_field' ],
			'mai-analytics-settings',
			'mai_analytics_data_source',
			[ 'key' => 'matomo_bulk_chunk', 'type' => 'number', 'description' => __( 'URLs per Matomo bulk request. Default 10. Bump higher if your Matomo can handle it; drop to 5 or lower if you see 5xx errors.', 'mai-analytics' ), 'class' => 'mai-analytics-provider-matomo' ]
		);

		add_settings_field(
			'publisher_cross_link',
			'',
			[ $this, 'render_publisher_cross_link' ],
			'mai-analytics-settings',
			'mai_analytics_data_source',
			[ 'class' => 'mai-analytics-provider-matomo' ]
		);

		add_settings_field(
			'trending_window',
			__( 'Trending Window', 'mai-analytics' ),
			[ $this, 'render_text_field' ],
			'mai-analytics-settings',
			'mai_analytics_data_source',
			[ 'key' => 'trending_window', 'type' => 'number', 'description' => __( 'Number of days used to calculate trending views.', 'mai-analytics' ) ]
		);

		// Redirect back to our tab after settings save.
		add_filter( 'wp_redirect', function( string $location ): string {
			$target = menu_page_url( 'mai-analytics', false ) . '&tab=settings&settings-updated=true';

			if ( str_contains( $location, 'page=mai-analytics-settings' ) ) {
				return $target;
			}

			if ( str_contains( $location, 'settings-updated=true' ) && str_contains( $location, 'options.php' ) ) {
				return $target;
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

		$providers = apply_filters( 'mai_analytics_providers', [] );

		foreach ( $providers as $provider ) {
			$valid_sources[] = $provider->get_slug();
		}

		$sanitized = [];

		$sanitized['data_source']    = in_array( $input['data_source'] ?? '', $valid_sources, true )
			? $input['data_source']
			: 'self_hosted';
		$sanitized['sync_user']         = get_current_user_id();
		$sanitized['trending_window']   = max( 1, absint( $input['trending_window'] ?? 7 ) );
		$sanitized['matomo_url']        = esc_url_raw( $input['matomo_url'] ?? '' );
		$sanitized['matomo_site_id']    = absint( $input['matomo_site_id'] ?? 0 ) ?: '';
		$sanitized['matomo_token']      = sanitize_text_field( $input['matomo_token'] ?? '' );
		$sanitized['matomo_bulk_chunk'] = max( 1, min( 50, absint( $input['matomo_bulk_chunk'] ?? 10 ) ?: 10 ) );

		return $sanitized;
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
			<option value="disabled" <?php selected( $current, 'disabled' ); ?>>
				<?php esc_html_e( 'Disabled', 'mai-analytics' ); ?>
			</option>
			<option value="self_hosted" <?php selected( $current, 'self_hosted' ); ?>>
				<?php esc_html_e( 'Self-Hosted (built-in tracking)', 'mai-analytics' ); ?>
			</option>
			<?php foreach ( $providers as $provider ) : ?>
				<option value="<?php echo esc_attr( $provider->get_slug() ); ?>"
					<?php selected( $current, $provider->get_slug() ); ?>>
					<?php echo esc_html( $provider->get_label() ); ?>
					<?php if ( ! $provider->is_available() ) : ?>
						<?php esc_html_e( '(not configured)', 'mai-analytics' ); ?>
					<?php endif; ?>
				</option>
			<?php endforeach; ?>
		</select>
		<?php
	}

	/**
	 * Renders the provider status indicator.
	 *
	 * @since 1.3.5 Accepts a provider, so the row can report on the provider
	 *              selected in the dropdown rather than the saved one.
	 *
	 * @param array $args Field arguments. Accepts 'provider', a WebViewProvider
	 *                    instance. Falls back to the saved provider when absent.
	 *
	 * @return void
	 */
	public function render_provider_status( array $args = [] ): void {
		$provider = $args['provider'] ?? ProviderSync::get_provider();

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

		// The stored sync error is global, not per provider, so it only belongs on
		// the saved provider's row. Showing it elsewhere would blame the wrong one.
		$is_saved   = $provider->get_slug() === Settings::get( 'data_source' );
		$last_error = $is_saved && method_exists( $provider, 'get_last_error' ) ? $provider::get_last_error() : '';

		if ( $last_error ) {
			printf(
				'<p style="color:#d63638; margin-top:8px;"><strong>%s</strong> %s</p>',
				esc_html__( 'Last error:', 'mai-analytics' ),
				esc_html( $last_error )
			);
		}

		// The status above reflects saved settings. Say so when the dropdown is
		// showing a provider that hasn't been saved yet.
		if ( ! $is_saved ) {
			echo '<p class="description">' . esc_html__( 'Not saved yet. Save changes to sync from this provider.', 'mai-analytics' ) . '</p>';
		}
	}

	/**
	 * Renders the "Copy from Mai Publisher" button.
	 *
	 * Fills the Matomo fields below with Mai Publisher's values client-side so the
	 * admin can review them before saving. Nothing is written until Save Changes.
	 *
	 * @since 1.3.5
	 *
	 * @return void
	 */
	public function render_copy_from_publisher(): void {
		$publisher = Publisher::get_matomo_settings();
		?>
		<button type="button" class="button" id="mai-analytics-copy-publisher">
			<?php esc_html_e( 'Copy from Mai Publisher', 'mai-analytics' ); ?>
		</button>
		<p class="mai-analytics-btn-status" style="display:none; margin:8px 0 0; font-weight:600;"></p>
		<p class="description">
			<?php
			printf(
				/* translators: 1: Matomo URL, 2: Matomo site ID, 3: whether a token is set */
				esc_html__( 'Fills the fields below with %1$s (site ID %2$s, %3$s). Review them, then save.', 'mai-analytics' ),
				esc_html( $publisher['matomo_url'] ),
				esc_html( $publisher['matomo_site_id'] ),
				$publisher['matomo_token']
					? esc_html__( 'token included', 'mai-analytics' )
					: esc_html__( 'no token set', 'mai-analytics' )
			);
			?>
		</p>
		<?php
	}

	/**
	 * Renders the cross-link to Mai Publisher's Matomo Tracking section, plus a
	 * mismatch warning when the two plugins are configured for Matomo with different
	 * credentials. Only outputs when Mai Publisher is active.
	 *
	 * @return void
	 */
	public function render_publisher_cross_link(): void {
		if ( ! class_exists( 'Mai_Publisher_Plugin' ) ) {
			return;
		}

		$publisher_url = admin_url( 'edit.php?post_type=mai_ad&page=settings#maipub_settings_matomo' );
		$mismatched    = Settings::detect_publisher_matomo_mismatch();

		if ( ! empty( $mismatched ) ) {
			$labels = [
				'matomo_url'     => __( 'URL', 'mai-analytics' ),
				'matomo_site_id' => __( 'Site ID', 'mai-analytics' ),
				'matomo_token'   => __( 'Token', 'mai-analytics' ),
			];
			$names = array_map( fn( $k ) => $labels[ $k ] ?? $k, $mismatched );
			$list  = implode( ', ', $names );
			?>
			<div class="notice notice-warning inline" style="margin:0 0 12px;padding:8px 12px;">
				<p style="margin:0;">
					<strong><?php esc_html_e( 'Heads up:', 'mai-analytics' ); ?></strong>
					<?php
					printf(
						/* translators: %s: comma-separated list of mismatched field names */
						esc_html__( 'Mai Publisher\'s Matomo Tracking is configured with different credentials than Mai Analytics (mismatched: %s). Both should usually point to the same Matomo instance.', 'mai-analytics' ),
						esc_html( $list )
					);
					?>
				</p>
			</div>
			<?php
		}
		?>
		<p class="description">
			<?php esc_html_e( 'Looking for client-side Matomo tracking config (content tracking, PPID)?', 'mai-analytics' ); ?>
			<a href="<?php echo esc_url( $publisher_url ); ?>"><?php esc_html_e( 'See Mai Publisher → Settings → Matomo Tracking', 'mai-analytics' ); ?></a>
		</p>
		<?php
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
			id="mai-analytics-<?php echo esc_attr( $key ); ?>"
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
