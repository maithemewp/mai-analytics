<?php

namespace Mai\Analytics\Providers;

use Mai\Analytics\Settings;
use Mai\Analytics\WebViewProvider;

class Matomo implements WebViewProvider {

	/**
	 * Gets the provider slug identifier.
	 *
	 * @return string The provider slug.
	 */
	public function get_slug(): string {
		return 'matomo';
	}

	/**
	 * Gets the human-readable provider label.
	 *
	 * @return string The provider display name.
	 */
	public function get_label(): string {
		return 'Matomo';
	}

	/**
	 * Gets the maximum number of paths to include in a single API call.
	 *
	 * @return int The batch size limit.
	 */
	public function get_batch_size(): int {
		return 100;
	}

	/**
	 * Gets the settings fields specific to this provider.
	 *
	 * Each field is an associative array with keys: 'key', 'label', 'type', 'description'.
	 *
	 * @return array Array of field definitions.
	 */
	public function get_settings_fields(): array {
		return [
			[
				'key'         => 'matomo_url',
				'type'        => 'url',
				'label'       => 'Matomo URL',
				'description' => 'The URL of your Matomo instance',
			],
			[
				'key'         => 'matomo_site_id',
				'type'        => 'number',
				'label'       => 'Site ID',
				'description' => 'Matomo site/app ID',
			],
			[
				'key'         => 'matomo_token',
				'type'        => 'password',
				'label'       => 'Auth Token',
				'description' => 'Matomo API authentication token',
			],
		];
	}

	/**
	 * Checks whether this provider is available and properly configured.
	 *
	 * All three Matomo settings (URL, site ID, and auth token) must be non-empty.
	 *
	 * @return bool True if the provider can be used.
	 */
	public function is_available(): bool {
		return ! empty( Settings::get( 'matomo_url' ) )
			&& ! empty( Settings::get( 'matomo_site_id' ) )
			&& ! empty( Settings::get( 'matomo_token' ) );
	}

