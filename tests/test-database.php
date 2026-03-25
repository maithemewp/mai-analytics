<?php

use Mai\Analytics\Database;

class Test_Database extends WP_UnitTestCase {

	public function setUp(): void {
		parent::setUp();
		Database::create_table();
	}

	public function tearDown(): void {
		global $wpdb;
		$wpdb->query( 'TRUNCATE TABLE ' . Database::get_table_name() );
		parent::tearDown();
	}

	public function test_table_exists(): void {
		global $wpdb;
		$table  = Database::get_table_name();
		$result = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );

		$this->assertEquals( $table, $result );
	}

	public function test_table_name_has_prefix(): void {
		global $wpdb;
		$table = Database::get_table_name();

		$this->assertStringStartsWith( $wpdb->prefix, $table );
		$this->assertStringEndsWith( 'mai_analytics_views', $table );
	}

	public function test_insert_view(): void {
		global $wpdb;
		$table   = Database::get_table_name();
		$post_id = self::factory()->post->create();

		$result = Database::insert_view( $post_id, 'post', 'web' );
		$this->assertNotFalse( $result );

		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $table WHERE object_id = %d AND object_type = 'post'", $post_id ) );
		$this->assertNotNull( $row );
		$this->assertEquals( $post_id, (int) $row->object_id );
		$this->assertEquals( 'post', $row->object_type );
		$this->assertEquals( 'web', $row->source );
	}

	public function test_insert_view_term(): void {
		global $wpdb;
		$table   = Database::get_table_name();
		$term_id = self::factory()->category->create();

		Database::insert_view( $term_id, 'term', 'app' );

		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $table WHERE object_id = %d AND object_type = 'term'", $term_id ) );
		$this->assertNotNull( $row );
		$this->assertEquals( 'app', $row->source );
	}

	public function test_insert_view_user(): void {
		global $wpdb;
		$table   = Database::get_table_name();
		$user_id = self::factory()->user->create();

		Database::insert_view( $user_id, 'user', 'web' );

		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $table WHERE object_id = %d AND object_type = 'user'", $user_id ) );
		$this->assertNotNull( $row );
		$this->assertEquals( 'user', $row->object_type );
	}

	public function test_db_version_option_set(): void {
		$this->assertEquals( MAI_ANALYTICS_DB_VERSION, get_option( Database::DB_VERSION_OPTION ) );
	}
}
