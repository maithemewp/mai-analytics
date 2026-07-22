<?php

namespace Mai\Analytics\Providers;

use Mai\Analytics\Sync;
use Mai\Analytics\WebViewProvider;

class SiteKit implements WebViewProvider {

	/**
	 * Minimum supported Site Kit version.
	 *
	 * Inherited from the previous REST-based implementation. The module classes
	 * this provider now calls predate it — `Analytics_4` is `@since 1.30.0` and
	 * `Module::get_data()` is `@since 1.0.0` — so nothing in the current code
	 * path requires 1.96.0 specifically. It is retained as a conservative,
	 * field-tested baseline rather than re-derived downward.
	 */
	private const MIN_SITE_KIT_VERSION = '1.96.0';

	/**
	 * Start date used for the all-time window when the GA4 property creation
	 * time is missing or unusable.
	 *
	 * Predates GA4 itself — the first App + Web properties appeared in 2019 —
	 * so it can never land after a property was created. It also doubles as the
	 * lower bound in `is_usable_start_date()`.
	 *
	 * This rests on an assumption about Google's API, not about Site Kit: that
	 * the GA4 Data API returns no rows for dates preceding property creation
	 * rather than erroring. True in practice, but not something Site Kit
	 * guarantees. (Data retention limits govern event-level and exploration
	 * data, not standard aggregate pagePath/screenPageViews reports.)
	 *
	 * @since 1.3.3
	 */
	private const ALL_TIME_FALLBACK_START = '2019-01-01';

	/**
	 * Site Kit classes this provider depends on directly.
	 *
	 * `User_Options` and `Analytics_4` are marked `@access private` upstream.
	 * `Plugin` is not — it is Site Kit's documented entry point — but its
	 * `instance()` returns null before Site Kit has bootstrapped, so all three
	 * are verified rather than assumed.
	 *
	 * Note this only catches class *removal*. The likelier upstream drift is a
	 * changed constructor signature, which surfaces as a caught `\Throwable` in
	 * `get_owner_bound_module()` instead.
	 *
	 * @since 1.3.3
	 */
	private const REQUIRED_CLASSES = [
		'\Google\Site_Kit\Plugin',
		'\Google\Site_Kit\Core\Storage\User_Options',
		'\Google\Site_Kit\Modules\Analytics_4',
	];

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

		if ( ! self::has_required_classes() ) {
			return __( 'This version of Site Kit does not expose the internals Mai Analytics reads. Syncing is paused until Site Kit is updated or an update to Mai Analytics restores compatibility.', 'mai-analytics' );
		}

