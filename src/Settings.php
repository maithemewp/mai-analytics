<?php

namespace Mai\Views;

class Settings {

	/**
	 * Gets a plugin setting value. All settings are filterable with sensible defaults.
	 *
	 * Settings are sourced from two places:
	 * - Filter-only: retention, sync_interval, exclude_bots
	 * - DB-backed: data_source, sync_user, trending_window, matomo_url, matomo_site_id, matomo_token
	 *
	 * All settings can be overridden via `mai_views_{$key}` filter.
	 *
	 * @param string $key The setting key.
	 *
	 * @return mixed The setting value, or null if the key is not recognized.
	 */
	public static function get( string $key ): mixed {
		// Filter-only settings with defaults.
		$filter_defaults = [
			'retention'   => apply_filters( 'mai_views_retention', 14 ),
			'sync_interval' => apply_filters( 'mai_views_sync_interval', 5 ),
			'exclude_bots'  => apply_filters( 'mai_views_exclude_bots', true ),
		];

		if ( isset( $filter_defaults[ $key ] ) ) {
			return $filter_defaults[ $key ];
		}

		// DB-backed settings with defaults and filter overrides.
		$db_defaults = [
			'data_source'      => 'self_hosted',
			'sync_user'        => 0,
			'trending_window'  => 7,
			'matomo_url'       => '',
			'matomo_site_id'   => '',
			'matomo_token'     => '',
		];

		if ( isset( $db_defaults[ $key ] ) ) {
			$saved = get_option( 'mai_views_settings', [] );
			$value = $saved[ $key ] ?? $db_defaults[ $key ];

			// Allow filter overrides for DB settings too.
			return apply_filters( "mai_views_{$key}", $value );
		}

		return null;
	}

	/**
	 * Gets all settings as a merged array.
	 *
	 * @return array All settings with their current values.
	 */
	public static function get_all(): array {
		return [
			'data_source'     => self::get( 'data_source' ),
			'sync_user'       => self::get( 'sync_user' ),
			'matomo_url'      => self::get( 'matomo_url' ),
			'matomo_site_id'  => self::get( 'matomo_site_id' ),
			'matomo_token'    => self::get( 'matomo_token' ),
			'trending_window' => self::get( 'trending_window' ),
			'retention'       => self::get( 'retention' ),
			'sync_interval'   => self::get( 'sync_interval' ),
			'exclude_bots'    => self::get( 'exclude_bots' ),
		];
	}

	/**
	 * Updates DB-backed settings.
	 *
	 * @param array $values Key-value pairs to save.
	 *
	 * @return void
	 */
	public static function update( array $values ): void {
		$db_keys = [ 'data_source', 'sync_user', 'trending_window', 'matomo_url', 'matomo_site_id', 'matomo_token' ];
		$saved   = get_option( 'mai_views_settings', [] );

		foreach ( $values as $key => $value ) {
			if ( in_array( $key, $db_keys, true ) ) {
				$saved[ $key ] = $value;
			}
		}

		update_option( 'mai_views_settings', $saved, false );
	}
}
