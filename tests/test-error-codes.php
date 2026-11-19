<?php

use Mai\Analytics\ErrorCode;

/**
 * Pins every error code and status as literals, so a rename or a status
 * change can't pass silently. Callers such as the app and the admin screens
 * read these strings.
 */
class Test_Error_Codes extends WP_UnitTestCase {

	/**
	 * Every case, with the code string and status it must answer.
	 */
	public function codes(): array {
		return [
			'InvalidRequest'        => [ ErrorCode::InvalidRequest, 'mai_analytics_invalid_request', 400 ],
			'NotFound'              => [ ErrorCode::NotFound, 'mai_analytics_not_found', 404 ],
			'Bot'                   => [ ErrorCode::Bot, 'mai_analytics_bot', 403 ],
			'ProviderUnavailable'   => [ ErrorCode::ProviderUnavailable, 'mai_analytics_provider_unavailable', 501 ],
			'ProviderDisabled'      => [ ErrorCode::ProviderDisabled, 'mai_analytics_provider_disabled', 501 ],
			'ProviderRequestFailed' => [ ErrorCode::ProviderRequestFailed, 'mai_analytics_provider_request_failed', 502 ],
			'DatabaseError'         => [ ErrorCode::DatabaseError, 'mai_analytics_database_error', 503 ],
		];
	}

	/**
	 * @dataProvider codes
	 */
	public function test_code_and_status( ErrorCode $code, string $value, int $status ): void {
		$this->assertSame( $value, $code->value );
		$this->assertSame( $status, $code->status() );
	}

	public function test_every_case_is_pinned(): void {
		$this->assertCount( count( ErrorCode::cases() ), $this->codes() );
	}
}
