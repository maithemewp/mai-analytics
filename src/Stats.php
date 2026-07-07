<?php

namespace Mai\Analytics;

/**
 * Owns the mai_trending meta lifecycle: writes (with 0 => delete) and pruning.
 * All-time view handling (mai_views / mai_views_web / mai_views_app) is not part
 * of this store — it still lives in Sync and ProviderSync.
 *
 * @since 1.2.0
 */
class Stats {

	/**
	 * Data sources that sync trending from an external provider. Only these run
	 * the provider-staleness prune branch; any other source (self_hosted,
	 * disabled, or an unknown/misconfigured value) never does, so a stray
	 * data_source can only ever delete zero rows, never real trending.
	 *
	 * @since 1.2.0
	 */
	private const PROVIDER_SOURCES = [ 'matomo', 'site_kit', 'jetpack' ];

	/**
	 * Rows selected and deleted per prune pass. Each pass selects at most this
	 * many ids (SQL LIMIT) and deletes them, looping until a pass comes back
	 * short — so a single prune run is bounded in memory and lock duration
	 * regardless of how large the backlog is. Filterable via
	 * `mai_analytics_prune_batch_size`.
	 *
	 * @since 1.2.0
	 */
	public const PRUNE_BATCH = 5000;

	/**
	 * Sets a post/term/user trending value. A value of 0 (or less) deletes the
	 * row rather than storing a zero, so the trending meta stays bounded to
	 * posts actually trending in the window and the meta_value+0 sort that
	 * orders trending grids never has to scan dead rows.
	 *
	 * The 0-delete rule means safety is caller-provided: a caller must only pass
	 * a value derived from a confirmed result, never a failure sentinel (see the
	 * provider null-guard in ProviderSync). A negative value is treated as delete,
	 * same as 0.
	 *
	 * @since 1.2.0
	 *
	 * @param int    $object_id   The post, term, or user ID.
	 * @param string $object_type The object type: 'post', 'term', or 'user'.
	 * @param int    $value       The trending count.
	 *
	 * @return void
	 */
	public static function set_trending( int $object_id, string $object_type, int $value ): void {
		if ( $value > 0 ) {
			Sync::update_meta( $object_id, $object_type, 'mai_trending', 'replace', $value );
		} else {
			Sync::delete_meta( $object_id, $object_type, 'mai_trending' );
		}
	}

	/**
	 * Records the provider-sync timestamp for an object (provider success marker).
	 *
	 * @since 1.3.0
	 *
	 * @param int    $object_id   The post, term, or user ID.
	 * @param string $object_type The object type: 'post', 'term', or 'user'.
	 * @param int    $now         The sync timestamp.
	 *
	 * @return void
	 */
	public static function mark_synced( int $object_id, string $object_type, int $now ): void {
		Sync::update_meta( $object_id, $object_type, 'mai_views_synced_at', 'replace', $now );
	}

	/**
	 * Replaces the web view count for an object.
	 *
	 * @since 1.3.0
	 *
	 * @param int    $object_id   The post, term, or user ID.
	 * @param string $object_type The object type: 'post', 'term', or 'user'.
	 * @param int    $value       The authoritative web total.
	 *
	 * @return void
	 */
	public static function set_web( int $object_id, string $object_type, int $value ): void {
		Sync::update_meta( $object_id, $object_type, 'mai_views_web', 'replace', $value );
	}

	/**
	 * Adds newly-counted web views to an object's running web total (self-hosted path).
	 *
	 * @since 1.3.0
	 *
	 * @param int    $object_id   The post, term, or user ID.
	 * @param string $object_type The object type: 'post', 'term', or 'user'.
	 * @param int    $delta       New web views counted since the last sync.
	 *
	 * @return void
	 */
	public static function add_web( int $object_id, string $object_type, int $delta ): void {
		Sync::update_meta( $object_id, $object_type, 'mai_views_web', 'increment', $delta );
	}

