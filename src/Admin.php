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
	 * Enqueues Chart.js, dashboard CSS, and dashboard JS on the analytics page only.
	 *
	 * @param string $hook The current admin page hook suffix.
	 *
	 * @return void
	 */
	public function enqueue_assets( string $hook ): void {
		if ( ! str_contains( $hook, 'mai-analytics' ) ) {
			return;
		}

		wp_enqueue_script(
			'chartjs',
			'https://cdn.jsdelivr.net/npm/chart.js@4/dist/chart.umd.min.js',
			[],
			'4.4.7',
			true
		);

		wp_enqueue_style(
			'mai-analytics-admin',
			MAI_ANALYTICS_PLUGIN_URL . 'assets/css/admin-dashboard.css',
			[],
			MAI_ANALYTICS_VERSION
		);

		wp_enqueue_script(
			'mai-analytics-admin',
			MAI_ANALYTICS_PLUGIN_URL . 'assets/js/admin-dashboard.js',
			[ 'chartjs' ],
			MAI_ANALYTICS_VERSION,
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
	}

	/**
	 * Renders the analytics page with tab navigation.
	 *
	 * @return void
	 */
	public function render_page(): void {
		$tab         = sanitize_key( $_GET['tab'] ?? 'dashboard' );
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
				$this->render_dashboard_tab( $is_external );
			}
			?>
		</div>
		<?php
	}

	/**
	 * Renders the dashboard tab content.
	 *
	 * @param bool $is_external Whether an external provider is active.
	 *
	 * @return void
	 */
	private function render_dashboard_tab( bool $is_external ): void {
		?>
		<!-- Summary Cards -->
		<div class="mai-analytics-cards">
			<div class="mai-analytics-card" data-card="total_views">
				<span class="mai-analytics-card__value">—</span>
				<span class="mai-analytics-card__label"><?php esc_html_e( 'Total Views', 'mai-analytics' ); ?></span>
			</div>
			<?php if ( $is_external ) : ?>
			<div class="mai-analytics-card" data-card="last_sync">
				<span class="mai-analytics-card__value">—</span>
				<span class="mai-analytics-card__label"><?php esc_html_e( 'Last Sync', 'mai-analytics' ); ?></span>
			</div>
			<?php else : ?>
			<div class="mai-analytics-card" data-card="views_today">
				<span class="mai-analytics-card__value">—</span>
				<span class="mai-analytics-card__label"><?php esc_html_e( 'Views Today', 'mai-analytics' ); ?></span>
			</div>
			<?php endif; ?>
			<div class="mai-analytics-card" data-card="trending_count">
				<span class="mai-analytics-card__value">—</span>
				<span class="mai-analytics-card__label"><?php esc_html_e( 'Trending Objects', 'mai-analytics' ); ?></span>
			</div>
			<?php if ( ! $is_external ) : ?>
			<div class="mai-analytics-card" data-card="buffer_rows">
				<span class="mai-analytics-card__value">—</span>
				<span class="mai-analytics-card__label"><?php esc_html_e( 'Buffer Rows', 'mai-analytics' ); ?></span>
			</div>
			<?php endif; ?>
		</div>

		<!-- Chart (hidden in external provider mode) -->
		<div class="mai-analytics-chart-section"<?php echo $is_external ? ' style="display:none;"' : ''; ?>>
			<div class="mai-analytics-chart-controls">
				<div class="mai-analytics-toggle-group" data-toggle="metric">
					<button class="button active" data-value="total"><?php esc_html_e( 'Total Views', 'mai-analytics' ); ?></button>
					<button class="button" data-value="trending"><?php esc_html_e( 'Trending', 'mai-analytics' ); ?></button>
				</div>
				<div class="mai-analytics-toggle-group" data-toggle="source">
					<button class="button active" data-value="all"><?php esc_html_e( 'All Sources', 'mai-analytics' ); ?></button>
					<button class="button" data-value="web"><?php esc_html_e( 'Web', 'mai-analytics' ); ?></button>
					<button class="button" data-value="app"><?php esc_html_e( 'App', 'mai-analytics' ); ?></button>
				</div>
			</div>
			<div class="mai-analytics-chart-wrap">
				<canvas id="mai-analytics-chart" height="300"></canvas>
			</div>
		</div>

		<!-- Tabs -->
		<nav class="nav-tab-wrapper mai-analytics-tabs">
			<a href="#" class="nav-tab nav-tab-active" data-tab="posts"><?php esc_html_e( 'Posts', 'mai-analytics' ); ?></a>
			<a href="#" class="nav-tab" data-tab="terms"><?php esc_html_e( 'Terms', 'mai-analytics' ); ?></a>
			<a href="#" class="nav-tab" data-tab="authors"><?php esc_html_e( 'Authors', 'mai-analytics' ); ?></a>
			<a href="#" class="nav-tab" data-tab="archives"><?php esc_html_e( 'Archives', 'mai-analytics' ); ?></a>
		</nav>

		<!-- Filters -->
		<div class="mai-analytics-filters">
			<select id="mai-analytics-orderby">
				<option value="views"><?php esc_html_e( 'Order by Views', 'mai-analytics' ); ?></option>
				<option value="trending"><?php esc_html_e( 'Order by Trending', 'mai-analytics' ); ?></option>
			</select>
			<select id="mai-analytics-post-type" class="mai-analytics-filter-posts">
				<option value=""><?php esc_html_e( 'All Post Types', 'mai-analytics' ); ?></option>
			</select>
			<select id="mai-analytics-taxonomy" class="mai-analytics-filter-posts mai-analytics-filter-terms">
				<option value=""><?php esc_html_e( 'All Taxonomies', 'mai-analytics' ); ?></option>
			</select>
			<select id="mai-analytics-term" class="mai-analytics-filter-posts" style="display:none;">
				<option value=""><?php esc_html_e( 'All Terms', 'mai-analytics' ); ?></option>
			</select>
			<select id="mai-analytics-author" class="mai-analytics-filter-posts">
				<option value=""><?php esc_html_e( 'All Authors', 'mai-analytics' ); ?></option>
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
						<?php esc_html_e( 'Fetch stats from the provider for all posts, terms, and authors — not just ones with recent traffic. Use this after switching providers, or to populate stats for pages that haven\'t been visited yet. This may take a while on large sites.', 'mai-analytics' ); ?>
					</p>
				</td>
			</tr>
		</table>
		<?php endif; ?>
		<?php
	}
}
