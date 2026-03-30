<?php

namespace Mai\Views;

use WP_CLI;

class CLI {

	/**
	 * Registers all WP-CLI subcommands for Mai Views.
	 */
	public function __construct() {
		WP_CLI::add_command( 'mai-views health',         [ $this, 'health' ] );
		WP_CLI::add_command( 'mai-views migrate',       [ $this, 'migrate' ] );
		WP_CLI::add_command( 'mai-views sync',          [ $this, 'sync' ] );
		WP_CLI::add_command( 'mai-views stats',         [ $this, 'stats' ] );
		WP_CLI::add_command( 'mai-views prune',         [ $this, 'prune' ] );
		WP_CLI::add_command( 'mai-views seed',          [ $this, 'seed' ] );
		WP_CLI::add_command( 'mai-views reset',         [ $this, 'reset' ] );
		WP_CLI::add_command( 'mai-views update-bots',   [ $this, 'update_bots' ] );
		WP_CLI::add_command( 'mai-views provider-sync', [ $this, 'provider_sync' ] );
		WP_CLI::add_command( 'mai-views warm',          [ $this, 'warm' ] );
	}

	/**
	 * Run health checks on the Mai Views installation.
	 *
	 * Checks plugin health, database state, REST endpoints, provider connectivity,
	 * view recording, and Mai Publisher coexistence. Exits with error if any
	 * critical check fails.
	 *
	 * ## OPTIONS
	 *
	 * [--fix]
	 * : Attempt to auto-fix issues found (re-create table, reschedule cron, etc.).
	 *
	 * ## EXAMPLES
	 *
	 *     wp mai-views health
	 *     wp mai-views health --fix
	 *
	 * @param array $args       Positional arguments (unused).
	 * @param array $assoc_args Associative arguments: --fix.
	 *
	 * @return void
	 */
	public function health( array $args, array $assoc_args ): void {
		global $wpdb;

		$fix    = \WP_CLI\Utils\get_flag_value( $assoc_args, 'fix', false );
		$pass   = 0;
		$fail   = 0;
		$warn   = 0;
		$table  = Database::get_table_name();

		$check = function( string $label, bool $ok, string $detail = '', bool $critical = true ) use ( &$pass, &$fail, &$warn ) {
			if ( $ok ) {
				WP_CLI::log( WP_CLI::colorize( "  %G\u{2713}%n {$label}" . ( $detail ? " — {$detail}" : '' ) ) );
				$pass++;
			} elseif ( $critical ) {
				WP_CLI::log( WP_CLI::colorize( "  %R\u{2717}%n {$label}" . ( $detail ? " — {$detail}" : '' ) ) );
				$fail++;
			} else {
				WP_CLI::log( WP_CLI::colorize( "  %Y!%n {$label}" . ( $detail ? " — {$detail}" : '' ) ) );
				$warn++;
			}
		};

		// ── Core ──
		WP_CLI::log( '' );
		WP_CLI::log( WP_CLI::colorize( '%B=== Core ===%n' ) );

		$check( 'Plugin loaded', defined( 'MAI_VIEWS_VERSION' ), 'v' . MAI_VIEWS_VERSION );
		$check( 'Constants defined', defined( 'MAI_VIEWS_PLUGIN_DIR' ) && defined( 'MAI_VIEWS_PLUGIN_FILE' ) );
		$check( 'Autoloader', class_exists( Plugin::class ) && class_exists( Sync::class ) );

		$env = wp_get_environment_type();
		$tracking = Tracker::is_tracking_enabled();
		$check( 'Environment', true, $env );
		$check( 'Beacon tracking', $tracking, $tracking ? 'enabled' : "disabled (environment={$env}, set MAI_VIEWS_ENABLE_TRACKING to override)", false );

		// ── Database ──
		WP_CLI::log( '' );
		WP_CLI::log( WP_CLI::colorize( '%B=== Database ===%n' ) );

		$table_exists = (bool) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
		$check( 'Buffer table exists', $table_exists, $table );

		if ( ! $table_exists && $fix ) {
			Database::create_table();
			$table_exists = (bool) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
			WP_CLI::log( WP_CLI::colorize( "    %C\u{2192} Fixed: created table%n" ) );
		}

		$db_version = get_option( Database::DB_VERSION_OPTION, '0' );
		$check( 'DB version current', version_compare( $db_version, MAI_VIEWS_DB_VERSION, '>=' ), "stored={$db_version} required=" . MAI_VIEWS_DB_VERSION );

		$buffer_rows = $table_exists ? (int) $wpdb->get_var( "SELECT COUNT(*) FROM $table" ) : 0;
		WP_CLI::log( WP_CLI::colorize( "  %w  Buffer rows: " . number_format( $buffer_rows ) . "%n" ) );

		$stale = (int) $wpdb->get_var( "SELECT COUNT(*) FROM $wpdb->postmeta WHERE meta_key LIKE 'mai_analytics_%'" );
		$check( 'No stale mai_analytics_* meta', 0 === $stale, $stale ? "{$stale} rows (run: wp mai-views migrate --force)" : 'clean', false );

		// ── Meta & Shortcode ──
		WP_CLI::log( '' );
		WP_CLI::log( WP_CLI::colorize( '%B=== Meta & Shortcode ===%n' ) );

		$post_meta = get_registered_meta_keys( 'post' );
		$expected_keys = [ 'mai_views', 'mai_views_web', 'mai_views_app', 'mai_trending' ];
		$missing_keys  = array_filter( $expected_keys, fn( $k ) => ! isset( $post_meta[ $k ] ) );
		$check( 'Post meta keys registered', empty( $missing_keys ), empty( $missing_keys ) ? 'all 4' : 'missing: ' . implode( ', ', $missing_keys ) );

		$term_meta = get_registered_meta_keys( 'term' );
		$check( 'Term meta keys registered', isset( $term_meta['mai_views'] ) && isset( $term_meta['mai_trending'] ) );

		$user_meta = get_registered_meta_keys( 'user' );
		$check( 'User meta keys registered', isset( $user_meta['mai_views'] ) && isset( $user_meta['mai_trending'] ) );

		$check( '[mai_views] shortcode', shortcode_exists( 'mai_views' ) );
		$check( 'mai_views_get_count()', function_exists( 'mai_views_get_count' ) );
		$check( 'mai_views_get_views()', function_exists( 'mai_views_get_views' ) );

		// ── Cron ──
		WP_CLI::log( '' );
		WP_CLI::log( WP_CLI::colorize( '%B=== Cron ===%n' ) );

		$next_cron = wp_next_scheduled( 'mai_views_cron_sync' );
		$check( 'Cron scheduled', (bool) $next_cron, $next_cron ? wp_date( 'Y-m-d H:i:s', $next_cron ) : 'not scheduled' );

		if ( ! $next_cron && $fix ) {
			wp_schedule_event( time(), 'mai_views_15min', 'mai_views_cron_sync' );
			WP_CLI::log( WP_CLI::colorize( "    %C\u{2192} Fixed: rescheduled cron%n" ) );
		}

		$schedules = wp_get_schedules();
		$check( 'mai_views_15min schedule', isset( $schedules['mai_views_15min'] ), isset( $schedules['mai_views_15min'] ) ? $schedules['mai_views_15min']['interval'] . 's' : 'missing' );

		$last_sync = get_option( 'mai_views_synced', 0 );
		$sync_age  = $last_sync ? human_time_diff( $last_sync ) . ' ago' : 'never';
		$sync_stale = $last_sync && ( time() - $last_sync ) > 3600;
		$check( 'Last sync recent', ! $sync_stale, $sync_age, false );

		// ── Settings & Provider ──
		WP_CLI::log( '' );
		WP_CLI::log( WP_CLI::colorize( '%B=== Settings & Provider ===%n' ) );

		$settings = get_option( 'mai_views_settings', [] );
		$check( 'Settings saved', ! empty( $settings ), 'data_source=' . ( $settings['data_source'] ?? 'NOT SET' ) );

		$data_source = $settings['data_source'] ?? 'self_hosted';

		if ( 'self_hosted' !== $data_source ) {
			$provider = ProviderSync::get_provider();
			$check( 'Provider found', (bool) $provider, $provider ? $provider->get_label() : 'no matching provider for ' . $data_source );

			if ( $provider ) {
				$check( 'Provider available', $provider->is_available(), $provider->is_available() ? 'connected' : ( method_exists( $provider, 'get_unavailable_reason' ) ? $provider->get_unavailable_reason() : 'unavailable' ) );

				$last_error = method_exists( $provider, 'get_last_error' ) ? $provider::get_last_error() : '';
				$check( 'No provider errors', empty( $last_error ), $last_error ?: 'clean', false );

				$provider_sync = get_option( 'mai_views_provider_last_sync', 0 );
				$provider_age  = $provider_sync ? human_time_diff( $provider_sync ) . ' ago' : 'never';
				WP_CLI::log( WP_CLI::colorize( "  %w  Last provider sync: {$provider_age}%n" ) );
			}
		} else {
			WP_CLI::log( '  Self-hosted mode — no external provider.' );
		}

		// ── REST Endpoints ──
		WP_CLI::log( '' );
		WP_CLI::log( WP_CLI::colorize( '%B=== REST Endpoints ===%n' ) );

		$server     = rest_get_server();
		$routes     = array_keys( $server->get_routes() );
		$mai_routes = array_filter( $routes, fn( $r ) => str_starts_with( $r, '/mai-views/' ) );
		$check( 'REST routes registered', count( $mai_routes ) >= 10, count( $mai_routes ) . ' routes' );

		// Find a published post to test with.
		$test_post_id = (int) $wpdb->get_var(
			"SELECT ID FROM $wpdb->posts WHERE post_status = 'publish' AND post_type = 'post' ORDER BY ID ASC LIMIT 1"
		);

		if ( $test_post_id ) {
			// Test GET views endpoint.
			$request  = new \WP_REST_Request( 'GET', "/mai-views/v1/views/post/{$test_post_id}" );
			$response = rest_do_request( $request );
			$check( 'GET /views/post/{id}', ! $response->is_error(), 'status=' . $response->get_status() );

			if ( ! $response->is_error() ) {
				$data = $response->get_data();
				$has_keys = isset( $data['views'] ) && isset( $data['trending'] );
				$check( '  Response shape', $has_keys, $has_keys ? "views={$data['views']} trending={$data['trending']}" : 'missing keys' );
			}

			// Test POST view endpoint (records a real view).
			$request = new \WP_REST_Request( 'POST', "/mai-views/v1/view/post/{$test_post_id}" );
			$request->set_header( 'User-Agent', 'Mai-Views-Doctor/1.0' );
			$response = rest_do_request( $request );
			$check( 'POST /view/post/{id}', ! $response->is_error(), 'status=' . $response->get_status() );

			if ( ! $response->is_error() ) {
				$data = $response->get_data();
				$succeeded = ! empty( $data['success'] );

				// Check if a row exists in the buffer for this post (may be deduped in provider mode).
				$in_buffer = (int) $wpdb->get_var(
					$wpdb->prepare(
						"SELECT COUNT(*) FROM $table WHERE object_id = %d AND object_type = 'post'",
						$test_post_id
					)
				);

				$detail = $succeeded
					? ( $in_buffer ? "{$in_buffer} buffer rows (ok — may have deduped)" : 'accepted but not in buffer (check bot filter)' )
					: 'endpoint returned failure';

				$check( '  View accepted', $succeeded, $detail );
			}

			// Test trending endpoint.
			$request  = new \WP_REST_Request( 'GET', '/mai-views/v1/views/trending' );
			$response = rest_do_request( $request );
			$check( 'GET /views/trending', ! $response->is_error(), 'status=' . $response->get_status() );
		} else {
			WP_CLI::log( '  (no published posts to test endpoints against)' );
		}

		// Test admin endpoints (need a privileged user).
		$admin_id = Migration::get_first_admin_id();

		if ( $admin_id ) {
			wp_set_current_user( $admin_id );

			$request  = new \WP_REST_Request( 'GET', '/mai-views/v1/admin/summary' );
			$response = rest_do_request( $request );
			$check( 'GET /admin/summary', ! $response->is_error(), 'status=' . $response->get_status() );

			if ( ! $response->is_error() ) {
				$data  = $response->get_data();
				$parts = [];
				foreach ( $data as $k => $v ) {
					$parts[] = "{$k}={$v}";
				}
				WP_CLI::log( '      ' . implode( ' ', $parts ) );
			}

			$request  = new \WP_REST_Request( 'GET', '/mai-views/v1/admin/top/posts' );
			$response = rest_do_request( $request );
			$check( 'GET /admin/top/posts', ! $response->is_error(), 'status=' . $response->get_status() . ' items=' . count( $response->get_data()['items'] ?? [] ) );

			$request  = new \WP_REST_Request( 'GET', '/mai-views/v1/admin/top/terms' );
			$response = rest_do_request( $request );
			$check( 'GET /admin/top/terms', ! $response->is_error(), 'status=' . $response->get_status() );

			$request  = new \WP_REST_Request( 'GET', '/mai-views/v1/admin/filters' );
			$response = rest_do_request( $request );
			$check( 'GET /admin/filters', ! $response->is_error(), 'status=' . $response->get_status() );

			wp_set_current_user( 0 );
		}

		// ── Provider API Test ──
		if ( 'self_hosted' !== $data_source && isset( $provider ) && $provider && $provider->is_available() && $test_post_id ) {
			WP_CLI::log( '' );
			WP_CLI::log( WP_CLI::colorize( '%B=== Provider API Test ===%n' ) );

			$path = wp_parse_url( get_permalink( $test_post_id ), PHP_URL_PATH ) ?: '/';
			$today = gmdate( 'Y-m-d' );
			$week_ago = gmdate( 'Y-m-d', strtotime( '-7 days' ) );

			// Need auth context for Site Kit.
			if ( $admin_id ) {
				wp_set_current_user( $admin_id );
			}

			$alltime = $provider->get_views( [ $path ], '', $today );
			$check( 'Provider all-time fetch', ! empty( $alltime ) || is_array( $alltime ), empty( $alltime ) ? 'empty (post may have no views)' : 'views=' . ( $alltime[ $path ] ?? 0 ) . " for {$path}", false );

			$trending = $provider->get_views( [ $path ], $week_ago, $today );
			$check( 'Provider trending fetch', ! empty( $trending ) || is_array( $trending ), empty( $trending ) ? 'empty (no recent views)' : 'views=' . ( $trending[ $path ] ?? 0 ) . ' (7d)', false );

			if ( $admin_id ) {
				wp_set_current_user( 0 );
			}
		}

		// ── Mai Publisher Coexistence ──
		WP_CLI::log( '' );
		WP_CLI::log( WP_CLI::colorize( '%B=== Mai Publisher Coexistence ===%n' ) );

		$pub_active = class_exists( 'Mai_Publisher_Plugin' );
		WP_CLI::log( '  Mai Publisher: ' . ( $pub_active ? 'active' : 'not active' ) );

		if ( $pub_active ) {
			$has_ajax = has_action( 'wp_ajax_maipub_views' );
			$check( 'Views class NOT instantiated', ! $has_ajax, $has_ajax ? 'maipub_views AJAX hook found (double-loading!)' : 'correctly gated' );

			$pub_settings = get_option( 'mai_publisher', [] );
			$check( 'Publisher settings readable', ! empty( $pub_settings ), ! empty( $pub_settings ) ? 'views_api=' . ( $pub_settings['views_api'] ?? 'not set' ) : 'empty', false );
		}

		// ── Summary ──
		WP_CLI::log( '' );
		$total = $pass + $fail + $warn;
		$summary = sprintf( '%d passed, %d failed, %d warnings out of %d checks', $pass, $fail, $warn, $total );

		if ( $fail > 0 ) {
			WP_CLI::error( $summary, false );
		} elseif ( $warn > 0 ) {
			WP_CLI::warning( $summary );
		} else {
			WP_CLI::success( $summary );
		}
	}

