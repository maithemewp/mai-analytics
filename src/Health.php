<?php

namespace Mai\Analytics;

class Health {

	/**
	 * Runs all health checks and returns structured results.
	 *
	 * @param bool $include_endpoint_tests Whether to run REST endpoint tests (slower).
	 *
	 * @return array {
	 *     @type array  $checks Array of check results, each: [label, status (pass|fail|warn), detail, section].
	 *     @type int    $pass   Number of passed checks.
	 *     @type int    $fail   Number of failed checks.
	 *     @type int    $warn   Number of warnings.
	 * }
	 */
	public static function run( bool $include_endpoint_tests = true ): array {
		global $wpdb;

		$checks = [];
		$table  = Database::get_table_name();

		$check = function( string $section, string $label, bool $ok, string $detail = '', bool $critical = true ) use ( &$checks ) {
			$checks[] = [
				'section' => $section,
				'label'   => $label,
				'status'  => $ok ? 'pass' : ( $critical ? 'fail' : 'warn' ),
				'detail'  => $detail,
			];
		};

		// ── Core ──
		$check( 'Core', 'Plugin loaded', defined( 'MAI_ANALYTICS_VERSION' ), 'v' . MAI_ANALYTICS_VERSION );
		$check( 'Core', 'Constants defined', defined( 'MAI_ANALYTICS_PLUGIN_DIR' ) && defined( 'MAI_ANALYTICS_PLUGIN_FILE' ) );
		$check( 'Core', 'Autoloader', class_exists( Plugin::class ) && class_exists( Sync::class ) );

		$env      = wp_get_environment_type();
		$tracking = Tracker::is_tracking_enabled();
		$check( 'Core', 'Environment', true, $env );
		$check( 'Core', 'Beacon tracking', $tracking, $tracking ? 'enabled' : "disabled (environment={$env})", false );

		// ── Database ──
		$table_exists = (bool) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
		$check( 'Database', 'Buffer table exists', $table_exists, $table );

		$db_version = get_option( Database::DB_VERSION_OPTION, '0' );
		$check( 'Database', 'DB version current', version_compare( $db_version, MAI_ANALYTICS_DB_VERSION, '>=' ), "stored={$db_version} required=" . MAI_ANALYTICS_DB_VERSION );

		$buffer_rows = $table_exists ? (int) $wpdb->get_var( "SELECT COUNT(*) FROM $table" ) : 0;
		$check( 'Database', 'Buffer rows', true, number_format( $buffer_rows ) );

		$stale = (int) $wpdb->get_var( "SELECT COUNT(*) FROM $wpdb->postmeta WHERE meta_key LIKE 'mai_analytics_%'" );
		$check( 'Database', 'No stale mai_analytics_* meta', 0 === $stale, $stale ? "{$stale} rows" : 'clean', false );

		// ── Meta & Shortcode ──
		$post_meta     = get_registered_meta_keys( 'post' );
		$expected_keys = [ 'mai_views', 'mai_views_web', 'mai_views_app', 'mai_trending' ];
		$missing_keys  = array_filter( $expected_keys, fn( $k ) => ! isset( $post_meta[ $k ] ) );
		$check( 'Meta', 'Post meta keys registered', empty( $missing_keys ), empty( $missing_keys ) ? 'all 4' : 'missing: ' . implode( ', ', $missing_keys ) );

		$term_meta = get_registered_meta_keys( 'term' );
		$check( 'Meta', 'Term meta keys registered', isset( $term_meta['mai_views'] ) && isset( $term_meta['mai_trending'] ) );

		$user_meta = get_registered_meta_keys( 'user' );
		$check( 'Meta', 'User meta keys registered', isset( $user_meta['mai_views'] ) && isset( $user_meta['mai_trending'] ) );

		$check( 'Meta', '[mai_views] shortcode', shortcode_exists( 'mai_views' ) );
		$check( 'Meta', 'mai_analytics_get_count()', function_exists( 'mai_analytics_get_count' ) );
		$check( 'Meta', 'mai_analytics_get_views()', function_exists( 'mai_analytics_get_views' ) );

		// ── Cron ──
		$next_cron = wp_next_scheduled( 'mai_analytics_cron_sync' );
		$check( 'Cron', 'Cron scheduled', (bool) $next_cron, $next_cron ? wp_date( 'Y-m-d H:i:s', $next_cron ) : 'not scheduled' );

		$schedules = wp_get_schedules();
		$check( 'Cron', 'mai_analytics_15min schedule', isset( $schedules['mai_analytics_15min'] ), isset( $schedules['mai_analytics_15min'] ) ? $schedules['mai_analytics_15min']['interval'] . 's' : 'missing' );

		$last_sync  = get_option( 'mai_analytics_synced', 0 );
		$sync_age   = $last_sync ? human_time_diff( $last_sync ) . ' ago' : 'never';
		$sync_stale = $last_sync && ( time() - $last_sync ) > 3600;
		$check( 'Cron', 'Last sync recent', ! $sync_stale, $sync_age, false );

		// ── Settings & Provider ──
		$settings    = get_option( 'mai_analytics_settings', [] );
		$data_source = $settings['data_source'] ?? 'self_hosted';
		$check( 'Provider', 'Settings saved', ! empty( $settings ), 'data_source=' . $data_source );

		if ( 'self_hosted' !== $data_source && 'disabled' !== $data_source ) {
			$provider = ProviderSync::get_provider();
			$check( 'Provider', 'Provider found', (bool) $provider, $provider ? $provider->get_label() : 'no matching provider for ' . $data_source );

			if ( $provider ) {
				$check( 'Provider', 'Provider available', $provider->is_available(), $provider->is_available() ? 'connected' : ( method_exists( $provider, 'get_unavailable_reason' ) ? $provider->get_unavailable_reason() : 'unavailable' ) );

				$last_error = method_exists( $provider, 'get_last_error' ) ? $provider::get_last_error() : '';
				$check( 'Provider', 'No provider errors', empty( $last_error ), $last_error ?: 'clean', false );

				$provider_sync = get_option( 'mai_analytics_provider_last_sync', 0 );
				$check( 'Provider', 'Last provider sync', true, $provider_sync ? human_time_diff( $provider_sync ) . ' ago' : 'never' );
			}
		}

		// ── REST Endpoints ──
		if ( $include_endpoint_tests ) {
			$server     = rest_get_server();
			$routes     = array_keys( $server->get_routes() );
			$mai_routes = array_filter( $routes, fn( $r ) => str_starts_with( $r, '/mai-analytics/' ) );
			$check( 'REST', 'REST routes registered', count( $mai_routes ) >= 10, count( $mai_routes ) . ' routes' );

			$test_post_id = (int) $wpdb->get_var(
				"SELECT ID FROM $wpdb->posts WHERE post_status = 'publish' AND post_type = 'post' ORDER BY ID ASC LIMIT 1"
			);

			if ( $test_post_id ) {
				$request  = new \WP_REST_Request( 'GET', "/mai-analytics/v1/views/post/{$test_post_id}" );
				$response = rest_do_request( $request );
				$check( 'REST', 'GET /views/post/{id}', ! $response->is_error(), 'status=' . $response->get_status() );

				if ( ! $response->is_error() ) {
					$data     = $response->get_data();
					$has_keys = isset( $data['views'] ) && isset( $data['trending'] );
					$check( 'REST', 'Response shape', $has_keys, $has_keys ? "views={$data['views']} trending={$data['trending']}" : 'missing keys' );
				}

				$request = new \WP_REST_Request( 'POST', "/mai-analytics/v1/view/post/{$test_post_id}" );
				$request->set_header( 'User-Agent', 'Mai-Analytics-Health/1.0' );
				$response = rest_do_request( $request );
				$check( 'REST', 'POST /view/post/{id}', ! $response->is_error(), 'status=' . $response->get_status() );

				if ( ! $response->is_error() ) {
					$data      = $response->get_data();
					$succeeded = ! empty( $data['success'] );
					$in_buffer = (int) $wpdb->get_var(
						$wpdb->prepare(
							"SELECT COUNT(*) FROM $table WHERE object_id = %d AND object_type = 'post'",
							$test_post_id
						)
					);
					$detail = $succeeded
						? ( $in_buffer ? "{$in_buffer} buffer rows (ok)" : 'accepted but not in buffer' )
						: 'endpoint returned failure';
					$check( 'REST', 'View accepted', $succeeded, $detail );
				}

				$request  = new \WP_REST_Request( 'GET', '/mai-analytics/v1/views/trending' );
				$response = rest_do_request( $request );
				$check( 'REST', 'GET /views/trending', ! $response->is_error(), 'status=' . $response->get_status() );
			}

			// Admin endpoints need a privileged user context.
			$previous_user = get_current_user_id();

			if ( ! current_user_can( 'edit_others_posts' ) ) {
				$admin_id = Migration::get_first_admin_id();

				if ( $admin_id ) {
					wp_set_current_user( $admin_id );
				}
			}

			$request  = new \WP_REST_Request( 'GET', '/mai-analytics/v1/admin/summary' );
			$response = rest_do_request( $request );
			$check( 'REST', 'GET /admin/summary', ! $response->is_error(), 'status=' . $response->get_status() );

			$request  = new \WP_REST_Request( 'GET', '/mai-analytics/v1/admin/top/posts' );
			$response = rest_do_request( $request );
			$check( 'REST', 'GET /admin/top/posts', ! $response->is_error(), 'status=' . $response->get_status() );

			$request  = new \WP_REST_Request( 'GET', '/mai-analytics/v1/admin/filters' );
			$response = rest_do_request( $request );
			$check( 'REST', 'GET /admin/filters', ! $response->is_error(), 'status=' . $response->get_status() );

			wp_set_current_user( $previous_user );
		}

		// ── Mai Publisher Coexistence ──
		$pub_active = class_exists( 'Mai_Publisher_Plugin' );
		$check( 'Publisher', 'Mai Publisher', true, $pub_active ? 'active' : 'not active' );

		if ( $pub_active ) {
			$has_ajax = has_action( 'wp_ajax_maipub_views' );
			$check( 'Publisher', 'Views class gated', ! $has_ajax, $has_ajax ? 'maipub_views AJAX hook found (double-loading!)' : 'correctly gated' );
		}

		// ── Summary ──
		$pass = count( array_filter( $checks, fn( $c ) => 'pass' === $c['status'] ) );
		$fail = count( array_filter( $checks, fn( $c ) => 'fail' === $c['status'] ) );
		$warn = count( array_filter( $checks, fn( $c ) => 'warn' === $c['status'] ) );

		return [
			'checks' => $checks,
			'pass'   => $pass,
			'fail'   => $fail,
			'warn'   => $warn,
			'total'  => count( $checks ),
		];
	}
}
