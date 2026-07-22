<?php

use Mai\Analytics\Providers\SiteKit;
use Mai\Analytics\Sync;
use Google\Site_Kit\Plugin as Site_Kit_Plugin;
use Google\Site_Kit\Modules\Analytics_4;
use Mai\Analytics\Tests\Fake_Report;

require_once __DIR__ . '/stubs/site-kit.php';

/**
 * Covers the Site Kit provider's owner binding, all-time date resolution, and
 * failure semantics. Site Kit itself is absent; tests/stubs/site-kit.php supplies
 * the three classes the provider duck-types against.
 *
 * @since 1.3.3
 */
class Test_Providers_SiteKit extends WP_UnitTestCase {

	private $owner_id;

	public function setUp(): void {
		parent::setUp();

		$this->reset_state();

		Site_Kit_Plugin::$instance = new Site_Kit_Plugin();

		$this->owner_id = self::factory()->user->create( [ 'role' => 'administrator' ] );
	}

	public function tearDown(): void {
		$this->reset_state();

		Site_Kit_Plugin::$instance = null;

		parent::tearDown();
	}

	private function reset_state(): void {
		delete_option( 'googlesitekit_analytics-4_settings' );
		delete_option( 'googlesitekit_owner_id' );
		delete_option( 'googlesitekit_first_admin' );
		delete_option( 'mai_analytics_provider_error' );

		Analytics_4::reset();
	}

	/**
	 * Stores GA4 module settings with the created owner, plus any extra keys.
	 *
	 * @param array $extra Additional settings keys.
	 *
	 * @return void
	 */
	private function set_module_settings( array $extra = [] ): void {
		update_option( 'googlesitekit_analytics-4_settings', array_merge(
			[ 'ownerID' => $this->owner_id ],
			$extra
		) );
	}

	/**
	 * A trending window plus an all-time window (empty start).
	 *
	 * @return array
	 */
	private function windows(): array {
		return [
			'trending' => [ '2026-07-14', '2026-07-21' ],
			'all_time' => [ '', '2026-07-21' ],
		];
	}

	private function get_views( ?array $windows = null ): ?array {
		$provider = new SiteKit();

		return $provider->get_views( [ '/post-a/' ], $windows ?? $this->windows() );
	}

	// -----------------------------------------------------------------
	// Owner resolution
	// -----------------------------------------------------------------

	public function test_owner_id_prefers_module_owner(): void {
		update_option( 'googlesitekit_analytics-4_settings', [ 'ownerID' => 11 ] );
		update_option( 'googlesitekit_owner_id', 22 );
		update_option( 'googlesitekit_first_admin', 33 );

		$this->assertSame( 11, SiteKit::get_owner_id() );
	}

	public function test_owner_id_falls_back_to_site_wide_option(): void {
		update_option( 'googlesitekit_owner_id', 22 );
		update_option( 'googlesitekit_first_admin', 33 );

		$this->assertSame( 22, SiteKit::get_owner_id() );
	}

	public function test_owner_id_falls_back_to_legacy_option(): void {
		update_option( 'googlesitekit_first_admin', 33 );

		$this->assertSame( 33, SiteKit::get_owner_id() );
	}

	public function test_owner_id_is_zero_when_nothing_is_set(): void {
		$this->assertSame( 0, SiteKit::get_owner_id() );
	}

	public function test_no_owner_stores_an_error_and_returns_null(): void {
		$this->assertNull( $this->get_views() );
		$this->assertNotEmpty( SiteKit::get_last_error() );
	}

	public function test_missing_owner_user_stores_an_actionable_error(): void {
		update_option( 'googlesitekit_analytics-4_settings', [ 'ownerID' => 999999 ] );

		$this->assertNull( $this->get_views() );
		$this->assertStringContainsString( '999999', SiteKit::get_last_error() );
	}

	// -----------------------------------------------------------------
	// Owner binding — the actual fix
	// -----------------------------------------------------------------

	public function test_module_is_bound_to_the_owner(): void {
		$this->set_module_settings();

		$this->get_views();

		$this->assertNotEmpty( Analytics_4::$calls );

		foreach ( Analytics_4::$calls as $call ) {
			$this->assertSame( $this->owner_id, $call['owner_id'] );
		}
	}

	public function test_current_user_is_never_switched(): void {
		$this->set_module_settings();

		$before = get_current_user_id();

		$this->get_views();

		$this->assertSame( $before, get_current_user_id() );
	}

	// -----------------------------------------------------------------
	// All-time window — the second bug
	// -----------------------------------------------------------------

	public function test_all_time_window_always_sends_an_explicit_start_date(): void {
		$this->set_module_settings();

		$this->get_views();

		foreach ( Analytics_4::$calls as $call ) {
			$this->assertNotEmpty( $call['params']['startDate'] );
			$this->assertNotFalse( strtotime( $call['params']['startDate'] ) );
			$this->assertNotEmpty( $call['params']['endDate'] );
			$this->assertNotFalse( strtotime( $call['params']['endDate'] ) );
		}
	}

	public function test_all_time_start_uses_property_create_time(): void {
		// 2021-06-01 UTC, in milliseconds. Backed off one day for property timezone.
		$this->set_module_settings( [ 'propertyCreateTime' => 1622505600000 ] );

		$this->get_views();

		$this->assertSame( '2021-05-31', Analytics_4::$calls[1]['params']['startDate'] );
	}

	public function test_all_time_start_falls_back_when_create_time_is_absent(): void {
		$this->set_module_settings();

		$this->get_views();

		$this->assertSame( '2019-01-01', Analytics_4::$calls[1]['params']['startDate'] );
	}

