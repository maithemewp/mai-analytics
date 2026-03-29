<?php

use Mai\Views\Providers\SiteKit;
use Mai\Views\Providers\Matomo;
use Mai\Views\Settings;

class Test_Providers extends WP_UnitTestCase {

	public function setUp(): void {
		parent::setUp();
		delete_option( 'mai_views_settings' );
		delete_option( 'googlesitekit_analytics-4_settings' );
	}

	public function tearDown(): void {
		delete_option( 'mai_views_settings' );
		delete_option( 'googlesitekit_analytics-4_settings' );
		parent::tearDown();
	}

	public function test_site_kit_not_available_without_plugin(): void {
		// GOOGLESITEKIT_VERSION should not be defined in the test environment.
		$provider = new SiteKit();
		$this->assertFalse( $provider->is_available() );
	}

	public function test_site_kit_slug_and_label(): void {
		$provider = new SiteKit();
		$this->assertEquals( 'site_kit', $provider->get_slug() );
		$this->assertNotEmpty( $provider->get_label() );
	}

	public function test_site_kit_batch_size(): void {
		$provider = new SiteKit();
		$this->assertEquals( 50, $provider->get_batch_size() );
	}

	public function test_site_kit_no_extra_settings_fields(): void {
		$provider = new SiteKit();
		$this->assertEmpty( $provider->get_settings_fields() );
	}

	public function test_matomo_not_available_without_credentials(): void {
		// No matomo settings stored.
		$provider = new Matomo();
		$this->assertFalse( $provider->is_available() );
	}

	public function test_matomo_not_available_with_partial_credentials(): void {
		update_option( 'mai_views_settings', [
			'matomo_url'     => 'https://matomo.example.com',
			'matomo_site_id' => '',
			'matomo_token'   => '',
		] );

		$provider = new Matomo();
		$this->assertFalse( $provider->is_available() );
	}

	public function test_matomo_available_when_configured(): void {
		update_option( 'mai_views_settings', [
			'matomo_url'     => 'https://matomo.example.com',
			'matomo_site_id' => '1',
			'matomo_token'   => 'abc123',
		] );

		$provider = new Matomo();
		$this->assertTrue( $provider->is_available() );
	}

	public function test_matomo_slug_and_label(): void {
		$provider = new Matomo();
		$this->assertEquals( 'matomo', $provider->get_slug() );
		$this->assertEquals( 'Matomo', $provider->get_label() );
	}

	public function test_matomo_batch_size(): void {
		$provider = new Matomo();
		$this->assertEquals( 100, $provider->get_batch_size() );
	}

	public function test_matomo_has_settings_fields(): void {
		$provider = new Matomo();
		$fields   = $provider->get_settings_fields();

		$this->assertNotEmpty( $fields );

		// Should contain matomo_url, matomo_site_id, and matomo_token.
		$keys = array_column( $fields, 'key' );
		$this->assertContains( 'matomo_url', $keys );
		$this->assertContains( 'matomo_site_id', $keys );
		$this->assertContains( 'matomo_token', $keys );
	}
}