	/**
	 * Migrate settings from Mai Publisher and/or old Mai Analytics installs.
	 *
	 * Runs the automatic migration that normally fires on plugins_loaded,
	 * but forced and with verbose output.
	 *
	 * ## OPTIONS
	 *
	 * [--force]
	 * : Run migration even if it has already completed.
	 *
	 * ## EXAMPLES
	 *
	 *     wp mai-views migrate
	 *     wp mai-views migrate --force
	 *
	 * @param array $args       Positional arguments (unused).
	 * @param array $assoc_args Associative arguments: --force.
	 *
	 * @return void
	 */
	public function migrate( array $args, array $assoc_args ): void {
		$force = \WP_CLI\Utils\get_flag_value( $assoc_args, 'force', false );

		if ( $force ) {
			delete_option( 'mai_views_migrated_from_publisher' );
			delete_option( 'mai_views_migrated_from_analytics' );
			WP_CLI::log( 'Force flag set. Cleared migration flags.' );
		}

		$had_publisher_settings = (bool) get_option( 'mai_publisher' );
		$had_analytics_settings = (bool) get_option( 'mai_analytics_settings' );
		$already_configured     = (bool) get_option( 'mai_views_settings' );

		if ( $already_configured && ! $force ) {
			WP_CLI::log( 'Mai Views settings already exist. Use --force to re-run migration.' );
		}

		Migration::maybe_migrate();

		if ( $had_publisher_settings ) {
			$settings = get_option( 'mai_views_settings', [] );
			WP_CLI::log( sprintf( 'Migrated from Mai Publisher: data_source=%s', $settings['data_source'] ?? 'unknown' ) );

			$defaults = get_option( 'mai_views_migrated_defaults', [] );

			if ( $defaults ) {
				WP_CLI::log( sprintf( 'Migrated filter defaults: %s', wp_json_encode( $defaults ) ) );
			}
		} elseif ( $had_analytics_settings ) {
			WP_CLI::log( 'Migrated from old Mai Analytics install.' );
		} elseif ( ! $already_configured ) {
			WP_CLI::log( 'No Mai Publisher or Mai Analytics settings found. Nothing to migrate.' );
		}

		WP_CLI::success( 'Migration complete.' );
	}

