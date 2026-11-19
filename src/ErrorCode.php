<?php

declare( strict_types=1 );

namespace Mai\Analytics;

// Prevent direct file access.
defined( 'ABSPATH' ) || die;

/**
 * Every error code the plugin answers, with its HTTP status.
 *
 * The code strings live here, so a rename touches one file. The one exception
 * is `mai_analytics_invalid_request` for core's argument errors, which
 * RestErrors builds from the slug it is given. A test pins that the two agree.
 *
 * @since 1.3.8
 */
enum ErrorCode: string {
	case InvalidRequest        = 'mai_analytics_invalid_request';
	case NotFound              = 'mai_analytics_not_found';
	case Bot                   = 'mai_analytics_bot';
	case ProviderUnavailable   = 'mai_analytics_provider_unavailable';
	case ProviderRequired      = 'mai_analytics_provider_required';
	case ProviderRequestFailed = 'mai_analytics_provider_request_failed';
	case DatabaseError         = 'mai_analytics_database_error';
	case Busy                  = 'mai_analytics_busy';

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
			self::ProviderUnavailable, self::ProviderRequired => 501,
			self::Busy                                        => 429,
			self::ProviderRequestFailed                       => 502,
			self::DatabaseError                               => 503,
		};
	}

	/**
	 * Whether a caller should wait and retry once, per the stack's rule that
	 * every 429 and 503 carries data.retry_after and a Retry-After header.
	 *
	 * @since 1.3.8
	 *
	 * @return bool
	 */
	public function is_retryable(): bool {
		return self::Busy === $this || self::DatabaseError === $this;
	}
}
