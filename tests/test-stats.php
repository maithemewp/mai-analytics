<?php

use Mai\Analytics\Stats;

class Test_Stats extends WP_UnitTestCase {

	public function test_set_trending_writes_positive_value(): void {
		$post_id = self::factory()->post->create();

		Stats::set_trending( $post_id, 'post', 7 );

		$this->assertSame( '7', get_post_meta( $post_id, 'mai_trending', true ) );
	}

	public function test_set_trending_zero_deletes_the_row(): void {
		$post_id = self::factory()->post->create();
		update_post_meta( $post_id, 'mai_trending', 99 );

		Stats::set_trending( $post_id, 'post', 0 );

		$this->assertFalse( metadata_exists( 'post', $post_id, 'mai_trending' ) );
	}
}