	/**
	 * Fetches pageview counts for the given URL paths across one or more named windows.
	 *
	 * All paths × windows ride a single Matomo `API.getBulkRequest` HTTP roundtrip.
	 * For 100 paths and two windows that's one request with 200 sub-queries instead
	 * of two requests with 100 each.
	 *
	 * Why not period=range:
	 *   Many Matomo installs — including those without on-the-fly archiving
	 *   enabled, the default for self-hosted setups — return `[]` for
	 *   `period=range` queries that span more than the pre-archived window. This
	 *   was learned the hard way in the pre-bundle Mai Publisher class-views.php,
	 *   whose source carried this comment:
	 *
	 *       "Testing with Matomo showed month/year were not getting values,
	 *        while weeks were. Idk if it's a Matomo thing or not, but this works."
	 *
	 * What works reliably (per-window):
	 *   - Non-empty start_date (trending) → period=day, date=lastN where N is
	 *     the number of days in the requested range.
	 *   - Empty start_date  (all-time)    → period=week, date=last{years*52}
	 *     where `years` comes from the mai_analytics_views_years filter
	 *     (default 5). Five years of weekly archives is a practical proxy for
	 *     "all-time" on publishing sites and uses Matomo's pre-built weekly
	 *     archives, which respond promptly even on cron-only setups.
	 *
	 * Sub-query ordering inside the bulk request follows the caller's `$windows`
	 * order. The pre-bundle code put trending first in the bulk request with
	 * the comment "Add trending first incase views times out" — callers should
	 * preserve that intent by passing trending-style windows before all-time.
	 *
	 * SiteKit and Jetpack interpret an empty start_date natively and are not
	 * affected by this translation, which is local to the Matomo provider.
	 *
	 * @param array<string>                            $paths   URL paths.
	 * @param array<string, array{0:string,1:string}>  $windows Map of window name to [start, end].
	 *
	 * @return array<string, array<string, int>> Map of path => (window name => view count).
	 */
	public function get_views( array $paths, array $windows ): array {
		$matomo_url = Settings::get( 'matomo_url' );
		$site_id    = Settings::get( 'matomo_site_id' );
		$token      = Settings::get( 'matomo_token' );

		if ( ! ( $matomo_url && $site_id && $token ) ) {
			self::set_last_error( __( 'Matomo provider missing required settings.', 'mai-analytics' ) );
			return [];
		}

		if ( ! $paths || ! $windows ) {
			return [];
		}

		$api_url      = trailingslashit( $matomo_url ) . 'index.php';
		$path_list    = array_values( $paths );
		$window_names = array_keys( $windows );
		$window_count = count( $window_names );

		// Pre-translate windows once. The translation depends only on the date
		// range, not the path, so doing it here avoids running it
		// (paths × windows) times in the inner loop.
		$years      = (int) Settings::get( 'views_years' );
		$translated = [];

		foreach ( $windows as $name => $range ) {
			[ $start_date, $end_date ] = $range;

			if ( '' === $start_date ) {
				$translated[ $name ] = [ 'period' => 'week', 'date' => 'last' . max( 1, $years * 52 ) ];
			} else {
				$days = (int) round( ( strtotime( $end_date ) - strtotime( $start_date ) ) / DAY_IN_SECONDS );
				$translated[ $name ] = [ 'period' => 'day', 'date' => 'last' . max( 1, $days ) ];
			}
		}

		// Bulk request body. The urls[] ordering is paths × windows: for each
		// path we append one sub-query per window in caller-provided order.
		// Index mapping on the response side: index = path_index * window_count + window_index.
		$body = [
			'module'     => 'API',
			'method'     => 'API.getBulkRequest',
			'format'     => 'json',
			'idSite'     => $site_id,
			'token_auth' => $token,
			'urls'       => [],
		];

		foreach ( $path_list as $path ) {
			// Matomo records full URLs against pageUrl, so expand the path-only
			// argument here. SiteKit (GA4 pagePath) and Jetpack handle paths
			// directly, so the upstream contract stays path-based.
			//
			// rawurldecode() normalizes percent-encoded characters so URLs that
			// arrive pre-encoded match Matomo's stored form. Specifically calls
			// out a real-world bug seen on OnTapSports where Unicode dashes
			// (–, —) in slugs broke URL matching in Matomo lookups.
			$page_url = rawurldecode( home_url( $path ) );

			foreach ( $translated as $t ) {
				$body['urls'][] = http_build_query(
					[
						'method'      => 'Actions.getPageUrl',
						'pageUrl'     => $page_url,
						'period'      => $t['period'],
						'date'        => $t['date'],
						'hideColumns' => 'label',
						'showColumns' => 'nb_visits',
					]
				);
			}
		}

		$response = wp_remote_post( $api_url, [
			'headers' => [
				'Content-Type' => 'application/x-www-form-urlencoded',
				'User-Agent'   => 'MaiAnalytics/1.0',
			],
			'body'    => $body,
			'timeout' => 30,
		] );

		if ( is_wp_error( $response ) ) {
			self::set_last_error( 'Matomo API request failed: ' . $response->get_error_message() );
			return [];
		}

		$code = wp_remote_retrieve_response_code( $response );

		if ( 200 !== $code ) {
			self::set_last_error( 'Matomo API returned HTTP ' . $code . ': ' . wp_remote_retrieve_response_message( $response ) );
			return [];
		}

		$body_raw = wp_remote_retrieve_body( $response );
		$data     = json_decode( $body_raw, true );

		if ( ! $data || ! is_array( $data ) ) {
			self::set_last_error( __( 'Matomo API returned empty or invalid JSON response.', 'mai-analytics' ) );
			return [];
		}

		if ( isset( $data['result'] ) && 'error' === $data['result'] ) {
			$message = $data['message'] ?? __( 'Unknown Matomo API error.', 'mai-analytics' );
			self::set_last_error( 'Matomo API error: ' . $message );
			return [];
		}

		// Map response indexes back to (path, window). Each $data[$i] is the
		// response for the i-th urls[] sub-query, in the same order we built it.
		// We sum nb_visits across the period buckets returned (weekly buckets
		// for an all-time query, daily buckets for trending).
		$results = [];

		foreach ( $data as $index => $row ) {
			$path_index   = intdiv( (int) $index, $window_count );
			$window_index = (int) $index % $window_count;

			if ( ! isset( $path_list[ $path_index ], $window_names[ $window_index ] ) ) {
				continue;
			}

			$path        = $path_list[ $path_index ];
			$window_name = $window_names[ $window_index ];

			$visits = 0;

			if ( is_array( $row ) ) {
				foreach ( $row as $values ) {
					// Each entry may be an array of objects or a single object.
					if ( isset( $values['nb_visits'] ) ) {
						$visits += absint( $values['nb_visits'] );
					} elseif ( is_array( $values ) && isset( $values[0]['nb_visits'] ) ) {
						$visits += absint( $values[0]['nb_visits'] );
					}
				}
			}

			if ( $visits > 0 ) {
				$results[ $path ][ $window_name ] = $visits;
			}
		}

		// Clear any stale error on a successful round-trip so the admin
		// "Sync Now" health check (which reads this transient) reflects the
		// current request, not a previous failure.
		delete_transient( 'mai_analytics_provider_error' );

		return $results;
	}

	/**
	 * Stores the last provider error for display in the admin UI and for the
	 * AdminRestApi `sync_now` health check, which decides success/failure by
	 * reading this transient. Mirrors `SiteKit::set_last_error()` so all three
	 * providers surface failures the same way.
	 *
	 * @param string $message The error message.
	 *
	 * @return void
	 */
	private static function set_last_error( string $message ): void {
		error_log( '[Mai Analytics] ' . $message );
		set_transient( 'mai_analytics_provider_error', $message, HOUR_IN_SECONDS );
	}
}
