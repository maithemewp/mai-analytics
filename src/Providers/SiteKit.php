<?php

namespace Mai\Analytics\Providers;

use Mai\Analytics\Sync;
use Mai\Analytics\WebViewProvider;

class SiteKit implements WebViewProvider {

	/**
	 * Minimum Site Kit version required for the GA4 module classes we call.
	 */
	private const MIN_SITE_KIT_VERSION = '1.96.0';

	/**
	 * Start date used for the all-time window when the GA4 property creation
	 * time is unavailable.
	 *
	 * Deliberately predates GA4 itself — the first App + Web properties appeared
	 * in 2019 — so it can never land after a property was created. That is what
	 * makes the fallback safe: a start date earlier than the property returns
	 * exactly the same totals as the property creation date, because GA4 holds
	 * no data from before the property existed. A fallback that could land
	 * *later* would silently undercount.
	 *
	 * @since 1.3.3
	 */
	private const ALL_TIME_FALLBACK_START = '2019-01-01';

	/**
	 * Site Kit classes this provider instantiates directly. All are marked
	 * `@access private` upstream, so their availability is verified before use
	 * rather than assumed — see `has_required_classes()`.
	 *
	 * @since 1.3.3
	 */
	private const REQUIRED_CLASSES = [
		'\Google\Site_Kit\Plugin',
		'\Google\Site_Kit\Core\Storage\User_Options',
		'\Google\Site_Kit\Modules\Analytics_4',
	];

