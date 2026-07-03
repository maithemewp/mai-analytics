<?php

namespace Mai\Analytics;

/**
 * Owns the view-meta lifecycle. This plan implements the trending portion:
 * writes (with 0 => delete) and pruning. The mai_views/web/app migration lands
 * in Plan 2.
 */
class Stats {

	/**
	 * Default number of rows deleted per prune batch.
	 */
	public const PRUNE_BATCH = 5000;

	/**
	 * Sets a post/term/user trending value. A value of 0 (or less) deletes the
	 * row rather than storing a zero, so the trending meta stays bounded to
	 * posts actually trending in the window and the meta_value+0 sort that
	 * orders trending grids never has to scan dead rows.
	 *
	 * Callers must only pass a value derived from a confirmed result, never a
	 * failure sentinel (see the provider null-guard in ProviderSync).
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
	 * Deletes stale and zero mai_trending rows so the trending index stays bounded.
	 *
	 * Zero rows are always deleted. Stale rows are mode-specific: in self-hosted mode a
	 * row is stale when the object has no view in the current trending-window buffer;
	 * in provider mode a row is stale when its mai_views_synced_at predates the window,
	 * and only when the provider circuit breaker is not fresh (an outage must never wipe
	 * trending).
	 *
	 * @param int   $window_days Trending window in days.
	 * @param array $opts        'dry_run' (bool), 'batch_size' (int).
	 *
	 * @return int Rows deleted (or, for dry_run, that would be deleted).
	 */
	public static function prune_trending( int $window_days, array $opts = [] ): int {
		$dry_run = ! empty( $opts['dry_run'] );
		$batch   = (int) apply_filters( 'mai_analytics_prune_batch_size', $opts['batch_size'] ?? self::PRUNE_BATCH );
		$batch   = max( 1, $batch );
		$source  = Settings::get( 'data_source' );

		$total = 0;

		foreach ( [ 'post', 'term', 'user' ] as $type ) {
			// Always: zero rows.
			$total += self::delete_trending_ids( $type, self::zero_trending_ids( $type ), $batch, $dry_run );

			if ( 'self_hosted' === $source ) {
				$total += self::delete_trending_ids( $type, self::self_hosted_stale_ids( $type, $window_days ), $batch, $dry_run );
			} elseif ( 0 === Sync::seconds_until_provider_error_clear() ) {
				$total += self::delete_trending_ids( $type, self::stale_provider_ids( $type, $window_days ), $batch, $dry_run );
			}
		}

		return $total;
	}

	/**
	 * Object ids whose mai_trending value is 0.
	 */
	private static function zero_trending_ids( string $type ): array {
		global $wpdb;
		[ $table, $id_col ] = self::meta_table( $type );
		if ( ! $table ) {
			return [];
		}
		// No user input in $table/$id_col (both come from $wpdb's own properties), and
		// there's no placeholder to bind, so prepare() would trip the "must have a
		// placeholder" doing_it_wrong notice; query directly instead.
		return array_map( 'intval', $wpdb->get_col(
			"SELECT {$id_col} FROM {$table} WHERE meta_key = 'mai_trending' AND meta_value + 0 = 0"
		) );
	}

	/**
	 * Object ids with a mai_trending value whose mai_views_synced_at predates the window
	 * (or is missing). Provider mode only.
	 */
	private static function stale_provider_ids( string $type, int $window_days ): array {
		global $wpdb;
		[ $table, $id_col ] = self::meta_table( $type );
		if ( ! $table ) {
			return [];
		}
		$cutoff = time() - ( $window_days * DAY_IN_SECONDS );
		return array_map( 'intval', $wpdb->get_col( $wpdb->prepare(
			"SELECT t.{$id_col}
			 FROM {$table} t
			 LEFT JOIN {$table} s ON s.{$id_col} = t.{$id_col} AND s.meta_key = 'mai_views_synced_at'
			 WHERE t.meta_key = 'mai_trending'
			   AND t.meta_value + 0 > 0
			   AND ( s.meta_value IS NULL OR s.meta_value + 0 < %d )",
			$cutoff
		) ) );
	}

	/**
	 * Self-hosted stale ids: objects with a mai_trending value that are NOT in the
	 * current trending-window buffer set. The buffer is authoritative, so any trending
	 * row without a windowed view is stale (this also catches beyond-retention posts
	 * whose buffer rows were pruned, which the inline decay never sees).
	 */
	private static function self_hosted_stale_ids( string $type, int $window_days ): array {
		global $wpdb;
		[ $meta_table, $id_col ] = self::meta_table( $type );
		if ( ! $meta_table ) {
			return [];
		}
		$buffer = Database::get_table_name();

		return array_map( 'intval', $wpdb->get_col( $wpdb->prepare(
			"SELECT m.{$id_col}
			 FROM {$meta_table} m
			 WHERE m.meta_key = 'mai_trending'
			   AND m.meta_value + 0 > 0
			   AND m.{$id_col} NOT IN (
			       SELECT DISTINCT b.object_id
			       FROM {$buffer} b
			       WHERE b.object_type = %s
			         AND b.viewed_at > DATE_SUB(UTC_TIMESTAMP(), INTERVAL %d DAY)
			   )",
			$type,
			$window_days
		) ) );
	}

	/**
	 * Deletes the mai_trending rows for the given object ids, in batches.
	 * For dry_run, returns the count without deleting.
	 */
	private static function delete_trending_ids( string $type, array $ids, int $batch, bool $dry_run ): int {
		$ids = array_values( array_unique( array_filter( $ids ) ) );
		if ( ! $ids ) {
			return 0;
		}
		if ( $dry_run ) {
			return count( $ids );
		}

		$deleted = 0;
		foreach ( array_chunk( $ids, $batch ) as $chunk ) {
			$deleted += self::delete_trending_chunk( $type, $chunk );
		}
		return $deleted;
	}

	/**
	 * Deletes mai_trending for one chunk of object ids.
	 */
	private static function delete_trending_chunk( string $type, array $ids ): int {
		$deleted = 0;
		foreach ( $ids as $id ) {
			Sync::delete_meta( (int) $id, $type, 'mai_trending' );
			$deleted++;
		}
		return $deleted;
	}

	/**
	 * Returns [ meta_table, id_column ] for an object type, or [ null, null ].
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
