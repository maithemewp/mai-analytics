<?php

use Mai\Analytics\Database;
use Mai\Analytics\ErrorCode;
use Mai\Analytics\Errors;

/**
 * Error mapping contract cases, rewritten from mai-items-core's spec against
 * mai-analytics' own routes.
 */
class Test_REST_Errors extends WP_UnitTestCase {

	private WP_REST_Server $server;

	public function setUp(): void {
		parent::setUp();
		Database::create_table();

		global $wp_rest_server;
		$this->server = $wp_rest_server = new WP_REST_Server();
		do_action( 'rest_api_init' );
	}

	public function tearDown(): void {
		global $wp_rest_server;
		$wp_rest_server = null;
		parent::tearDown();
	}

	private function top_posts_over_limit(): WP_REST_Response {
		$request = new WP_REST_Request( 'GET', '/mai-analytics/v1/admin/top/posts' );
		$request->set_param( 'per_page', 500 );

		return $this->server->dispatch( $request );
	}

	public function test_signed_out_bad_arg_on_admin_route_gets_permission_error(): void {
		wp_set_current_user( 0 );

		$response = $this->top_posts_over_limit();

		$this->assertSame( 401, $response->get_status() );
		$this->assertSame( 'rest_forbidden', $response->get_data()['code'] );
	}

