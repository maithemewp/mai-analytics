<?php

namespace Mai\Analytics\Providers;

use Mai\Analytics\Settings;
use Mai\Analytics\Sync;
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
		// Without this guard the cascade falls through to the
		// "GA4 not connected" string even on properly-configured sites,
		// so callers that surface the reason next to is_available() show a
		// contradictory pair (available: yes / unavailable: …). Match the
		// docblock contract: empty string when the provider is usable.
		if ( $this->is_available() ) {
			return '';
		}

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
	 * Fetches pageview counts for the given URL paths across one or more named windows.
	 *
	 * Dispatches one internal REST request per window to the Site Kit Analytics 4
	 * report endpoint. Each window is a single-range GA4 query; we issue them
	 * sequentially because Site Kit's REST controller accepts a singular
	 * `startDate`/`endDate` pair and we don't want to silently rely on whether
	 * a given Site Kit version forwards GA4's native `dateRanges` parameter to
	 * runReport.
	 *
	 * The OAuth-context user switch is performed once for the whole call, not
	 * once per window — fewer impersonation/restore cycles than the old
	 * separate-call pattern.
	 *
	 * Future optimization: when we're confident every supported Site Kit
	 * version forwards `dateRanges` cleanly, replace the per-window loop with
	 * a single GA4 report carrying multiple `dateRanges` and synthetic
	 * dateRange dimension parsing. That would collapse this to one REST call.
	 *
	 * Empty start_date semantics: an empty start in a window means "all-time".
	 * Per commit f5199c6, we omit `startDate`/`endDate` entirely for that
	 * window so GA4 returns data since property creation; we do not reintroduce
	 * a hardcoded floor.
	 *
	 * Failure semantics: all-or-nothing per call. If any window errors, we set
	 * the provider error transient and return `[]` so ProviderSync preserves
	 * existing meta rather than overwriting the missing window's column with 0.
	 *
	 * @param array<string>                            $paths   URL paths.
	 * @param array<string, array{0:string,1:string}>  $windows Map of window name to [start, end].
	 *
	 * @return array<string, array<string, int>> Map of path => (window name => view count).
	 */
	public function get_views( array $paths, array $windows ): array {
		if ( ! $paths || ! $windows ) {
			return [];
		}

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

		// Verify the resolved owner actually exists. If the option points at
		// a deleted user, wp_set_current_user( $owner_id ) silently switches
		// us to a phantom user with no caps; the GA4 REST permission_callback
		// then returns rest_forbidden, which surfaces as a confusing
		// "Sorry, you are not allowed to do that." with no signal that the
		// underlying problem is a stale option pointing at a removed admin.
		// Validate up-front so the admin sees an actionable message instead.
		$owner = get_user_by( 'id', $owner_id );

		if ( ! $owner ) {
			self::set_last_error( sprintf(
				/* translators: %d is the stale owner user ID. */
				__( 'Site Kit owner user (ID %d) does not exist. Have an existing admin re-sign-in via Site Kit, or update googlesitekit_owner_id to a valid user.', 'mai-analytics' ),
				$owner_id
			) );
			return [];
		}

		wp_set_current_user( $owner->ID );

		$results       = [];
		$any_success   = false;
		$any_error_msg = '';

		$base_params = [
			'metrics'          => [ [ 'name' => 'screenPageViews' ] ],
			'dimensions'       => [ [ 'name' => 'pagePath' ] ],
			'dimensionFilters' => [ 'pagePath' => array_values( $paths ) ],
			'orderby'          => [
				[
					'metric' => [ 'metricName' => 'screenPageViews' ],
					'desc'   => true,
				],
			],
			'limit'            => count( $paths ),
		];

		foreach ( $windows as $window_name => $range ) {
			[ $start_date, $end_date ] = $range;

			$request = new WP_REST_Request( 'GET', '/google-site-kit/v1/modules/analytics-4/data/report' );

			// Empty start_date means "all-time" per the caller's contract.
			// Per commit f5199c6: omit startDate/endDate entirely so GA4 returns
			// all data since the property was created. The hardcoded floor we
			// previously used was rejected by that commit ("No hardcoded start
			// date needed") and is intentionally not reintroduced here.
			$params = $base_params;
			if ( '' !== $start_date && '' !== $end_date ) {
				$params['startDate'] = $start_date;
				$params['endDate']   = $end_date;
			}

			$request->set_query_params( $params );

			$response = rest_do_request( $request );

			if ( $response->is_error() ) {
				$any_error_msg = $response->as_error()->get_error_message();
				continue;
			}

			$data = $response->get_data();

			if ( empty( $data['rows'] ) ) {
				continue;
			}

			$any_success = true;

			foreach ( $data['rows'] as $row ) {
				$path  = $row['dimensionValues'][0]['value'] ?? '';
				$count = (int) ( $row['metricValues'][0]['value'] ?? 0 );

				if ( '' === $path || $count <= 0 ) {
					continue;
				}

				$results[ $path ][ (string) $window_name ] = $count;
			}
		}

		wp_set_current_user( $previous_id );

		// All-or-nothing failure semantics: if any window errored, return [] so
		// ProviderSync's `empty( $web_views )` check trips and existing meta is
		// preserved. Returning a partial result would let the caller's
		// `$web_views[$path][$missing_window] ?? 0` fall through to 0 and
		// silently overwrite the failed column with zero. The transient still
		// surfaces the error to the admin UI either way.
		if ( '' !== $any_error_msg ) {
			self::set_last_error( $any_error_msg );
			return [];
		}

		if ( $any_success ) {
			Sync::clear_provider_error();
		}

		return $results;
	}

	/**
	 * Logs the error with a Site Kit prefix and records it for surfaces
	 * that read provider state. Storage shape lives in
	 * `Sync::set_provider_error()`.
	 *
	 * @param string $message The error message.
	 *
	 * @return void
	 */
	private static function set_last_error( string $message ): void {
		mai_analytics_logger()->error( 'Site Kit report error: ' . $message );
		Sync::set_provider_error( $message );
	}

	/**
	 * Gets the last stored provider error message, if any.
	 *
	 * Reads via the central decoder so legacy plain-string transients still
	 * resolve correctly during the upgrade window.
	 *
	 * @return string The error message, or empty string if none.
	 */
	public static function get_last_error(): string {
		return Sync::get_last_error()['message'];
	}
}
