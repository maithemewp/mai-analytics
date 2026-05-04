<?php

/**
 * Plugin Name:     Mai Analytics
 * Plugin URI:      https://bizbudding.com/
 * Description:     View tracking for posts, terms, and authors. Supports self-hosted tracking, Google Analytics (via Site Kit), Matomo, and Jetpack Stats.
 * Version:         1.1.5
 *
 * Author:          BizBudding
 * Author URI:      https://bizbudding.com
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) exit;

// Prevent double-loading when installed standalone AND bundled via Composer.
if ( defined( 'MAI_ANALYTICS_VERSION' ) ) {
	return;
}

use Mai\Analytics\Database;
use Mai\Analytics\Plugin;

// Constants.
define( 'MAI_ANALYTICS_VERSION', '1.1.5' );
define( 'MAI_ANALYTICS_DB_VERSION', '1.0.2' );
define( 'MAI_ANALYTICS_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'MAI_ANALYTICS_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'MAI_ANALYTICS_PLUGIN_FILE', __FILE__ );
define( 'MAI_ANALYTICS_BASENAME', dirname( plugin_basename( __FILE__ ) ) );

// Composer autoload (PSR-4 + plugin-update-checker). Re-prepend this copy's
// ClassLoader on plugins_loaded so Mai\Analytics\* resolves to this plugin's
// src/ even when another plugin (e.g. Mai Publisher) also has Composer
// autoloader registered. Every composer autoload_real.php calls register(true),
// which means whichever plugin's vendor/autoload.php ran last sits at the head
// of spl_autoload_functions. Deferring to plugins_loaded lets us re-prepend
// after all plugin main files have finished loading.
$mai_analytics_loader = require_once __DIR__ . '/vendor/autoload.php';

if ( $mai_analytics_loader instanceof \Composer\Autoload\ClassLoader ) {
	add_action( 'plugins_loaded', function () use ( $mai_analytics_loader ) {
		$mai_analytics_loader->unregister();
		$mai_analytics_loader->register( true );
	}, 0 );
}

unset( $mai_analytics_loader );

// Helpers (previously auto-loaded via composer files autoload).
require_once __DIR__ . '/includes/functions.php';

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
