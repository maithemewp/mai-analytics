<?php

namespace Mai\Analytics;

class Admin {

	/**
	 * The Posts tab's publish date window, in days, when the URL doesn't set one.
	 *
	 * @since 1.3.6
	 */
	private const DEFAULT_PUBLISHED_DAYS = 30;

	/**
	 * Registers the admin menu page and asset enqueue hook.
	 */
	public function __construct() {
		add_action( 'admin_menu', [ $this, 'register_menu' ], 12 );
		add_filter( 'submenu_file', [ $this, 'fix_submenu_highlight' ] );
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_assets' ] );
	}

	/**
	 * Forces the "Analytics" submenu item to highlight as current.
	 *
	 * Our page loads at admin.php?page=mai-analytics rather than through the
	 * parent slug's own file (edit.php, options-general.php), so WP core's
	 * highlight check in wp-admin/menu-header.php never matches it.
	 *
	 * @param string $submenu_file The current submenu file.
	 *
	 * @return string
	 */
	public function fix_submenu_highlight( $submenu_file ) {
		if ( isset( $_GET['page'] ) && 'mai-analytics' === $_GET['page'] ) {
			return 'mai-analytics';
		}

		return $submenu_file;
	}

	/**
	 * Registers the analytics submenu page. Parent menu is chosen based on which
	 * companion plugin is active, in priority order:
	 *
	 * 1. Mai Publisher  → submenu under Mai Ads CPT
	 * 2. Mai Engine     → submenu under Mai Theme
	 * 3. Neither active → submenu under WordPress Settings
	 *
	 * @return void
	 */
	public function register_menu(): void {
		if ( class_exists( 'Mai_Publisher_Plugin' ) ) {
			add_submenu_page(
				'edit.php?post_type=mai_ad',
				__( 'Mai Analytics', 'mai-analytics' ),
				__( 'Analytics', 'mai-analytics' ),
				'edit_others_posts',
				'mai-analytics',
				[ $this, 'render_page' ]
			);
		} elseif ( class_exists( 'Mai_Engine' ) ) {
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
			'2.6.2'
		);

		wp_enqueue_script(
			'tom-select',
			MAI_ANALYTICS_PLUGIN_URL . 'assets/js/tom-select.complete.min.js',
			[],
			'2.6.2',
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

		$is_settings = 'settings' === ( $_GET['tab'] ?? '' );

		wp_localize_script( 'mai-analytics-admin', 'maiAnalytics', [
			'restBase'   => esc_url_raw( rest_url( 'mai-analytics/v1/admin/' ) ),
			'nonce'      => wp_create_nonce( 'wp_rest' ),
			'dataSource' => Settings::get( 'data_source' ),
			'hasApp'     => ! $is_settings && $this->has_app_traffic(),
		] );

		// Settings tab assets.
		if ( $is_settings ) {
			// Rows are hidden by default and revealed to match whatever the data
			// source dropdown is showing right now, so the page reflects an
			// unsaved selection without a round trip.
			$hidden = [ '.mai-analytics-provider-matomo' ];
			$shown  = [ ':has(#mai-analytics-data-source option[value="matomo"]:checked) .mai-analytics-provider-matomo' ];

			foreach ( apply_filters( 'mai_analytics_providers', [] ) as $provider ) {
				$slug  = preg_replace( '/[^a-z0-9_-]/', '', strtolower( $provider->get_slug() ) );
				$class = '.mai-analytics-provider-status-' . $slug;

				$hidden[] = $class;
				$shown[]  = sprintf( ':has(#mai-analytics-data-source option[value="%s"]:checked) %s', $slug, $class );
			}

			wp_add_inline_style( 'wp-admin', sprintf(
				'%s { display: none; } %s { display: table-row; }',
				implode( ",\n", $hidden ),
				implode( ",\n", $shown )
			) );

			$settings_js = MAI_ANALYTICS_PLUGIN_DIR . 'assets/js/admin-settings.js';

			wp_enqueue_script(
				'mai-analytics-admin-settings',
				MAI_ANALYTICS_PLUGIN_URL . 'assets/js/admin-settings.js',
				[],
				MAI_ANALYTICS_VERSION . '.' . filemtime( $settings_js ),
				true
			);

			wp_localize_script( 'mai-analytics-admin-settings', 'maiAnalyticsSettings', [
				'restBase'        => esc_url_raw( rest_url( 'mai-analytics/v1/admin/' ) ),
				'nonce'           => wp_create_nonce( 'wp_rest' ),
				'publisherMatomo' => Publisher::get_copyable_matomo_settings(),
			] );
		}
	}

	/**
	 * Whether the site has any app traffic at all.
	 *
	 * App-less sites (the vast majority) get the Web/App breakdown columns
	 * hidden in the dashboard, since they would just repeat `views, views, 0`.
	 * Cached for 5 minutes to keep dashboard load fast on big sites.
	 *
	 * @since 1.3.6
	 *
	 * @return bool
	 */
	private function has_app_traffic(): bool {
		$has_app = get_transient( 'mai_analytics_has_app' );

		if ( false !== $has_app ) {
			return (bool) $has_app;
		}

		global $wpdb;

		$app_total = (int) $wpdb->get_var( "SELECT COALESCE(SUM(meta_value), 0) FROM $wpdb->postmeta WHERE meta_key = 'mai_views_app'" );

		if ( 0 === $app_total ) {
			$app_total = (int) $wpdb->get_var( "SELECT COALESCE(SUM(meta_value), 0) FROM $wpdb->termmeta WHERE meta_key = 'mai_views_app'" );
		}

		if ( 0 === $app_total ) {
			$app_total = (int) $wpdb->get_var( "SELECT COALESCE(SUM(meta_value), 0) FROM $wpdb->usermeta WHERE meta_key = 'mai_views_app'" );
		}

		if ( 0 === $app_total ) {
			$app_total = (int) array_sum( (array) get_option( 'mai_analytics_post_type_views_app', [] ) );
		}

		$has_app = $app_total > 0;

		set_transient( 'mai_analytics_has_app', $has_app ? 1 : 0, 5 * MINUTE_IN_SECONDS );

		return $has_app;
	}

	/**
	 * Reads the dashboard's filters from the URL, so a view can be linked.
	 *
	 * Uses `type` and `tax` rather than `post_type` and `taxonomy`, because
	 * wp-admin/admin.php reads those two on every admin page to set the
	 * current screen, which changes the menu parent. Sort order and page
	 * number are read by the JS, which owns them.
	 *
	 * @since 1.3.6
	 *
	 * @return array{
	 *     type: string,
	 *     type_label: string,
	 *     tax: string,
	 *     tax_label: string,
	 *     terms: array<int, string>,
	 *     authors: array<int, string>,
	 *     published: int,
	 *     search: string,
	 *     per_page: int,
	 * }
	 */
	private function get_dashboard_state(): array {
		// Query values can arrive as arrays (?type[]=x), so only scalars count.
		$get = static fn( string $key ): string => is_scalar( $_GET[ $key ] ?? null ) ? (string) wp_unslash( $_GET[ $key ] ) : '';
		$ids = static fn( string $key ): array => array_values( array_unique( array_filter( array_map( 'absint', explode( ',', $get( $key ) ) ) ) ) );

		$per_page = absint( $get( 'per_page' ) );

		$state = [
			'type'       => '',
			'type_label' => '',
			'tax'        => '',
			'tax_label'  => '',
			'terms'      => [],
			'authors'    => [],
			'published'  => '' !== $get( 'published' ) ? min( 365, absint( $get( 'published' ) ) ) : self::DEFAULT_PUBLISHED_DAYS,
			'search'     => sanitize_text_field( $get( 'search' ) ),
			'per_page'   => in_array( $per_page, [ 25, 50, 100 ], true ) ? $per_page : 25,
		];

		$post_type = get_post_type_object( sanitize_key( $get( 'type' ) ) );

		if ( $post_type && $post_type->public ) {
			$state['type']       = $post_type->name;
			$state['type_label'] = $post_type->labels->name;
		}

		$taxonomy = get_taxonomy( sanitize_key( $get( 'tax' ) ) );

		if ( $taxonomy && $taxonomy->public ) {
			$state['tax']       = $taxonomy->name;
			$state['tax_label'] = $taxonomy->labels->name;

			foreach ( $ids( 'terms' ) as $term_id ) {
				$term = get_term( $term_id, $taxonomy->name );

				if ( $term instanceof \WP_Term ) {
					$state['terms'][ $term->term_id ] = $term->name;
				}
			}
		}

		foreach ( $ids( 'authors' ) as $user_id ) {
			$user = get_userdata( $user_id );

			if ( $user ) {
				$state['authors'][ $user->ID ] = $user->display_name;
			}
		}

		return $state;
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
		$base_url    = menu_page_url( 'mai-analytics', false );
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
	 * stored an error in the `mai_analytics_provider_error` option. Shows
	 * the captured timestamp as a relative age so the operator can tell at a
	 * glance whether they're looking at a fresh failure or stale residue.
	 *
	 * Hidden for users without `manage_options` (only admins should see
	 * provider failure detail). Dismiss button calls the REST endpoint that
	 * deletes the option.
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
		$base_url = menu_page_url( 'mai-analytics', false );
		$subtabs  = [
			'posts'    => __( 'Posts', 'mai-analytics' ),
			'terms'    => __( 'Terms', 'mai-analytics' ),
			'authors'  => __( 'Authors', 'mai-analytics' ),
			'archives' => __( 'Archives', 'mai-analytics' ),
		];

		// Trending count label per sub-tab. The JS swaps it on tab change.
		$trending_labels = [
			'posts'    => __( 'Trending Posts', 'mai-analytics' ),
			'terms'    => __( 'Trending Terms', 'mai-analytics' ),
			'authors'  => __( 'Trending Authors', 'mai-analytics' ),
			'archives' => __( 'Trending Archives', 'mai-analytics' ),
		];

		$state = $this->get_dashboard_state();

		$published_presets = [
			7   => __( '7 days', 'mai-analytics' ),
			14  => __( '14 days', 'mai-analytics' ),
			30  => __( '30 days', 'mai-analytics' ),
			60  => __( '60 days', 'mai-analytics' ),
			90  => __( '90 days', 'mai-analytics' ),
			365 => __( '1 year', 'mai-analytics' ),
			0   => __( 'All time', 'mai-analytics' ),
		];

		$is_custom_days = ! array_key_exists( $state['published'], $published_presets );

		$last_sync = $is_external
			? (int) get_option( 'mai_analytics_provider_last_sync', 0 )
			: (int) get_option( 'mai_analytics_synced', 0 );

		$this->render_provider_error_notice();
		?>
		<p class="mai-analytics-last-sync">
			<?php
			if ( $last_sync ) {
				printf(
					/* translators: %s: formatted date and time */
					esc_html__( 'Last synced %s', 'mai-analytics' ),
					esc_html( wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $last_sync ) )
				);
			} else {
				esc_html_e( 'Not synced yet.', 'mai-analytics' );
			}
			?>
		</p>

		<!-- Tabs -->
		<nav class="nav-tab-wrapper mai-analytics-tabs">
			<?php foreach ( $subtabs as $slug => $label ) : ?>
				<a href="<?php echo esc_url( $base_url . '&subtab=' . $slug ); ?>" class="nav-tab <?php echo $slug === $subtab ? 'nav-tab-active' : ''; ?>" data-tab="<?php echo esc_attr( $slug ); ?>"><?php echo esc_html( $label ); ?></a>
			<?php endforeach; ?>
		</nav>

		<!-- Totals for whatever the table below lists. -->
		<div class="mai-analytics-cards">
			<div class="mai-analytics-card" data-card="views">
				<span class="mai-analytics-card__value">…</span>
				<span class="mai-analytics-card__label"><?php esc_html_e( 'Total Views', 'mai-analytics' ); ?></span>
			</div>
			<div class="mai-analytics-card" data-card="trending_views">
				<span class="mai-analytics-card__value">…</span>
				<span class="mai-analytics-card__label"><?php esc_html_e( 'Trending Views', 'mai-analytics' ); ?></span>
			</div>
			<div class="mai-analytics-card" data-card="trending_count" data-labels="<?php echo esc_attr( wp_json_encode( $trending_labels ) ); ?>">
				<span class="mai-analytics-card__value">…</span>
				<span class="mai-analytics-card__label"><?php echo esc_html( $trending_labels[ $subtab ] ); ?></span>
			</div>
		</div>

		<!-- Filters. Rendered from the URL, so a linked view shows its filters on first paint. -->
		<div class="mai-analytics-filters<?php echo $state['tax'] ? ' has-taxonomy' : ''; ?>" data-tab="<?php echo esc_attr( $subtab ); ?>">
			<select id="mai-analytics-post-type" class="mai-analytics-select mai-analytics-filters__field mai-analytics-filters__field--posts" placeholder="<?php esc_attr_e( 'All Post Types', 'mai-analytics' ); ?>">
				<?php if ( $state['type'] ) : ?>
					<option value="<?php echo esc_attr( $state['type'] ); ?>" selected><?php echo esc_html( $state['type_label'] ); ?></option>
				<?php endif; ?>
			</select>
			<select id="mai-analytics-taxonomy" class="mai-analytics-select mai-analytics-filters__field mai-analytics-filters__field--posts mai-analytics-filters__field--terms" placeholder="<?php esc_attr_e( 'All Taxonomies', 'mai-analytics' ); ?>">
				<?php if ( $state['tax'] ) : ?>
					<option value="<?php echo esc_attr( $state['tax'] ); ?>" selected><?php echo esc_html( $state['tax_label'] ); ?></option>
				<?php endif; ?>
			</select>
			<select id="mai-analytics-term" class="mai-analytics-select mai-analytics-filters__field mai-analytics-filters__field--posts mai-analytics-filters__field--needs-taxonomy" placeholder="<?php esc_attr_e( 'Search terms...', 'mai-analytics' ); ?>" multiple>
				<?php foreach ( $state['terms'] as $term_id => $term_name ) : ?>
					<option value="<?php echo esc_attr( (string) $term_id ); ?>" selected><?php echo esc_html( $term_name ); ?></option>
				<?php endforeach; ?>
			</select>
			<select id="mai-analytics-author" class="mai-analytics-select mai-analytics-filters__field mai-analytics-filters__field--posts" placeholder="<?php esc_attr_e( 'All Authors', 'mai-analytics' ); ?>" multiple>
				<?php foreach ( $state['authors'] as $user_id => $display_name ) : ?>
					<option value="<?php echo esc_attr( (string) $user_id ); ?>" selected><?php echo esc_html( $display_name ); ?></option>
				<?php endforeach; ?>
			</select>
			<div class="mai-analytics-filters__field mai-analytics-filters__field--posts mai-analytics-filters__published<?php echo $is_custom_days ? ' is-custom' : ''; ?>">
				<select id="mai-analytics-published-days" class="mai-analytics-select" data-default="<?php echo esc_attr( (string) self::DEFAULT_PUBLISHED_DAYS ); ?>" data-prefix="<?php esc_attr_e( 'Published:', 'mai-analytics' ); ?>">
					<?php foreach ( $published_presets as $days => $label ) : ?>
						<option value="<?php echo esc_attr( (string) $days ); ?>" <?php selected( ! $is_custom_days && $days === $state['published'] ); ?>><?php echo esc_html( $label ); ?></option>
					<?php endforeach; ?>
					<option value="custom" <?php selected( $is_custom_days ); ?>><?php esc_html_e( 'Custom', 'mai-analytics' ); ?></option>
				</select>
				<input type="number" id="mai-analytics-custom-days" class="mai-analytics-filters__custom-days" min="1" max="365" placeholder="<?php esc_attr_e( 'Days', 'mai-analytics' ); ?>" value="<?php echo $is_custom_days ? esc_attr( (string) $state['published'] ) : ''; ?>">
			</div>
		</div>

		<!-- Table Controls -->
		<div class="mai-analytics-table-controls">
			<div class="mai-analytics-search-wrap">
				<input type="text" id="mai-analytics-search" placeholder="<?php esc_attr_e( 'Search by title/name...', 'mai-analytics' ); ?>" value="<?php echo esc_attr( $state['search'] ); ?>">
				<span class="mai-analytics-search-spinner" style="display:none;"></span>
			</div>
			<select id="mai-analytics-per-page" class="mai-analytics-select mai-analytics-table-controls__per-page">
				<?php foreach ( [ 25, 50, 100 ] as $per_page ) : ?>
					<option value="<?php echo esc_attr( (string) $per_page ); ?>" <?php selected( $state['per_page'], $per_page ); ?>>
						<?php
						/* translators: %d: number of rows per page */
						echo esc_html( sprintf( __( '%d per page', 'mai-analytics' ), $per_page ) );
						?>
					</option>
				<?php endforeach; ?>
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
				<p></p>
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
					<label style="margin-left:8px;">
						<input type="checkbox" id="mai-analytics-warm-force">
						<?php esc_html_e( 'Force re-warm even if recently checked', 'mai-analytics' ); ?>
					</label>
					<p class="mai-analytics-btn-status" style="display:none; margin:8px 0 0; font-weight:600;"></p>
					<p class="description">
						<?php esc_html_e( 'Fetch stats from the provider for all posts, terms, and authors, not just ones with recent traffic. Use this after switching providers, or to populate stats for pages that haven\'t been visited yet. Most-recent content is processed first; this may take a while on large sites. Objects synced within the last hour are skipped by default; check Force re-warm to bypass.', 'mai-analytics' ); ?>
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