	/**
	 * Force a manual sync of the buffer table to meta.
	 *
	 * ## OPTIONS
	 *
	 * [--verbose]
	 * : Show detailed output.
	 *
	 * ## EXAMPLES
	 *
	 *     wp mai-views sync
	 *     wp mai-views sync --verbose
	 *
	 * @param array $args       Positional arguments (unused).
	 * @param array $assoc_args Associative arguments: --verbose.
	 *
	 * @return void
	 */
	public function sync( array $args, array $assoc_args ): void {
		global $wpdb;

		$verbose = \WP_CLI\Utils\get_flag_value( $assoc_args, 'verbose', false );
		$table   = Database::get_table_name();

		if ( $verbose ) {
			$count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM $table" );
			WP_CLI::log( sprintf( 'Buffer table rows before sync: %s', number_format( $count ) ) );
		}

		Sync::sync();

		if ( $verbose ) {
			$count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM $table" );
			$last  = get_option( 'mai_views_synced', 0 );
			WP_CLI::log( sprintf( 'Buffer table rows after sync:  %s', number_format( $count ) ) );
			WP_CLI::log( sprintf( 'Last sync: %s', $last ? wp_date( 'Y-m-d H:i:s', $last ) : 'never' ) );
		}

		WP_CLI::success( 'Sync complete.' );
	}

