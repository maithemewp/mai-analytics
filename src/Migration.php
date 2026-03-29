<?php

namespace Mai\Views;

class Migration {

	/**
	 * Runs one-time migrations from Mai Publisher and/or old Mai Analytics installs.
	 *
	 * Called early in Plugin::init() before other components load.
	 *
	 * @return void
	 */
	public static function maybe_migrate(): void {
		self::maybe_migrate_from_publisher();
		self::maybe_migrate_from_analytics();
	}

	/**
	 * Migrates views-related settings from Mai Publisher's option.
	 *
	 * Only runs if mai_views_settings doesn't exist yet and mai_publisher option is present.
	 * Reads the Mai Publisher option but does not modify it.
	 *
	 * @return void
	 */
	private static function maybe_migrate_from_publisher(): void {
		// Already migrated or manually configured.
		if ( get_option( 'mai_views_settings' ) ) {
			return;
		}

		$publisher = get_option( 'mai_publisher', [] );

		if ( ! $publisher ) {
			return;
		}

		$views_api = $publisher['views_api'] ?? 'disabled';

		// Map Mai Publisher's views_api to Mai Views' data_source.
		$source_map = [
			'matomo'   => 'matomo',
			'jetpack'  => 'jetpack',
			'disabled' => 'disabled',
		];

		$data_source = $source_map[ $views_api ] ?? 'self_hosted';

		$settings = [
			'data_source'    => $data_source,
			'sync_user'      => get_current_user_id() ?: self::get_first_admin_id(),
			'matomo_url'     => $publisher['matomo_url'] ?? '',
			'matomo_site_id' => $publisher['matomo_site_id'] ?? '',
			'matomo_token'   => $publisher['matomo_token'] ?? '',
		];

		update_option( 'mai_views_settings', $settings, false );

		// Store Mai Publisher's trending_days and views_interval as filter defaults
		// via a persistent option, since they were DB-backed in Mai Publisher
		// but are filter-only in Mai Views.
		$filter_defaults = [];

		if ( ! empty( $publisher['trending_days'] ) ) {
			$filter_defaults['trending_window'] = (int) $publisher['trending_days'];
		}

		if ( ! empty( $publisher['views_interval'] ) ) {
			$filter_defaults['sync_interval'] = (int) $publisher['views_interval'];
		}

		if ( $filter_defaults ) {
			update_option( 'mai_views_migrated_defaults', $filter_defaults, false );
		}

		update_option( 'mai_views_migrated_from_publisher', true, false );
	}

	/**
	 * Migrates options and meta from old Mai Analytics installs.
	 *
	 * Only relevant for test/dev installs that ran Mai Analytics before the rename.
	 *
	 * @return void
	 */
	private static function maybe_migrate_from_analytics(): void {
		if ( get_option( 'mai_views_migrated_from_analytics' ) ) {
			return;
		}

		// Check if any old Mai Analytics options exist.
		$old_settings = get_option( 'mai_analytics_settings', [] );

		if ( ! $old_settings ) {
			return;
		}

		// Migrate settings if not already set.
		if ( ! get_option( 'mai_views_settings' ) ) {
			update_option( 'mai_views_settings', $old_settings, false );
		}

		// Migrate options.
		$option_map = [
			'mai_analytics_synced'                => 'mai_views_synced',
			'mai_analytics_provider_last_sync'    => 'mai_views_provider_last_sync',
			'mai_analytics_post_type_views'       => 'mai_views_post_type_views',
			'mai_analytics_post_type_views_web'   => 'mai_views_post_type_views_web',
			'mai_analytics_post_type_views_app'   => 'mai_views_post_type_views_app',
			'mai_analytics_post_type_trending'    => 'mai_views_post_type_trending',
		];

		foreach ( $option_map as $old_key => $new_key ) {
			$old_value = get_option( $old_key );

			if ( false !== $old_value && ! get_option( $new_key ) ) {
				update_option( $new_key, $old_value, false );
				delete_option( $old_key );
			}
		}

		// Clean up old settings option.
		delete_option( 'mai_analytics_settings' );

		// Migrate meta keys in bulk via direct SQL for efficiency.
		self::migrate_meta_keys();

		update_option( 'mai_views_migrated_from_analytics', true, false );
	}

