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
}