	/**
	 * Adds newly-buffered app views to an object's running app total.
	 *
	 * @since 1.3.0
	 *
	 * @param int    $object_id   The post, term, or user ID.
	 * @param string $object_type The object type: 'post', 'term', or 'user'.
	 * @param int    $delta       New app views counted since the last sync.
	 *
	 * @return void
	 */
	public static function add_app( int $object_id, string $object_type, int $delta ): void {
		Sync::update_meta( $object_id, $object_type, 'mai_views_app', 'increment', $delta );
	}

	/**
	 * Recomputes an object's total views as max( web + app, trending ), preserving
	 * the floor invariant that total is never below the trending value.
	 *
	 * @since 1.3.0
	 *
	 * @param int    $object_id   The post, term, or user ID.
	 * @param string $object_type The object type: 'post', 'term', or 'user'.
	 *
	 * @return void
	 */
	public static function recompute_total( int $object_id, string $object_type ): void {
		$web      = (int) Sync::get_meta( $object_id, $object_type, 'mai_views_web' );
		$app      = (int) Sync::get_meta( $object_id, $object_type, 'mai_views_app' );
		$trending = (int) Sync::get_meta( $object_id, $object_type, 'mai_trending' );
		Sync::update_meta( $object_id, $object_type, 'mai_views', 'replace', max( $web + $app, $trending ) );
	}

	/**
	 * Deletes stale and zero mai_trending rows so the trending index stays bounded.
	 *
	 * Zero rows are always deleted, in any mode. Stale-row deletion is mode-specific
	 * and fails safe:
	 *
	 * - Self-hosted: a row is stale when the object has no view in the current
	 *   trending-window buffer — but only when the buffer is actually live (has
	 *   recent rows). An empty or broken buffer is never read as "nothing trends".
	 * - Provider: a row is stale when its mai_views_synced_at EXISTS and predates
	 *   the window. Rows with no mai_views_synced_at are kept — the provider has
	 *   never measured them, so they are never deleted on an absence of data. This
	 *   branch runs only when the provider is healthy (no unresolved provider error
	 *   on file), so a provider outage can never wipe trending.
	 *
	 * Work is bounded per pass (see PRUNE_BATCH) and looped until drained, so a
	 * large first prune stays within memory and lock limits.
	 *
	 * @since 1.2.0
	 *
	 * @param int      $window_days Trending window in days.
	 * @param bool     $dry_run     When true, count what would be deleted without deleting.
	 * @param int|null $batch_size  Rows per pass; null uses PRUNE_BATCH (filterable).
	 * @param int|null $max_batches Cap on delete passes for this call; null = unbounded.
	 *                              The cron path caps and reschedules; the CLI drains fully.
	 *
	 * @return int Rows deleted (or, for dry_run, that would be deleted).
	 */
	public static function prune_trending( int $window_days, bool $dry_run = false, ?int $batch_size = null, ?int $max_batches = null ): int {
		/**
		 * Filters the number of rows selected and deleted per prune pass.
		 *
		 * @since 1.2.0
		 *
		 * @param int $batch Rows per pass. Default PRUNE_BATCH (or the caller's batch_size).
		 */
		$batch  = (int) apply_filters( 'mai_analytics_prune_batch_size', $batch_size ?? self::PRUNE_BATCH );
		$batch  = max( 1, $batch );
		$source = Settings::get( 'data_source' );

		// Remaining delete passes for this call, shared across every category/type so
		// one invocation is bounded overall. null = unbounded (drain to completion).
		$budget = null !== $max_batches ? max( 1, $max_batches ) : null;

		$is_provider      = in_array( $source, self::PROVIDER_SOURCES, true );
		$provider_healthy = $is_provider && self::provider_is_healthy();
		$buffer_live      = ( 'self_hosted' === $source ) && self::buffer_is_live( $window_days );

		$counts = [ 'zero' => 0, 'provider_stale' => 0, 'self_hosted_stale' => 0 ];

		foreach ( [ 'post', 'term', 'user' ] as $type ) {
			// Always safe: a zero is dead weight in any mode.
			$counts['zero'] += self::prune_category( $type, 'zero', $window_days, $batch, $dry_run, $budget );

			if ( 'self_hosted' === $source ) {
				// Trust buffer-absence only when the buffer is demonstrably live.
				if ( $buffer_live ) {
					$counts['self_hosted_stale'] += self::prune_category( $type, 'self_hosted_stale', $window_days, $batch, $dry_run, $budget );
				}
			} elseif ( $provider_healthy ) {
				$counts['provider_stale'] += self::prune_category( $type, 'provider_stale', $window_days, $batch, $dry_run, $budget );
			}
		}

		$total = array_sum( $counts );

		if ( ! $dry_run ) {
			self::log_prune( $source, $is_provider, $provider_healthy, $buffer_live, $counts, $total );
		}

		return $total;
	}

