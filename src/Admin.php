<?php

namespace Mai\Views;

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
				__( 'Mai Views', 'mai-views' ),
				__( 'Mai Views', 'mai-views' ),
				'edit_others_posts',
				'mai-views',
				[ $this, 'render_page' ]
			);
		} else {
			add_options_page(
				__( 'Mai Views', 'mai-views' ),
				__( 'Mai Views', 'mai-views' ),
				'edit_others_posts',
				'mai-views',
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
		if ( ! str_contains( $hook, 'mai-views' ) ) {
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
			'tom-select',
			MAI_VIEWS_PLUGIN_URL . 'assets/css/tom-select.min.css',
			[],
			'2.4.1'
		);

		wp_enqueue_script(
			'tom-select',
			MAI_VIEWS_PLUGIN_URL . 'assets/js/tom-select.complete.min.js',
			[],
			'2.4.1',
			true
		);

		$css_file = MAI_VIEWS_PLUGIN_DIR . 'assets/css/admin-dashboard.css';
		$js_file  = MAI_VIEWS_PLUGIN_DIR . 'assets/js/admin-dashboard.js';

		wp_enqueue_style(
			'mai-views-admin',
			MAI_VIEWS_PLUGIN_URL . 'assets/css/admin-dashboard.css',
			[ 'tom-select' ],
			MAI_VIEWS_VERSION . '.' . filemtime( $css_file )
		);

		wp_enqueue_script(
			'mai-views-admin',
			MAI_VIEWS_PLUGIN_URL . 'assets/js/admin-dashboard.js',
			[ 'chartjs', 'tom-select' ],
			MAI_VIEWS_VERSION . '.' . filemtime( $js_file ),
			true
		);

		wp_localize_script( 'mai-views-admin', 'maiViews', [
			'restBase'   => esc_url_raw( rest_url( 'mai-views/v1/admin/' ) ),
			'nonce'      => wp_create_nonce( 'wp_rest' ),
			'dataSource' => Settings::get( 'data_source' ),
		] );

		// Settings tab assets.
		if ( 'settings' === ( $_GET['tab'] ?? '' ) ) {
			wp_add_inline_style( 'wp-admin', '
				.mai-views-provider-status,
				.mai-views-provider-matomo { display: none; }
				:has(#mai-views-data-source option[value="site_kit"]:checked) .mai-views-provider-status,
				:has(#mai-views-data-source option[value="matomo"]:checked) .mai-views-provider-status,
				:has(#mai-views-data-source option[value="matomo"]:checked) .mai-views-provider-matomo { display: table-row; }
			' );

			$settings_js = MAI_VIEWS_PLUGIN_DIR . 'assets/js/admin-settings.js';

			wp_enqueue_script(
				'mai-views-admin-settings',
				MAI_VIEWS_PLUGIN_URL . 'assets/js/admin-settings.js',
				[],
				MAI_VIEWS_VERSION . '.' . filemtime( $settings_js ),
				true
			);

			wp_localize_script( 'mai-views-admin-settings', 'maiViewsSettings', [
				'restBase' => esc_url_raw( rest_url( 'mai-views/v1/admin/' ) ),
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
		$base_url    = admin_url( 'admin.php?page=mai-views' );
		?>
		<div class="wrap mai-views-wrap">
			<h1><?php printf( '%s (v%s)', esc_html__( 'Mai Views', 'mai-views' ), MAI_VIEWS_VERSION ); ?></h1>

			<nav class="nav-tab-wrapper" style="margin-bottom:20px;">
				<a href="<?php echo esc_url( $base_url ); ?>" class="nav-tab <?php echo 'dashboard' === $tab ? 'nav-tab-active' : ''; ?>"><?php esc_html_e( 'Dashboard', 'mai-views' ); ?></a>
				<?php if ( current_user_can( 'manage_options' ) ) : ?>
				<a href="<?php echo esc_url( $base_url . '&tab=settings' ); ?>" class="nav-tab <?php echo 'settings' === $tab ? 'nav-tab-active' : ''; ?>"><?php esc_html_e( 'Settings', 'mai-views' ); ?></a>
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
		<div class="mai-views-cards">
			<div class="mai-views-card" data-card="total_views">
				<span class="mai-views-card__value">—</span>
				<span class="mai-views-card__label"><?php esc_html_e( 'Total Views', 'mai-views' ); ?></span>
			</div>
			<div class="mai-views-card" data-card="trending_views">
				<span class="mai-views-card__value">—</span>
				<span class="mai-views-card__label"><?php esc_html_e( 'Trending Views', 'mai-views' ); ?></span>
			</div>
			<div class="mai-views-card" data-card="trending_count">
				<span class="mai-views-card__value">—</span>
				<span class="mai-views-card__label"><?php esc_html_e( 'Trending Pages', 'mai-views' ); ?></span>
			</div>
			<div class="mai-views-card" data-card="last_sync">
				<span class="mai-views-card__value">—</span>
				<span class="mai-views-card__label"><?php esc_html_e( 'Last Sync', 'mai-views' ); ?></span>
			</div>
		</div>

		<!-- Chart -->
		<div class="mai-views-chart-section">
			<div class="mai-views-chart-wrap">
				<canvas id="mai-views-chart" height="250"></canvas>
			</div>
		</div>

		<!-- Tabs -->
		<nav class="nav-tab-wrapper mai-views-tabs">
			<a href="#" class="nav-tab nav-tab-active" data-tab="posts"><?php esc_html_e( 'Posts', 'mai-views' ); ?></a>
			<a href="#" class="nav-tab" data-tab="terms"><?php esc_html_e( 'Terms', 'mai-views' ); ?></a>
			<a href="#" class="nav-tab" data-tab="authors"><?php esc_html_e( 'Authors', 'mai-views' ); ?></a>
			<a href="#" class="nav-tab" data-tab="archives"><?php esc_html_e( 'Archives', 'mai-views' ); ?></a>
		</nav>

		<!-- Filters -->
		<div class="mai-views-filters">
			<select id="mai-views-post-type" class="mai-views-filter-posts">
				<option value=""><?php esc_html_e( 'All Post Types', 'mai-views' ); ?></option>
			</select>
			<select id="mai-views-taxonomy" class="mai-views-filter-posts mai-views-filter-terms">
				<option value=""><?php esc_html_e( 'All Taxonomies', 'mai-views' ); ?></option>
			</select>
			<select id="mai-views-term" class="mai-views-tom-select" style="display:none;" placeholder="<?php esc_attr_e( 'Search terms...', 'mai-views' ); ?>" multiple></select>
			<select id="mai-views-author" class="mai-views-tom-select mai-views-filter-posts" placeholder="<?php esc_attr_e( 'Search authors...', 'mai-views' ); ?>" multiple></select>
		</div>

		<!-- Active Filters -->
		<div class="mai-views-active-filters" style="display:none;"></div>

		<!-- Table Controls -->
		<div class="mai-views-table-controls">
			<div class="mai-views-search-wrap">
				<input type="text" id="mai-views-search" placeholder="<?php esc_attr_e( 'Search by title/name...', 'mai-views' ); ?>">
				<span class="mai-views-search-spinner" style="display:none;"></span>
			</div>
			<select id="mai-views-per-page">
				<option value="25"><?php esc_html_e( '25 per page', 'mai-views' ); ?></option>
				<option value="50"><?php esc_html_e( '50 per page', 'mai-views' ); ?></option>
				<option value="100"><?php esc_html_e( '100 per page', 'mai-views' ); ?></option>
			</select>
		</div>

		<!-- Table -->
		<div class="mai-views-table-wrap">
			<div class="mai-views-loading"><?php esc_html_e( 'Loading...', 'mai-views' ); ?></div>
			<table class="wp-list-table widefat striped mai-views-table" style="display:none;">
				<thead><tr></tr></thead>
				<tbody></tbody>
			</table>
			<div class="mai-views-empty" style="display:none;">
				<p><?php esc_html_e( 'No data yet. Views will appear here once visitors start browsing your site.', 'mai-views' ); ?></p>
			</div>
		</div>

		<!-- Pagination -->
		<div class="mai-views-pagination" style="display:none;">
			<span class="mai-views-pagination__info"></span>
			<span class="mai-views-pagination__buttons"></span>
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
			settings_fields( 'mai_views_settings' );
			do_settings_sections( 'mai-views-settings' );
			submit_button();
			?>
		</form>

		<?php if ( $is_external ) : ?>
		<?php $last_sync = get_option( 'mai_views_provider_last_sync', 0 ); ?>
		<hr>
		<h2><?php esc_html_e( 'Sync Tools', 'mai-views' ); ?></h2>
		<?php if ( $last_sync ) : ?>
			<p class="description" style="margin-bottom:16px;">
				<?php
				printf(
					/* translators: %s: formatted date/time */
					esc_html__( 'Last synced: %s', 'mai-views' ),
					esc_html( wp_date( 'M j, Y g:i a', $last_sync ) )
				);
				?>
			</p>
		<?php endif; ?>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><?php esc_html_e( 'Sync Now', 'mai-views' ); ?></th>
				<td>
					<button type="button" class="button" id="mai-views-sync-now">
						<?php esc_html_e( 'Sync Now', 'mai-views' ); ?>
					</button>
					<p class="mai-views-btn-status" style="display:none; margin:8px 0 0; font-weight:600;"></p>
					<p class="description">
						<?php esc_html_e( 'Process any pages that have received traffic since the last sync. This normally runs automatically every 15 minutes.', 'mai-views' ); ?>
					</p>
				</td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Warm All Stats', 'mai-views' ); ?></th>
				<td>
					<button type="button" class="button" id="mai-views-warm">
						<?php esc_html_e( 'Warm Stats', 'mai-views' ); ?>
					</button>
					<p class="mai-views-btn-status" style="display:none; margin:8px 0 0; font-weight:600;"></p>
					<p class="description">
						<?php esc_html_e( 'Fetch stats from the provider for all posts, terms, and authors — not just ones with recent traffic. Use this after switching providers, or to populate stats for pages that haven\'t been visited yet. This may take a while on large sites.', 'mai-views' ); ?>
					</p>
				</td>
			</tr>
		</table>
		<?php endif; ?>

		<hr>
		<h2><?php esc_html_e( 'Health Check', 'mai-views' ); ?></h2>
		<p class="description" style="margin-bottom:12px;">
			<?php esc_html_e( 'Run diagnostics to verify plugin health, database state, cron, provider connectivity, and REST endpoints.', 'mai-views' ); ?>
		</p>
		<button type="button" class="button" id="mai-views-health-check">
			<?php esc_html_e( 'Run Health Check', 'mai-views' ); ?>
		</button>
		<div id="mai-views-health-results" style="display:none; margin-top:16px; background:#fff; border:1px solid #c3c4c7; border-radius:4px; padding:16px;"></div>
		<?php
	}
}