	public function test_subscriber_bad_arg_gets_403(): void {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'subscriber' ] ) );

		$response = $this->top_posts_over_limit();

		$this->assertSame( 403, $response->get_status() );
		$this->assertSame( 'rest_forbidden', $response->get_data()['code'] );
	}

	public function test_editor_bad_arg_gets_invalid_request(): void {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'editor' ] ) );

		$response = $this->top_posts_over_limit();
		$data     = $response->get_data();

		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( ErrorCode::InvalidRequest->value, $data['code'] );
		$this->assertSame( 400, $data['data']['status'] );
		$this->assertArrayHasKey( 'per_page', $data['data']['params'] );
	}

	public function test_permission_callback_runs_once(): void {
		wp_set_current_user( 0 );

		$calls = 0;
		add_filter(
			'user_has_cap',
			function ( $allcaps, $caps ) use ( &$calls ) {
				if ( in_array( 'edit_others_posts', $caps, true ) ) {
					$calls++;
				}

				return $allcaps;
			},
			10,
			2
		);

		$this->top_posts_over_limit();

		$this->assertSame( 1, $calls );
	}

	public function test_public_route_bad_arg_gets_invalid_request(): void {
		$request = new WP_REST_Request( 'GET', '/mai-analytics/v1/views/trending' );
		$request->set_param( 'per_page', 0 );
		$response = $this->server->dispatch( $request );

		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( ErrorCode::InvalidRequest->value, $response->get_data()['code'] );
	}

	public function test_bad_source_gets_invalid_request(): void {
		$post_id = self::factory()->post->create( [ 'post_status' => 'publish' ] );
		$request = new WP_REST_Request( 'POST', '/mai-analytics/v1/view/post/' . $post_id );
		$request->set_header( 'User-Agent', 'Mozilla/5.0' );
		$request->set_param( 'source', 'bad' );
		$response = $this->server->dispatch( $request );
		$data     = $response->get_data();

		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( ErrorCode::InvalidRequest->value, $data['code'] );
		$this->assertArrayHasKey( 'source', $data['data']['params'] );
	}

	public function test_missing_param_gets_invalid_request(): void {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'editor' ] ) );

		$request  = new WP_REST_Request( 'GET', '/mai-analytics/v1/admin/search' );
		$response = $this->server->dispatch( $request );

		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( ErrorCode::InvalidRequest->value, $response->get_data()['code'] );
	}

	public function test_bad_json_gets_invalid_request(): void {
		$post_id = self::factory()->post->create( [ 'post_status' => 'publish' ] );
		$request = new WP_REST_Request( 'POST', '/mai-analytics/v1/view/post/' . $post_id );
		$request->set_header( 'User-Agent', 'Mozilla/5.0' );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body( '{' );
		$response = $this->server->dispatch( $request );

		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( ErrorCode::InvalidRequest->value, $response->get_data()['code'] );
	}

	public function test_route_in_other_case_is_mapped(): void {
		$request = new WP_REST_Request( 'GET', '/MAI-ANALYTICS/v1/views/trending' );
		$request->set_param( 'per_page', 0 );
		$response = $this->server->dispatch( $request );

		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( ErrorCode::InvalidRequest->value, $response->get_data()['code'] );
	}

	public function test_other_namespace_passes_through(): void {
		register_rest_route(
			'mai-analytics-test/v1',
			'/echo',
			[
				'methods'             => 'GET',
				'callback'            => fn() => new WP_REST_Response( [] ),
				'permission_callback' => '__return_true',
				'args'                => [
					'thing' => [
						'validate_callback' => '__return_false',
					],
				],
			]
		);

		$request = new WP_REST_Request( 'GET', '/mai-analytics-test/v1/echo' );
		$request->set_param( 'thing', 'x' );
		$response = $this->server->dispatch( $request );

		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'rest_invalid_param', $response->get_data()['code'] );
	}

	public function test_throwing_permission_callback_is_refused(): void {
		register_rest_route(
			'mai-analytics/v1',
			'/throws',
			[
				'methods'             => 'GET',
				'callback'            => fn() => new WP_REST_Response( [] ),
				'permission_callback' => function () {
					throw new RuntimeException( 'boom' );
				},
				'args'                => [
					'thing' => [
						'validate_callback' => '__return_false',
					],
				],
			]
		);

		wp_set_current_user( self::factory()->user->create( [ 'role' => 'editor' ] ) );

		$request = new WP_REST_Request( 'GET', '/mai-analytics/v1/throws' );
		$request->set_param( 'thing', 'x' );
		$response = $this->server->dispatch( $request );

		$this->assertSame( 403, $response->get_status() );
		$this->assertSame( 'rest_forbidden', $response->get_data()['code'] );
	}

	public function test_filter_is_added_once(): void {
		global $wp_filter;

		\Mai\Analytics\RestErrors::map( 'mai-analytics/v1', 'mai_analytics' );
		\Mai\Analytics\RestErrors::map( 'mai-analytics/v1', 'mai_analytics' );

		$this->assertCount( 1, $wp_filter['rest_request_before_callbacks']->callbacks[99] );
	}

	public function test_no_route_is_untouched(): void {
		$request  = new WP_REST_Request( 'POST', '/mai-analytics/v1/view/post/abc' );
		$response = $this->server->dispatch( $request );

		$this->assertSame( 404, $response->get_status() );
		$this->assertSame( 'rest_no_route', $response->get_data()['code'] );
	}

	public function test_mapped_code_comes_from_the_slug(): void {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'editor' ] ) );

		// RestErrors builds the code from the slug, not from the enum, so this
		// pins that the two agree.
		$this->assertSame( ErrorCode::InvalidRequest->value, $this->top_posts_over_limit()->get_data()['code'] );
	}

	public function test_mapping_survives_between_tests(): void {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'editor' ] ) );

		$response = $this->top_posts_over_limit();

		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( ErrorCode::InvalidRequest->value, $response->get_data()['code'] );
	}

	public function test_database_error_response_shape(): void {
		$response = Errors::response( ErrorCode::DatabaseError, 'x', [ 'status' => 200 ], [ 'success' => false ] );
		$data     = $response->get_data();
		$headers  = $response->get_headers();

		$this->assertSame( 503, $response->get_status() );
		$this->assertSame( 503, $data['data']['status'] );
		$this->assertSame( 1, $data['data']['retry_after'] );
		$this->assertSame( '1', $headers['Retry-After'] );
		$this->assertFalse( $data['success'] );
		$this->assertSame( ErrorCode::DatabaseError->value, $data['code'] );
	}
}