	/**
	 * Whether the active provider is healthy enough to prune stale trending.
	 *
	 * True when there is no unresolved provider error on file. Providers clear the
	 * error on a successful sync and set it (persisting until the next success) on
	 * failure, so this stays "unhealthy" for the whole of an outage — unlike the
	 * time-windowed circuit breaker, which lapses to "clear" a few minutes after
	 * each failed attempt even while the outage continues.
	 *
	 * @since 1.2.0
	 *
	 * @return bool
	 */
	private static function provider_is_healthy(): bool {
		$last = Sync::get_last_error();
		return empty( $last['message'] );
	}

	/**
	 * Whether the self-hosted buffer is live (has at least one row inside the
	 * retention window). Used to distinguish "nothing is trending" from "the
	 * buffer is empty/broken" before trusting buffer-absence to delete trending.
	 *
	 * @since 1.2.0
	 *
	 * @param int $window_days Trending window in days.
	 *
	 * @return bool
	 */
	private static function buffer_is_live( int $window_days ): bool {
		global $wpdb;

		$buffer    = Database::get_table_name();
		$retention = max( (int) Settings::get( 'retention' ), $window_days );

		$has_row = $wpdb->get_var( $wpdb->prepare(
			"SELECT 1 FROM {$buffer} WHERE viewed_at > DATE_SUB( UTC_TIMESTAMP(), INTERVAL %d DAY ) LIMIT 1",
			$retention
		) );

		return (bool) $has_row;
	}

	/**
	 * Prunes one staleness category for one object type. For dry_run, returns the
	 * count without deleting. Otherwise selects up to $batch ids at a time and
	 * deletes them, looping until a pass returns fewer than $batch rows, so neither
	 * the id set nor the delete loop is ever fully resident.
	 *
	 * @since 1.2.0
	 *
	 * @param string   $type        The object type: 'post', 'term', or 'user'.
	 * @param string   $category    'zero', 'provider_stale', or 'self_hosted_stale'.
	 * @param int      $window_days Trending window in days.
	 * @param int      $batch       Max rows per pass.
	 * @param bool     $dry_run     When true, count without deleting.
	 * @param int|null $budget      Remaining delete passes (decremented by ref); null = unbounded.
	 *
	 * @return int Rows deleted (or that would be deleted).
	 */
	private static function prune_category( string $type, string $category, int $window_days, int $batch, bool $dry_run, ?int &$budget ): int {
		$query = self::category_query( $type, $category, $window_days );

		if ( null === $query ) {
			return 0;
		}

		[ $select_col, $body, $args ] = $query;

		global $wpdb;

		if ( $dry_run ) {
			$sql = "SELECT COUNT(*) {$body}";
			$sql = $args ? $wpdb->prepare( $sql, $args ) : $sql;
			return (int) $wpdb->get_var( $sql );
		}

		$deleted = 0;

		while ( null === $budget || $budget > 0 ) {
			$ids = array_map( 'intval', $wpdb->get_col(
				$wpdb->prepare( "SELECT {$select_col} {$body} LIMIT %d", array_merge( $args, [ $batch ] ) )
			) );

			$pass_deleted = 0;

			foreach ( $ids as $id ) {
				if ( $id > 0 ) {
					Sync::delete_meta( $id, $type, 'mai_trending' );
					$pass_deleted++;
				}
			}

			$deleted += $pass_deleted;

			if ( null !== $budget ) {
				$budget--;
			}

			// Stop when the pass came back short, or made no progress (guards
			// against an unexpected non-deletable row looping forever).
			if ( count( $ids ) < $batch || $pass_deleted < 1 ) {
				break;
			}
		}

		return $deleted;
	}

