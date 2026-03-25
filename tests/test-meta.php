<?php

class Test_Meta extends WP_UnitTestCase {

	public function test_post_meta_registered(): void {
		$registered = get_registered_meta_keys( 'post' );

		$this->assertArrayHasKey( 'mai_analytics_views', $registered );
		$this->assertArrayHasKey( 'mai_analytics_trending', $registered );
	}

	public function test_post_meta_show_in_rest(): void {
		$registered = get_registered_meta_keys( 'post' );

		$this->assertTrue( $registered['mai_analytics_views']['show_in_rest'] );
		$this->assertTrue( $registered['mai_analytics_trending']['show_in_rest'] );
	}

	public function test_term_meta_registered(): void {
		$registered = get_registered_meta_keys( 'term' );

		$this->assertArrayHasKey( 'mai_analytics_views', $registered );
		$this->assertArrayHasKey( 'mai_analytics_trending', $registered );
	}

	public function test_user_meta_registered(): void {
		$registered = get_registered_meta_keys( 'user' );

		$this->assertArrayHasKey( 'mai_analytics_views', $registered );
		$this->assertArrayHasKey( 'mai_analytics_trending', $registered );
	}
}