	/**
	 * Renames old Mai Analytics meta keys to the new Mai Views keys.
	 *
	 * For mai_analytics_views → mai_views and mai_analytics_trending → mai_trending,
	 * keeps the higher value if both old and new keys exist.
	 *
	 * @return void
	 */
	private static function migrate_meta_keys(): void {
		global $wpdb;

		// Direct renames (no conflict possible).
		$direct_renames = [
			'mai_analytics_views_web' => 'mai_views_web',
			'mai_analytics_views_app' => 'mai_views_app',
		];

		foreach ( $direct_renames as $old_key => $new_key ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->query(
				$wpdb->prepare(
					"UPDATE {$wpdb->postmeta} SET meta_key = %s WHERE meta_key = %s",
					$new_key,
					$old_key
				)
			);
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->query(
				$wpdb->prepare(
					"UPDATE {$wpdb->termmeta} SET meta_key = %s WHERE meta_key = %s",
					$new_key,
					$old_key
				)
			);
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->query(
				$wpdb->prepare(
					"UPDATE {$wpdb->usermeta} SET meta_key = %s WHERE meta_key = %s",
					$new_key,
					$old_key
				)
			);
		}

		// Merge renames (keep higher value if both exist).
		$merge_renames = [
			'mai_analytics_views'    => 'mai_views',
			'mai_analytics_trending' => 'mai_trending',
		];

		foreach ( $merge_renames as $old_key => $new_key ) {
			foreach ( [ $wpdb->postmeta, $wpdb->termmeta, $wpdb->usermeta ] as $table ) {
				$id_col = $table === $wpdb->usermeta ? 'user_id' : ( $table === $wpdb->termmeta ? 'term_id' : 'post_id' );

				// Where new key doesn't exist, just rename.
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$wpdb->query(
					$wpdb->prepare(
						"UPDATE $table old_meta
						 SET old_meta.meta_key = %s
						 WHERE old_meta.meta_key = %s
						   AND NOT EXISTS (
						       SELECT 1 FROM (SELECT * FROM $table) new_meta
						       WHERE new_meta.{$id_col} = old_meta.{$id_col}
						         AND new_meta.meta_key = %s
						   )",
						$new_key,
						$old_key,
						$new_key
					)
				);

				// Where both exist, keep the higher value and delete the old.
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$wpdb->query(
					$wpdb->prepare(
						"UPDATE $table new_meta
						 INNER JOIN (SELECT * FROM $table) old_meta
						   ON new_meta.{$id_col} = old_meta.{$id_col}
						 SET new_meta.meta_value = GREATEST(
						     CAST(new_meta.meta_value AS UNSIGNED),
						     CAST(old_meta.meta_value AS UNSIGNED)
						 )
						 WHERE new_meta.meta_key = %s
						   AND old_meta.meta_key = %s",
						$new_key,
						$old_key
					)
				);

				// Delete remaining old keys.
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$wpdb->query(
					$wpdb->prepare(
						"DELETE FROM $table WHERE meta_key = %s",
						$old_key
					)
				);
			}
		}
	}

	/**
	 * Gets the first administrator user ID for sync_user fallback.
	 *
	 * @return int The admin user ID, or 0 if none found.
	 */
	private static function get_first_admin_id(): int {
		$admins = get_users( [
			'role'    => 'administrator',
			'number'  => 1,
			'orderby' => 'ID',
			'order'   => 'ASC',
			'fields'  => 'ID',
		] );

		return $admins ? (int) $admins[0] : 0;
	}
}