	/**
	 * Builds the SELECT for a prune category as [ $select_col, $from_where, $args ],
	 * or null when the type has no meta table. $from_where is the shared FROM/JOIN/
	 * WHERE body reused for both the COUNT (dry-run) and the LIMIT select (delete).
	 *
	 * @since 1.2.0
	 *
	 * @param string $type        The object type: 'post', 'term', or 'user'.
	 * @param string $category    'zero', 'provider_stale', or 'self_hosted_stale'.
	 * @param int    $window_days Trending window in days.
	 *
	 * @return array|null [ string $select_col, string $from_where, array $args ] or null.
	 */
	private static function category_query( string $type, string $category, int $window_days ): ?array {
		[ $table, $id_col ] = self::meta_table( $type );

		if ( ! $table ) {
			return null;
		}

		switch ( $category ) {
			case 'zero':
				// $table is a $wpdb property and $id_col is a hardcoded literal —
				// both trusted, no user input — and the only placeholder is the
				// LIMIT/COUNT wrapper's, added by the caller. `meta_value + 0 = 0`
				// also matches non-numeric/empty meta, which is fine: set_trending
				// only ever writes positive ints, so any such row is junk to delete.
				return [
					$id_col,
					"FROM {$table} WHERE meta_key = 'mai_trending' AND meta_value + 0 = 0",
					[],
				];

			case 'provider_stale':
				$cutoff = time() - ( $window_days * DAY_IN_SECONDS );

				// LEFT JOIN + IS NOT NULL, not an inner join, to make the intent
				// explicit: only rows that HAVE a mai_views_synced_at older than the
				// window are stale. A missing synced_at means "never measured" and is
				// deliberately kept.
				return [
					"t.{$id_col}",
					"FROM {$table} t
					 LEFT JOIN {$table} s ON s.{$id_col} = t.{$id_col} AND s.meta_key = 'mai_views_synced_at'
					 WHERE t.meta_key = 'mai_trending'
					   AND t.meta_value + 0 > 0
					   AND s.meta_value IS NOT NULL
					   AND s.meta_value + 0 < %d",
					[ $cutoff ],
				];

			case 'self_hosted_stale':
				$buffer = Database::get_table_name();

				return [
					"m.{$id_col}",
					"FROM {$table} m
					 WHERE m.meta_key = 'mai_trending'
					   AND m.meta_value + 0 > 0
					   AND m.{$id_col} NOT IN (
					       SELECT DISTINCT b.object_id
					       FROM {$buffer} b
					       WHERE b.object_type = %s
					         AND b.viewed_at > DATE_SUB( UTC_TIMESTAMP(), INTERVAL %d DAY )
					   )",
					[ $type, $window_days ],
				];
		}

		return null;
	}

