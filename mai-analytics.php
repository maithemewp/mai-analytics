<?php

/**
 * Plugin Name:     Mai Analytics
 * Plugin URI:      https://bizbudding.com/
 * Description:     View tracking for posts, terms, and authors. Supports self-hosted tracking, Google Analytics (via Site Kit), and Matomo.
 * Version:         1.0.0
 *
 * Author:          BizBudding
 * Author URI:      https://bizbudding.com
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) exit;

use Mai\Analytics\Database;
use Mai\Analytics\Plugin;

// Constants.
define( 'MAI_ANALYTICS_VERSION', '1.0.0' );
define( 'MAI_ANALYTICS_DB_VERSION', '1.1.0' );
define( 'MAI_ANALYTICS_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'MAI_ANALYTICS_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'MAI_ANALYTICS_PLUGIN_FILE', __FILE__ );
define( 'MAI_ANALYTICS_BASENAME', dirname( plugin_basename( __FILE__ ) ) );

// Composer autoload (PSR-4 + plugin-update-checker).
require_once __DIR__ . '/vendor/autoload.php';

// Activation: create database table and schedule cron.
register_activation_hook( __FILE__, function(): void {
	Database::create_table();

	if ( ! wp_next_scheduled( 'mai_analytics_cron_sync' ) ) {
		wp_schedule_event( time(), 'mai_analytics_15min', 'mai_analytics_cron_sync' );
	}
} );

// Deactivation: clear scheduled cron.
register_deactivation_hook( __FILE__, function(): void {
	wp_clear_scheduled_hook( 'mai_analytics_cron_sync' );
} );

// Initialize on plugins_loaded.
add_action( 'plugins_loaded', [ Plugin::class, 'init' ] );