	/**
	 * Show current stats summary.
	 *
	 * ## OPTIONS
	 *
	 * [--type=<type>]
	 * : Filter by type: post, term, or user.
	 *
	 * ## EXAMPLES
	 *
	 *     wp mai-views stats
	 *     wp mai-views stats --type=post
	 *
	 * @param array $args       Positional arguments (unused).
	 * @param array $assoc_args Associative arguments: --type.
	 *
	 * @return void
	 */
	public function stats( array $args, array $assoc_args ): void {
		global $wpdb;

		$table = Database::get_table_name();
		$type  = isset( $assoc_args['type'] ) ? sanitize_key( $assoc_args['type'] ) : '';

		if ( $type ) {
			$buffer_count = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $table WHERE object_type = %s", $type ) );
			$object_count = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(DISTINCT object_id) FROM $table WHERE object_type = %s", $type ) );
		} else {
			$buffer_count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM $table" );
			$object_count = (int) $wpdb->get_var( "SELECT COUNT(DISTINCT CONCAT(object_id, '-', object_type)) FROM $table" );
		}

		$total_views = 0;

		if ( ! $type || 'post' === $type ) {
			$total_views += (int) $wpdb->get_var( "SELECT COALESCE(SUM(meta_value), 0) FROM $wpdb->postmeta WHERE meta_key = 'mai_views'" );
		}

		if ( ! $type || 'term' === $type ) {
			$total_views += (int) $wpdb->get_var( "SELECT COALESCE(SUM(meta_value), 0) FROM $wpdb->termmeta WHERE meta_key = 'mai_views'" );
		}

		if ( ! $type || 'user' === $type ) {
			$total_views += (int) $wpdb->get_var( "SELECT COALESCE(SUM(meta_value), 0) FROM $wpdb->usermeta WHERE meta_key = 'mai_views'" );
		}

		$last_sync = get_option( 'mai_views_synced', 0 );

		$data_source = Settings::get( 'data_source' );

		WP_CLI::log( sprintf( 'Data source:              %s', $data_source ) );
		WP_CLI::log( sprintf( 'Tracked objects in buffer: %s', number_format( $object_count ) ) );
		WP_CLI::log( sprintf( 'Buffer table rows:        %s', number_format( $buffer_count ) ) );
		WP_CLI::log( sprintf( 'Total lifetime views:     %s', number_format( $total_views ) ) );
		WP_CLI::log( sprintf( 'Last sync:                %s', $last_sync ? wp_date( 'Y-m-d H:i:s', $last_sync ) : 'never' ) );

		if ( 'self_hosted' !== $data_source ) {
			$provider_sync = get_option( 'mai_views_provider_last_sync', 0 );

			WP_CLI::log( sprintf( 'Last provider sync:       %s', $provider_sync ? wp_date( 'Y-m-d H:i:s', $provider_sync ) : 'never' ) );
		}
	}

