<?php

declare( strict_types=1 );

namespace Mai\Analytics;

use WP_Error;
use WP_REST_Request;

// Prevent direct file access.
defined( 'ABSPATH' ) || die;

/**
 * Turns core's argument errors on a plugin's routes into {slug}_invalid_request,
 * after the route's own permission check.
 *
 * Core validates args before it asks the permission callback
 * (class-wp-rest-server.php:1115 to :1123, then :1256 to :1260), so without
 * this a signed-out caller with a bad arg would get a 400 instead of a 401 or 403.
 *
 * A copy of `Mai\Items\RestErrors` from mai-items-core, kept the same until
 * this plugin can depend on that library. Besides the namespace, the
 * direct-access guard and fuller PHPDoc, the one behaviour difference: it
 * reports a throwing permission callback with wp_trigger_error() only where
 * WordPress has it (6.4 and later), since this plugin supports 6.1.
 *
 * @since 1.3.8
 */
final class RestErrors {

	/**
	 * Core's three argument errors, and the one it gives when a route-level
	 * validate_callback returns false (class-wp-rest-request.php:983).
	 *
	 * @since 1.3.8
	 */
	public const CORE_CODES = [
		'rest_invalid_param',
		'rest_missing_callback_param',
		'rest_invalid_json',
		'rest_invalid_params',
	];

	/**
	 * Recorded namespaces, lowercased, to each one's slug.
	 *
	 * @since 1.3.8
	 *
	 * @var array<string, string>
	 */
	private static array $namespaces = [];

	/**
	 * The filter, kept so it is added once and can be found again.
	 *
	 * @since 1.3.8
	 *
	 * @var \Closure|null
	 */
	private static ?\Closure $filter = null;

	/**
	 * Maps the argument errors of every route in a namespace.
	 *
	 * @since 1.3.8
	 *
	 * @param string $namespace Such as `mai-analytics/v1`.
	 * @param string $slug      Such as `mai_analytics`. Errors become {slug}_invalid_request.
	 *
	 * @return void
	 */
	public static function map( string $namespace, string $slug ): void {
		self::$namespaces[ strtolower( trim( $namespace, '/' ) ) ] = $slug;

		self::$filter ??= self::filter( ... );

		// Checked each time rather than remembered, so the filter comes back
		// if a test framework restored the hooks.
		if ( false === has_filter( 'rest_request_before_callbacks', self::$filter ) ) {
			// After the plugins' own checks at the default priority, which keep deciding first.
			add_filter( 'rest_request_before_callbacks', self::$filter, 99, 3 );
		}
	}

	/**
	 * The error a plugin's own checks return for a bad request.
	 *
	 * @since 1.3.8
	 *
	 * @param string               $slug    The code prefix.
	 * @param string               $message The error message.
	 * @param array<string, mixed> $data    Kept as it is, with status 400.
	 *
	 * @return WP_Error
	 */
	public static function invalid_request( string $slug, string $message, array $data = [] ): WP_Error {
		return new WP_Error( $slug . '_invalid_request', $message, array_merge( $data, [ 'status' => 400 ] ) );
	}

	/**
	 * Runs the permission check first, then remaps core's argument errors.
	 *
	 * @since 1.3.8
	 *
	 * @param mixed $response What core has so far. A WP_Error when an arg failed.
	 * @param mixed $handler  The matched route handler.
	 * @param mixed $request  The request.
	 *
	 * @return mixed
	 */
	private static function filter( mixed $response, mixed $handler, mixed $request ): mixed {
		if ( ! $response instanceof WP_Error || ! $request instanceof WP_REST_Request || ! is_array( $handler ) ) {
			return $response;
		}

		$slug = self::slug_for( $request->get_route() );

		if ( null === $slug ) {
			return $response;
		}

		$code   = (string) $response->get_error_code();
		$mapped = $slug . '_invalid_request';
		$core   = in_array( $code, self::CORE_CODES, true );

		if ( ! $core && $mapped !== $code ) {
			return $response;
		}

		try {
			// Core skips its own check once the response is an error, so this is the only call.
			if ( ! empty( $handler['permission_callback'] ) && is_callable( $handler['permission_callback'] ) ) {
				$permission = call_user_func( $handler['permission_callback'], $request );

				if ( $permission instanceof WP_Error ) {
					return $permission;
				}

				// As core builds it (class-wp-rest-server.php:1264 to :1270).
				if ( false === $permission || null === $permission ) {
					return new WP_Error(
						'rest_forbidden',
						__( 'Sorry, you are not allowed to do that.' ),
						[ 'status' => rest_authorization_required_code() ]
					);
				}
			}
		} catch ( \Throwable $error ) {
			try {
				if ( function_exists( 'wp_trigger_error' ) ) {
					wp_trigger_error( self::class . '::map', 'A permission callback threw: ' . $error->getMessage(), E_USER_WARNING );
				}
			} catch ( \Throwable ) {
				// Reporting must never break the request it reports on.
			}

			// Fails closed: a caller whose permission could not be checked never sees the arg error.
			return new WP_Error(
				'rest_forbidden',
				__( 'Sorry, you are not allowed to do that.' ),
				[ 'status' => rest_authorization_required_code() ]
			);
		}

		if ( ! $core ) {
			return $response;
		}

		$data = $response->get_error_data();

		return self::invalid_request( $slug, $response->get_error_message(), is_array( $data ) ? $data : [] );
	}

	/**
	 * The slug of the recorded namespace a route is in, compared without case
	 * because core matches routes without case.
	 *
	 * @since 1.3.8
	 *
	 * @param string $route The request route.
	 *
	 * @return string|null The slug, or null when the route is outside every recorded namespace.
	 */
	private static function slug_for( string $route ): ?string {
		$route = strtolower( '/' . ltrim( $route, '/' ) );

		foreach ( self::$namespaces as $namespace => $slug ) {
			$prefix = '/' . $namespace;

			if ( $route === $prefix || str_starts_with( $route, $prefix . '/' ) ) {
				return $slug;
			}
		}

		return null;
	}
}
