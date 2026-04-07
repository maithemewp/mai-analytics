<?php

use Mai\Analytics\MaiGrid;

class Test_Grid extends WP_UnitTestCase {

	private MaiGrid $grid;

	public function setUp(): void {
		parent::setUp();
		$this->grid = new MaiGrid();
	}

	public function test_trending_choice_added(): void {
		$field = [ 'choices' => [ 'date' => 'Date' ] ];
		set_current_screen( 'edit-post' );

		$result = $this->grid->add_trending_choice( $field );

		$this->assertArrayHasKey( 'trending', $result['choices'] );
		$this->assertStringContainsString( 'Mai Analytics', $result['choices']['trending'] );
	}

	public function test_views_choice_added_first(): void {
		$field = [ 'choices' => [ 'date' => 'Date' ] ];
		set_current_screen( 'edit-post' );

		$result = $this->grid->add_views_choice( $field );
		$keys   = array_keys( $result['choices'] );

		$this->assertEquals( 'views', $keys[0] );
	}

	public function test_query_modified_for_trending(): void {
		$result = $this->grid->handle_query( [], [ 'query_by' => 'trending' ] );

		$this->assertEquals( 'mai_trending', $result['meta_key'] );
		$this->assertEquals( 'meta_value_num', $result['orderby'] );
		$this->assertEquals( 'DESC', $result['order'] );
	}

	public function test_query_modified_for_views(): void {
		$result = $this->grid->handle_query( [], [ 'orderby' => 'views' ] );

		$this->assertEquals( 'mai_views', $result['meta_key'] );
		$this->assertEquals( 'meta_value_num', $result['orderby'] );
	}

	public function test_query_unmodified_for_other(): void {
		$result = $this->grid->handle_query( [ 'orderby' => 'date' ], [ 'orderby' => 'date' ] );

		$this->assertArrayNotHasKey( 'meta_key', $result );
	}
}
