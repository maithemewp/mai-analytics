<?php

use Mai\Views\Tracker;

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

	public function test_tracker_class_instantiates(): void {
		$this->assertInstanceOf( Tracker::class, new Tracker() );
	}
}
