<?php

use Mai\Analytics\Database;

class Test_Database extends WP_UnitTestCase {

	public function setUp(): void {
		parent::setUp();
		Database::create_table();
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
		$this->assertStringEndsWith( 'mai_analytics_buffer', $table );
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

	/**
	 * Makes the buffer table look missing and stops dbDelta() from running anything.
	 */
	private function break_table(): void {
		$like = "SHOW TABLES LIKE '" . Database::get_table_name() . "'";

		add_filter(
			'query',
			fn( $query ) => $like === $query ? "SHOW TABLES LIKE 'mai_analytics_none'" : $query
		);
		add_filter( 'dbdelta_queries', '__return_empty_array' );
	}

	public function test_create_table_records_version(): void {
		delete_option( Database::DB_VERSION_OPTION );

		$this->assertTrue( Database::create_table() );
		$this->assertSame( MAI_ANALYTICS_DB_VERSION, get_option( Database::DB_VERSION_OPTION ) );
	}

	public function test_failed_create_does_not_record_version(): void {
		delete_option( Database::DB_VERSION_OPTION );
		delete_transient( Database::RETRY_TRANSIENT );
		$this->break_table();

		$this->assertFalse( Database::create_table() );
		$this->assertFalse( get_option( Database::DB_VERSION_OPTION ) );
		$this->assertNotFalse( get_transient( Database::RETRY_TRANSIENT ) );
	}

	public function test_maybe_update_waits_while_retry_is_set(): void {
		delete_option( Database::DB_VERSION_OPTION );
		set_transient( Database::RETRY_TRANSIENT, 1, HOUR_IN_SECONDS );

		$calls = 0;
		add_filter(
			'dbdelta_queries',
			function ( $queries ) use ( &$calls ) {
				$calls++;
				return $queries;
			}
		);

		Database::maybe_update();

		$this->assertSame( 0, $calls );
	}

	public function test_maybe_update_runs_no_query_when_current(): void {
		global $wpdb;

		update_option( 'mai_analytics_table_migrated', true );
		update_option( Database::DB_VERSION_OPTION, MAI_ANALYTICS_DB_VERSION );
		delete_transient( Database::RETRY_TRANSIENT );

		// The retry transient is normally missing, and a missing option costs a
		// SELECT. Load the version options first, so only the transient could query.
		get_option( 'mai_analytics_table_migrated' );
		get_option( Database::DB_VERSION_OPTION );

		$before = $wpdb->num_queries;
		Database::maybe_update();

		$this->assertSame( $before, $wpdb->num_queries );
	}

	public function test_old_version_not_copied_without_table(): void {
		update_option( 'mai_views_db_version', '1.0.2' );
		delete_option( 'mai_analytics_table_migrated' );
		delete_option( Database::DB_VERSION_OPTION );
		delete_transient( Database::RETRY_TRANSIENT );
		$this->break_table();

		Database::maybe_update();

		$this->assertNotSame( '1.0.2', get_option( Database::DB_VERSION_OPTION ) );
	}

	public function test_is_queued_returns_true_when_exists(): void {
		$post_id   = self::factory()->post->create();
		$last_sync = time() - 3600; // 1 hour ago.

		Database::insert_view( $post_id, 'post', 'web' );

		$this->assertTrue( Database::is_queued( $post_id, 'post', $last_sync ) );
	}

	public function test_is_queued_returns_false_when_empty(): void {
		$post_id   = self::factory()->post->create();
		$last_sync = time() + 3600; // 1 hour in the future, so nothing qualifies.

		Database::insert_view( $post_id, 'post', 'web' );

		$this->assertFalse( Database::is_queued( $post_id, 'post', $last_sync ) );
	}

	public function test_is_queued_returns_false_for_nonexistent_object(): void {
		$this->assertFalse( Database::is_queued( 999999, 'post', 0 ) );
	}

	public function test_get_distinct_objects_since(): void {
		$post_id = self::factory()->post->create();
		$term_id = self::factory()->category->create();

		Database::insert_view( $post_id, 'post', 'web' );
		Database::insert_view( $post_id, 'post', 'app' );
		Database::insert_view( $term_id, 'term', 'web' );

		$objects = Database::get_distinct_objects_since( '1970-01-01 00:00:00' );

		// Should return 2 distinct objects (post and term), not 3 rows.
		$this->assertCount( 2, $objects );

		$ids   = array_column( $objects, 'object_id' );
		$types = array_column( $objects, 'object_type' );

		$this->assertContains( (string) $post_id, $ids );
		$this->assertContains( (string) $term_id, $ids );
		$this->assertContains( 'post', $types );
		$this->assertContains( 'term', $types );
	}

	public function test_get_distinct_objects_since_respects_date(): void {
		global $wpdb;
		$table   = Database::get_table_name();
		$post_id = self::factory()->post->create();

		// Insert an old view.
		$wpdb->insert( $table, [
			'object_id'   => $post_id,
			'object_type' => 'post',
			'object_key'  => '',
			'viewed_at'   => '2020-01-01 00:00:00',
			'source'      => 'web',
		] );

		// Query since a date after the old view.
		$objects = Database::get_distinct_objects_since( '2023-01-01 00:00:00' );

		$this->assertEmpty( $objects );
	}
}
