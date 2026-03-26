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
	 * Fetches pageview counts for the given URL paths within a date range.
	 *
	 * Uses the Matomo Bulk API (API.getBulkRequest) with Actions.getPageUrl
	 * to retrieve nb_visits for each path in a single HTTP request.
	 *
	 * @param array  $paths      Array of URL paths (e.g., ['/some-post/', '/category/news/']).
	 * @param string $start_date Start date in 'Y-m-d' format.
	 * @param string $end_date   End date in 'Y-m-d' format.
	 *
	 * @return array Associative array of path => view count. Missing paths are omitted.
	 */
	public function get_views( array $paths, string $start_date, string $end_date ): array {
		$matomo_url = Settings::get( 'matomo_url' );
		$site_id    = Settings::get( 'matomo_site_id' );
		$token      = Settings::get( 'matomo_token' );

		// Bail if settings are incomplete.
		if ( ! ( $matomo_url && $site_id && $token ) ) {
			error_log( '[Mai Analytics] Matomo provider missing required settings.' );
			return [];
		}

		// Build the API endpoint URL.
		$api_url = trailingslashit( $matomo_url ) . 'index.php';

		// Build bulk request body with a urls[] entry per path.
		$body = [
			'module'     => 'API',
			'method'     => 'API.getBulkRequest',
			'format'     => 'json',
			'idSite'     => $site_id,
			'token_auth' => $token,
			'urls'       => [],
		];

		foreach ( $paths as $path ) {
			$body['urls'][] = http_build_query(
				[
					'method'      => 'Actions.getPageUrl',
					'pageUrl'     => $path,
					'period'      => 'range',
					'date'        => $start_date . ',' . $end_date,
					'hideColumns' => 'label',
					'showColumns' => 'nb_visits',
				]
			);
		}

		// Send a POST request to the Matomo API.
		$response = wp_remote_post( $api_url, [
			'headers' => [
				'Content-Type' => 'application/x-www-form-urlencoded',
				'User-Agent'   => 'MaiAnalytics/1.0',
			],
			'body'    => $body,
			'timeout' => 30,
		] );

		// Check for WP error.
		if ( is_wp_error( $response ) ) {
			error_log( '[Mai Analytics] Matomo API request failed: ' . $response->get_error_message() );
			return [];
		}

		// Check for successful HTTP status.
		$code = wp_remote_retrieve_response_code( $response );

		if ( 200 !== $code ) {
			error_log( '[Mai Analytics] Matomo API returned HTTP ' . $code . ': ' . wp_remote_retrieve_response_message( $response ) );
			return [];
		}

		// Decode response body.
		$body_raw = wp_remote_retrieve_body( $response );
		$data     = json_decode( $body_raw, true );

		if ( ! $data || ! is_array( $data ) ) {
			error_log( '[Mai Analytics] Matomo API returned empty or invalid JSON response.' );
			return [];
		}

		// Check for Matomo-level error.
		if ( isset( $data['result'] ) && 'error' === $data['result'] ) {
			$message = $data['message'] ?? 'Unknown Matomo API error.';
			error_log( '[Mai Analytics] Matomo API error: ' . $message );
			return [];
		}

		// Parse response: each index corresponds to the path at the same index.
		// Each entry is an array of period rows, each containing objects with nb_visits.
		$results = [];

		foreach ( $data as $index => $row ) {
			// Skip if this index doesn't correspond to a requested path.
			if ( ! isset( $paths[ $index ] ) ) {
				continue;
			}

			$path   = $paths[ $index ];
			$visits = 0;

			// For 'range' period, the response is typically a single-element array,
			// but we sum across all entries for robustness.
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
				$results[ $path ] = $visits;
			}
		}

		return $results;
	}
}