	/**
	 * A non-millisecond propertyCreateTime floors to 0, which would yield
	 * 1970-01-01 — whose strtotime() is falsy, so Site Kit would silently
	 * substitute the last 28 days and resurrect the bug this release fixes.
	 *
	 * @return void
	 */
	public function test_all_time_start_rejects_unusable_create_time(): void {
		$this->set_module_settings( [ 'propertyCreateTime' => 999 ] );

		$this->get_views();

		$this->assertSame( '2019-01-01', Analytics_4::$calls[1]['params']['startDate'] );
	}

	public function test_all_time_start_rejects_future_create_time(): void {
		$this->set_module_settings( [ 'propertyCreateTime' => ( time() + YEAR_IN_SECONDS ) * 1000 ] );

		$this->get_views();

		$this->assertSame( '2019-01-01', Analytics_4::$calls[1]['params']['startDate'] );
	}

	public function test_empty_end_date_is_defended(): void {
		$this->set_module_settings();

		$this->get_views( [ 'all_time' => [ '', '' ] ] );

		$this->assertSame( gmdate( 'Y-m-d' ), Analytics_4::$calls[0]['params']['endDate'] );
	}

	// -----------------------------------------------------------------
	// Response parsing
	// -----------------------------------------------------------------

	public function test_parses_object_shaped_response(): void {
		$this->set_module_settings();

		Analytics_4::$responses = [
			Fake_Report::from( [ '/post-a/' => 12 ] ),
			Fake_Report::from( [ '/post-a/' => 40 ] ),
		];

		$this->assertSame(
			[ '/post-a/' => [ 'trending' => 12, 'all_time' => 40 ] ],
			$this->get_views()
		);
	}

	public function test_parses_array_shaped_response(): void {
		$this->set_module_settings();

		$payload = [
			'rowCount' => 1,
			'rows'     => [
				[
					'dimensionValues' => [ [ 'value' => '/post-a/' ] ],
					'metricValues'    => [ [ 'value' => '7' ] ],
				],
			],
		];

		Analytics_4::$responses = [ $payload, $payload ];

		$this->assertSame(
			[ '/post-a/' => [ 'trending' => 7, 'all_time' => 7 ] ],
			$this->get_views()
		);
	}

	/**
	 * A report with no data still reports rowCount, so it must parse as a
	 * successful empty result — [] rather than null — or the caller would treat
	 * a zero-traffic batch as an outage and never stamp it as synced.
	 *
	 * @return void
	 */
	public function test_empty_but_valid_array_response_is_not_an_error(): void {
		$this->set_module_settings();

		Analytics_4::$responses = [ [ 'rowCount' => 0 ], [ 'rowCount' => 0 ] ];

		$this->assertSame( [], $this->get_views() );
		$this->assertSame( '', SiteKit::get_last_error() );
	}

	/**
	 * An unrecognized response must never look like "no traffic" — that would
	 * stop syncing indefinitely with a green status and no logged error.
	 *
	 * @return void
	 */
	public function test_unrecognized_response_shape_is_reported_as_an_error(): void {
		$this->set_module_settings();

		Analytics_4::$responses = [ 'not a report' ];

		$this->assertNull( $this->get_views() );
		$this->assertStringContainsString( 'Unrecognized', SiteKit::get_last_error() );
	}

	public function test_unrecognized_row_shape_is_reported_as_an_error(): void {
		$this->set_module_settings();

		Analytics_4::$responses = [ new Fake_Report( [ new stdClass() ] ) ];

		$this->assertNull( $this->get_views() );
		$this->assertStringContainsString( 'Unrecognized', SiteKit::get_last_error() );
	}

	// -----------------------------------------------------------------
	// Failure semantics
	// -----------------------------------------------------------------

	public function test_wp_error_returns_null_and_stores_the_message(): void {
		$this->set_module_settings();

		Analytics_4::$responses = [ new WP_Error( 'boom', 'Insufficient scopes.' ) ];

		$this->assertNull( $this->get_views() );
		$this->assertStringContainsString( 'Insufficient scopes.', SiteKit::get_last_error() );
	}

	public function test_thrown_error_returns_null_and_stores_the_message(): void {
		$this->set_module_settings();

		Analytics_4::$responses = [ new TypeError( 'Too few arguments' ) ];

		$this->assertNull( $this->get_views() );
		$this->assertStringContainsString( 'Too few arguments', SiteKit::get_last_error() );
	}

	public function test_stored_error_carries_window_context(): void {
		$this->set_module_settings();

		Analytics_4::$responses = [ new WP_Error( 'boom', 'Invalid argument.' ) ];

		$this->get_views();

		$this->assertStringContainsString( 'trending', SiteKit::get_last_error() );
	}

	/**
	 * Once a window fails the return value is committed to [], so remaining
	 * windows must not spend another authenticated round trip.
	 *
	 * @return void
	 */
	public function test_failure_stops_further_windows(): void {
		$this->set_module_settings();

		Analytics_4::$responses = [ new WP_Error( 'boom', 'Invalid argument.' ) ];

		$this->get_views();

		$this->assertCount( 1, Analytics_4::$calls );
	}

	public function test_success_clears_a_previous_provider_error(): void {
		$this->set_module_settings();

		Sync::set_provider_error( 'stale failure' );

		Analytics_4::$responses = [
			Fake_Report::from( [ '/post-a/' => 3 ] ),
			Fake_Report::from( [ '/post-a/' => 9 ] ),
		];

		$this->get_views();

		$this->assertSame( '', SiteKit::get_last_error() );
	}

	public function test_no_paths_short_circuits_without_calling_site_kit(): void {
		$this->set_module_settings();

		$provider = new SiteKit();

		$this->assertSame( [], $provider->get_views( [], $this->windows() ) );
		$this->assertEmpty( Analytics_4::$calls );
	}
}
