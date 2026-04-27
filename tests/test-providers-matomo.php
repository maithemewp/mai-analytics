<?php

use Mai\Analytics\Providers\Matomo;

/**
 * Wire-level tests for the Matomo provider's bulk multi-window request.
 *
 * These tests intercept wp_remote_post via the pre_http_request filter so we
 * can assert on the request body the provider builds and synthesize the
 * corresponding bulk response. They pin the wire format so future edits can't
 * silently regress the per-(path, window) index mapping or the period/date
 * translation that powers Matomo correctness.
 */
class Test_Providers_Matomo extends WP_UnitTestCase {

	/** @var array Last captured request body keyed by URL. */
	private static array $captured = [];

	/** @var mixed Predefined response body to return next. */
	private static $next_response = null;

	public function setUp(): void {
		parent::setUp();

		update_option( 'mai_analytics_settings', [
			'data_source'    => 'matomo',
			'matomo_url'     => 'https://example.test/',
			'matomo_site_id' => '1',
			'matomo_token'   => 'test-token',
		] );

		self::$captured      = [];
		self::$next_response = null;

		add_filter( 'pre_http_request', [ __CLASS__, 'capture_request' ], 10, 3 );
	}

	public function tearDown(): void {
		remove_filter( 'pre_http_request', [ __CLASS__, 'capture_request' ], 10 );
		delete_option( 'mai_analytics_settings' );
		self::$captured      = [];
		self::$next_response = null;
		parent::tearDown();
	}

	/**
	 * Captures the outgoing request body so the test can assert on it,
	 * and returns a canned successful response.
	 */
	public static function capture_request( $preempt, array $args, string $url ) {
		self::$captured[] = [
			'url'  => $url,
			'body' => $args['body'] ?? null,
		];

		$body = self::$next_response !== null
			? self::$next_response
			: '[]';

		return [
			'response' => [ 'code' => 200, 'message' => 'OK' ],
			'body'     => is_string( $body ) ? $body : wp_json_encode( $body ),
			'headers'  => [],
		];
	}

	/**
	 * The bulk request must contain one urls[] entry per (path, window) pair,
	 * ordered paths × windows in caller-provided order.
	 */
	public function test_bulk_request_packs_paths_times_windows(): void {
		$provider = new Matomo();

		$provider->get_views(
			[ '/post-a/', '/post-b/' ],
			[
				'trending' => [ '2026-04-01', '2026-04-27' ],
				'all_time' => [ '', '2026-04-27' ],
			]
		);

		$this->assertCount( 1, self::$captured, 'Expected exactly one HTTP roundtrip.' );

		$urls = self::$captured[0]['body']['urls'] ?? [];

		$this->assertCount( 4, $urls, 'urls[] must contain paths × windows entries.' );

		// Order: post-a/trending, post-a/all_time, post-b/trending, post-b/all_time.
		$entries = array_map( function ( $q ) {
			parse_str( $q, $parsed );
			return $parsed;
		}, $urls );

		$this->assertStringContainsString( '/post-a/', $entries[0]['pageUrl'] );
		$this->assertStringContainsString( '/post-a/', $entries[1]['pageUrl'] );
		$this->assertStringContainsString( '/post-b/', $entries[2]['pageUrl'] );
		$this->assertStringContainsString( '/post-b/', $entries[3]['pageUrl'] );

		// Trending sub-queries (period=day) come before all-time (period=week)
		// within each path's group.
		$this->assertEquals( 'day',  $entries[0]['period'] );
		$this->assertEquals( 'week', $entries[1]['period'] );
		$this->assertEquals( 'day',  $entries[2]['period'] );
		$this->assertEquals( 'week', $entries[3]['period'] );
	}

	/**
	 * Empty start_date in a window must produce period=week, date=last{years*52}.
	 */
	public function test_empty_start_translates_to_weekly_period(): void {
		// Force a known views_years so the assertion is stable.
		add_filter( 'mai_analytics_views_years', fn() => 3 );

		$provider = new Matomo();
		$provider->get_views(
			[ '/post-a/' ],
			[ 'all_time' => [ '', '2026-04-27' ] ]
		);

		remove_all_filters( 'mai_analytics_views_years' );

		$urls = self::$captured[0]['body']['urls'] ?? [];
		parse_str( $urls[0], $entry );

		$this->assertEquals( 'week', $entry['period'] );
		$this->assertEquals( 'last' . ( 3 * 52 ), $entry['date'] );
	}

	/**
	 * Non-empty start_date must produce period=day, date=last{days}.
	 */
	public function test_non_empty_start_translates_to_daily_period(): void {
		$provider = new Matomo();
		$provider->get_views(
			[ '/post-a/' ],
			[ 'trending' => [ '2026-04-20', '2026-04-27' ] ]
		);

		$urls = self::$captured[0]['body']['urls'] ?? [];
		parse_str( $urls[0], $entry );

		$this->assertEquals( 'day', $entry['period'] );
		// 7 days between start and end.
		$this->assertEquals( 'last7', $entry['date'] );
	}

	/**
	 * Parser must map response indexes back to the correct (path, window) pair.
	 *
	 * For 2 paths × 2 windows, the response is a flat 4-element array:
	 *   index 0 = post-a/trending
	 *   index 1 = post-a/all_time
	 *   index 2 = post-b/trending
	 *   index 3 = post-b/all_time
	 */
	public function test_response_index_maps_back_to_path_and_window(): void {
		// Each row is itself an array of period buckets containing nb_visits.
		// We use a single bucket per row so the values are unambiguous.
		self::$next_response = [
			[ [ 'nb_visits' => 11 ] ], // post-a/trending
			[ [ 'nb_visits' => 22 ] ], // post-a/all_time
			[ [ 'nb_visits' => 33 ] ], // post-b/trending
			[ [ 'nb_visits' => 44 ] ], // post-b/all_time
		];

		$provider = new Matomo();
		$result   = $provider->get_views(
			[ '/post-a/', '/post-b/' ],
			[
				'trending' => [ '2026-04-01', '2026-04-27' ],
				'all_time' => [ '', '2026-04-27' ],
			]
		);

		$this->assertEquals( 11, $result['/post-a/']['trending'] ?? null );
		$this->assertEquals( 22, $result['/post-a/']['all_time'] ?? null );
		$this->assertEquals( 33, $result['/post-b/']['trending'] ?? null );
		$this->assertEquals( 44, $result['/post-b/']['all_time'] ?? null );
	}
}
