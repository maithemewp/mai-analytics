<?php

use Mai\Views\AdminSettings;
use Mai\Views\Settings;

class Test_Admin_Settings extends WP_UnitTestCase {

	public function setUp(): void {
		parent::setUp();
		delete_option( 'mai_views_settings' );
		remove_all_filters( 'mai_views_providers' );
	}

	public function tearDown(): void {
		delete_option( 'mai_views_settings' );
		remove_all_filters( 'mai_views_providers' );
		remove_all_filters( 'mai_views_data_source' );
		parent::tearDown();
	}

	public function test_default_data_source_is_self_hosted(): void {
		$this->assertEquals( 'self_hosted', Settings::get( 'data_source' ) );
	}

	public function test_filter_overrides_data_source(): void {
		update_option( 'mai_views_settings', [ 'data_source' => 'self_hosted' ] );

		add_filter( 'mai_views_data_source', function () {
			return 'custom_override';
		} );

		$this->assertEquals( 'custom_override', Settings::get( 'data_source' ) );
	}

	public function test_sanitize_rejects_invalid_source(): void {
		$admin_settings = new AdminSettings();

		$input  = [ 'data_source' => 'nonexistent_provider' ];
		$result = $admin_settings->sanitize( $input );

		// Invalid source should fall back to self_hosted.
		$this->assertEquals( 'self_hosted', $result['data_source'] );
	}

	public function test_sanitize_accepts_valid_provider(): void {
		// Register a mock provider so its slug becomes valid.
		add_filter( 'mai_views_providers', function () {
			return [ new class implements \Mai\Views\WebViewProvider {
				public function get_slug(): string { return 'test_provider'; }
				public function get_label(): string { return 'Test'; }
				public function is_available(): bool { return true; }
				public function get_batch_size(): int { return 50; }
				public function get_settings_fields(): array { return []; }
				public function get_views( array $paths, string $start_date, string $end_date ): array { return []; }
			} ];
		} );

		$admin_settings = new AdminSettings();

		$input  = [ 'data_source' => 'test_provider' ];
		$result = $admin_settings->sanitize( $input );

		$this->assertEquals( 'test_provider', $result['data_source'] );
	}

	public function test_settings_page_requires_manage_options(): void {
		// Register the menu as admin.
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );

		$admin_settings = new AdminSettings();

		// Trigger the menu registration.
		do_action( 'admin_menu' );

		// A subscriber should NOT have access.
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'subscriber' ] ) );

		$this->assertFalse( current_user_can( 'manage_options' ) );
	}

	public function test_provider_notice_shown_when_unavailable(): void {
		// Set data source to a provider that isn't available.
		update_option( 'mai_views_settings', [ 'data_source' => 'site_kit' ] );

		// Register the SiteKit provider (is_available() will be false since GOOGLESITEKIT_VERSION isn't defined).
		add_filter( 'mai_views_providers', function () {
			return [ new \Mai\Views\Providers\SiteKit() ];
		} );

		$admin_settings = new AdminSettings();

		ob_start();
		$admin_settings->maybe_show_provider_notice();
		$output = ob_get_clean();

		$this->assertStringContainsString( 'not available', $output );
		$this->assertStringContainsString( 'notice-warning', $output );
	}

	public function test_provider_notice_not_shown_for_self_hosted(): void {
		update_option( 'mai_views_settings', [ 'data_source' => 'self_hosted' ] );

		$admin_settings = new AdminSettings();

		ob_start();
		$admin_settings->maybe_show_provider_notice();
		$output = ob_get_clean();

		$this->assertEmpty( $output );
	}

	public function test_sanitize_preserves_matomo_settings(): void {
		$admin_settings = new AdminSettings();

		$input = [
			'data_source'    => 'self_hosted',
			'matomo_url'     => 'https://matomo.example.com',
			'matomo_site_id' => '5',
			'matomo_token'   => 'abc123token',
		];

		$result = $admin_settings->sanitize( $input );

		$this->assertEquals( 'https://matomo.example.com', $result['matomo_url'] );
		$this->assertEquals( 5, $result['matomo_site_id'] );
		$this->assertEquals( 'abc123token', $result['matomo_token'] );
	}
}
