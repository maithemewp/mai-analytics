<?php

use Mai\Views\Database;

class Test_REST_API extends WP_UnitTestCase {

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

	public function test_record_post_view(): void {
		$post_id = self::factory()->post->create( [ 'post_status' => 'publish' ] );
		$request = new WP_REST_Request( 'POST', '/mai-views/v1/view/post/' . $post_id );
		$request->set_header( 'User-Agent', 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7)' );

		$response = $this->server->dispatch( $request );

		$this->assertEquals( 200, $response->get_status() );
		$this->assertTrue( $response->get_data()['success'] );
	}

	public function test_record_term_view(): void {
		$term_id = self::factory()->category->create();
		$request = new WP_REST_Request( 'POST', '/mai-views/v1/view/term/' . $term_id );
		$request->set_header( 'User-Agent', 'Mozilla/5.0' );

		$this->assertEquals( 200, $this->server->dispatch( $request )->get_status() );
	}

	public function test_record_user_view(): void {
		$user_id = self::factory()->user->create();
		$request = new WP_REST_Request( 'POST', '/mai-views/v1/view/user/' . $user_id );
		$request->set_header( 'User-Agent', 'Mozilla/5.0' );

		$this->assertEquals( 200, $this->server->dispatch( $request )->get_status() );
	}

	public function test_reject_invalid_post_id(): void {
		$request = new WP_REST_Request( 'POST', '/mai-views/v1/view/post/999999' );
		$request->set_header( 'User-Agent', 'Mozilla/5.0' );

		$this->assertEquals( 404, $this->server->dispatch( $request )->get_status() );
	}

	public function test_reject_draft_post(): void {
		$post_id = self::factory()->post->create( [ 'post_status' => 'draft' ] );
		$request = new WP_REST_Request( 'POST', '/mai-views/v1/view/post/' . $post_id );
		$request->set_header( 'User-Agent', 'Mozilla/5.0' );

		$this->assertEquals( 404, $this->server->dispatch( $request )->get_status() );
	}

	public function test_reject_bot_user_agent(): void {
		$post_id = self::factory()->post->create( [ 'post_status' => 'publish' ] );
		$request = new WP_REST_Request( 'POST', '/mai-views/v1/view/post/' . $post_id );
		$request->set_header( 'User-Agent', 'Googlebot/2.1' );

		$this->assertEquals( 403, $this->server->dispatch( $request )->get_status() );
	}

	public function test_get_post_views(): void {
		$post_id = self::factory()->post->create();
		update_post_meta( $post_id, 'mai_views', 42 );
		update_post_meta( $post_id, 'mai_trending', 7 );

		$request  = new WP_REST_Request( 'GET', '/mai-views/v1/views/post/' . $post_id );
		$data     = $this->server->dispatch( $request )->get_data();

		$this->assertEquals( 42, $data['views'] );
		$this->assertEquals( 7, $data['trending'] );
	}

	public function test_get_trending(): void {
		$post_a = self::factory()->post->create( [ 'post_status' => 'publish' ] );
		$post_b = self::factory()->post->create( [ 'post_status' => 'publish' ] );

		for ( $i = 0; $i < 5; $i++ ) { Database::insert_view( $post_a, 'post' ); }
		for ( $i = 0; $i < 2; $i++ ) { Database::insert_view( $post_b, 'post' ); }

		$request = new WP_REST_Request( 'GET', '/mai-views/v1/views/trending' );
		$request->set_param( 'type', 'post' );
		$data = $this->server->dispatch( $request )->get_data();

		$this->assertGreaterThanOrEqual( 2, count( $data ) );
		$this->assertGreaterThanOrEqual( $data[1]['view_count'], $data[0]['view_count'] );
	}

	public function test_source_parameter(): void {
		global $wpdb;
		$post_id = self::factory()->post->create( [ 'post_status' => 'publish' ] );

		$request = new WP_REST_Request( 'POST', '/mai-views/v1/view/post/' . $post_id );
		$request->set_header( 'User-Agent', 'Mozilla/5.0' );
		$request->set_param( 'source', 'app' );
		$this->server->dispatch( $request );

		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . Database::get_table_name() . ' WHERE object_id = %d', $post_id ) );
		$this->assertEquals( 'app', $row->source );
	}
}