	/**
	 * Logs a summary of a prune run and, when a run removes an unusually large
	 * share of trending rows, a warning tripwire (e.g. a broken buffer or a
	 * misgate wiping the index). Info goes to Ray/WP-CLI; the warning also reaches
	 * debug.log. No-op when Mai_Logger is unavailable.
	 *
	 * @since 1.2.0
	 *
	 * @param string $source           The active data_source.
	 * @param bool   $is_provider       Whether $source is a provider source.
	 * @param bool   $provider_healthy  Whether the provider-stale branch ran.
	 * @param bool   $buffer_live       Whether the self-hosted-stale branch ran.
	 * @param array  $counts            Per-category delete counts.
	 * @param int    $total             Total rows deleted.
	 *
	 * @return void
	 */
	private static function log_prune( string $source, bool $is_provider, bool $provider_healthy, bool $buffer_live, array $counts, int $total ): void {
		if ( ! class_exists( 'Mai_Logger' ) ) {
			return;
		}

		$note = '';
		if ( $is_provider && ! $provider_healthy ) {
			$note = ' [provider unhealthy — stale prune skipped]';
		} elseif ( 'self_hosted' === $source && ! $buffer_live ) {
			$note = ' [buffer not live — stale prune skipped]';
		}

		$logger = new \Mai_Logger( 'mai-analytics' );

		$logger->info( sprintf(
			'Trending prune (source=%s): deleted %d (zero=%d, provider_stale=%d, self_hosted_stale=%d)%s',
			$source,
			$total,
			$counts['zero'],
			$counts['provider_stale'],
			$counts['self_hosted_stale'],
			$note
		) );

		// The tripwire watches STALE deletions only. Zero-row deletes are always
		// safe and expected — a large first cleanup is almost entirely zeros — so
		// they must not trip it, or the warning cries wolf on the intended fix and
		// drowns out a genuine stale-wipe.
		$stale_deleted = $counts['provider_stale'] + $counts['self_hosted_stale'];

		if ( $stale_deleted < 1 ) {
			return;
		}

		/**
		 * Filters the share of the trending index a single prune may remove via
		 * staleness before it logs a warning tripwire. Default 0.9 fires only on a
		 * near-total wipe (defense-in-depth for an unforeseen regression, since the
		 * gates already prevent the known ones). Set to 0 to disable.
		 *
		 * @since 1.2.0
		 *
		 * @param float $fraction Fraction between 0 and 1. Default 0.9.
		 */
		$fraction = (float) apply_filters( 'mai_analytics_prune_anomaly_fraction', 0.9 );
		$before   = $stale_deleted + self::remaining_trending_rows();

		if ( $fraction > 0 && $before > 0 && ( $stale_deleted / $before ) >= $fraction ) {
			$logger->warning( sprintf(
				'Trending prune removed %d of %d trending rows (%d%%) via staleness in one run — verify this was intended.',
				$stale_deleted,
				$before,
				(int) round( ( $stale_deleted / $before ) * 100 )
			) );
		}
	}

	/**
	 * Total mai_trending rows remaining across post/term/user meta. Used only to
	 * derive the anomaly fraction in log_prune.
	 *
	 * @since 1.2.0
	 *
	 * @return int
	 */
	private static function remaining_trending_rows(): int {
		global $wpdb;

		$total = 0;

		foreach ( [ 'post', 'term', 'user' ] as $type ) {
			[ $table ] = self::meta_table( $type );

			if ( ! $table ) {
				continue;
			}

			$total += (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE meta_key = 'mai_trending'" );
		}

		return $total;
	}

	/**
	 * Returns [ meta_table, id_column ] for an object type, or [ null, null ].
	 *
	 * @since 1.2.0
	 *
	 * @param string $type The object type: 'post', 'term', or 'user'.
	 *
	 * @return array [ string|null $table, string|null $id_col ].
	 */
	private static function meta_table( string $type ): array {
		global $wpdb;
		return match ( $type ) {
			'post' => [ $wpdb->postmeta, 'post_id' ],
			'term' => [ $wpdb->termmeta, 'term_id' ],
			'user' => [ $wpdb->usermeta, 'user_id' ],
			default => [ null, null ],
		};
	}
}
