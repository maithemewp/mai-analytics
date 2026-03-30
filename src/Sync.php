<?php

namespace Mai\Views;

class Sync {

	/**
	 * Aggregates buffer table views into meta, recalculates trending, and prunes old rows.
	 *
	 * Views are split by source (web/app) into separate meta keys, with a computed total.
	 * Trending is a single merged key from all sources.
	 *
	 * @return void
	 */
	public static function sync(): void {
		// Prevent concurrent syncs from double-counting.
		if ( get_transient( 'mai_views_syncing' ) ) {
			return;
		}

		set_transient( 'mai_views_syncing', 1, 5 * MINUTE_IN_SECONDS );

		// Mark sync as started so other triggers don't re-fire while we're working.
		$last_sync = get_option( 'mai_views_synced', 0 );
		update_option( 'mai_views_synced', time(), false );

		global $wpdb;

		$table          = Database::get_table_name();
		$last_sync_date = $last_sync ? gmdate( 'Y-m-d H:i:s', $last_sync ) : '1970-01-01 00:00:00';
		$trending_days  = Settings::get( 'trending_window' );
		$retention_days = Settings::get( 'retention' );

		// Ensure retention covers the trending window.
		if ( $retention_days < $trending_days ) {
			$retention_days = $trending_days;
		}

		// 1. Increment lifetime views by source for new views since last sync.
		$new_views = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT object_id, object_type, object_key, source, COUNT(*) as cnt
				 FROM $table
				 WHERE viewed_at > %s
				 GROUP BY object_id, object_type, object_key, source",
				$last_sync_date
			)
		);

		if ( $new_views ) {
			$pt_views     = get_option( 'mai_views_post_type_views', [] );
			$pt_views_web = get_option( 'mai_views_post_type_views_web', [] );
			$pt_views_app = get_option( 'mai_views_post_type_views_app', [] );

			foreach ( $new_views as $row ) {
				$cnt        = (int) $row->cnt;
				$source_key = 'app' === $row->source ? 'mai_views_app' : 'mai_views_web';

				if ( 'post_type' === $row->object_type ) {
					$pt_views[ $row->object_key ] = ( $pt_views[ $row->object_key ] ?? 0 ) + $cnt;

					if ( 'app' === $row->source ) {
						$pt_views_app[ $row->object_key ] = ( $pt_views_app[ $row->object_key ] ?? 0 ) + $cnt;
					} else {
						$pt_views_web[ $row->object_key ] = ( $pt_views_web[ $row->object_key ] ?? 0 ) + $cnt;
					}
				} else {
					// Increment source-specific meta.
					self::update_meta( (int) $row->object_id, $row->object_type, $source_key, 'increment', $cnt );

					// Recompute total.
					$web = (int) self::get_meta( (int) $row->object_id, $row->object_type, 'mai_views_web' );
					$app = (int) self::get_meta( (int) $row->object_id, $row->object_type, 'mai_views_app' );
					self::update_meta( (int) $row->object_id, $row->object_type, 'mai_views', 'replace', $web + $app );
				}
			}

			update_option( 'mai_views_post_type_views', $pt_views, false );
			update_option( 'mai_views_post_type_views_web', $pt_views_web, false );
			update_option( 'mai_views_post_type_views_app', $pt_views_app, false );
		}

		// 2. Recalculate trending: query the trending window (all sources merged).
		$trending = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT object_id, object_type, object_key, COUNT(*) as trending_count
				 FROM $table
				 WHERE viewed_at > DATE_SUB(UTC_TIMESTAMP(), INTERVAL %d DAY)
				 GROUP BY object_id, object_type, object_key",
				$trending_days
			)
		);

		$has_trending = [];
		$pt_trending  = [];

		if ( $trending ) {
			foreach ( $trending as $row ) {
				if ( 'post_type' === $row->object_type ) {
					$pt_trending[ $row->object_key ] = (int) $row->trending_count;
					$has_trending[ 'post_type:' . $row->object_key ] = true;
				} else {
					$has_trending[ $row->object_type . ':' . $row->object_id ] = true;
					self::update_meta( (int) $row->object_id, $row->object_type, 'mai_trending', 'replace', (int) $row->trending_count );
				}
			}
		}

		// Zero out post_type trending for archives that fell out of the window.
		$existing_pt_trending = get_option( 'mai_views_post_type_trending', [] );

		foreach ( $existing_pt_trending as $key => $count ) {
			if ( ! isset( $pt_trending[ $key ] ) ) {
				$pt_trending[ $key ] = 0;
			}
		}

		update_option( 'mai_views_post_type_trending', $pt_trending, false );

		// Zero out trending for non-archive objects that fell out of the trending window.
		$all_in_buffer = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT DISTINCT object_id, object_type
				 FROM $table
				 WHERE object_type != 'post_type'
				 AND viewed_at <= DATE_SUB(UTC_TIMESTAMP(), INTERVAL %d DAY)
				 AND viewed_at > DATE_SUB(UTC_TIMESTAMP(), INTERVAL %d DAY)",
				$trending_days,
				$retention_days
			)
		);

		if ( $all_in_buffer ) {
			foreach ( $all_in_buffer as $row ) {
				if ( ! isset( $has_trending[ $row->object_type . ':' . $row->object_id ] ) ) {
					self::update_meta( (int) $row->object_id, $row->object_type, 'mai_trending', 'replace', 0 );
				}
			}
		}

		// 3. Prune old rows beyond retention.
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM $table WHERE viewed_at < DATE_SUB(UTC_TIMESTAMP(), INTERVAL %d DAY)",
				$retention_days
			)
		);

		// 4. Record sync time and release lock.
		update_option( 'mai_views_synced', time(), false );
		delete_transient( 'mai_views_syncing' );
	}

	/**
	 * Gets a meta value for a post, term, or user.
	 *
	 * @param int    $object_id   The post, term, or user ID.
	 * @param string $object_type The object type: 'post', 'term', or 'user'.
	 * @param string $key         The meta key to retrieve.
	 *
	 * @return mixed The meta value, or empty string if not found.
	 */
	public static function get_meta( int $object_id, string $object_type, string $key ): mixed {
		return match ( $object_type ) {
			'post' => get_post_meta( $object_id, $key, true ),
			'term' => get_term_meta( $object_id, $key, true ),
			'user' => get_user_meta( $object_id, $key, true ),
			default => '',
		};
	}

	/**
	 * Updates a meta value for a post, term, or user.
	 *
	 * @param int    $object_id   The post, term, or user ID.
	 * @param string $object_type The object type: 'post', 'term', or 'user'.
	 * @param string $key         The meta key to update.
	 * @param string $mode        The update mode: 'increment' or 'replace'.
	 * @param int    $value       The value to increment by or replace with.
	 *
	 * @return void
	 */
	public static function update_meta( int $object_id, string $object_type, string $key, string $mode, int $value ): void {
		$functions = [
			'post' => [ 'get' => 'get_post_meta', 'update' => 'update_post_meta' ],
			'term' => [ 'get' => 'get_term_meta', 'update' => 'update_term_meta' ],
			'user' => [ 'get' => 'get_user_meta', 'update' => 'update_user_meta' ],
		];

		if ( ! isset( $functions[ $object_type ] ) ) {
			return;
		}

		$func = $functions[ $object_type ];

		if ( 'increment' === $mode ) {
			$current = (int) call_user_func( $func['get'], $object_id, $key, true );
			$value   = $current + $value;
		}

		call_user_func( $func['update'], $object_id, $key, $value );
	}
}
