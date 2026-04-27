<?php

namespace Mai\Analytics;

use WP_CLI;

class CLI {

	/**
	 * Registers all WP-CLI subcommands for Mai Analytics.
	 */
	public function __construct() {
		WP_CLI::add_command( 'mai-analytics health',         [ $this, 'health' ] );
		WP_CLI::add_command( 'mai-analytics migrate',       [ $this, 'migrate' ] );
		WP_CLI::add_command( 'mai-analytics sync',          [ $this, 'sync' ] );
		WP_CLI::add_command( 'mai-analytics stats',         [ $this, 'stats' ] );
		WP_CLI::add_command( 'mai-analytics prune',         [ $this, 'prune' ] );
		WP_CLI::add_command( 'mai-analytics seed',          [ $this, 'seed' ] );
		WP_CLI::add_command( 'mai-analytics reset',         [ $this, 'reset' ] );
		WP_CLI::add_command( 'mai-analytics update-bots',   [ $this, 'update_bots' ] );
		WP_CLI::add_command( 'mai-analytics warm',          [ $this, 'warm' ] );
	}

	/**
	 * Run health checks on the Mai Analytics installation.
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
	 *     wp mai-analytics health
	 *     wp mai-analytics health --fix
	 *
	 * @param array $args       Positional arguments (unused).
	 * @param array $assoc_args Associative arguments: --fix.
	 *
	 * @return void
	 */
	public function health( array $args, array $assoc_args ): void {
		$fix     = \WP_CLI\Utils\get_flag_value( $assoc_args, 'fix', false );
		$results = Health::run();

		$current_section = '';

		foreach ( $results['checks'] as $c ) {
			if ( $c['section'] !== $current_section ) {
				$current_section = $c['section'];
				WP_CLI::log( '' );
				WP_CLI::log( WP_CLI::colorize( "%B=== {$current_section} ===%n" ) );
			}

			$detail = $c['detail'] ? " — {$c['detail']}" : '';

			$line = match ( $c['status'] ) {
				'pass' => WP_CLI::colorize( "  %G\u{2713}%n {$c['label']}{$detail}" ),
				'fail' => WP_CLI::colorize( "  %R\u{2717}%n {$c['label']}{$detail}" ),
				'warn' => WP_CLI::colorize( "  %Y!%n {$c['label']}{$detail}" ),
			};

			WP_CLI::log( $line );
		}

		// Auto-fix: recreate table and reschedule cron if missing.
		if ( $fix ) {
			$table_failed = array_filter( $results['checks'], fn( $c ) => 'Buffer table exists' === $c['label'] && 'fail' === $c['status'] );
			$cron_failed  = array_filter( $results['checks'], fn( $c ) => 'Cron scheduled' === $c['label'] && 'fail' === $c['status'] );

			if ( $table_failed ) {
				Database::create_table();
				WP_CLI::log( WP_CLI::colorize( "  %C\u{2192} Fixed: created table%n" ) );
			}

			if ( $cron_failed ) {
				wp_schedule_event( time(), 'mai_analytics_15min', 'mai_analytics_cron_sync' );
				WP_CLI::log( WP_CLI::colorize( "  %C\u{2192} Fixed: rescheduled cron%n" ) );
			}
		}

		WP_CLI::log( '' );
		$summary = sprintf( '%d passed, %d failed, %d warnings out of %d checks', $results['pass'], $results['fail'], $results['warn'], $results['total'] );

		if ( $results['fail'] > 0 ) {
			WP_CLI::error( $summary, false );
		} elseif ( $results['warn'] > 0 ) {
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
	 *     wp mai-analytics migrate
	 *     wp mai-analytics migrate --force
	 *
	 * @param array $args       Positional arguments (unused).
	 * @param array $assoc_args Associative arguments: --force.
	 *
	 * @return void
	 */
	public function migrate( array $args, array $assoc_args ): void {
		$force = \WP_CLI\Utils\get_flag_value( $assoc_args, 'force', false );

		if ( $force ) {
			delete_option( 'mai_analytics_migrated_from_publisher' );
			delete_option( 'mai_analytics_migrated_from_analytics' );
			WP_CLI::log( 'Force flag set. Cleared migration flags.' );
		}

		$had_publisher_settings = (bool) get_option( 'mai_publisher' );
		$had_analytics_settings = (bool) get_option( 'mai_analytics_settings' );
		$already_configured     = (bool) get_option( 'mai_analytics_settings' );

		if ( $already_configured && ! $force ) {
			WP_CLI::log( 'Mai Analytics settings already exist. Use --force to re-run migration.' );
		}

		Migration::maybe_migrate();

		if ( $had_publisher_settings ) {
			$settings = get_option( 'mai_analytics_settings', [] );
			WP_CLI::log( sprintf( 'Migrated from Mai Publisher: data_source=%s', $settings['data_source'] ?? 'unknown' ) );

			$defaults = get_option( 'mai_analytics_migrated_defaults', [] );

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
	 * Force a manual sync. Routes to buffer sync or provider sync based on data source.
	 *
	 * ## OPTIONS
	 *
	 * [--verbose]
	 * : Show detailed output.
	 *
	 * ## EXAMPLES
	 *
	 *     wp mai-analytics sync
	 *     wp mai-analytics sync --verbose
	 *
	 * @param array $args       Positional arguments (unused).
	 * @param array $assoc_args Associative arguments: --verbose.
	 *
	 * @return void
	 */
	public function sync( array $args, array $assoc_args ): void {
		global $wpdb;

		$data_source = Settings::get( 'data_source' );

		if ( 'disabled' === $data_source ) {
			WP_CLI::error( 'View tracking is disabled. Change the data source in settings first.' );
		}

		$verbose = \WP_CLI\Utils\get_flag_value( $assoc_args, 'verbose', false );
		$table   = Database::get_table_name();

		if ( $verbose ) {
			$count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM $table" );
			WP_CLI::log( sprintf( 'Data source: %s', $data_source ) );
			WP_CLI::log( sprintf( 'Buffer table rows before sync: %s', number_format( $count ) ) );
		}

		if ( 'self_hosted' === $data_source ) {
			WP_CLI::log( 'Running buffer sync...' );
			Sync::sync();
		} else {
			WP_CLI::log( sprintf( 'Running provider sync (%s)...', $data_source ) );
			ProviderSync::sync();
		}

		if ( $verbose ) {
			$count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM $table" );
			WP_CLI::log( sprintf( 'Buffer table rows after sync:  %s', number_format( $count ) ) );
		}

		$last_sync = get_option( 'mai_analytics_synced', 0 );
		WP_CLI::success( sprintf( 'Sync complete. Last sync: %s', $last_sync ? wp_date( 'Y-m-d H:i:s', $last_sync ) : 'never' ) );
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
	 *     wp mai-analytics stats
	 *     wp mai-analytics stats --type=post
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

		$last_sync = get_option( 'mai_analytics_synced', 0 );

		$data_source = Settings::get( 'data_source' );

		WP_CLI::log( sprintf( 'Data source:              %s', $data_source ) );
		WP_CLI::log( sprintf( 'Tracked objects in buffer: %s', number_format( $object_count ) ) );
		WP_CLI::log( sprintf( 'Buffer table rows:        %s', number_format( $buffer_count ) ) );
		WP_CLI::log( sprintf( 'Total lifetime views:     %s', number_format( $total_views ) ) );
		WP_CLI::log( sprintf( 'Last sync:                %s', $last_sync ? wp_date( 'Y-m-d H:i:s', $last_sync ) : 'never' ) );

		if ( 'self_hosted' !== $data_source ) {
			$provider_sync = get_option( 'mai_analytics_provider_last_sync', 0 );

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
	 *     wp mai-analytics prune
	 *     wp mai-analytics prune --older-than=48h --dry-run
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
	 *     wp mai-analytics seed
	 *     wp mai-analytics seed --posts=100 --views=500 --days=14
	 *     wp mai-analytics seed --include-terms --include-authors
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
		update_option( 'mai_analytics_synced', 0 );
		delete_transient( 'mai_analytics_syncing' );

		Sync::sync();

		WP_CLI::success( 'Seed complete. Run `wp mai-analytics stats` to see results.' );
	}

	/**
	 * Wipe all Mai Analytics data (buffer table, meta, options).
	 *
	 * ## OPTIONS
	 *
	 * [--yes]
	 * : Skip confirmation prompt.
	 *
	 * ## EXAMPLES
	 *
	 *     wp mai-analytics reset
	 *     wp mai-analytics reset --yes
	 *
	 * @param array $args       Positional arguments (unused).
	 * @param array $assoc_args Associative arguments: --yes.
	 *
	 * @return void
	 */
	public function reset( array $args, array $assoc_args ): void {
		global $wpdb;

		WP_CLI::confirm( 'This will DELETE all Mai Analytics data (buffer table, view/trending meta, options). Continue?', $assoc_args );

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

		delete_option( 'mai_analytics_synced' );
		delete_option( 'mai_analytics_provider_last_sync' );
		delete_option( 'mai_analytics_post_type_views_web' );
		delete_option( 'mai_analytics_post_type_views_app' );
		delete_transient( 'mai_analytics_sync_lock' );
		delete_transient( 'mai_analytics_syncing' );
		delete_transient( 'mai_analytics_provider_syncing' );
		WP_CLI::log( 'Options and transients cleared.' );

		WP_CLI::success( 'All Mai Analytics data has been reset.' );
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
	 * [--chunk=<n>]
	 * : Override the Matomo bulk chunk size (paths per HTTP request) for this
	 * call. Useful on beefy infra to push more (paths × windows) into a single
	 * request without registering a `mai_analytics_matomo_bulk_chunk` filter
	 * in a mu-plugin. Ignored by non-Matomo providers.
	 *
	 * [--verbose]
	 * : Show detailed per-batch output.
	 *
	 * ## EXAMPLES
	 *
	 *     wp mai-analytics warm
	 *     wp mai-analytics warm --type=post --ids=1,2,3
	 *     wp mai-analytics warm --type=term --taxonomy=category --verbose
	 *     wp mai-analytics warm --chunk=25 --verbose
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

		// Per-call override of the Matomo bulk chunk size. Registered as a
		// filter rather than passed through warm_args because the filter is
		// the public extension point any mu-plugin would use anyway, and the
		// CLI process exits when warm finishes so the closure doesn't leak
		// into request-handling code.
		$chunk = isset( $assoc_args['chunk'] ) ? (int) $assoc_args['chunk'] : 0;

		if ( $chunk > 0 ) {
			add_filter( 'mai_analytics_matomo_bulk_chunk', fn() => $chunk );
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
	 *     wp mai-analytics update-bots
	 *
	 * @param array $args       Positional arguments (unused).
	 * @param array $assoc_args Associative arguments (unused).
	 *
	 * @return void
	 */
	public function update_bots( array $args, array $assoc_args ): void {
		$script = MAI_ANALYTICS_PLUGIN_DIR . 'bin/update-bot-patterns.php';

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
