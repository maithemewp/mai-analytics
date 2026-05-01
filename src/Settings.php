<?php

namespace Mai\Analytics;

class Settings {

	/**
	 * Gets a plugin setting value. All settings are filterable with sensible defaults.
	 *
	 * Settings are sourced from two places:
	 * - Filter-only: retention, sync_interval, exclude_bots, views_years
	 * - DB-backed: data_source, sync_user, trending_window, matomo_url, matomo_site_id, matomo_token
	 *
	 * All settings can be overridden via `mai_analytics_{$key}` filter.
	 *
	 * @param string $key The setting key.
	 *
	 * @return mixed The setting value, or null if the key is not recognized.
	 */
	public static function get( string $key ): mixed {
		// Filter-only settings with defaults.
		$filter_defaults = [
			'retention'     => apply_filters( 'mai_analytics_retention', 14 ),
			'sync_interval' => apply_filters( 'mai_analytics_sync_interval', 5 ),
			'exclude_bots'  => apply_filters( 'mai_analytics_exclude_bots', true ),
			'views_years'   => apply_filters( 'mai_analytics_views_years', 5 ),
		];

		if ( isset( $filter_defaults[ $key ] ) ) {
			return $filter_defaults[ $key ];
		}

		// DB-backed settings with defaults and filter overrides.
		$db_defaults = [
			'data_source'       => 'self_hosted',
			'sync_user'         => 0,
			'trending_window'   => 7,
			'matomo_url'        => '',
			'matomo_site_id'    => '',
			'matomo_token'      => '',
			'matomo_bulk_chunk' => 10,
		];

		if ( isset( $db_defaults[ $key ] ) ) {
			$saved = get_option( 'mai_analytics_settings', [] );
			$value = $saved[ $key ] ?? $db_defaults[ $key ];

			// Allow filter overrides for DB settings too.
			return apply_filters( "mai_analytics_{$key}", $value );
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
			'data_source'       => self::get( 'data_source' ),
			'sync_user'         => self::get( 'sync_user' ),
			'matomo_url'        => self::get( 'matomo_url' ),
			'matomo_site_id'    => self::get( 'matomo_site_id' ),
			'matomo_token'      => self::get( 'matomo_token' ),
			'matomo_bulk_chunk' => self::get( 'matomo_bulk_chunk' ),
			'trending_window'   => self::get( 'trending_window' ),
			'retention'         => self::get( 'retention' ),
			'sync_interval'     => self::get( 'sync_interval' ),
			'exclude_bots'      => self::get( 'exclude_bots' ),
			'views_years'       => self::get( 'views_years' ),
		];
	}

	/**
	 * Returns a normalized snapshot of analytics settings for external reporting
	 * consumers (e.g. mai-publisher's /v1/seller endpoint). Read-only; does not mutate state.
	 *
	 * @return array {
	 *     @type bool   $matomo_enabled True when data_source === 'matomo'.
	 *     @type string $matomo_url     Matomo instance URL.
	 *     @type int    $matomo_site_id Matomo site ID.
	 *     @type string $data_source    The active provider key (disabled|self_hosted|matomo|site_kit|jetpack).
	 *     @type int    $views_years    Years of view history retained.
	 *     @type int    $sync_interval  Sync cadence in minutes.
	 *     @type int    $trending_days  Trending window in days (mapped from trending_window).
	 * }
	 */
	public static function get_reporting_snapshot(): array {
		return [
			'matomo_enabled' => 'matomo' === self::get( 'data_source' ),
			'matomo_url'     => (string) self::get( 'matomo_url' ),
			'matomo_site_id' => (int)    self::get( 'matomo_site_id' ),
			'data_source'    => (string) self::get( 'data_source' ),
			'views_years'    => (int)    self::get( 'views_years' ),
			'sync_interval'  => (int)    self::get( 'sync_interval' ),
			'trending_days'  => (int)    self::get( 'trending_window' ),
		];
	}

	/**
	 * Detects mismatches between Mai Publisher's client-side Matomo config and Mai
	 * Analytics' server-side Matomo config. Used to render a warning notice on both
	 * settings pages when the two configs drift apart.
	 *
	 * Returns an empty array when Mai Publisher is inactive, when either side is not
	 * using Matomo, or when both sides match. Otherwise returns the list of mismatched
	 * field keys: any of 'matomo_url', 'matomo_site_id', 'matomo_token'.
	 *
	 * Token mismatches are ignored when either side has an empty token (Mai Publisher
	 * commonly leaves it empty since client-side tracking does not need it).
	 *
	 * @return array List of mismatched field keys.
	 */
	public static function detect_publisher_matomo_mismatch(): array {
		if ( ! function_exists( 'maipub_get_option' ) ) {
			return [];
		}

		if ( 'matomo' !== self::get( 'data_source' ) ) {
			return [];
		}

		if ( ! maipub_get_option( 'matomo_enabled', false ) ) {
			return [];
		}

		$analytics = [
			'matomo_url'     => trailingslashit( trim( (string) self::get( 'matomo_url' ) ) ),
			'matomo_site_id' => (string) self::get( 'matomo_site_id' ),
			'matomo_token'   => trim( (string) self::get( 'matomo_token' ) ),
		];

		$publisher = [
			'matomo_url'     => trailingslashit( trim( (string) maipub_get_option( 'matomo_url', '' ) ) ),
			'matomo_site_id' => (string) maipub_get_option( 'matomo_site_id', '' ),
			'matomo_token'   => trim( (string) maipub_get_option( 'matomo_token', '' ) ),
		];

		$mismatched = [];

		foreach ( $analytics as $key => $value ) {
			if ( 'matomo_token' === $key && ( '' === $value || '' === $publisher[ $key ] ) ) {
				continue;
			}

			if ( '' === $value && '' === $publisher[ $key ] ) {
				continue;
			}

			if ( $value !== $publisher[ $key ] ) {
				$mismatched[] = $key;
			}
		}

		return $mismatched;
	}

	/**
	 * Updates DB-backed settings.
	 *
	 * @param array $values Key-value pairs to save.
	 *
	 * @return void
	 */
	public static function update( array $values ): void {
		$db_keys = [ 'data_source', 'sync_user', 'trending_window', 'matomo_url', 'matomo_site_id', 'matomo_token', 'matomo_bulk_chunk' ];
		$saved   = get_option( 'mai_analytics_settings', [] );

		foreach ( $values as $key => $value ) {
			if ( in_array( $key, $db_keys, true ) ) {
				$saved[ $key ] = $value;
			}
		}

		update_option( 'mai_analytics_settings', $saved, false );
	}
}
