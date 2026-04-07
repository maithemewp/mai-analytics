<?php

namespace Mai\Analytics;

class Database {

	public const TABLE_NAME        = 'mai_analytics_buffer';
	public const DB_VERSION_OPTION = 'mai_analytics_db_version';

	/**
	 * Gets the full table name with prefix.
	 *
	 * @return string The prefixed database table name.
	 */
	public static function get_table_name(): string {
		global $wpdb;
		return $wpdb->prefix . self::TABLE_NAME;
	}

	/**
	 * Creates the buffer table using dbDelta for safe schema migrations.
	 *
	 * @return void
	 */
	public static function create_table(): void {
		global $wpdb;

		$table   = self::get_table_name();
		$charset = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE $table (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			object_id bigint(20) unsigned NOT NULL DEFAULT 0,
			object_type varchar(20) NOT NULL,
			object_key varchar(50) NOT NULL DEFAULT '',
			viewed_at datetime NOT NULL,
			source varchar(10) NOT NULL DEFAULT 'web',
			PRIMARY KEY  (id),
			KEY object_viewed (object_id, object_type, viewed_at),
			KEY object_key_type (object_key, object_type, viewed_at)
		) $charset;";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );

		update_option( self::DB_VERSION_OPTION, MAI_ANALYTICS_DB_VERSION );
	}

	/**
	 * Checks if the database needs updating and runs create_table if so.
	 *
	 * @return void
	 */
	public static function maybe_update(): void {
		self::maybe_migrate_old_table();

		$installed = get_option( self::DB_VERSION_OPTION, '0' );

		if ( version_compare( $installed, MAI_ANALYTICS_DB_VERSION, '<' ) ) {
			self::create_table();
		}
	}

	/**
	 * Renames legacy table names to the current mai_analytics_buffer name.
	 *
	 * Handles two legacy names:
	 * - mai_analytics_views (from original Mai Analytics dev installs)
	 * - mai_views_buffer (from the Mai Views era)
	 *
	 * @return void
	 */
	private static function maybe_migrate_old_table(): void {
		if ( get_option( 'mai_analytics_table_migrated' ) ) {
			return;
		}

		global $wpdb;

		$new_table  = self::get_table_name();
		$old_tables = [
			$wpdb->prefix . 'mai_views_buffer',
			$wpdb->prefix . 'mai_analytics_views',
		];

		foreach ( $old_tables as $old_table ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$old_exists = (bool) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $old_table ) );

			if ( ! $old_exists ) {
				continue;
			}

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$new_exists = (bool) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $new_table ) );

			if ( $new_exists ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$wpdb->query( "INSERT INTO `$new_table` SELECT * FROM `$old_table`" );
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$wpdb->query( "DROP TABLE `$old_table`" );
			} else {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$wpdb->query(
					$wpdb->prepare(
						'RENAME TABLE %i TO %i',
						$old_table,
						$new_table
					)
				);
			}
		}

		// Migrate DB version from the Mai Views era.
		$old_version = get_option( 'mai_views_db_version', '' );

		if ( $old_version ) {
			update_option( self::DB_VERSION_OPTION, $old_version );
			delete_option( 'mai_views_db_version' );
		}

		update_option( 'mai_analytics_table_migrated', true );
	}

	/**
	 * Inserts a single view row into the buffer table.
	 *
	 * @param int    $object_id   The post, term, or user ID. Use 0 for post_type archives.
	 * @param string $object_type The object type: 'post', 'term', 'user', or 'post_type'.
	 * @param string $source      The traffic source: 'web' or 'app'.
	 * @param string $object_key  The object key for post_type archives (e.g. 'post', 'portfolio').
	 *
	 * @return int|false The number of rows inserted, or false on error.
	 */
	public static function insert_view( int $object_id, string $object_type, string $source = 'web', string $object_key = '' ): int|false {
		global $wpdb;

		return $wpdb->insert(
			self::get_table_name(),
			[
				'object_id'   => $object_id,
				'object_type' => $object_type,
				'object_key'  => $object_key,
				'viewed_at'   => current_time( 'mysql', true ),
				'source'      => $source,
			],
			[ '%d', '%s', '%s', '%s', '%s' ]
		);
	}

	/**
	 * Checks if an object is already in the buffer since the last provider sync.
	 *
	 * Uses the existing (object_id, object_type, viewed_at) index for fast lookups.
	 * Sites with persistent object cache get a cache-first check for even faster dedup.
	 *
	 * @param int    $object_id   The object ID.
	 * @param string $object_type The object type.
	 * @param int    $last_sync   Unix timestamp of the last provider sync.
	 *
	 * @return bool True if the object already has a buffer row since last sync.
	 */
	public static function is_queued( int $object_id, string $object_type, int $last_sync ): bool {
		$cache_key = "mai_analytics_queued_{$object_type}_{$object_id}";

		if ( wp_using_ext_object_cache() && wp_cache_get( $cache_key, 'mai-analytics' ) ) {
			return true;
		}

		global $wpdb;

		$table     = self::get_table_name();
		$since     = $last_sync ? gmdate( 'Y-m-d H:i:s', $last_sync ) : '1970-01-01 00:00:00';
		$exists    = (bool) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT 1 FROM $table WHERE object_id = %d AND object_type = %s AND viewed_at > %s LIMIT 1",
				$object_id,
				$object_type,
				$since
			)
		);

		if ( $exists && wp_using_ext_object_cache() ) {
			wp_cache_set( $cache_key, 1, 'mai-analytics', 15 * MINUTE_IN_SECONDS );
		}

		return $exists;
	}

	/**
	 * Gets distinct objects from the buffer since a given timestamp.
	 *
	 * Used by ProviderSync to determine which objects need provider data fetched.
	 *
	 * @param string $since MySQL datetime string. Objects with buffer rows after this time are returned.
	 *
	 * @return array Array of objects: [ ['object_id' => int, 'object_type' => string, 'object_key' => string], ... ]
	 */
	public static function get_distinct_objects_since( string $since ): array {
		global $wpdb;

		$table = self::get_table_name();

		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT DISTINCT object_id, object_type, object_key
				 FROM $table
				 WHERE viewed_at > %s
				 ORDER BY object_id ASC",
				$since
			)
		);
	}
}