	/**
	 * Manually prune old buffer rows.
	 *
	 * ## OPTIONS
	 *
	 * [--older-than=<duration>]
	 * : Duration like 48h or 7d. Default: retention setting.
	 *
	 * [--dry-run]
	 * : Show what would be pruned without deleting.
	 *
	 * ## EXAMPLES
	 *
	 *     wp mai-views prune
	 *     wp mai-views prune --older-than=48h --dry-run
	 *
	 * @param array $args       Positional arguments (unused).
	 * @param array $assoc_args Associative arguments: --older-than, --dry-run.
	 *
	 * @return void
	 */
	public function prune( array $args, array $assoc_args ): void {
		global $wpdb;

		$table   = Database::get_table_name();
		$dry_run = \WP_CLI\Utils\get_flag_value( $assoc_args, 'dry-run', false );
		$older   = $assoc_args['older-than'] ?? '';

		if ( $older ) {
			if ( preg_match( '/^(\d+)h$/', $older, $m ) ) {
				$hours = (int) $m[1];
			} elseif ( preg_match( '/^(\d+)d$/', $older, $m ) ) {
				$hours = (int) $m[1] * 24;
			} else {
				WP_CLI::error( 'Invalid duration format. Use format like 48h or 7d.' );
			}
		} else {
			$hours = Settings::get( 'retention' ) * 24;
		}

		$count = (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT COUNT(*) FROM $table WHERE viewed_at < DATE_SUB(UTC_TIMESTAMP(), INTERVAL %d HOUR)", $hours )
		);

		if ( $dry_run ) {
			WP_CLI::success( sprintf( '[DRY RUN] Would prune %s rows older than %s.', number_format( $count ), $older ?: Settings::get( 'retention' ) . 'd' ) );
			return;
		}

		$wpdb->query(
			$wpdb->prepare( "DELETE FROM $table WHERE viewed_at < DATE_SUB(UTC_TIMESTAMP(), INTERVAL %d HOUR)", $hours )
		);

		WP_CLI::success( sprintf( 'Pruned %s rows.', number_format( $count ) ) );
	}

