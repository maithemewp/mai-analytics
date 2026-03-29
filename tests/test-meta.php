<?php

class Test_Meta extends WP_UnitTestCase {

	public function test_post_meta_registered(): void {
		$registered = get_registered_meta_keys( 'post' );

		$this->assertArrayHasKey( 'mai_views', $registered );
		$this->assertArrayHasKey( 'mai_trending', $registered );
	}

	public function test_post_meta_show_in_rest(): void {
		$registered = get_registered_meta_keys( 'post' );

		$this->assertTrue( $registered['mai_views']['show_in_rest'] );
		$this->assertTrue( $registered['mai_trending']['show_in_rest'] );
	}

	public function test_term_meta_registered(): void {
		$registered = get_registered_meta_keys( 'term' );

		$this->assertArrayHasKey( 'mai_views', $registered );
		$this->assertArrayHasKey( 'mai_trending', $registered );
	}

	public function test_user_meta_registered(): void {
		$registered = get_registered_meta_keys( 'user' );

		$this->assertArrayHasKey( 'mai_views', $registered );
		$this->assertArrayHasKey( 'mai_trending', $registered );
	}

	public function test_views_web_meta_registered(): void {
		$post_meta = get_registered_meta_keys( 'post' );
		$term_meta = get_registered_meta_keys( 'term' );
		$user_meta = get_registered_meta_keys( 'user' );

		$this->assertArrayHasKey( 'mai_views_web', $post_meta );
		$this->assertArrayHasKey( 'mai_views_web', $term_meta );
		$this->assertArrayHasKey( 'mai_views_web', $user_meta );

		// Verify type and REST visibility.
		$this->assertEquals( 'integer', $post_meta['mai_views_web']['type'] );
		$this->assertTrue( $post_meta['mai_views_web']['show_in_rest'] );
	}

	public function test_views_app_meta_registered(): void {
		$post_meta = get_registered_meta_keys( 'post' );
		$term_meta = get_registered_meta_keys( 'term' );
		$user_meta = get_registered_meta_keys( 'user' );

		$this->assertArrayHasKey( 'mai_views_app', $post_meta );
		$this->assertArrayHasKey( 'mai_views_app', $term_meta );
		$this->assertArrayHasKey( 'mai_views_app', $user_meta );

		// Verify type and REST visibility.
		$this->assertEquals( 'integer', $post_meta['mai_views_app']['type'] );
		$this->assertTrue( $post_meta['mai_views_app']['show_in_rest'] );
	}
}
