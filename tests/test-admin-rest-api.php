<?php

use Mai\Analytics\Database;
use Mai\Analytics\Sync;

class Test_Admin_REST_API extends WP_UnitTestCase {

	private WP_REST_Server $server;

	public function setUp(): void {
		parent::setUp();
		Database::create_table();

		global $wp_rest_server;
		$this->server = $wp_rest_server = new WP_REST_Server();
		do_action( 'rest_api_init' );
	}

	public function tearDown(): void {
		global $wpdb, $wp_rest_server;
		$wpdb->query( 'TRUNCATE TABLE ' . Database::get_table_name() );
		$wp_rest_server = null;
		parent::tearDown();
	}

	public function test_unauthenticated_user_denied(): void {
		wp_set_current_user( 0 );

		$request  = new WP_REST_Request( 'GET', '/mai-analytics/v1/admin/summary' );
		$response = $this->server->dispatch( $request );

		$this->assertEquals( 401, $response->get_status() );
	}

	public function test_subscriber_denied(): void {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'subscriber' ] ) );

		$request  = new WP_REST_Request( 'GET', '/mai-analytics/v1/admin/summary' );
		$response = $this->server->dispatch( $request );

		$this->assertEquals( 403, $response->get_status() );
	}

	public function test_editor_allowed(): void {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'editor' ] ) );

		$request  = new WP_REST_Request( 'GET', '/mai-analytics/v1/admin/summary' );
		$response = $this->server->dispatch( $request );

		$this->assertEquals( 200, $response->get_status() );
	}

	public function test_summary_returns_expected_shape(): void {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'editor' ] ) );

		$request  = new WP_REST_Request( 'GET', '/mai-analytics/v1/admin/summary' );
		$data     = $this->server->dispatch( $request )->get_data();

		$this->assertArrayHasKey( 'total_views', $data );
		$this->assertArrayHasKey( 'views_today', $data );
		$this->assertArrayHasKey( 'trending_count', $data );
		$this->assertArrayHasKey( 'buffer_rows', $data );
		$this->assertArrayHasKey( 'last_sync', $data );
	}

	public function test_summary_counts_views(): void {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'editor' ] ) );

		$post_id = self::factory()->post->create();
		update_post_meta( $post_id, 'mai_analytics_views', 42 );

		$request = new WP_REST_Request( 'GET', '/mai-analytics/v1/admin/summary' );
		$data    = $this->server->dispatch( $request )->get_data();

		$this->assertGreaterThanOrEqual( 42, $data['total_views'] );
	}

	public function test_chart_returns_7_labels(): void {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'editor' ] ) );

		$request = new WP_REST_Request( 'GET', '/mai-analytics/v1/admin/chart' );
		$data    = $this->server->dispatch( $request )->get_data();

		$this->assertCount( 7, $data['labels'] );
		$this->assertNotEmpty( $data['datasets'] );
	}

	public function test_chart_source_filter(): void {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'editor' ] ) );

		$post_id = self::factory()->post->create( [ 'post_status' => 'publish' ] );
		Database::insert_view( $post_id, 'post', 'web' );
		Database::insert_view( $post_id, 'post', 'app' );

		$request = new WP_REST_Request( 'GET', '/mai-analytics/v1/admin/chart' );
		$request->set_param( 'source', 'web' );
		$data = $this->server->dispatch( $request )->get_data();

		$this->assertCount( 1, $data['datasets'] );
		$this->assertStringContainsString( 'Web', $data['datasets'][0]['label'] );
	}

	public function test_top_posts_pagination(): void {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'editor' ] ) );

		// Create 3 posts with views.
		for ( $i = 0; $i < 3; $i++ ) {
			$id = self::factory()->post->create( [ 'post_status' => 'publish' ] );
			update_post_meta( $id, 'mai_analytics_views', 100 - $i );
		}

		$request = new WP_REST_Request( 'GET', '/mai-analytics/v1/admin/top/posts' );
		$request->set_param( 'per_page', 2 );
		$request->set_param( 'page', 1 );
		$data = $this->server->dispatch( $request )->get_data();

		$this->assertCount( 2, $data['items'] );
		$this->assertEquals( 3, $data['total'] );
		$this->assertEquals( 2, $data['pages'] );
	}

	public function test_top_posts_ordered_by_views(): void {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'editor' ] ) );

		$id1 = self::factory()->post->create( [ 'post_status' => 'publish' ] );
		$id2 = self::factory()->post->create( [ 'post_status' => 'publish' ] );
		update_post_meta( $id1, 'mai_analytics_views', 50 );
		update_post_meta( $id2, 'mai_analytics_views', 200 );

		$request = new WP_REST_Request( 'GET', '/mai-analytics/v1/admin/top/posts' );
		$data    = $this->server->dispatch( $request )->get_data();

		$this->assertEquals( $id2, $data['items'][0]['id'] );
	}

	public function test_top_terms(): void {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'editor' ] ) );

		$term_id = self::factory()->category->create();
		update_term_meta( $term_id, 'mai_analytics_views', 75 );

		$request = new WP_REST_Request( 'GET', '/mai-analytics/v1/admin/top/terms' );
		$data    = $this->server->dispatch( $request )->get_data();

		$this->assertGreaterThanOrEqual( 1, count( $data['items'] ) );
		$this->assertEquals( $term_id, $data['items'][0]['id'] );
	}

	public function test_top_authors(): void {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'editor' ] ) );

		$user_id = self::factory()->user->create();
		update_user_meta( $user_id, 'mai_analytics_views', 30 );

		$request = new WP_REST_Request( 'GET', '/mai-analytics/v1/admin/top/authors' );
		$data    = $this->server->dispatch( $request )->get_data();

		$this->assertGreaterThanOrEqual( 1, count( $data['items'] ) );
	}

	public function test_filters_returns_expected_shape(): void {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'editor' ] ) );

		$request = new WP_REST_Request( 'GET', '/mai-analytics/v1/admin/filters' );
		$data    = $this->server->dispatch( $request )->get_data();

		$this->assertArrayHasKey( 'post_types', $data );
		$this->assertArrayHasKey( 'taxonomies', $data );
		$this->assertArrayHasKey( 'authors', $data );
		$this->assertArrayHasKey( 'terms', $data );
	}

	public function test_empty_state_returns_gracefully(): void {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'editor' ] ) );

		$request = new WP_REST_Request( 'GET', '/mai-analytics/v1/admin/top/posts' );
		$data    = $this->server->dispatch( $request )->get_data();

		$this->assertEmpty( $data['items'] );
		$this->assertEquals( 0, $data['total'] );
	}
}
