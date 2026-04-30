<?php

namespace Mai\Analytics;

class Admin {

	/**
	 * Registers the admin menu page and asset enqueue hook.
	 */
	public function __construct() {
		add_action( 'admin_menu', [ $this, 'register_menu' ], 12 );
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_assets' ] );
	}

	/**
	 * Registers the analytics submenu page under Mai Theme or Settings.
	 *
	 * @return void
	 */
	public function register_menu(): void {
		if ( class_exists( 'Mai_Engine' ) ) {
			add_submenu_page(
				'mai-theme',
				__( 'Mai Analytics', 'mai-analytics' ),
				__( 'Mai Analytics', 'mai-analytics' ),
				'edit_others_posts',
				'mai-analytics',
				[ $this, 'render_page' ]
			);
		} else {
			add_options_page(
				__( 'Mai Analytics', 'mai-analytics' ),
				__( 'Mai Analytics', 'mai-analytics' ),
				'edit_others_posts',
				'mai-analytics',
				[ $this, 'render_page' ]
			);
		}
	}

	/**
	 * Enqueues dashboard CSS and JS on the analytics page only.
	 *
	 * @param string $hook The current admin page hook suffix.
	 *
	 * @return void
	 */
	public function enqueue_assets( string $hook ): void {
		if ( ! str_contains( $hook, 'mai-analytics' ) ) {
			return;
		}

		wp_enqueue_style(
			'tom-select',
			MAI_ANALYTICS_PLUGIN_URL . 'assets/css/tom-select.min.css',
			[],
			'2.4.1'
		);

		wp_enqueue_script(
			'tom-select',
			MAI_ANALYTICS_PLUGIN_URL . 'assets/js/tom-select.complete.min.js',
			[],
			'2.4.1',
			true
		);

		$css_file = MAI_ANALYTICS_PLUGIN_DIR . 'assets/css/admin-dashboard.css';
		$js_file  = MAI_ANALYTICS_PLUGIN_DIR . 'assets/js/admin-dashboard.js';

		wp_enqueue_style(
			'mai-analytics-admin',
			MAI_ANALYTICS_PLUGIN_URL . 'assets/css/admin-dashboard.css',
			[ 'tom-select' ],
			MAI_ANALYTICS_VERSION . '.' . filemtime( $css_file )
		);

		wp_enqueue_script(
			'mai-analytics-admin',
			MAI_ANALYTICS_PLUGIN_URL . 'assets/js/admin-dashboard.js',
			[ 'tom-select' ],
			MAI_ANALYTICS_VERSION . '.' . filemtime( $js_file ),
			true
		);

		wp_localize_script( 'mai-analytics-admin', 'maiAnalytics', [
			'restBase'   => esc_url_raw( rest_url( 'mai-analytics/v1/admin/' ) ),
			'nonce'      => wp_create_nonce( 'wp_rest' ),
			'dataSource' => Settings::get( 'data_source' ),
		] );

		// Settings tab assets.
		if ( 'settings' === ( $_GET['tab'] ?? '' ) ) {
			wp_add_inline_style( 'wp-admin', '
				.mai-analytics-provider-status,
				.mai-analytics-provider-matomo { display: none; }
				:has(#mai-analytics-data-source option[value="site_kit"]:checked) .mai-analytics-provider-status,
				:has(#mai-analytics-data-source option[value="matomo"]:checked) .mai-analytics-provider-status,
				:has(#mai-analytics-data-source option[value="matomo"]:checked) .mai-analytics-provider-matomo { display: table-row; }
			' );

			$settings_js = MAI_ANALYTICS_PLUGIN_DIR . 'assets/js/admin-settings.js';

			wp_enqueue_script(
				'mai-analytics-admin-settings',
				MAI_ANALYTICS_PLUGIN_URL . 'assets/js/admin-settings.js',
				[],
				MAI_ANALYTICS_VERSION . '.' . filemtime( $settings_js ),
				true
			);

			wp_localize_script( 'mai-analytics-admin-settings', 'maiAnalyticsSettings', [
				'restBase' => esc_url_raw( rest_url( 'mai-analytics/v1/admin/' ) ),
				'nonce'    => wp_create_nonce( 'wp_rest' ),
			] );
		}
	}

	/**
	 * Renders the analytics page with tab navigation.
	 *
	 * @return void
	 */
	public function render_page(): void {
		$tab         = sanitize_key( $_GET['tab'] ?? 'dashboard' );
		$subtab_raw  = sanitize_key( $_GET['subtab'] ?? 'posts' );
		$valid_sub   = [ 'posts', 'terms', 'authors', 'archives' ];
		$subtab      = in_array( $subtab_raw, $valid_sub, true ) ? $subtab_raw : 'posts';
		$is_external = 'self_hosted' !== Settings::get( 'data_source' );
		$base_url    = admin_url( 'admin.php?page=mai-analytics' );
		?>
		<div class="wrap mai-analytics-wrap">
			<h1><?php printf( '%s (v%s)', esc_html__( 'Mai Analytics', 'mai-analytics' ), MAI_ANALYTICS_VERSION ); ?></h1>

			<nav class="nav-tab-wrapper" style="margin-bottom:20px;">
				<a href="<?php echo esc_url( $base_url ); ?>" class="nav-tab <?php echo 'dashboard' === $tab ? 'nav-tab-active' : ''; ?>"><?php esc_html_e( 'Dashboard', 'mai-analytics' ); ?></a>
				<?php if ( current_user_can( 'manage_options' ) ) : ?>
				<a href="<?php echo esc_url( $base_url . '&tab=settings' ); ?>" class="nav-tab <?php echo 'settings' === $tab ? 'nav-tab-active' : ''; ?>"><?php esc_html_e( 'Settings', 'mai-analytics' ); ?></a>
				<?php endif; ?>
			</nav>

			<?php
			if ( 'settings' === $tab && current_user_can( 'manage_options' ) ) {
				$this->render_settings_tab( $is_external );
			} else {
				$this->render_dashboard_tab( $is_external, $subtab );
			}
			?>
		</div>
		<?php
	}

	/**
	 * Renders a dismissible admin notice when the most recent provider sync
	 * stored an error in the `mai_analytics_provider_error` transient. Shows
	 * the captured timestamp as a relative age so the operator can tell at a
	 * glance whether they're looking at a fresh failure or stale residue.
	 *
	 * Hidden for users without `manage_options` (only admins should see
	 * provider failure detail). Dismiss button calls the REST endpoint that
	 * deletes the transient.
	 *
	 * @return void
	 */
	private function render_provider_error_notice(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$err = Sync::get_last_error();

		if ( '' === $err['message'] ) {
			return;
		}

		$age = $err['time'] > 0
			? sprintf( __( '%s ago', 'mai-analytics' ), human_time_diff( $err['time'], time() ) )
			: __( 'unknown age', 'mai-analytics' );

		$nonce       = wp_create_nonce( 'wp_rest' );
		$dismiss_url = rest_url( 'mai-analytics/v1/admin/dismiss-error' );
		?>
		<div class="notice notice-error mai-analytics-provider-error" data-rest-url="<?php echo esc_url( $dismiss_url ); ?>" data-nonce="<?php echo esc_attr( $nonce ); ?>" style="display:flex;align-items:center;gap:1em;justify-content:space-between;">
			<p style="margin:0.5em 0;">
				<strong><?php esc_html_e( 'Provider error:', 'mai-analytics' ); ?></strong>
				<?php echo esc_html( $err['message'] ); ?>
				<em style="opacity:.7;">— <?php echo esc_html( $age ); ?></em>
			</p>
			<button type="button" class="button button-small mai-analytics-provider-error__dismiss"><?php esc_html_e( 'Dismiss', 'mai-analytics' ); ?></button>
		</div>
		<script>
			(function(){
				var el = document.currentScript.previousElementSibling;
				while ( el && ! el.classList.contains( 'mai-analytics-provider-error' ) ) {
					el = el.previousElementSibling;
				}
				if ( ! el ) { return; }
				var btn = el.querySelector( '.mai-analytics-provider-error__dismiss' );
				if ( ! btn ) { return; }
				btn.addEventListener( 'click', function(){
					var url   = el.getAttribute( 'data-rest-url' );
					var nonce = el.getAttribute( 'data-nonce' );
					btn.disabled = true;
					fetch( url, { method: 'POST', headers: { 'X-WP-Nonce': nonce } } )
						.finally( function(){ el.parentNode.removeChild( el ); } );
				} );
			})();
		</script>
		<?php
	}

	/**
	 * Renders the dashboard tab content.
	 *
	 * @param bool   $is_external Whether an external provider is active.
	 * @param string $subtab      The active sub-tab: posts|terms|authors|archives.
	 *
	 * @return void
	 */
	private function render_dashboard_tab( bool $is_external, string $subtab = 'posts' ): void {
		$base_url = admin_url( 'admin.php?page=mai-analytics' );
		$subtabs  = [
			'posts'    => __( 'Posts', 'mai-analytics' ),
			'terms'    => __( 'Terms', 'mai-analytics' ),
			'authors'  => __( 'Authors', 'mai-analytics' ),
			'archives' => __( 'Archives', 'mai-analytics' ),
		];

		$this->render_provider_error_notice();
		?>
		<!-- Summary Cards -->
		<div class="mai-analytics-cards">
			<div class="mai-analytics-card" data-card="total_views">
				<span class="mai-analytics-card__value">—</span>
				<span class="mai-analytics-card__label"><?php esc_html_e( 'Total Views', 'mai-analytics' ); ?></span>
			</div>
			<div class="mai-analytics-card" data-card="trending_views">
				<span class="mai-analytics-card__value">—</span>
				<span class="mai-analytics-card__label"><?php esc_html_e( 'Trending Views', 'mai-analytics' ); ?></span>
			</div>
			<div class="mai-analytics-card" data-card="trending_count">
				<span class="mai-analytics-card__value">—</span>
				<span class="mai-analytics-card__label"><?php esc_html_e( 'Trending Pages', 'mai-analytics' ); ?></span>
			</div>
			<div class="mai-analytics-card" data-card="last_sync">
				<span class="mai-analytics-card__value">—</span>
				<span class="mai-analytics-card__label"><?php esc_html_e( 'Last Sync', 'mai-analytics' ); ?></span>
			</div>
		</div>

		<!-- Tabs -->
		<nav class="nav-tab-wrapper mai-analytics-tabs">
			<?php foreach ( $subtabs as $slug => $label ) : ?>
				<a href="<?php echo esc_url( $base_url . '&subtab=' . $slug ); ?>" class="nav-tab <?php echo $slug === $subtab ? 'nav-tab-active' : ''; ?>" data-tab="<?php echo esc_attr( $slug ); ?>"><?php echo esc_html( $label ); ?></a>
			<?php endforeach; ?>
		</nav>

		<!-- Filters -->
		<div class="mai-analytics-filters">
			<select id="mai-analytics-post-type" class="mai-analytics-filters__field mai-analytics-filters__field--posts" placeholder="<?php esc_attr_e( 'All Post Types', 'mai-analytics' ); ?>"></select>
			<select id="mai-analytics-taxonomy" class="mai-analytics-filters__field mai-analytics-filters__field--posts mai-analytics-filters__field--terms" placeholder="<?php esc_attr_e( 'All Taxonomies', 'mai-analytics' ); ?>"></select>
			<select id="mai-analytics-term" class="mai-analytics-filters__field mai-analytics-filters__field--posts" placeholder="<?php esc_attr_e( 'Search terms...', 'mai-analytics' ); ?>" multiple></select>
			<select id="mai-analytics-author" class="mai-analytics-filters__field mai-analytics-filters__field--posts" placeholder="<?php esc_attr_e( 'All Authors', 'mai-analytics' ); ?>" multiple></select>
			<div class="mai-analytics-filters__field mai-analytics-filters__field--posts mai-analytics-filters__published">
				<select id="mai-analytics-published-days" placeholder="<?php esc_attr_e( 'All Publish Dates', 'mai-analytics' ); ?>">
					<option value="" hidden></option>
					<option value="3"><?php esc_html_e( '3 days', 'mai-analytics' ); ?></option>
					<option value="7"><?php esc_html_e( '7 days (1 week)', 'mai-analytics' ); ?></option>
					<option value="14"><?php esc_html_e( '14 days (2 weeks)', 'mai-analytics' ); ?></option>
					<option value="21"><?php esc_html_e( '21 days (3 weeks)', 'mai-analytics' ); ?></option>
					<option value="28"><?php esc_html_e( '28 days (4 weeks)', 'mai-analytics' ); ?></option>
					<option value="60"><?php esc_html_e( '60 days (2 months)', 'mai-analytics' ); ?></option>
					<option value="90"><?php esc_html_e( '90 days (3 months)', 'mai-analytics' ); ?></option>
					<option value="custom"><?php esc_html_e( 'Custom Days', 'mai-analytics' ); ?></option>
				</select>
				<input type="number" id="mai-analytics-custom-days" class="mai-analytics-filters__custom-days" min="1" max="365" placeholder="<?php esc_attr_e( 'Days', 'mai-analytics' ); ?>">
			</div>
		</div>

		<!-- Table Controls -->
		<div class="mai-analytics-table-controls">
			<div class="mai-analytics-search-wrap">
				<input type="text" id="mai-analytics-search" placeholder="<?php esc_attr_e( 'Search by title/name...', 'mai-analytics' ); ?>">
				<span class="mai-analytics-search-spinner" style="display:none;"></span>
			</div>
			<select id="mai-analytics-per-page">
				<option value="25"><?php esc_html_e( '25 per page', 'mai-analytics' ); ?></option>
				<option value="50"><?php esc_html_e( '50 per page', 'mai-analytics' ); ?></option>
				<option value="100"><?php esc_html_e( '100 per page', 'mai-analytics' ); ?></option>
			</select>
		</div>

		<!-- Table -->
		<div class="mai-analytics-table-wrap">
			<div class="mai-analytics-loading"><?php esc_html_e( 'Loading...', 'mai-analytics' ); ?></div>
			<table class="wp-list-table widefat striped mai-analytics-table" style="display:none;">
				<thead><tr></tr></thead>
				<tbody></tbody>
			</table>
			<div class="mai-analytics-empty" style="display:none;">
				<p><?php esc_html_e( 'No data yet. Views will appear here once visitors start browsing your site.', 'mai-analytics' ); ?></p>
			</div>
		</div>

		<!-- Pagination -->
		<div class="mai-analytics-pagination" style="display:none;">
			<span class="mai-analytics-pagination__info"></span>
			<span class="mai-analytics-pagination__buttons"></span>
		</div>
		<?php
	}

	/**
	 * Renders the settings tab content.
	 *
	 * @param bool $is_external Whether an external provider is active.
	 *
	 * @return void
	 */
	private function render_settings_tab( bool $is_external ): void {
		$admin_settings = new AdminSettings();
		?>
		<form method="post" action="options.php">
			<?php
			settings_fields( 'mai_analytics_settings' );
			do_settings_sections( 'mai-analytics-settings' );
			submit_button();
			?>
		</form>

		<?php if ( $is_external ) : ?>
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
						<?php esc_html_e( 'Fetch stats from the provider for all posts, terms, and authors, not just ones with recent traffic. Use this after switching providers, or to populate stats for pages that haven\'t been visited yet. Most-recent content is processed first; this may take a while on large sites.', 'mai-analytics' ); ?>
					</p>
				</td>
			</tr>
		</table>
		<?php endif; ?>

		<hr>
		<h2><?php esc_html_e( 'Health Check', 'mai-analytics' ); ?></h2>
		<p class="description" style="margin-bottom:12px;">
			<?php esc_html_e( 'Run diagnostics to verify plugin health, database state, cron, provider connectivity, and REST endpoints.', 'mai-analytics' ); ?>
		</p>
		<button type="button" class="button" id="mai-analytics-health-check">
			<?php esc_html_e( 'Run Health Check', 'mai-analytics' ); ?>
		</button>
		<div id="mai-analytics-health-results" style="display:none; margin-top:16px; background:#fff; border:1px solid #c3c4c7; border-radius:4px; padding:16px;"></div>
		<?php
	}
}
