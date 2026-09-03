<?php

namespace Mai\Analytics;

class Migration {

	/**
	 * Runs one-time migrations from Mai Publisher and/or legacy meta keys.
	 *
	 * Called early in Plugin::init() before other components load.
	 *
	 * @return void
	 */
	public static function maybe_migrate(): void {
		// Deferred to admin_init so Mai Publisher has loaded. Its Matomo values
		// commonly come from constants or filters that only maipub_get_option()
		// resolves, and that function does not exist yet during plugins_loaded.
		add_action( 'admin_init', [ __CLASS__, 'maybe_migrate_from_publisher' ], 5 );

		self::maybe_migrate_legacy_meta_keys();
	}

	/**
	 * Migrates views-related settings from Mai Publisher.
	 *
	 * Runs once, on the first admin request after Mai Analytics is installed
	 * alongside Mai Publisher. Fills in only the settings Mai Analytics does not
	 * already have, so values an admin entered here are never clobbered. Reads
	 * Mai Publisher's settings but does not modify them.
	 *
	 * Matomo credentials come from Publisher::get_matomo_settings(), which uses
	 * Mai Publisher's accessor so constant- and filter-supplied values resolve.
	 * The legacy views_* keys are read from the raw option, since those only ever
	 * lived in the database.
	 *
	 * @since 1.3.5 Merges instead of overwriting, and reads Matomo values through
	 *              Mai Publisher's accessor.
	 *
	 * @return void
	 */
	public static function maybe_migrate_from_publisher(): void {
		if ( get_option( 'mai_analytics_migrated_from_publisher' ) ) {
			return;
		}

		$publisher = get_option( 'mai_publisher', [] );

		if ( ! $publisher && ! Publisher::is_active() ) {
			return;
		}

		$settings = get_option( 'mai_analytics_settings', [] );

		// Fill only what's missing here. An admin who already entered credentials
		// on the Mai Analytics settings page keeps them.
		foreach ( Publisher::get_matomo_settings() as $key => $value ) {
			if ( '' !== $value && empty( $settings[ $key ] ) ) {
				$settings[ $key ] = $value;
			}
		}

		if ( ! isset( $settings['data_source'] ) ) {
			$views_api = $publisher['views_api'] ?? '';

			// Map Mai Publisher's legacy views_api to Mai Analytics' data_source,
			// falling back to Matomo when Mai Publisher has Matomo configured.
			$settings['data_source'] = in_array( $views_api, [ 'matomo', 'jetpack', 'disabled' ], true )
				? $views_api
				: ( Publisher::has_matomo_settings() ? 'matomo' : 'self_hosted' );
		}

		if ( ! isset( $settings['sync_user'] ) ) {
			$settings['sync_user'] = get_current_user_id() ?: self::get_first_admin_id();
		}

		update_option( 'mai_analytics_settings', $settings, false );

		// Store Mai Publisher's trending_days, views_interval, and views_years as
		// filter defaults via a persistent option, since they were DB-backed in
		// Mai Publisher but are filter-only in Mai Analytics.
		$filter_defaults = [];

		if ( ! empty( $publisher['trending_days'] ) ) {
			$filter_defaults['trending_window'] = (int) $publisher['trending_days'];
		}

		if ( ! empty( $publisher['views_interval'] ) ) {
			$filter_defaults['sync_interval'] = (int) $publisher['views_interval'];
		}

		if ( ! empty( $publisher['views_years'] ) ) {
			$filter_defaults['views_years'] = (int) $publisher['views_years'];
		}

		if ( $filter_defaults ) {
			update_option( 'mai_analytics_migrated_defaults', $filter_defaults, false );
		}

		update_option( 'mai_analytics_migrated_from_publisher', true, false );
	}

	/**
	 * Renames legacy meta keys from early Mai Analytics dev installs.
	 *
	 * The original Mai Analytics used mai_analytics_views, mai_analytics_views_web, etc.
	 * as meta keys. Current Mai Analytics uses mai_views, mai_views_web, etc.
	 *
	 * For mai_analytics_views → mai_views and mai_analytics_trending → mai_trending,
	 * keeps the higher value if both old and new keys exist.
	 *
	 * @return void
	 */
	private static function maybe_migrate_legacy_meta_keys(): void {
		if ( get_option( 'mai_analytics_migrated_from_analytics' ) ) {
			return;
		}

		global $wpdb;

		// Check if any old meta keys exist before doing work.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$has_old = (bool) $wpdb->get_var(
			"SELECT 1 FROM {$wpdb->postmeta} WHERE meta_key LIKE 'mai_analytics_views%' OR meta_key = 'mai_analytics_trending' LIMIT 1"
		);

		if ( ! $has_old ) {
			update_option( 'mai_analytics_migrated_from_analytics', true, false );
			return;
		}

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

		update_option( 'mai_analytics_migrated_from_analytics', true, false );
	}

	/**
	 * Gets the first administrator user ID for sync_user fallback.
	 *
	 * @return int The admin user ID, or 0 if none found.
	 */
	public static function get_first_admin_id(): int {
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