	/**
	 * Memoized all-time start date, so a multi-batch sync resolves it once
	 * instead of re-querying for every batch of paths.
	 *
	 * @since 1.3.3
	 *
	 * @var string
	 */
	private static $all_time_start = '';

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
	 * internal classes this provider calls still exist, and that the GA4
	 * settings option contains non-empty accountID, propertyID,
	 * webDataStreamID, and measurementID values.
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
	 * whichever user is current at that moment for the rest of the request. It
	 * only ever re-binds that on `wp_login`. Under WP-Cron the current user is
	 * 0, so a call-time `wp_set_current_user()` — which is what this method
	 * used to do — never reached Site Kit's OAuth context, and every sync
	 * failed with "you haven't granted all permissions requested during setup".
	 * Constructing our own owner-bound `User_Options` sidesteps the timing
	 * entirely: `Module::__construct()` passes it into the `Authentication` it
	 * builds, so the OAuth client is owner-scoped by construction. This mirrors
	 * how Site Kit's own background jobs work (see its `Synchronize_Property`,
	 * which switches to the owner rather than relying on the current user).
	 *
	 * Empty start_date semantics: an empty start in a window means "all-time".
	 * Site Kit has no "no date range" mode — an omitted or unparseable range is
	 * silently rewritten to the last 28 days — so all-time resolves to an
	 * explicit early start date via `get_all_time_start_date()`.
	 *
	 * Failure semantics: all-or-nothing per call. If any window errors, we set
	 * the provider error state and return `[]` so ProviderSync preserves
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

		$owner_id = self::get_owner_id();

		if ( ! $owner_id ) {
			self::set_last_error( __( 'Site Kit owner user not found. Cannot authenticate GA4 request.', 'mai-analytics' ) );
			return [];
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
			return [];
		}

		$module = self::get_owner_bound_module( $owner_id );

		if ( ! $module ) {
			self::set_last_error( __( 'Could not build the Site Kit Analytics module. Site Kit may have changed internally; check for a Mai Analytics update.', 'mai-analytics' ) );
			return [];
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

			$params              = $base_params;
			$params['startDate'] = '' !== $start_date ? $start_date : self::get_all_time_start_date();
			$params['endDate']   = $end_date;

			// Site Kit's module internals are private API. A signature change
			// upstream would otherwise surface as a fatal on every cron run,
			// so contain it and let the all-or-nothing path preserve meta.
			try {
				$response = $module->get_data( 'report', $params );
			} catch ( \Throwable $e ) {
				$any_error_msg = $e->getMessage();
				continue;
			}

			if ( is_wp_error( $response ) ) {
				$any_error_msg = $response->get_error_message();
				continue;
			}

			$rows = self::parse_report_rows( $response );

			if ( ! $rows ) {
				continue;
			}

			$any_success = true;

			foreach ( $rows as $path => $count ) {
				$results[ $path ][ (string) $window_name ] = $count;
			}
		}

		// All-or-nothing failure semantics: if any window errored, return [] so
		// ProviderSync's `empty( $web_views )` check trips and existing meta is
		// preserved. Returning a partial result would let the caller's
		// `$web_views[$path][$missing_window] ?? 0` fall through to 0 and
		// silently overwrite the failed column with zero. The stored error
		// surfaces the failure to the admin UI either way.
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
	 * Resolves the user whose Google credentials back the GA4 module.
	 *
	 * Prefers the module's own `ownerID` — the value Site Kit itself reads via
	 * `Module_With_Owner_Trait::get_owner_id()` — then the site-wide owner
	 * option, then the legacy option used by older Site Kit versions.
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
	 * @since 1.3.3
	 *
	 * @param int $owner_id The Site Kit module owner user ID.
	 *
	 * @return object|null The Analytics_4 module instance, or null on failure.
	 */
	private static function get_owner_bound_module( int $owner_id ): ?object {
		if ( ! self::has_required_classes() ) {
			return null;
		}

		// Site Kit's main instance is null until its own bootstrap has run.
		$plugin = \Google\Site_Kit\Plugin::instance();

		if ( ! $plugin ) {
			return null;
		}

		try {
			// context() is one of the few public methods Site Kit exposes, so
			// the Context is obtained rather than constructed.
			$context      = $plugin->context();
			$user_options = new \Google\Site_Kit\Core\Storage\User_Options( $context, $owner_id );

			return new \Google\Site_Kit\Modules\Analytics_4( $context, null, $user_options );
		} catch ( \Throwable $e ) {
			mai_analytics_logger()->error( 'Site Kit module build failed: ' . $e->getMessage() );
			return null;
		}
	}

	/**
	 * Reduces a GA4 report response to a path => view count map.
	 *
	 * Accepts both the Google service response object and a pre-decoded array,
	 * since the response shape is not part of any contract we control.
	 *
	 * @since 1.3.3
	 *
	 * @param mixed $response The report response from Analytics_4::get_data().
	 *
	 * @return array<string, int> Map of path to view count. Empty when nothing usable.
	 */
	private static function parse_report_rows( $response ): array {
		$rows = [];

		if ( is_object( $response ) && method_exists( $response, 'getRows' ) ) {
			$rows = $response->getRows() ?: [];
		} elseif ( is_array( $response ) && isset( $response['rows'] ) ) {
			$rows = (array) $response['rows'];
		}

		$results = [];

		foreach ( $rows as $row ) {
			if ( is_object( $row ) && method_exists( $row, 'getDimensionValues' ) ) {
				$dimensions = $row->getDimensionValues() ?: [];
				$metrics    = $row->getMetricValues() ?: [];
				$path       = isset( $dimensions[0] ) ? (string) $dimensions[0]->getValue() : '';
				$count      = isset( $metrics[0] ) ? (int) $metrics[0]->getValue() : 0;
			} else {
				$path  = (string) ( $row['dimensionValues'][0]['value'] ?? '' );
				$count = (int) ( $row['metricValues'][0]['value'] ?? 0 );
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
	 * Site Kit has no "all data" mode: `ReportParsers::parse_dateranges()`
	 * rewrites an omitted or unparseable range to the last 28 days, so an
	 * explicit start is required or the all-time column silently becomes a
	 * rolling 28-day count.
	 *
	 * Prefers the GA4 property creation time, which is populated by one of Site
	 * Kit's own cron jobs and can be 0 on a freshly connected property. Both
	 * branches return identical view totals — GA4 holds no data from before the
	 * property existed — so the fallback only widens the queried range, never
	 * the result. Using propertyCreateTime simply narrows the request and costs
	 * less API quota.
	 *
	 * @since 1.3.3
	 *
	 * @return string The start date as Y-m-d.
	 */
	private static function get_all_time_start_date(): string {
		if ( '' !== self::$all_time_start ) {
			return self::$all_time_start;
		}

		$settings = get_option( 'googlesitekit_analytics-4_settings', [] );
		$created  = isset( $settings['propertyCreateTime'] ) ? (int) $settings['propertyCreateTime'] : 0;

		// Site Kit stores propertyCreateTime in milliseconds.
		self::$all_time_start = $created > 0
			? gmdate( 'Y-m-d', (int) floor( $created / 1000 ) )
			: self::ALL_TIME_FALLBACK_START;

		return self::$all_time_start;
	}

	/**
	 * Checks that every Site Kit class this provider instantiates still exists.
	 *
	 * These are all `@access private` upstream, so their presence is verified
	 * rather than assumed — a Site Kit refactor degrades this provider to
	 * "unavailable" instead of fataling on every cron run.
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
