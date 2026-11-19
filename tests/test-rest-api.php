<?php

use Mai\Analytics\Database;
use Mai\Analytics\ErrorCode;
use Mai\Analytics\IdList;

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
		global $wp_rest_server;
		$wp_rest_server = null;
		parent::tearDown();
	}

	public function test_record_post_view(): void {
		$post_id = self::factory()->post->create( [ 'post_status' => 'publish' ] );
		$request = new WP_REST_Request( 'POST', '/mai-analytics/v1/view/post/' . $post_id );
		$request->set_header( 'User-Agent', 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7)' );

		$response = $this->server->dispatch( $request );

		$this->assertEquals( 200, $response->get_status() );
		$this->assertTrue( $response->get_data()['success'] );
	}

	public function test_record_term_view(): void {
		$term_id = self::factory()->category->create();
		$request = new WP_REST_Request( 'POST', '/mai-analytics/v1/view/term/' . $term_id );
		$request->set_header( 'User-Agent', 'Mozilla/5.0' );

		$this->assertEquals( 200, $this->server->dispatch( $request )->get_status() );
	}

	public function test_record_user_view(): void {
		$user_id = self::factory()->user->create();
		self::factory()->post->create( [ 'post_status' => 'publish', 'post_author' => $user_id ] );
		$request = new WP_REST_Request( 'POST', '/mai-analytics/v1/view/user/' . $user_id );
		$request->set_header( 'User-Agent', 'Mozilla/5.0' );

		$this->assertEquals( 200, $this->server->dispatch( $request )->get_status() );
	}

	/**
	 * Asserts a view route error: its status, code, data.status and success false.
	 */
	private function assertViewError( WP_REST_Response $response, ErrorCode $code ): void {
		$data = $response->get_data();

		$this->assertSame( $code->status(), $response->get_status() );
		$this->assertSame( $code->value, $data['code'] );
		$this->assertSame( $code->status(), $data['data']['status'] );
		$this->assertFalse( $data['success'] );
		$this->assertNotEmpty( $data['message'] );
	}

	private function view_request( string $path ): WP_REST_Request {
		$request = new WP_REST_Request( 'POST', '/mai-analytics/v1/view/' . $path );
		$request->set_header( 'User-Agent', 'Mozilla/5.0' );

		return $request;
	}

	private function buffer_rows(): int {
		global $wpdb;

		return (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . Database::get_table_name() );
	}

	public function test_reject_invalid_post_id(): void {
		$request = new WP_REST_Request( 'POST', '/mai-analytics/v1/view/post/999999' );
		$request->set_header( 'User-Agent', 'Mozilla/5.0' );

		$this->assertViewError( $this->server->dispatch( $request ), ErrorCode::NotFound );
	}

	public function test_reject_draft_post(): void {
		$post_id = self::factory()->post->create( [ 'post_status' => 'draft' ] );
		$request = new WP_REST_Request( 'POST', '/mai-analytics/v1/view/post/' . $post_id );
		$request->set_header( 'User-Agent', 'Mozilla/5.0' );

		$this->assertViewError( $this->server->dispatch( $request ), ErrorCode::NotFound );
	}

	public function test_reject_bot_user_agent(): void {
		$post_id = self::factory()->post->create( [ 'post_status' => 'publish' ] );
		$request = new WP_REST_Request( 'POST', '/mai-analytics/v1/view/post/' . $post_id );
		$request->set_header( 'User-Agent', 'Googlebot/2.1' );

		$this->assertViewError( $this->server->dispatch( $request ), ErrorCode::Bot );
	}

	public function test_reject_non_public_post_type_archive(): void {
		register_post_type( 'mai_hidden_type', [ 'public' => false ] );

		$response = $this->server->dispatch( $this->view_request( 'post_type/mai_hidden_type' ) );

		$this->assertViewError( $response, ErrorCode::NotFound );
	}

	public function test_insert_failure_answers_503(): void {
		global $wpdb;

		$post_id    = self::factory()->post->create( [ 'post_status' => 'publish' ] );
		$suppressed = $wpdb->suppress_errors( true );
		$prefix     = 'INSERT INTO `' . Database::get_table_name() . '`';
		$break      = function ( $query ) use ( $prefix ) {
			if ( str_starts_with( $query, $prefix ) ) {
				return 'INSERT INTO `mai_analytics_missing_table`' . substr( $query, strlen( $prefix ) );
			}

			return $query;
		};

		add_filter( 'query', $break );

		$post_response      = $this->server->dispatch( $this->view_request( 'post/' . $post_id ) );
		$post_type_response = $this->server->dispatch( $this->view_request( 'post_type/post' ) );

		remove_filter( 'query', $break );
		$wpdb->suppress_errors( $suppressed );

		foreach ( [ $post_response, $post_type_response ] as $response ) {
			$this->assertViewError( $response, ErrorCode::DatabaseError );
			$this->assertSame( 1, $response->get_data()['data']['retry_after'] );
			$this->assertSame( '1', $response->get_headers()['Retry-After'] );
		}
	}

	/**
	 * Runs a callback while every query that checks a user's posts fails.
	 *
	 * @return mixed What the callback returns.
	 */
	private function with_failing_user_check( callable $callback ): mixed {
		global $wpdb;

		$suppressed = $wpdb->suppress_errors( true );
		$break      = function ( $query ) use ( $wpdb ) {
			if ( str_contains( $query, 'post_author' ) && str_contains( $query, $wpdb->users ) ) {
				return str_replace( $wpdb->users, 'mai_analytics_missing_table', $query );
			}

			return $query;
		};

		add_filter( 'query', $break );

		try {
			return $callback();
		} finally {
			remove_filter( 'query', $break );
			$wpdb->suppress_errors( $suppressed );
		}
	}

	public function test_user_check_failure_answers_503(): void {
		$author = self::factory()->user->create();
		self::factory()->post->create( [ 'post_status' => 'publish', 'post_author' => $author ] );
		$before = $this->buffer_rows();

		[ $post, $get ] = $this->with_failing_user_check( fn() => [
			$this->server->dispatch( $this->view_request( 'user/' . $author ) ),
			$this->get_views_at( 'user/' . $author ),
		] );

		$this->assertViewError( $post, ErrorCode::DatabaseError );
		$this->assertViewError( $get, ErrorCode::DatabaseError );
		$this->assertSame( '1', $get->get_headers()['Retry-After'] );
		$this->assertSame( $before, $this->buffer_rows() );
	}

	public function test_trending_users_check_failure_answers_503(): void {
		$author = self::factory()->user->create();
		self::factory()->post->create( [ 'post_status' => 'publish', 'post_author' => $author ] );
		Database::insert_view( $author, 'user' );

		$request = new WP_REST_Request( 'GET', '/mai-analytics/v1/views/trending' );
		$request->set_param( 'type', 'user' );

		$response = $this->with_failing_user_check( fn() => $this->server->dispatch( $request ) );

		$this->assertSame( 503, $response->get_status() );
		$this->assertSame( ErrorCode::DatabaseError->value, $response->get_data()['code'] );
	}

	public function test_trending_query_failure_answers_503(): void {
		global $wpdb;

		$post_id = self::factory()->post->create( [ 'post_status' => 'publish' ] );
		Database::insert_view( $post_id, 'post' );
		Database::insert_view( 0, 'post_type', 'web', 'post' );

		$table      = Database::get_table_name();
		$suppressed = $wpdb->suppress_errors( true );
		$break      = function ( $query ) use ( $table ) {
			if ( str_contains( $query, 'view_count' ) && str_contains( $query, $table ) ) {
				return str_replace( $table, 'mai_analytics_missing_table', $query );
			}

			return $query;
		};

		add_filter( 'query', $break );

		$responses = [];

		foreach ( [ 'post', 'post_type' ] as $type ) {
			$request = new WP_REST_Request( 'GET', '/mai-analytics/v1/views/trending' );
			$request->set_param( 'type', $type );
			$responses[ $type ] = $this->server->dispatch( $request );
		}

		remove_filter( 'query', $break );
		$wpdb->suppress_errors( $suppressed );

		foreach ( $responses as $type => $response ) {
			$this->assertSame( 503, $response->get_status(), $type );
			$this->assertSame( ErrorCode::DatabaseError->value, $response->get_data()['code'], $type );
			$this->assertSame( '1', $response->get_headers()['Retry-After'], $type );
		}
	}

	public function test_user_check_stops_at_first_post(): void {
		global $wpdb;

		$author = self::factory()->user->create();
		self::factory()->post->create_many( 3, [ 'post_status' => 'publish', 'post_author' => $author ] );

		$seen  = [];
		$watch = function ( $query ) use ( &$seen, $wpdb ) {
			if ( str_contains( $query, 'post_author' ) && str_contains( $query, $wpdb->users ) ) {
				$seen[] = $query;
			}

			return $query;
		};

		add_filter( 'query', $watch );
		$response = $this->get_views_at( 'user/' . $author );
		remove_filter( 'query', $watch );

		$this->assertSame( 200, $response->get_status() );
		$this->assertCount( 1, $seen );
		$this->assertStringContainsString( 'EXISTS', $seen[0] );
		$this->assertStringContainsString( 'LIMIT 1', $seen[0] );
		$this->assertStringNotContainsString( 'DISTINCT', $seen[0] );
	}

	public function test_provider_mode_dedup_still_succeeds(): void {
		add_filter( 'mai_analytics_data_source', fn() => 'matomo' );

		$post_id = self::factory()->post->create( [ 'post_status' => 'publish' ] );
		Database::insert_view( $post_id, 'post' );

		$response = $this->server->dispatch( $this->view_request( 'post/' . $post_id ) );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( [ 'success' => true ], $response->get_data() );
	}

	private function get_views_at( string $path ): WP_REST_Response {
		return $this->server->dispatch( new WP_REST_Request( 'GET', '/mai-analytics/v1/views/' . $path ) );
	}

	public function test_get_views_hides_unpublished(): void {
		register_post_type( 'mai_hidden_type', [ 'public' => false ] );

		$ids = [
			self::factory()->post->create( [ 'post_status' => 'draft' ] ),
			self::factory()->post->create( [ 'post_status' => 'private' ] ),
			self::factory()->post->create( [ 'post_status' => 'pending' ] ),
			self::factory()->post->create( [ 'post_status' => 'trash' ] ),
			self::factory()->post->create( [ 'post_status' => 'publish', 'post_type' => 'mai_hidden_type' ] ),
		];

		foreach ( [ 0, self::factory()->user->create( [ 'role' => 'editor' ] ) ] as $user ) {
			wp_set_current_user( $user );

			foreach ( $ids as $id ) {
				$this->assertViewError( $this->get_views_at( 'post/' . $id ), ErrorCode::NotFound );
			}
		}
	}

	public function test_get_views_missing_items(): void {
		register_taxonomy( 'mai_hidden_tax', 'post', [ 'public' => false ] );
		$hidden_term = self::factory()->term->create( [ 'taxonomy' => 'mai_hidden_tax' ] );
		$no_posts    = self::factory()->user->create();
		$author      = self::factory()->user->create();
		self::factory()->post->create( [ 'post_status' => 'publish', 'post_author' => $author ] );

		$this->assertViewError( $this->get_views_at( 'post/999999' ), ErrorCode::NotFound );
		$this->assertViewError( $this->get_views_at( 'term/' . $hidden_term ), ErrorCode::NotFound );
		$this->assertViewError( $this->get_views_at( 'term/999999' ), ErrorCode::NotFound );
		$this->assertViewError( $this->get_views_at( 'user/999999' ), ErrorCode::NotFound );
		$this->assertViewError( $this->get_views_at( 'user/' . $no_posts ), ErrorCode::NotFound );
		$this->assertSame( 200, $this->get_views_at( 'user/' . $author )->get_status() );
	}

	public function test_view_post_for_user_without_posts(): void {
		$user_id = self::factory()->user->create();
		$before  = $this->buffer_rows();

		$this->assertViewError( $this->server->dispatch( $this->view_request( 'user/' . $user_id ) ), ErrorCode::NotFound );
		$this->assertSame( $before, $this->buffer_rows() );
	}

	public function test_get_post_type_views_hides_non_public(): void {
		register_post_type( 'mai_hidden_type', [ 'public' => false ] );

		$this->assertViewError( $this->get_views_at( 'post_type/mai_hidden_type' ), ErrorCode::NotFound );
		$this->assertSame( 200, $this->get_views_at( 'post_type/post' )->get_status() );
	}

	public function test_queryable_non_public_type_is_counted(): void {
		register_post_type( 'mai_queryable', [ 'public' => false, 'publicly_queryable' => true ] );
		$post_id = self::factory()->post->create( [ 'post_type' => 'mai_queryable', 'post_status' => 'publish' ] );

		$this->assertSame( 200, $this->server->dispatch( $this->view_request( 'post/' . $post_id ) )->get_status() );
	}

	public function test_public_non_queryable_type_is_not_counted(): void {
		register_post_type( 'mai_unqueryable', [ 'public' => true, 'publicly_queryable' => false ] );
		$post_id = self::factory()->post->create( [ 'post_type' => 'mai_unqueryable', 'post_status' => 'publish' ] );

		$this->assertViewError( $this->server->dispatch( $this->view_request( 'post/' . $post_id ) ), ErrorCode::NotFound );
	}

	public function test_queryable_custom_status_is_counted(): void {
		// Without public, internal, protected or private, core makes the status
		// internal (wp-includes/post.php:1486-1488), so public is named here.
		register_post_status( 'mai_showcase', [ 'public' => false, 'publicly_queryable' => true ] );
		$post_id = self::factory()->post->create( [ 'post_status' => 'mai_showcase' ] );

		$this->assertSame( 200, $this->server->dispatch( $this->view_request( 'post/' . $post_id ) )->get_status() );
	}

	public function test_attachments_are_not_counted(): void {
		$parent   = self::factory()->post->create( [ 'post_status' => 'publish' ] );
		$attached = self::factory()->attachment->create( [ 'post_parent' => $parent, 'post_mime_type' => 'image/jpeg' ] );
		$loose    = self::factory()->attachment->create( [ 'post_mime_type' => 'image/jpeg' ] );

		foreach ( [ $attached, $loose ] as $id ) {
			$this->assertViewError( $this->server->dispatch( $this->view_request( 'post/' . $id ) ), ErrorCode::NotFound );
			$this->assertViewError( $this->get_views_at( 'post/' . $id ), ErrorCode::NotFound );
		}
	}

	public function test_author_of_viewable_non_public_type_is_visible(): void {
		register_post_type( 'mai_queryable', [ 'public' => false, 'publicly_queryable' => true ] );
		$author = self::factory()->user->create();
		self::factory()->post->create( [ 'post_type' => 'mai_queryable', 'post_status' => 'publish', 'post_author' => $author ] );

		$this->assertSame( 200, $this->get_views_at( 'user/' . $author )->get_status() );
	}

	public function test_author_of_viewable_custom_status_is_visible(): void {
		register_post_status( 'mai_showcase', [ 'public' => false, 'publicly_queryable' => true ] );
		$author = self::factory()->user->create();
		self::factory()->post->create( [ 'post_status' => 'mai_showcase', 'post_author' => $author ] );

		$this->assertSame( 200, $this->get_views_at( 'user/' . $author )->get_status() );
	}

	public function test_author_of_only_attachments_is_hidden(): void {
		$author = self::factory()->user->create();
		self::factory()->attachment->create( [ 'post_author' => $author, 'post_mime_type' => 'image/jpeg' ] );

		$this->assertViewError( $this->get_views_at( 'user/' . $author ), ErrorCode::NotFound );
	}

	public function test_password_protected_post_is_counted(): void {
		$post_id = self::factory()->post->create( [ 'post_status' => 'publish', 'post_password' => 'secret' ] );

		$this->assertSame( 200, $this->server->dispatch( $this->view_request( 'post/' . $post_id ) )->get_status() );
	}

	private function trending( string $type, int $per_page ): array {
		$request = new WP_REST_Request( 'GET', '/mai-analytics/v1/views/trending' );
		$request->set_param( 'type', $type );
		$request->set_param( 'per_page', $per_page );

		return $this->server->dispatch( $request )->get_data();
	}

	public function test_trending_drops_hidden_posts(): void {
		$ids = [];

		for ( $i = 0; $i < 12; $i++ ) {
			$ids[ $i ] = self::factory()->post->create( [ 'post_status' => 'publish' ] );

			// The first posts get the most views.
			for ( $v = 0; $v < 20 - $i; $v++ ) {
				Database::insert_view( $ids[ $i ], 'post' );
			}
		}

		$private = array_slice( $ids, 0, 3 );

		foreach ( $private as $id ) {
			wp_update_post( [ 'ID' => $id, 'post_status' => 'private' ] );
		}

		$data = $this->trending( 'post', 5 );

		$this->assertCount( 5, $data );
		$this->assertEmpty( array_intersect( $private, array_column( $data, 'id' ) ) );
	}

	public function test_trending_drops_hidden_terms_and_users(): void {
		register_taxonomy( 'mai_soon_hidden', 'post', [ 'public' => true ] );
		$term    = self::factory()->term->create( [ 'taxonomy' => 'mai_soon_hidden' ] );
		$shown   = self::factory()->category->create();
		$deleted = self::factory()->user->create();
		$lonely  = self::factory()->user->create();
		$author  = self::factory()->user->create();
		self::factory()->post->create( [ 'post_status' => 'publish', 'post_author' => $author ] );
		self::factory()->post->create( [ 'post_status' => 'publish', 'post_author' => $deleted ] );

		foreach ( [ $term, $shown ] as $id ) {
			Database::insert_view( $id, 'term' );
		}

		foreach ( [ $deleted, $lonely, $author ] as $id ) {
			Database::insert_view( $id, 'user' );
		}

		register_taxonomy( 'mai_soon_hidden', 'post', [ 'public' => false ] );
		wp_delete_user( $deleted );

		$this->assertSame( [ $shown ], array_column( $this->trending( 'term', 10 ), 'id' ) );
		$this->assertSame( [ $author ], array_column( $this->trending( 'user', 10 ), 'id' ) );
	}

	public function test_trending_drops_hidden_post_type_archives(): void {
		register_post_type( 'mai_hidden_type', [ 'public' => false ] );

		Database::insert_view( 0, 'post_type', 'web', 'post' );
		Database::insert_view( 0, 'post_type', 'web', 'mai_hidden_type' );
		Database::insert_view( 0, 'post_type', 'web', 'mai_hidden_type' );
		Database::insert_view( 0, 'post_type', 'web', 'mai_never_registered' );

		$this->assertSame( [ 'post' ], array_column( $this->trending( 'post_type', 10 ), 'post_type' ) );
	}

	/**
	 * Views a batch of new posts, each fewer times than any earlier one.
	 *
	 * @return int[] The post IDs.
	 */
	private function viewed_posts( int $count, int $most ): array {
		$ids = [];

		for ( $i = 0; $i < $count; $i++ ) {
			$ids[] = $id = self::factory()->post->create( [ 'post_status' => 'publish' ] );

			for ( $v = 0; $v < $most - $i; $v++ ) {
				Database::insert_view( $id, 'post' );
			}
		}

		return $ids;
	}

	private function trending_queries( int $per_page ): int {
		global $wpdb;

		wp_cache_flush();
		$before = $wpdb->num_queries;
		$this->trending( 'post', $per_page );

		return $wpdb->num_queries - $before;
	}

	public function test_trending_primes_only_what_it_returns(): void {
		$top = $this->viewed_posts( 5, 60 );
		$few = $this->trending_queries( 5 );

		// Twenty more rows that the over-fetch reads but the cut drops.
		$rest = $this->viewed_posts( 20, 40 );
		$many = $this->trending_queries( 5 );

		$this->assertSame( $few, $many );

		foreach ( $rest as $id ) {
			$this->assertFalse( wp_cache_get( $id, 'post_meta' ), "post $id meta" );
		}

		foreach ( $top as $id ) {
			$this->assertNotFalse( wp_cache_get( $id, 'post_meta' ), "post $id meta" );
		}
	}

	public function test_trending_checks_in_bulk(): void {
		global $wpdb;

		for ( $i = 0; $i < 20; $i++ ) {
			$user = self::factory()->user->create();
			self::factory()->post->create( [ 'post_status' => 'publish', 'post_author' => $user ] );
			Database::insert_view( $user, 'user' );
		}

		wp_cache_flush();

		$before = $wpdb->num_queries;
		$data   = $this->trending( 'user', 20 );
		$ran    = $wpdb->num_queries - $before;

		$this->assertCount( 20, $data );
		$this->assertLessThan( count( $data ), $ran );
	}

	public function test_trending_terms_limit(): void {
		$request = new WP_REST_Request( 'GET', '/mai-analytics/v1/views/trending' );
		$request->set_param( 'taxonomy', 'category' );
		$request->set_param( 'terms', implode( ',', range( 1, 100 ) ) );

		$this->assertSame( 200, $this->server->dispatch( $request )->get_status() );

		$request->set_param( 'terms', implode( ',', range( 1, 101 ) ) );
		$response = $this->server->dispatch( $request );
		$data     = $response->get_data();

		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( ErrorCode::InvalidRequest->value, $data['code'] );
		$this->assertArrayHasKey( 'terms', $data['data']['params'] );
	}

	public function test_id_list_counting(): void {
		$this->assertTrue( IdList::within_limit( '1,2,' ) );
		$this->assertTrue( IdList::within_limit( 5 ) );
		$this->assertTrue( IdList::within_limit( implode( ',', range( 1, 100 ) ) . ',,' ) );
		$this->assertFalse( IdList::within_limit( implode( ',', range( 1, 101 ) ) ) );
		$this->assertFalse( IdList::within_limit( [ 1, 2 ] ) );
		$this->assertFalse( IdList::within_limit( null ) );
	}

	public function test_route_in_other_case_counts(): void {
		$post_id = self::factory()->post->create( [ 'post_status' => 'publish' ] );
		$before  = $this->buffer_rows();
		$request = new WP_REST_Request( 'POST', '/mai-analytics/v1/VIEW/post/' . $post_id );
		$request->set_header( 'User-Agent', 'Mozilla/5.0' );

		$response = $this->server->dispatch( $request );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( $before + 1, $this->buffer_rows() );
	}

	public function test_get_post_views(): void {
		$post_id = self::factory()->post->create();
		update_post_meta( $post_id, 'mai_views', 42 );
		update_post_meta( $post_id, 'mai_trending', 7 );

		$request  = new WP_REST_Request( 'GET', '/mai-analytics/v1/views/post/' . $post_id );
		$data     = $this->server->dispatch( $request )->get_data();

		$this->assertEquals( 42, $data['views'] );
		$this->assertEquals( 7, $data['trending'] );
	}

	public function test_get_trending(): void {
		$post_a = self::factory()->post->create( [ 'post_status' => 'publish' ] );
		$post_b = self::factory()->post->create( [ 'post_status' => 'publish' ] );

		for ( $i = 0; $i < 5; $i++ ) { Database::insert_view( $post_a, 'post' ); }
		for ( $i = 0; $i < 2; $i++ ) { Database::insert_view( $post_b, 'post' ); }

		$request = new WP_REST_Request( 'GET', '/mai-analytics/v1/views/trending' );
		$request->set_param( 'type', 'post' );
		$data = $this->server->dispatch( $request )->get_data();

		$this->assertGreaterThanOrEqual( 2, count( $data ) );
		$this->assertGreaterThanOrEqual( $data[1]['view_count'], $data[0]['view_count'] );
	}

	public function test_source_parameter(): void {
		global $wpdb;
		$post_id = self::factory()->post->create( [ 'post_status' => 'publish' ] );

		$request = new WP_REST_Request( 'POST', '/mai-analytics/v1/view/post/' . $post_id );
		$request->set_header( 'User-Agent', 'Mozilla/5.0' );
		$request->set_param( 'source', 'app' );
		$this->server->dispatch( $request );

		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . Database::get_table_name() . ' WHERE object_id = %d', $post_id ) );
		$this->assertEquals( 'app', $row->source );
	}
}