		return __( 'Google Analytics 4 is not connected in Site Kit.', 'mai-analytics' );
	}

	/**
	 * Checks whether Site Kit is installed, active, and has a fully configured GA4 property.
	 *
	 * Verifies that the GOOGLESITEKIT_VERSION constant is defined, that the
	 * version meets MIN_SITE_KIT_VERSION, that the internal classes this
	 * provider calls still exist, and that the GA4 settings option contains
	 * non-empty accountID, propertyID, webDataStreamID, and measurementID values.
	 *
	 * @return bool True if Site Kit is available and GA4 is fully configured.
	 */
	public function is_available(): bool {
		if ( ! defined( 'GOOGLESITEKIT_VERSION' ) ) {
			return false;
		}

		// Ensure Site Kit is recent enough to have the GA4 module we rely on.
		if ( version_compare( GOOGLESITEKIT_VERSION, self::MIN_SITE_KIT_VERSION, '<' ) ) {
			return false;
		}

		if ( ! self::has_required_classes() ) {
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
	 * Builds a GA4 module instance bound to the Site Kit module owner and calls
	 * its `get_data()` directly, one report per window.
	 *
	 * Why not the REST route, and why not `wp_set_current_user()`: Site Kit
	 * constructs its `User_Options` on `init` at priority -999 and caches
	 * whichever user is current at that moment. The only thing that re-binds it
	 * for the remainder of a request is `wp_login`; everything else (Permissions,
	 * the Synchronize_* crons, shared-module token refresh) switches temporarily
	 * and restores. Under WP-Cron the current user is 0, so the call-time
	 * `wp_set_current_user()` this method used to do never reached Site Kit's
	 * OAuth context, and every sync failed with "you haven't granted all
	 * permissions requested during setup". Constructing our own owner-bound
	 * `User_Options` sidesteps the timing entirely: `Module::__construct()`
	 * passes it into the `Authentication` it builds, so the OAuth client is
	 * owner-scoped by construction. This mirrors how Site Kit's own background
	 * jobs work — see its `Synchronize_Property`.
	 *
	 * Empty start_date semantics: an empty start in a window means "all-time".
	 * Site Kit has no "no date range" mode, so all-time resolves to an explicit
	 * early start date via `get_all_time_start_date()`.
	 *
	 * Failure semantics: all-or-nothing per call, per the WebViewProvider
	 * contract. If anything fails we set the provider error state and return
	 * null so ProviderSync preserves existing meta. Returning the windows that
	 * did succeed would let the caller read the missing ones as genuine zeros
	 * and erase real counts.
	 *
	 * @param array<string>                            $paths   URL paths.
	 * @param array<string, array{0:string,1:string}>  $windows Map of window name to [start, end].
	 *
	 * @return array<string, array<string, int>>|null Map of path => (window name =>
	 *     view count), or null when the request could not be completed.
	 */
	public function get_views( array $paths, array $windows ): ?array {
		if ( ! $paths || ! $windows ) {
			return [];
		}

		$owner_id = self::get_owner_id();

		if ( ! $owner_id ) {
			self::set_last_error( __( 'Site Kit owner user not found. Cannot authenticate GA4 request.', 'mai-analytics' ) );
			return null;
		}

		// A stale owner option pointing at a deleted user yields a module bound
		// to a phantom user with no stored token, which surfaces as an opaque
		// scopes error. Validate up-front so the admin sees something actionable.
		if ( ! get_user_by( 'id', $owner_id ) ) {
			self::set_last_error( sprintf(
				/* translators: %d is the stale owner user ID. */
				__( 'Site Kit owner user (ID %d) does not exist. Have an existing admin re-sign-in via Site Kit, or update the Analytics module owner.', 'mai-analytics' ),
				$owner_id
			) );
			return null;
		}

		$build_error = '';
		$module      = self::get_owner_bound_module( $owner_id, $build_error );

		if ( ! $module ) {
			self::set_last_error( $build_error );
			return null;
		}

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

			// Both dates must survive Site Kit's `strtotime()` gate or it
			// discards the pair and substitutes the last 28 days. The start is
			// validated in get_all_time_start_date(); the end is defended here
			// because get_views() is a public interface method and callers
			// build their own windows.
			$params              = $base_params;
			$params['startDate'] = '' !== $start_date ? $start_date : self::get_all_time_start_date();
			$params['endDate']   = '' !== $end_date ? $end_date : gmdate( 'Y-m-d' );

			// Site Kit already converts its own exceptions to WP_Error inside
			// Module::execute_data_request(), so this catch exists for
			// engine-level \Error — e.g. a TypeError from a changed private-API
			// signature — plus the shape errors parse_report_rows() raises.
			// Both would otherwise fatal every cron run.
			try {
				$response = $module->get_data( 'report', $params );

				if ( is_wp_error( $response ) ) {
					throw new \RuntimeException( $response->get_error_message() );
				}

				$rows = self::parse_report_rows( $response );
			} catch ( \Throwable $e ) {
				// Window and range context, because a bare Google message like
				// "Request contains an invalid argument" is undiagnosable later.
				$any_error_msg = sprintf(
					'[%s window, %s to %s] %s',
					$window_name,
					$params['startDate'],
					$params['endDate'],
					$e->getMessage()
				);

				// The return value is already committed to [] — don't spend
				// another authenticated round trip on the remaining windows.
				break;
			}

			if ( ! $rows ) {
				continue;
			}

			$any_success = true;

			foreach ( $rows as $path => $count ) {
				$results[ $path ][ (string) $window_name ] = $count;
			}
		}

		// All-or-nothing failure semantics: if any window errored, return null so
		// the caller preserves existing meta. Returning a partial result would
		// let `$web_views[$path][$missing_window] ?? 0` fall through to 0 and
		// silently overwrite the failed column with zero. The stored error
		// surfaces the failure to the admin UI either way.
		if ( '' !== $any_error_msg ) {
			self::set_last_error( $any_error_msg );
			return null;
		}

		if ( $any_success ) {
			Sync::clear_provider_error();
		}

		return $results;
	}

	/**
	 * Resolves the user whose Google credentials back the GA4 module.
	 *
	 * Prefers the module's own `ownerID` — the value Site Kit itself reads via
	 * `Module_With_Owner_Trait::get_owner_id()`.
	 *
	 * Neither fallback option exists in Site Kit 1.181; a full-source grep for
	 * `googlesitekit_owner_id` and `googlesitekit_first_admin` returns nothing.
	 * They are retained only for sites still carrying values written by much
	 * older versions. Once those stop appearing in the wild, delete both.
	 *
	 * Public so support tooling and tests can resolve the same user this
	 * provider authenticates as.
	 *
	 * @since 1.3.3
	 *
	 * @return int The owner user ID, or 0 when none can be resolved.
	 */
	public static function get_owner_id(): int {
		$settings = get_option( 'googlesitekit_analytics-4_settings', [] );
		$owner_id = isset( $settings['ownerID'] ) ? (int) $settings['ownerID'] : 0;

		if ( ! $owner_id ) {
			$owner_id = (int) get_option( 'googlesitekit_owner_id', 0 );
		}

		if ( ! $owner_id ) {
			$owner_id = (int) get_option( 'googlesitekit_first_admin', 0 );
		}

		return $owner_id;
	}

	/**
	 * Builds a GA4 module instance whose OAuth context is bound to the owner.
	 *
	 * `Module::__construct()` passes the `User_Options` it receives into the
	 * `Authentication` it creates, so binding the owner here is what makes the
	 * resulting token owner-scoped regardless of who — if anyone — is the
	 * current WordPress user.
	 *
	 * Returns a weakly-typed `?object` rather than `?Analytics_4` deliberately:
	 * a top-level `use` of an `@access private` Site Kit class would make this
	 * file's type references depend on a class that may not exist, which is the
	 * very condition `has_required_classes()` exists to detect. The concrete
	 * classes are also php-scoper-prefixed upstream
	 * (`Google\Site_Kit_Dependencies\…`), so duck typing is the durable choice.
	 *
	 * @since 1.3.3
	 *
	 * @param int    $owner_id The Site Kit module owner user ID.
	 * @param string $error    Set to a human-readable reason when null is returned.
	 *
	 * @return object|null The Analytics_4 module instance, or null on failure.
	 */
	private static function get_owner_bound_module( int $owner_id, string &$error = '' ): ?object {
		if ( ! self::has_required_classes() ) {
			$error = __( 'This version of Site Kit does not expose the internals Mai Analytics reads.', 'mai-analytics' );
			return null;
		}

		try {
			// Site Kit's main instance is null until its own bootstrap has run.
			$plugin = \Google\Site_Kit\Plugin::instance();

			if ( ! $plugin ) {
				$error = __( 'Site Kit has not finished loading, so its Analytics module is unavailable.', 'mai-analytics' );
				return null;
			}

			// context() is one of the few public methods Site Kit exposes, so
			// the Context is obtained rather than constructed.
			$context      = $plugin->context();
			$user_options = new \Google\Site_Kit\Core\Storage\User_Options( $context, $owner_id );

			return new \Google\Site_Kit\Modules\Analytics_4( $context, null, $user_options );
		} catch ( \Throwable $e ) {
			// Carry the real message: under cron the logger writes nowhere
			// unless WP_DEBUG_LOG is on, so routing it through the caller's
			// set_last_error() is the only way it reaches a human.
			$error = sprintf(
				/* translators: %s: the underlying PHP error message. */
				__( 'Could not build the Site Kit Analytics module — check for a Mai Analytics update. Details: %s', 'mai-analytics' ),
				$e->getMessage()
			);

			return null;
		}
	}

	/**
	 * Reduces a GA4 report response to a path => view count map.
	 *
	 * Shape detection is positive on both levels, and an unrecognized shape
	 * raises rather than returning an empty array. Returning empty would be
	 * indistinguishable from "this site genuinely had no views", which the
	 * caller treats as a successful window — so a changed Site Kit response
	 * contract would stop all syncing with no error, no log, and a green status
	 * in the admin. Failing loudly keeps that visible.
	 *
	 * @since 1.3.3
	 *
	 * @param mixed $response The report response from Analytics_4::get_data().
	 *
	 * @throws \RuntimeException When the response or a row is not a shape we understand.
	 *
	 * @return array<string, int> Map of path to view count.
	 */
	private static function parse_report_rows( $response ): array {
		if ( is_object( $response ) && method_exists( $response, 'getRows' ) ) {
			$rows = $response->getRows() ?: [];
		} elseif ( is_array( $response ) && array_key_exists( 'rowCount', $response ) ) {
			// GA4 omits `rows` entirely for a report with no data but always
			// reports `rowCount`, so keying off `rows` alone cannot tell "no
			// data" apart from "not a report response".
			$rows = (array) ( $response['rows'] ?? [] );
		} else {
			throw new \RuntimeException( sprintf(
				'Unrecognized GA4 report response (%s). Site Kit may have changed its report contract.',
				is_object( $response ) ? get_class( $response ) : gettype( $response )
			) );
		}

		$results = [];

		foreach ( $rows as $row ) {
			if ( is_object( $row ) && method_exists( $row, 'getDimensionValues' ) ) {
				$dimensions = $row->getDimensionValues() ?: [];
				$metrics    = $row->getMetricValues() ?: [];
				$path       = isset( $dimensions[0] ) ? (string) $dimensions[0]->getValue() : '';
				$count      = isset( $metrics[0] ) ? (int) $metrics[0]->getValue() : 0;
			} elseif ( is_array( $row ) ) {
				$path  = (string) ( $row['dimensionValues'][0]['value'] ?? '' );
				$count = (int) ( $row['metricValues'][0]['value'] ?? 0 );
			} else {
				// Array access on an object is a fatal, not a warning, so an
				// unexpected row type must never reach the array branch.
				throw new \RuntimeException( sprintf(
					'Unrecognized GA4 report row (%s).',
					is_object( $row ) ? get_class( $row ) : gettype( $row )
				) );
			}

			if ( '' === $path || $count <= 0 ) {
				continue;
			}

			$results[ $path ] = $count;
		}

		return $results;
	}

	/**
	 * Resolves the start date used for the all-time window.
	 *
	 * Site Kit has no "all data" mode: `Analytics_4\Report\ReportParsers::parse_dateranges()`
	 * rewrites an omitted or unparseable range to the last 28 days (it has done
	 * so since ~1.99, under earlier class names). Without an explicit start the
	 * all-time column silently becomes a rolling 28-day count.
	 *
	 * Prefers the GA4 property creation time, which narrows the request and
	 * costs less API quota. That value is populated by one of Site Kit's own
	 * cron jobs and is 0 on a freshly connected property — or on Site Kit
	 * < 1.116.0, which predates the setting entirely.
	 *
	 * The computed date is validated rather than trusted. `propertyCreateTime`
	 * is a private-API setting read from another plugin, and a unit change
	 * upstream would be silent: any value of 1–999 floors to 0, yielding
	 * 1970-01-01, whose `strtotime()` is 0 — falsy — so Site Kit would discard
	 * the range and revert to 28 days. "Earlier is always safe" only holds for
	 * dates Site Kit actually accepts.
	 *
	 * @since 1.3.3
	 *
	 * @return string The start date as Y-m-d.
	 */
	private static function get_all_time_start_date(): string {
		$settings = get_option( 'googlesitekit_analytics-4_settings', [] );
		$created  = isset( $settings['propertyCreateTime'] ) ? (int) $settings['propertyCreateTime'] : 0;

		// Back up a day: gmdate() yields the UTC date, but GA4 reads ranges in
		// the property's own timezone, so a property created late in its local
		// day west of UTC would otherwise lose its first day of views.
		$candidate = $created > 0
			? gmdate( 'Y-m-d', (int) floor( $created / 1000 ) - DAY_IN_SECONDS )
			: '';

		if ( self::is_usable_start_date( $candidate ) ) {
			return $candidate;
		}

		if ( $created > 0 ) {
			// Distinguishes "not populated yet" (expected, silent) from
			// "populated with something unusable" (a real upstream change).
			mai_analytics_logger()->error( sprintf(
				'Site Kit propertyCreateTime=%d produced an unusable all-time start date (%s); falling back to %s. Site Kit may have changed the stored unit.',
				$created,
				'' !== $candidate ? $candidate : '(none)',
				self::ALL_TIME_FALLBACK_START
			) );
		}

		return self::ALL_TIME_FALLBACK_START;
	}

	/**
	 * Checks whether a start date is one Site Kit will actually honor.
	 *
	 * @since 1.3.3
	 *
	 * @param string $date The candidate date as Y-m-d.
	 *
	 * @return bool True when the date parses, is not in the future, and is not
	 *              earlier than GA4 itself.
	 */
	private static function is_usable_start_date( string $date ): bool {
		if ( '' === $date ) {
			return false;
		}

		$timestamp = strtotime( $date );

		// Site Kit gates on strtotime() truthiness, so 1970-01-01 — which
		// parses to 0 — is treated as absent and silently rewritten.
		if ( ! $timestamp ) {
			return false;
		}

		// A future start date puts start after end, which Site Kit also
		// rewrites to 28 days, and would otherwise return nothing forever.
		return $timestamp >= strtotime( self::ALL_TIME_FALLBACK_START ) && $timestamp <= time();
	}

	/**
	 * Checks that every Site Kit class this provider depends on still exists.
	 *
	 * @since 1.3.3
	 *
	 * @return bool True when all required classes are present.
	 */
	private static function has_required_classes(): bool {
		foreach ( self::REQUIRED_CLASSES as $class ) {
			if ( ! class_exists( $class ) ) {
				return false;
			}
		}

		return true;
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
