<?php

declare( strict_types=1 );

namespace Mai\Analytics;

use WP_REST_Request;

// Prevent direct file access.
defined( 'ABSPATH' ) || die;

/**
 * Maps core's argument errors to the plugin's own code, after the permission check.
 *
 * Core validates args before it asks the permission callback, so a signed-out
 * caller with a bad arg would get a 400 instead of a 401. This runs the
 * permission callback first, then turns core's argument errors into
 * `{$slug}_invalid_request`.
 *
 * A copy of `Mai\Items\RestErrors` from mai-items-core, kept the same until
 * this plugin can depend on that library.
 *
 * @since 1.3.8
 */
final class RestErrors {

	/**
	 * Core's argument error codes that get remapped.
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
	 * Recorded namespaces, lowercased, keyed to their code slug.
	 *
	 * @since 1.3.8
	 *
	 * @var array<string, string>
	 */
	private static array $namespaces = [];

	/**
	 * Records a namespace and adds the filter.
	 *
	 * The filter is added on every call, never behind an "already added" flag,
	 * because the test library removes hooks after each test. Adding the same
	 * callback at the same priority twice is harmless.
	 *
	 * @since 1.3.8
	 *
	 * @param string $namespace The REST namespace, such as `mai-analytics/v1`.
	 * @param string $slug      The code prefix, such as `mai_analytics`.
	 *
	 * @return void
	 */
	public static function map( string $namespace, string $slug ): void {
		self::$namespaces[ strtolower( trim( $namespace, '/' ) ) ] = $slug;

		add_filter( 'rest_request_before_callbacks', [ self::class, 'filter' ], 99, 3 );
	}

	/**
	 * Runs the permission check first, then remaps core's argument errors.
	 *
	 * @since 1.3.8
	 *
	 * @param mixed           $response The response so far, usually null or a WP_Error.
	 * @param array           $handler  The matched route handler.
	 * @param WP_REST_Request $request  The request.
	 *
	 * @return mixed
	 */
	public static function filter( mixed $response, array $handler, WP_REST_Request $request ): mixed {
		if ( ! is_wp_error( $response ) ) {
			return $response;
		}

		$slug = self::slug_for_route( $request->get_route() );

		if ( null === $slug ) {
			return $response;
		}

		$code = $response->get_error_code();

		if ( ! in_array( $code, self::CORE_CODES, true ) && "{$slug}_invalid_request" !== $code ) {
			return $response;
		}

		if ( ! empty( $handler['permission_callback'] ) ) {
			$permission = call_user_func( $handler['permission_callback'], $request );

			if ( is_wp_error( $permission ) ) {
				return $permission;
			}

			if ( false === $permission || null === $permission ) {
				return new \WP_Error(
					'rest_forbidden',
					__( 'Sorry, you are not allowed to do that.' ),
					[ 'status' => rest_authorization_required_code() ]
				);
			}
		}

		$data = $response->get_error_data();
		$data = is_array( $data ) ? $data : [];

		unset( $data['status'] );

		$data['status'] = 400;

		return new \WP_Error( "{$slug}_invalid_request", $response->get_error_message(), $data );
	}

	/**
	 * Finds the code slug for a route, matching the namespace without case.
	 *
	 * @since 1.3.8
	 *
	 * @param string $route The request route.
	 *
	 * @return string|null The slug, or null when the route is outside every recorded namespace.
	 */
	private static function slug_for_route( string $route ): ?string {
		$route = strtolower( ltrim( $route, '/' ) );

		foreach ( self::$namespaces as $namespace => $slug ) {
			if ( str_starts_with( $route, $namespace . '/' ) ) {
				return $slug;
			}
		}

		return null;
	}
}
