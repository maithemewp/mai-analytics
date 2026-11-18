<?php

declare( strict_types=1 );

namespace Mai\Analytics;

// Prevent direct file access.
defined( 'ABSPATH' ) || die;

/**
 * Every error code the plugin answers, with its HTTP status.
 *
 * The code strings live only here, so a rename touches one file.
 *
 * @since 1.3.8
 */
enum ErrorCode: string {
	case InvalidRequest        = 'mai_analytics_invalid_request';
	case NotFound              = 'mai_analytics_not_found';
	case Bot                   = 'mai_analytics_bot';
	case ProviderUnavailable   = 'mai_analytics_provider_unavailable';
	case ProviderDisabled      = 'mai_analytics_provider_disabled';
	case ProviderRequestFailed = 'mai_analytics_provider_request_failed';
	case DatabaseError         = 'mai_analytics_database_error';

	/**
	 * Gets the HTTP status for this error.
	 *
	 * @since 1.3.8
	 *
	 * @return int
	 */
	public function status(): int {
		return match ( $this ) {
			self::InvalidRequest                              => 400,
			self::Bot                                         => 403,
			self::NotFound                                    => 404,
			self::ProviderUnavailable, self::ProviderDisabled => 501,
			self::ProviderRequestFailed                       => 502,
			self::DatabaseError                               => 503,
		};
	}

	/**
	 * Builds a WP_Error for this code. The status always comes from the case,
	 * overwriting any status in `$data`.
	 *
	 * A database error is answered through Errors::response() instead, because
	 * only a response can carry the Retry-After header.
	 *
	 * @since 1.3.8
	 *
	 * @param string $message The error message.
	 * @param array  $data    Extra data for the error.
	 *
	 * @return \WP_Error
	 */
	public function error( string $message, array $data = [] ): \WP_Error {
		$data['status'] = $this->status();

		return new \WP_Error( $this->value, $message, $data );
	}
}
