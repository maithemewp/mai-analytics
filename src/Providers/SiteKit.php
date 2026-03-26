<?php

namespace Mai\Analytics\Providers;

use Mai\Analytics\Settings;
use Mai\Analytics\WebViewProvider;
use WP_REST_Request;

class SiteKit implements WebViewProvider {

	/**
	 * Gets the provider slug identifier.
	 *
	 * @return string The provider slug.
	 */
	public function get_slug(): string {
		return 'site_kit';
	}

	/**
	 * Gets the human-readable provider label.
	 *
	 * @return string The provider display name.
	 */
	public function get_label(): string {
		return 'Google Analytics (via Site Kit)';
	}

	/**
	 * Gets the maximum number of paths to include in a single API call.
	 *
	 * @return int The batch size limit.
	 */
	public function get_batch_size(): int {
		return 50;
	}

	/**
	 * Gets the settings fields specific to this provider.
	 *
	 * Site Kit handles its own configuration, so no additional fields are needed.
	 *
	 * @return array Empty array since Site Kit manages its own settings.
	 */
	public function get_settings_fields(): array {
		return [];
	}

	/**
	 * Gets a human-readable reason why the provider is not available.
	 *
	 * @return string The reason, or empty string if available.
	 */
	public function get_unavailable_reason(): string {
		if ( ! defined( 'GOOGLESITEKIT_VERSION' ) ) {
			return __( 'Google Site Kit plugin is not installed or activated.', 'mai-analytics' );
		}

		if ( version_compare( GOOGLESITEKIT_VERSION, self::MIN_SITE_KIT_VERSION, '<' ) ) {
			return sprintf(
				/* translators: 1: current version, 2: required version */
				__( 'Site Kit version %1$s is too old. Version %2$s or later is required.', 'mai-analytics' ),
				GOOGLESITEKIT_VERSION,
				self::MIN_SITE_KIT_VERSION
			);
		}

		return __( 'Google Analytics 4 is not connected in Site Kit.', 'mai-analytics' );
	}

	/**
	 * Checks whether Site Kit is installed, active, and has a fully configured GA4 property.
	 *
	 * Verifies that the GOOGLESITEKIT_VERSION constant is defined and that the
	 * GA4 settings option contains non-empty accountID, propertyID,
	 * webDataStreamID, and measurementID values.
	 *
	 * @return bool True if Site Kit is available and GA4 is fully configured.
	 */
	/**
	 * Minimum Site Kit version required for the GA4 report REST API.
	 */
	private const MIN_SITE_KIT_VERSION = '1.96.0';

	public function is_available(): bool {
		if ( ! defined( 'GOOGLESITEKIT_VERSION' ) ) {
			return false;
		}

		// Ensure Site Kit is recent enough to have the GA4 report endpoint we rely on.
		if ( version_compare( GOOGLESITEKIT_VERSION, self::MIN_SITE_KIT_VERSION, '<' ) ) {
			return false;
		}

		$settings      = get_option( 'googlesitekit_analytics-4_settings', [] );
		$required_keys = [ 'accountID', 'propertyID', 'webDataStreamID', 'measurementID' ];

		foreach ( $required_keys as $key ) {
			if ( empty( $settings[ $key ] ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Fetches pageview counts for the given URL paths within a date range.
	 *
	 * Dispatches an internal REST request to the Site Kit Analytics 4 report endpoint,
	 * impersonating the configured sync user for OAuth context. Parses the response
	 * rows and returns an associative array of path => view count.
	 *
	 * @param array  $paths      Array of URL paths (e.g., ['/some-post/', '/category/news/']).
	 * @param string $start_date Start date in 'Y-m-d' format.
	 * @param string $end_date   End date in 'Y-m-d' format.
	 *
	 * @return array Associative array of path => view count. Missing paths are omitted.
	 */
	public function get_views( array $paths, string $start_date, string $end_date ): array {
		if ( ! $paths ) {
			return [];
		}

		// Switch to the Site Kit owner user for OAuth context.
		// Site Kit stores the authenticated owner in googlesitekit_owner_id.
		$owner_id    = (int) get_option( 'googlesitekit_owner_id', 0 );
		$previous_id = get_current_user_id();

		if ( ! $owner_id ) {
			// Fallback to legacy option used by older Site Kit versions.
			$owner_id = (int) get_option( 'googlesitekit_first_admin', 0 );
		}

		if ( ! $owner_id ) {
			self::set_last_error( __( 'Site Kit owner user not found. Cannot authenticate GA4 request.', 'mai-analytics' ) );
			return [];
		}

		wp_set_current_user( $owner_id );

		// Build the internal REST request.
		$request = new WP_REST_Request( 'GET', '/google-site-kit/v1/modules/analytics-4/data/report' );

		$request->set_query_params( [
			'metrics'         => [
				[ 'name' => 'screenPageViews' ],
			],
			'dimensions'      => [
				[ 'name' => 'pagePath' ],
			],
			'startDate'       => $start_date,
			'endDate'         => $end_date,
			'dimensionFilters' => [
				'filter' => [
					'fieldName'    => 'pagePath',
					'inListFilter' => [
						'values' => $paths,
					],
				],
			],
			'orderby'         => [
				[
					'metric' => [ 'metricName' => 'screenPageViews' ],
					'desc'   => true,
				],
			],
			'limit'           => count( $paths ),
		] );

		$response = rest_do_request( $request );

		// Restore the previous user.
		wp_set_current_user( $previous_id );

		// Handle errors.
		if ( $response->is_error() ) {
			$error = $response->as_error();
			self::set_last_error( $error->get_error_message() );
			return [];
		}

		$data = $response->get_data();

		if ( empty( $data['rows'] ) ) {
			return [];
		}

		// Parse rows into path => views associative array.
		$views = [];

		foreach ( $data['rows'] as $row ) {
			$path  = $row['dimensionValues'][0]['value'] ?? '';
			$count = (int) ( $row['metricValues'][0]['value'] ?? 0 );

			if ( $path ) {
				$views[ $path ] = $count;
			}
		}

		// Clear any previous error on success.
		delete_transient( 'mai_analytics_provider_error' );

		return $views;
	}

	/**
	 * Stores the last provider error for display in the admin UI.
	 *
	 * @param string $message The error message.
	 *
	 * @return void
	 */
	private static function set_last_error( string $message ): void {
		error_log( '[Mai Analytics] Site Kit report error: ' . $message );
		set_transient( 'mai_analytics_provider_error', $message, HOUR_IN_SECONDS );
	}

	/**
	 * Gets the last stored provider error, if any.
	 *
	 * @return string The error message, or empty string if none.
	 */
	public static function get_last_error(): string {
		return (string) get_transient( 'mai_analytics_provider_error' );
	}
}
