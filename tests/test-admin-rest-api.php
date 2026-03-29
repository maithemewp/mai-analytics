<?php

use Mai\Views\Database;
use Mai\Views\Sync;

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

		$request  = new WP_REST_Request( 'GET', '/mai-views/v1/admin/summary' );
		$response = $this->server->dispatch( $request );

		$this->assertEquals( 401, $response->get_status() );
	}

	public function test_subscriber_denied(): void {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'subscriber' ] ) );

		$request  = new WP_REST_Request( 'GET', '/mai-views/v1/admin/summary' );
		$response = $this->server->dispatch( $request );

		$this->assertEquals( 403, $response->get_status() );
	}

	public function test_editor_allowed(): void {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'editor' ] ) );

		$request  = new WP_REST_Request( 'GET', '/mai-views/v1/admin/summary' );
		$response = $this->server->dispatch( $request );

		$this->assertEquals( 200, $response->get_status() );
	}

	public function test_summary_returns_expected_shape(): void {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'editor' ] ) );

		$request  = new WP_REST_Request( 'GET', '/mai-views/v1/admin/summary' );
		$data     = $this->server->dispatch( $request )->get_data();

		$this->assertArrayHasKey( 'total_views', $data );
		$this->assertArrayHasKey( 'trending_count', $data );
		$this->assertArrayHasKey( 'last_sync', $data );
		$this->assertArrayNotHasKey( 'views_today', $data );
		$this->assertArrayNotHasKey( 'buffer_rows', $data );
	}

	public function test_summary_counts_views(): void {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'editor' ] ) );

		$post_id = self::factory()->post->create();
		update_post_meta( $post_id, 'mai_views', 42 );

		$request = new WP_REST_Request( 'GET', '/mai-views/v1/admin/summary' );
		$data    = $this->server->dispatch( $request )->get_data();

		$this->assertGreaterThanOrEqual( 42, $data['total_views'] );
	}


	public function test_top_posts_pagination(): void {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'editor' ] ) );

		// Create 3 posts with views.
		for ( $i = 0; $i < 3; $i++ ) {
			$id = self::factory()->post->create( [ 'post_status' => 'publish' ] );
			update_post_meta( $id, 'mai_views', 100 - $i );
		}

		$request = new WP_REST_Request( 'GET', '/mai-views/v1/admin/top/posts' );
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
		update_post_meta( $id1, 'mai_views', 50 );
		update_post_meta( $id2, 'mai_views', 200 );

		$request = new WP_REST_Request( 'GET', '/mai-views/v1/admin/top/posts' );
		$data    = $this->server->dispatch( $request )->get_data();

		$this->assertEquals( $id2, $data['items'][0]['id'] );
	}

	public function test_top_terms(): void {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'editor' ] ) );

		$term_id = self::factory()->category->create();
		update_term_meta( $term_id, 'mai_views', 75 );

		$request = new WP_REST_Request( 'GET', '/mai-views/v1/admin/top/terms' );
		$data    = $this->server->dispatch( $request )->get_data();

		$this->assertGreaterThanOrEqual( 1, count( $data['items'] ) );
		$this->assertEquals( $term_id, $data['items'][0]['id'] );
	}

	public function test_top_authors(): void {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'editor' ] ) );

		$user_id = self::factory()->user->create();
		update_user_meta( $user_id, 'mai_views', 30 );

		$request = new WP_REST_Request( 'GET', '/mai-views/v1/admin/top/authors' );
		$data    = $this->server->dispatch( $request )->get_data();

		$this->assertGreaterThanOrEqual( 1, count( $data['items'] ) );
	}

	public function test_filters_returns_expected_shape(): void {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'editor' ] ) );

		$request = new WP_REST_Request( 'GET', '/mai-views/v1/admin/filters' );
		$data    = $this->server->dispatch( $request )->get_data();

		$this->assertArrayHasKey( 'post_types', $data );
		$this->assertArrayHasKey( 'taxonomies', $data );
		$this->assertArrayNotHasKey( 'authors', $data );
		$this->assertArrayNotHasKey( 'terms', $data );
	}

	public function test_empty_state_returns_gracefully(): void {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'editor' ] ) );

		$request = new WP_REST_Request( 'GET', '/mai-views/v1/admin/top/posts' );
		$data    = $this->server->dispatch( $request )->get_data();

		$this->assertEmpty( $data['items'] );
		$this->assertEquals( 0, $data['total'] );
	}

	public function test_source_breakdown_uses_meta_not_buffer(): void {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'editor' ] ) );

		$post_id = self::factory()->post->create( [ 'post_status' => 'publish' ] );
		update_post_meta( $post_id, 'mai_views', 500 );
		update_post_meta( $post_id, 'mai_views_web', 400 );
		update_post_meta( $post_id, 'mai_views_app', 100 );

		// Insert a single buffer row — if source breakdown read from buffer,
		// web would be 1 instead of 400.
		Database::insert_view( $post_id, 'post', 'web' );

		$request = new WP_REST_Request( 'GET', '/mai-views/v1/admin/top/posts' );
		$data    = $this->server->dispatch( $request )->get_data();

		$this->assertCount( 1, $data['items'] );
		$this->assertEquals( 400, $data['items'][0]['web'] );
		$this->assertEquals( 100, $data['items'][0]['app'] );
	}

	public function test_source_breakdown_terms(): void {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'editor' ] ) );

		$term_id = self::factory()->category->create();
		update_term_meta( $term_id, 'mai_views', 200 );
		update_term_meta( $term_id, 'mai_views_web', 150 );
		update_term_meta( $term_id, 'mai_views_app', 50 );

		$request = new WP_REST_Request( 'GET', '/mai-views/v1/admin/top/terms' );
		$data    = $this->server->dispatch( $request )->get_data();

		$this->assertGreaterThanOrEqual( 1, count( $data['items'] ) );
		$this->assertEquals( 150, $data['items'][0]['web'] );
		$this->assertEquals( 50, $data['items'][0]['app'] );
	}

	public function test_source_breakdown_authors(): void {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'editor' ] ) );

		$user_id = self::factory()->user->create();
		update_user_meta( $user_id, 'mai_views', 80 );
		update_user_meta( $user_id, 'mai_views_web', 60 );
		update_user_meta( $user_id, 'mai_views_app', 20 );

		$request = new WP_REST_Request( 'GET', '/mai-views/v1/admin/top/authors' );
		$data    = $this->server->dispatch( $request )->get_data();

		$found = false;

		foreach ( $data['items'] as $item ) {
			if ( $item['id'] === $user_id ) {
				$this->assertEquals( 60, $item['web'] );
				$this->assertEquals( 20, $item['app'] );
				$found = true;
				break;
			}
		}

		$this->assertTrue( $found, 'Expected author not found in results.' );
	}

	public function test_source_breakdown_archives(): void {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'editor' ] ) );

		update_option( 'mai_views_post_type_views', [ 'post' => 300 ] );
		update_option( 'mai_views_post_type_trending', [ 'post' => 10 ] );
		update_option( 'mai_views_post_type_views_web', [ 'post' => 250 ] );
		update_option( 'mai_views_post_type_views_app', [ 'post' => 50 ] );

		$request = new WP_REST_Request( 'GET', '/mai-views/v1/admin/top/archives' );
		$data    = $this->server->dispatch( $request )->get_data();

		$found = false;

		foreach ( $data['items'] as $item ) {
			if ( 'post' === $item['post_type'] ) {
				$this->assertEquals( 300, $item['views'] );
				$this->assertEquals( 250, $item['web'] );
				$this->assertEquals( 50, $item['app'] );
				$found = true;
				break;
			}
		}

		$this->assertTrue( $found, 'Post archive not found in results.' );
	}

	public function test_search_authors(): void {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'editor' ] ) );

		$user_id = self::factory()->user->create( [ 'display_name' => 'Jane Analytics' ] );
		update_user_meta( $user_id, 'mai_views', 10 );

		$request = new WP_REST_Request( 'GET', '/mai-views/v1/admin/search' );
		$request->set_param( 'type', 'author' );
		$request->set_param( 'search', 'Jane' );
		$data = $this->server->dispatch( $request )->get_data();

		$this->assertGreaterThanOrEqual( 1, count( $data ) );
		$this->assertEquals( 'Jane Analytics', $data[0]['name'] );
	}

	public function test_search_authors_requires_min_2_chars(): void {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'editor' ] ) );

		$request = new WP_REST_Request( 'GET', '/mai-views/v1/admin/search' );
		$request->set_param( 'type', 'author' );
		$request->set_param( 'search', 'J' );
		$data = $this->server->dispatch( $request )->get_data();

		$this->assertEmpty( $data );
	}

	public function test_search_terms(): void {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'editor' ] ) );

		$term_id = self::factory()->category->create( [ 'name' => 'Analytics News' ] );

		// Assign to a post so count > 0.
		$post_id = self::factory()->post->create( [ 'post_status' => 'publish' ] );
		wp_set_object_terms( $post_id, $term_id, 'category' );

		$request = new WP_REST_Request( 'GET', '/mai-views/v1/admin/search' );
		$request->set_param( 'type', 'term' );
		$request->set_param( 'taxonomy', 'category' );
		$request->set_param( 'search', 'Analytics' );
		$data = $this->server->dispatch( $request )->get_data();

		$this->assertGreaterThanOrEqual( 1, count( $data ) );
		$this->assertEquals( 'Analytics News', $data[0]['name'] );
	}

	public function test_search_terms_requires_taxonomy(): void {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'editor' ] ) );

		$request = new WP_REST_Request( 'GET', '/mai-views/v1/admin/search' );
		$request->set_param( 'type', 'term' );
		$request->set_param( 'search', 'News' );
		$data = $this->server->dispatch( $request )->get_data();

		$this->assertEmpty( $data );
	}

	public function test_top_posts_search_filter(): void {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'editor' ] ) );

		$id1 = self::factory()->post->create( [ 'post_title' => 'Alpha Report', 'post_status' => 'publish' ] );
		$id2 = self::factory()->post->create( [ 'post_title' => 'Beta Summary', 'post_status' => 'publish' ] );
		update_post_meta( $id1, 'mai_views', 10 );
		update_post_meta( $id2, 'mai_views', 20 );

		$request = new WP_REST_Request( 'GET', '/mai-views/v1/admin/top/posts' );
		$request->set_param( 'search', 'Alpha' );
		$data = $this->server->dispatch( $request )->get_data();

		$this->assertCount( 1, $data['items'] );
		$this->assertEquals( $id1, $data['items'][0]['id'] );
	}

	public function test_top_terms_search_filter(): void {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'editor' ] ) );

		$term1 = self::factory()->category->create( [ 'name' => 'Sports' ] );
		$term2 = self::factory()->category->create( [ 'name' => 'Technology' ] );
		update_term_meta( $term1, 'mai_views', 50 );
		update_term_meta( $term2, 'mai_views', 30 );

		$request = new WP_REST_Request( 'GET', '/mai-views/v1/admin/top/terms' );
		$request->set_param( 'search', 'Tech' );
		$data = $this->server->dispatch( $request )->get_data();

		$this->assertCount( 1, $data['items'] );
		$this->assertEquals( $term2, $data['items'][0]['id'] );
	}

	public function test_top_authors_search_filter(): void {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'editor' ] ) );

		$user1 = self::factory()->user->create( [ 'display_name' => 'Alice Writer' ] );
		$user2 = self::factory()->user->create( [ 'display_name' => 'Bob Editor' ] );
		update_user_meta( $user1, 'mai_views', 10 );
		update_user_meta( $user2, 'mai_views', 20 );

		$request = new WP_REST_Request( 'GET', '/mai-views/v1/admin/top/authors' );
		$request->set_param( 'search', 'Alice' );
		$data = $this->server->dispatch( $request )->get_data();

		$this->assertCount( 1, $data['items'] );
		$this->assertEquals( $user1, $data['items'][0]['id'] );
	}

	public function test_top_posts_taxonomy_filter(): void {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'editor' ] ) );

		$cat_id = self::factory()->category->create( [ 'name' => 'Filtered Cat' ] );
		$id1    = self::factory()->post->create( [ 'post_status' => 'publish' ] );
		$id2    = self::factory()->post->create( [ 'post_status' => 'publish' ] );

		wp_set_object_terms( $id1, $cat_id, 'category' );
		update_post_meta( $id1, 'mai_views', 50 );
		update_post_meta( $id2, 'mai_views', 100 );

		$request = new WP_REST_Request( 'GET', '/mai-views/v1/admin/top/posts' );
		$request->set_param( 'taxonomy', 'category' );
		$request->set_param( 'term_id', $cat_id );
		$data = $this->server->dispatch( $request )->get_data();

		$this->assertCount( 1, $data['items'] );
		$this->assertEquals( $id1, $data['items'][0]['id'] );
	}

	public function test_top_posts_pagination_with_filter(): void {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'editor' ] ) );

		$cat_id = self::factory()->category->create();

		for ( $i = 0; $i < 5; $i++ ) {
			$id = self::factory()->post->create( [ 'post_status' => 'publish' ] );
			wp_set_object_terms( $id, $cat_id, 'category' );
			update_post_meta( $id, 'mai_views', 100 - $i );
		}

		// Extra post NOT in category.
		$extra = self::factory()->post->create( [ 'post_status' => 'publish' ] );
		update_post_meta( $extra, 'mai_views', 999 );

		$request = new WP_REST_Request( 'GET', '/mai-views/v1/admin/top/posts' );
		$request->set_param( 'taxonomy', 'category' );
		$request->set_param( 'term_id', $cat_id );
		$request->set_param( 'per_page', 2 );
		$request->set_param( 'page', 1 );
		$data = $this->server->dispatch( $request )->get_data();

		$this->assertCount( 2, $data['items'] );
		$this->assertEquals( 5, $data['total'] );
		$this->assertEquals( 3, $data['pages'] );

		// Page 2 also returns 2.
		$request->set_param( 'page', 2 );
		$data2 = $this->server->dispatch( $request )->get_data();

		$this->assertCount( 2, $data2['items'] );

		// No overlap between page 1 and page 2.
		$page1_ids = array_column( $data['items'], 'id' );
		$page2_ids = array_column( $data2['items'], 'id' );
		$this->assertEmpty( array_intersect( $page1_ids, $page2_ids ) );
	}

	public function test_top_posts_trending_orderby(): void {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'editor' ] ) );

		$id1 = self::factory()->post->create( [ 'post_status' => 'publish' ] );
		$id2 = self::factory()->post->create( [ 'post_status' => 'publish' ] );
		update_post_meta( $id1, 'mai_views', 500 );
		update_post_meta( $id1, 'mai_trending', 5 );
		update_post_meta( $id2, 'mai_views', 100 );
		update_post_meta( $id2, 'mai_trending', 50 );

		// Order by views: id1 first.
		$request = new WP_REST_Request( 'GET', '/mai-views/v1/admin/top/posts' );
		$request->set_param( 'orderby', 'views' );
		$data = $this->server->dispatch( $request )->get_data();
		$this->assertEquals( $id1, $data['items'][0]['id'] );

		// Order by trending: id2 first.
		$request->set_param( 'orderby', 'trending' );
		$data = $this->server->dispatch( $request )->get_data();
		$this->assertEquals( $id2, $data['items'][0]['id'] );

		// Views and trending values are correct regardless of orderby.
		$this->assertEquals( 100, $data['items'][0]['views'] );
		$this->assertEquals( 50, $data['items'][0]['trending'] );
	}

	public function test_top_posts_multi_author_filter(): void {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'editor' ] ) );

		$user1 = self::factory()->user->create();
		$user2 = self::factory()->user->create();
		$user3 = self::factory()->user->create();

		$id1 = self::factory()->post->create( [ 'post_status' => 'publish', 'post_author' => $user1 ] );
		$id2 = self::factory()->post->create( [ 'post_status' => 'publish', 'post_author' => $user2 ] );
		$id3 = self::factory()->post->create( [ 'post_status' => 'publish', 'post_author' => $user3 ] );

		update_post_meta( $id1, 'mai_views', 50 );
		update_post_meta( $id2, 'mai_views', 40 );
		update_post_meta( $id3, 'mai_views', 30 );

		// Filter by users 1 and 2 — should exclude user 3.
		$request = new WP_REST_Request( 'GET', '/mai-views/v1/admin/top/posts' );
		$request->set_param( 'author', $user1 . ',' . $user2 );
		$data = $this->server->dispatch( $request )->get_data();

		$this->assertEquals( 2, $data['total'] );
		$ids = array_column( $data['items'], 'id' );
		$this->assertContains( $id1, $ids );
		$this->assertContains( $id2, $ids );
		$this->assertNotContains( $id3, $ids );
	}

	public function test_top_posts_multi_term_filter(): void {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'editor' ] ) );

		$cat1 = self::factory()->category->create();
		$cat2 = self::factory()->category->create();
		$cat3 = self::factory()->category->create();

		$id1 = self::factory()->post->create( [ 'post_status' => 'publish' ] );
		$id2 = self::factory()->post->create( [ 'post_status' => 'publish' ] );
		$id3 = self::factory()->post->create( [ 'post_status' => 'publish' ] );

		wp_set_object_terms( $id1, $cat1, 'category' );
		wp_set_object_terms( $id2, $cat2, 'category' );
		wp_set_object_terms( $id3, $cat3, 'category' );

		update_post_meta( $id1, 'mai_views', 50 );
		update_post_meta( $id2, 'mai_views', 40 );
		update_post_meta( $id3, 'mai_views', 30 );

		// Filter by terms 1 and 2 — should exclude term 3.
		$request = new WP_REST_Request( 'GET', '/mai-views/v1/admin/top/posts' );
		$request->set_param( 'taxonomy', 'category' );
		$request->set_param( 'term_id', $cat1 . ',' . $cat2 );
		$data = $this->server->dispatch( $request )->get_data();

		$this->assertEquals( 2, $data['total'] );
		$ids = array_column( $data['items'], 'id' );
		$this->assertContains( $id1, $ids );
		$this->assertContains( $id2, $ids );
		$this->assertNotContains( $id3, $ids );
	}

	public function test_trending_count_from_meta(): void {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'editor' ] ) );

		$id1 = self::factory()->post->create( [ 'post_status' => 'publish' ] );
		$id2 = self::factory()->post->create( [ 'post_status' => 'publish' ] );
		update_post_meta( $id1, 'mai_trending', 10 );
		update_post_meta( $id2, 'mai_trending', 0 );

		$term_id = self::factory()->category->create();
		update_term_meta( $term_id, 'mai_trending', 5 );

		$request = new WP_REST_Request( 'GET', '/mai-views/v1/admin/summary' );
		$data    = $this->server->dispatch( $request )->get_data();

		// Should count objects with trending > 0 from meta (post + term = 2).
		$this->assertGreaterThanOrEqual( 2, $data['trending_count'] );
	}
}
