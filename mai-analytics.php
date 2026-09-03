<?php

/**
 * Plugin Name:     Mai Analytics
 * Plugin URI:      https://bizbudding.com/
 * Description:     View tracking for posts, terms, and authors. Supports self-hosted tracking, Google Analytics (via Site Kit), Matomo, and Jetpack Stats.
 * Version:         1.3.4
 * Requires PHP:    8.1
 *
 * Author:          BizBudding
 * Author URI:      https://bizbudding.com
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) exit;

if ( ! function_exists( 'mai_analytics_standalone_is_active' ) ) {
	/**
	 * Checks whether a standalone Mai Analytics plugin is active.
	 *
	 * Reads the active plugin list directly. is_plugin_active() lives in an admin
	 * file that isn't loaded this early, and this runs while plugins load.
	 *
	 * @since 1.3.5
	 *
	 * @return bool True when a non-bundled copy is active.
	 */
	function mai_analytics_standalone_is_active(): bool {
		$active = (array) get_option( 'active_plugins', [] );

		if ( is_multisite() ) {
			$active = array_merge( $active, array_keys( (array) get_site_option( 'active_sitewide_plugins', [] ) ) );
		}

		foreach ( $active as $plugin ) {
			if ( str_ends_with( $plugin, '/mai-analytics.php' ) && ! str_contains( $plugin, '/vendor/' ) ) {
				return true;
			}
		}

		return false;
	}
}

// A copy bundled via Composer inside another plugin always defers to a
// standalone Mai Analytics plugin, whichever one WordPress loads first. Without
// this, the winner is decided by plugin load order, so a bundled copy can
// silently shadow a newer standalone install.
if ( str_contains( wp_normalize_path( __DIR__ ), '/vendor/' ) && mai_analytics_standalone_is_active() ) {
	return;
}

// Prevent double-loading when installed standalone AND bundled via Composer.
if ( defined( 'MAI_ANALYTICS_VERSION' ) ) {
	return;
}

use Mai\Analytics\Database;
use Mai\Analytics\Plugin;

// Constants.
define( 'MAI_ANALYTICS_VERSION', '1.3.4' );
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
