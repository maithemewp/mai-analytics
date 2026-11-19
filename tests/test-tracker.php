<?php

use Mai\Analytics\Tracker;

class Test_Tracker extends WP_UnitTestCase {

	public function test_beacon_not_output_for_editors(): void {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'editor' ] ) );

		$tracker = new Tracker();
		ob_start();
		$tracker->output_beacon();

		$this->assertEmpty( ob_get_clean() );
	}

	public function test_beacon_not_output_for_admins(): void {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );

		$tracker = new Tracker();
		ob_start();
		$tracker->output_beacon();

		$this->assertEmpty( ob_get_clean() );
	}

	public function test_beacon_not_output_for_non_trackable_page(): void {
		wp_set_current_user( 0 );

		$tracker = new Tracker();
		ob_start();
		$tracker->output_beacon();

		$this->assertEmpty( ob_get_clean() );
	}

	/**
	 * Visits a URL signed out and returns the beacon output.
	 */
	private function beacon_at( string $url ): string {
		wp_set_current_user( 0 );
		add_filter( 'mai_analytics_tracking_enabled', '__return_true' );

		$this->go_to( $url );

		ob_start();
		( new Tracker() )->output_beacon();

		return (string) ob_get_clean();
	}

	public function test_beacon_for_queryable_non_public_type(): void {
		register_post_type( 'mai_queryable', [ 'public' => false, 'publicly_queryable' => true ] );
		$post_id = self::factory()->post->create( [ 'post_type' => 'mai_queryable', 'post_status' => 'publish' ] );

		$this->assertStringContainsString( 'view\/post\/' . $post_id, $this->beacon_at( get_permalink( $post_id ) ) );
	}

	/**
	 * Returns the beacon for a singular request whose global post is the given one.
	 *
	 * Core drops the post_type query var of a type that is not publicly
	 * queryable (wp-includes/class-wp.php:376-387), so such a post can't be
	 * reached by URL. This loads an ordinary post, then swaps the global post,
	 * so the tracker's own rule decides.
	 */
	private function beacon_for_global_post( int $post_id ): string {
		$this->beacon_at( get_permalink( self::factory()->post->create( [ 'post_status' => 'publish' ] ) ) );

		$GLOBALS['post'] = get_post( $post_id );

		ob_start();
		( new Tracker() )->output_beacon();

		return (string) ob_get_clean();
	}

	public function test_no_beacon_for_public_non_queryable_type(): void {
		register_post_type( 'mai_unqueryable', [ 'public' => true, 'publicly_queryable' => false ] );
		$post_id = self::factory()->post->create( [ 'post_type' => 'mai_unqueryable', 'post_status' => 'publish' ] );

		$this->assertSame( '', $this->beacon_for_global_post( $post_id ) );
	}

	public function test_beacon_for_published_post_with_global_set(): void {
		$post_id = self::factory()->post->create( [ 'post_status' => 'publish' ] );

		$this->assertStringContainsString( 'view\/post\/' . $post_id, $this->beacon_for_global_post( $post_id ) );
	}

	public function test_no_beacon_for_attachments(): void {
		$parent   = self::factory()->post->create( [ 'post_status' => 'publish' ] );
		$attached = self::factory()->attachment->create( [ 'post_parent' => $parent, 'post_mime_type' => 'image/jpeg' ] );
		$loose    = self::factory()->attachment->create( [ 'post_mime_type' => 'image/jpeg' ] );

		$this->assertSame( '', $this->beacon_for_global_post( $attached ) );
		$this->assertSame( '', $this->beacon_for_global_post( $loose ) );
	}

	public function test_beacon_for_blog_page(): void {
		$this->assertStringContainsString( 'view\/post_type\/post', $this->beacon_at( home_url( '/' ) ) );
	}

	public function test_beacon_for_post_type_archive(): void {
		register_post_type( 'mai_archived', [ 'public' => true, 'has_archive' => true ] );
		self::factory()->post->create( [ 'post_type' => 'mai_archived', 'post_status' => 'publish' ] );

		$this->assertStringContainsString( 'view\/post_type\/mai_archived', $this->beacon_at( get_post_type_archive_link( 'mai_archived' ) ) );
	}

	public function test_no_beacon_for_archive_of_hidden_type(): void {
		register_post_type( 'mai_archived', [ 'public' => true, 'has_archive' => true ] );
		self::factory()->post->create( [ 'post_type' => 'mai_archived', 'post_status' => 'publish' ] );
		$url = get_post_type_archive_link( 'mai_archived' );

		wp_set_current_user( 0 );
		add_filter( 'mai_analytics_tracking_enabled', '__return_true' );
		$this->go_to( $url );
		$this->assertTrue( is_post_type_archive( 'mai_archived' ) );

		// Hidden after the query ran, so the tracker's own rule decides.
		register_post_type( 'mai_archived', [ 'public' => false, 'has_archive' => true ] );

		ob_start();
		( new Tracker() )->output_beacon();

		$this->assertSame( '', (string) ob_get_clean() );
	}

	public function test_beacon_for_term_archive(): void {
		$term_id = self::factory()->category->create();
		self::factory()->post->create( [ 'post_status' => 'publish', 'post_category' => [ $term_id ] ] );

		$this->assertStringContainsString( 'view\/term\/' . $term_id, $this->beacon_at( get_term_link( $term_id ) ) );
	}

	public function test_no_beacon_for_term_of_hidden_taxonomy(): void {
		register_taxonomy( 'mai_soon_hidden', 'post', [ 'public' => true ] );
		$term_id = self::factory()->term->create( [ 'taxonomy' => 'mai_soon_hidden' ] );
		$post_id = self::factory()->post->create( [ 'post_status' => 'publish' ] );
		wp_set_object_terms( $post_id, $term_id, 'mai_soon_hidden' );
		$url = get_term_link( $term_id );

		wp_set_current_user( 0 );
		add_filter( 'mai_analytics_tracking_enabled', '__return_true' );
		$this->go_to( $url );
		$this->assertTrue( is_tax( 'mai_soon_hidden' ) );

		register_taxonomy( 'mai_soon_hidden', 'post', [ 'public' => false ] );

		ob_start();
		( new Tracker() )->output_beacon();

		$this->assertSame( '', (string) ob_get_clean() );
	}

	public function test_beacon_for_author_archive(): void {
		$author = self::factory()->user->create( [ 'role' => 'author' ] );
		self::factory()->post->create( [ 'post_status' => 'publish', 'post_author' => $author ] );

		$this->assertStringContainsString( 'view\/user\/' . $author, $this->beacon_at( get_author_posts_url( $author ) ) );
	}

	public function test_tracker_class_instantiates(): void {
		$this->assertInstanceOf( Tracker::class, new Tracker() );
	}
}
