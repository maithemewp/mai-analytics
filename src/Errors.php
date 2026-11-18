<?php

declare( strict_types=1 );

namespace Mai\Analytics;

use WP_REST_Response;

// Prevent direct file access.
defined( 'ABSPATH' ) || die;

/**
 * Builds error responses that keep the top-level fields callers read today.
 *
 * Core's WP_Error conversion would drop fields such as `success` or the warm
 * progress, so these errors are built as a WP_REST_Response with the same
 * `code`, `message` and `data` shape core gives a WP_Error.
 *
 * @since 1.3.8
 */
final class Errors {

	/**
	 * Builds an error response.
	 *
	 * @since 1.3.8
	 *
	 * @param ErrorCode $code    The error code.
	 * @param string    $message The error message.
	 * @param array     $data    Extra data. `status` is always set from the code.
	 * @param array     $legacy  Top-level fields kept for existing callers.
	 *
	 * @return WP_REST_Response
	 */
	public static function response( ErrorCode $code, string $message, array $data = [], array $legacy = [] ): WP_REST_Response {
		$status         = $code->status();
		$data['status'] = $status;

		if ( ErrorCode::DatabaseError === $code ) {
			$data['retry_after'] = 1;
		}

		$body = array_merge(
			$legacy,
			[
				'code'    => $code->value,
				'message' => $message,
				'data'    => $data,
			]
		);

		$response = new WP_REST_Response( $body, $status );

		if ( ErrorCode::DatabaseError === $code ) {
			$response->header( 'Retry-After', '1' );
		}

		return $response;
	}
}