	/**
	 * Generate fake view data for testing.
	 *
	 * ## OPTIONS
	 *
	 * [--posts=<count>]
	 * : Number of posts to seed views for. Default: 50.
	 *
	 * [--views=<count>]
	 * : Max views per post (randomized 1 to this value). Default: 200.
	 *
	 * [--days=<count>]
	 * : Spread views across this many days. Default: 7.
	 *
	 * [--include-terms]
	 * : Also seed views for terms on the selected posts.
	 *
	 * [--include-authors]
	 * : Also seed views for the authors of the selected posts.
	 *
	 * ## EXAMPLES
	 *
	 *     wp mai-views seed
	 *     wp mai-views seed --posts=100 --views=500 --days=14
	 *     wp mai-views seed --include-terms --include-authors
	 *
	 * @param array $args       Positional arguments (unused).
	 * @param array $assoc_args Associative arguments: --posts, --views, --days, --include-terms, --include-authors.
	 *
	 * @return void
	 */
	public function seed( array $args, array $assoc_args ): void {
		global $wpdb;

		$table           = Database::get_table_name();
		$num_posts       = (int) ( $assoc_args['posts'] ?? 50 );
		$max_views       = (int) ( $assoc_args['views'] ?? 200 );
		$days            = (int) ( $assoc_args['days'] ?? 30 );
		$include_terms   = \WP_CLI\Utils\get_flag_value( $assoc_args, 'include-terms', false );
		$include_authors = \WP_CLI\Utils\get_flag_value( $assoc_args, 'include-authors', false );

		$public_types = get_post_types( [ 'public' => true ] );
		$type_list    = implode( "','", array_map( 'esc_sql', $public_types ) );

		$post_ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT ID FROM $wpdb->posts
				 WHERE post_status = 'publish' AND post_type IN ('$type_list')
				 ORDER BY RAND() LIMIT %d",
				$num_posts
			)
		);

		if ( empty( $post_ids ) ) {
			WP_CLI::error( 'No published posts found to seed.' );
		}

		$total_views    = 0;
		$seeded_terms   = [];
		$seeded_authors = [];
		$sources        = [ 'web', 'web', 'web', 'app' ];
		$now            = time();

		WP_CLI::log( sprintf( 'Seeding views for %d posts over %d days...', count( $post_ids ), $days ) );

		foreach ( $post_ids as $post_id ) {
			$post_id   = (int) $post_id;
			$num_views = wp_rand( 1, $max_views );

			$total_views += $this->bulk_insert_views( $table, $post_id, 'post', $num_views, $days, $now, $sources );

			if ( $include_terms ) {
				$terms = wp_get_post_terms( $post_id, get_taxonomies( [ 'public' => true ] ), [ 'fields' => 'ids' ] );

				if ( ! is_wp_error( $terms ) ) {
					foreach ( $terms as $term_id ) {
						$seeded_terms[ (int) $term_id ] = true;
					}
				}
			}

			if ( $include_authors ) {
				$author_id = (int) get_post_field( 'post_author', $post_id );

				if ( $author_id ) {
					$seeded_authors[ $author_id ] = true;
				}
			}
		}

		if ( $include_terms && $seeded_terms ) {
			WP_CLI::log( sprintf( 'Seeding views for %d terms...', count( $seeded_terms ) ) );

			foreach ( array_keys( $seeded_terms ) as $term_id ) {
				$total_views += $this->bulk_insert_views( $table, $term_id, 'term', wp_rand( 1, (int) ( $max_views / 3 ) ), $days, $now, $sources );
			}
		}

		if ( $include_authors && $seeded_authors ) {
			WP_CLI::log( sprintf( 'Seeding views for %d authors...', count( $seeded_authors ) ) );

			foreach ( array_keys( $seeded_authors ) as $user_id ) {
				$total_views += $this->bulk_insert_views( $table, $user_id, 'user', wp_rand( 1, (int) ( $max_views / 5 ) ), $days, $now, $sources );
			}
		}

		WP_CLI::log( sprintf( 'Inserted %s raw view rows. Running sync...', number_format( $total_views ) ) );

		// Reset sync timestamp so all seeded rows are picked up.
		update_option( 'mai_views_synced', 0 );
		delete_transient( 'mai_views_syncing' );

		Sync::sync();

		WP_CLI::success( 'Seed complete. Run `wp mai-views stats` to see results.' );
	}

	/**
	 * Wipe all Mai Views data (buffer table, meta, options).
	 *
	 * ## OPTIONS
	 *
	 * [--yes]
	 * : Skip confirmation prompt.
	 *
	 * ## EXAMPLES
	 *
	 *     wp mai-views reset
	 *     wp mai-views reset --yes
	 *
	 * @param array $args       Positional arguments (unused).
	 * @param array $assoc_args Associative arguments: --yes.
	 *
	 * @return void
	 */
	public function reset( array $args, array $assoc_args ): void {
		global $wpdb;

		WP_CLI::confirm( 'This will DELETE all Mai Views data (buffer table, view/trending meta, options). Continue?', $assoc_args );

		$table = Database::get_table_name();

		$wpdb->query( "TRUNCATE TABLE $table" );
		WP_CLI::log( 'Buffer table truncated.' );

		$meta_keys    = "'mai_views','mai_views_web','mai_views_app','mai_trending'";
		$post_deleted = (int) $wpdb->query( "DELETE FROM $wpdb->postmeta WHERE meta_key IN ({$meta_keys})" );
		WP_CLI::log( sprintf( 'Deleted %s post meta rows.', number_format( $post_deleted ) ) );

		$term_deleted = (int) $wpdb->query( "DELETE FROM $wpdb->termmeta WHERE meta_key IN ({$meta_keys})" );
		WP_CLI::log( sprintf( 'Deleted %s term meta rows.', number_format( $term_deleted ) ) );

		$user_deleted = (int) $wpdb->query( "DELETE FROM $wpdb->usermeta WHERE meta_key IN ({$meta_keys})" );
		WP_CLI::log( sprintf( 'Deleted %s user meta rows.', number_format( $user_deleted ) ) );

		delete_option( 'mai_views_synced' );
		delete_option( 'mai_views_provider_last_sync' );
		delete_option( 'mai_views_post_type_views_web' );
		delete_option( 'mai_views_post_type_views_app' );
		delete_transient( 'mai_views_sync_lock' );
		delete_transient( 'mai_views_syncing' );
		delete_transient( 'mai_views_provider_syncing' );
		WP_CLI::log( 'Options and transients cleared.' );

		WP_CLI::success( 'All Mai Views data has been reset.' );
	}

	/**
	 * Process the current provider sync queue immediately.
	 *
	 * ## OPTIONS
	 *
	 * [--verbose]
	 * : Show detailed output.
	 *
	 * ## EXAMPLES
	 *
	 *     wp mai-views provider-sync
	 *
	 * @param array $args       Positional arguments (unused).
	 * @param array $assoc_args Associative arguments: --verbose.
	 *
	 * @return void
	 */
	public function provider_sync( array $args, array $assoc_args ): void {
		if ( 'self_hosted' === Settings::get( 'data_source' ) ) {
			WP_CLI::error( 'Provider sync is only available when an external data source is configured.' );
		}

		WP_CLI::log( 'Running provider sync...' );

		ProviderSync::sync();

		$last_sync = get_option( 'mai_views_provider_last_sync', 0 );

		WP_CLI::success( sprintf(
			'Provider sync complete. Last sync: %s',
			$last_sync ? wp_date( 'Y-m-d H:i:s', $last_sync ) : 'never'
		) );
	}

	/**
	 * Warm stats by bulk-fetching from the active provider for all or specific objects.
	 *
	 * ## OPTIONS
	 *
	 * [--type=<type>]
	 * : Object type to warm: post, term, user, or archive.
	 *
	 * [--ids=<ids>]
	 * : Comma-separated IDs (only with --type).
	 *
	 * [--post_type=<post_type>]
	 * : Limit to a specific post type (only with --type=post).
	 *
	 * [--taxonomy=<taxonomy>]
	 * : Limit to a specific taxonomy (only with --type=term).
	 *
	 * [--verbose]
	 * : Show detailed per-batch output.
	 *
	 * ## EXAMPLES
	 *
	 *     wp mai-views warm
	 *     wp mai-views warm --type=post --ids=1,2,3
	 *     wp mai-views warm --type=term --taxonomy=category --verbose
	 *
	 * @param array $args       Positional arguments (unused).
	 * @param array $assoc_args Associative arguments.
	 *
	 * @return void
	 */
	public function warm( array $args, array $assoc_args ): void {
		if ( 'self_hosted' === Settings::get( 'data_source' ) ) {
			WP_CLI::error( 'Warm is only available when an external data source is configured.' );
		}

		$verbose       = \WP_CLI\Utils\get_flag_value( $assoc_args, 'verbose', false );
		$total_updated = 0;
		$warm_args     = [];

		if ( isset( $assoc_args['type'] ) ) {
			$warm_args['type'] = $assoc_args['type'];
		}

		if ( isset( $assoc_args['ids'] ) ) {
			$warm_args['ids'] = array_map( 'absint', explode( ',', $assoc_args['ids'] ) );
		}

		if ( isset( $assoc_args['post_type'] ) ) {
			$warm_args['post_type'] = $assoc_args['post_type'];
		}

		if ( isset( $assoc_args['taxonomy'] ) ) {
			$warm_args['taxonomy'] = $assoc_args['taxonomy'];
		}

		WP_CLI::log( 'Warming stats from provider...' );

		foreach ( ProviderSync::warm( $warm_args ) as $progress ) {
			$total_updated += $progress['updated'] ?? 0;

			if ( $verbose ) {
				WP_CLI::log( sprintf(
					'  Batch %d/%d: updated %d %s objects',
					$progress['batch'],
					$progress['total'],
					$progress['updated'],
					$progress['type']
				) );
			}
		}

		WP_CLI::success( sprintf( 'Warm complete. Updated %d objects.', $total_updated ) );
	}

	/**
	 * Fetch the latest bot patterns from Matomo's device-detector.
	 *
	 * Runs the same script that composer post-update-cmd triggers.
	 *
	 * ## EXAMPLES
	 *
	 *     wp mai-views update-bots
	 *
	 * @param array $args       Positional arguments (unused).
	 * @param array $assoc_args Associative arguments (unused).
	 *
	 * @return void
	 */
	public function update_bots( array $args, array $assoc_args ): void {
		$script = MAI_VIEWS_PLUGIN_DIR . 'bin/update-bot-patterns.php';

		if ( ! file_exists( $script ) ) {
			WP_CLI::error( 'bin/update-bot-patterns.php not found.' );
		}

		WP_CLI::log( 'Updating bot patterns from Matomo device-detector...' );

		// Capture output from the script.
		ob_start();
		include $script;
		$output = ob_get_clean();

		WP_CLI::log( trim( $output ) );
	}

	/**
	 * Bulk inserts view rows for a single object.
	 *
	 * @param string $table       The database table name.
	 * @param int    $object_id   The post, term, or user ID.
	 * @param string $object_type The object type: 'post', 'term', or 'user'.
	 * @param int    $num_views   The number of view rows to insert.
	 * @param int    $days        The number of days to spread views across.
	 * @param int    $now         The current Unix timestamp.
	 * @param array  $sources     Array of source values to randomly select from.
	 *
	 * @return int The number of rows inserted.
	 */
	private function bulk_insert_views( string $table, int $object_id, string $object_type, int $num_views, int $days, int $now, array $sources ): int {
		global $wpdb;

		$values = [];

		for ( $i = 0; $i < $num_views; $i++ ) {
			$seconds_ago = wp_rand( 0, $days * DAY_IN_SECONDS );
			$viewed_at   = gmdate( 'Y-m-d H:i:s', $now - $seconds_ago );
			$source      = $sources[ array_rand( $sources ) ];
			$values[]    = $wpdb->prepare( '(%d, %s, %s, %s)', $object_id, $object_type, $viewed_at, $source );
		}

		foreach ( array_chunk( $values, 500 ) as $chunk ) {
			$wpdb->query( "INSERT INTO $table (object_id, object_type, viewed_at, source) VALUES " . implode( ', ', $chunk ) );
		}

		return $num_views;
	}

}
