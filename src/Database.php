<?php

namespace Mai\Analytics;

class Database {

	public const TABLE_NAME        = 'mai_analytics_views';
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
		$installed = get_option( self::DB_VERSION_OPTION, '0' );

		if ( version_compare( $installed, MAI_ANALYTICS_DB_VERSION, '<' ) ) {
			self::create_table();
		}
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
}
