<?php

use Mai\Analytics\Admin;

/**
 * The dashboard renders its filter controls from the URL, so a linked view
 * opens with the same filters in place.
 */
class Test_Admin_Dashboard extends WP_UnitTestCase {

	private array $get_backup = [];

	public function setUp(): void {
		parent::setUp();
		$this->get_backup = $_GET;
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
	}

	public function tearDown(): void {
		$_GET = $this->get_backup;
		parent::tearDown();
	}

	/**
	 * Renders the dashboard page for a query string.
	 *
	 * @param array $query The $_GET values, minus `page`.
	 *
	 * @return string
	 */
	private function render( array $query ): string {
		$_GET = array_merge( [ 'page' => 'mai-analytics' ], $query );

		ob_start();
		( new Admin() )->render_page();

		return (string) ob_get_clean();
	}

	public function test_url_filters_render_as_selected_controls(): void {
		$cat    = self::factory()->category->create( [ 'name' => 'Linked Cat' ] );
		$author = self::factory()->user->create( [ 'display_name' => 'Linked Author' ] );

		$html = $this->render( [
			'subtab'    => 'posts',
			'type'      => 'post',
			'tax'       => 'category',
			'terms'     => $cat . ',987654',
			'authors'   => (string) $author,
			'published' => '90',
			'search'    => 'curry',
			'per_page'  => '50',
		] );

		$this->assertStringContainsString( 'class="mai-analytics-filters has-taxonomy" data-tab="posts"', $html );
		$this->assertStringContainsString( '<option value="post" selected>Posts</option>', $html );
		$this->assertStringContainsString( '<option value="category" selected>Categories</option>', $html );
		$this->assertStringContainsString( '<option value="' . $cat . '" selected>Linked Cat</option>', $html );
		$this->assertStringNotContainsString( '987654', $html, 'Unknown term IDs are dropped.' );
		$this->assertStringContainsString( '<option value="' . $author . '" selected>Linked Author</option>', $html );
		$this->assertMatchesRegularExpression( '/<option value="90"\s+selected=\'selected\'>/', $html );
		$this->assertStringContainsString( 'value="curry"', $html );
		$this->assertMatchesRegularExpression( '/<option value="50"\s+selected=\'selected\'>/', $html );
	}

	public function test_published_defaults_to_30_days(): void {
		$html = $this->render( [] );

		$this->assertMatchesRegularExpression( '/<option value="30"\s+selected=\'selected\'>/', $html );
		$this->assertStringContainsString( 'data-default="30"', $html );
		$this->assertStringNotContainsString( 'is-custom', $html );
	}

	public function test_published_all_time_and_custom_days(): void {
		$html = $this->render( [ 'published' => '0' ] );

		$this->assertMatchesRegularExpression( '/<option value="0"\s+selected=\'selected\'>/', $html );

		$html = $this->render( [ 'published' => '45' ] );

		$this->assertStringContainsString( 'mai-analytics-filters__published is-custom', $html );
		$this->assertMatchesRegularExpression( '/<option value="custom"\s+selected=\'selected\'>/', $html );
		$this->assertStringContainsString( 'value="45"', $html );
	}

	public function test_invalid_url_values_are_ignored(): void {
		register_post_type( 'mai_hidden_type', [ 'public' => false ] );

		$html = $this->render( [
			'subtab'   => 'nope',
			'type'     => 'mai_hidden_type',
			'tax'      => [ 'category' ],
			'authors'  => 'abc',
			'per_page' => '7',
		] );

		_unregister_post_type( 'mai_hidden_type' );

		$this->assertStringContainsString( 'class="mai-analytics-filters" data-tab="posts"', $html );
		$this->assertStringNotContainsString( 'value="mai_hidden_type"', $html );
		$this->assertMatchesRegularExpression( '/<option value="25"\s+selected=\'selected\'>/', $html );
	}

	public function test_terms_tab_renders_its_tab_on_the_filters_row(): void {
		$html = $this->render( [ 'subtab' => 'terms', 'tax' => 'post_tag' ] );

		$this->assertStringContainsString( 'class="mai-analytics-filters has-taxonomy" data-tab="terms"', $html );
		$this->assertStringContainsString( '<option value="post_tag" selected>Tags</option>', $html );
	}
}
