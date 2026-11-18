<?php

// Exit if not called by WordPress during plugin uninstall.
defined( 'WP_UNINSTALL_PLUGIN' ) || die;

global $wpdb;

// Drop the buffer table.
$table = $wpdb->prefix . 'mai_analytics_buffer';
$wpdb->query( "DROP TABLE IF EXISTS `$table`" );

// Delete all plugin options. esc_like() keeps `_` from matching any character.
$wpdb->query(
	$wpdb->prepare(
		"DELETE FROM $wpdb->options WHERE option_name LIKE %s",
		$wpdb->esc_like( 'mai_analytics_' ) . '%'
	)
);

// Delete transients (stored as _transient_mai_analytics_* and _transient_timeout_mai_analytics_*).
$wpdb->query(
	$wpdb->prepare(
		"DELETE FROM $wpdb->options WHERE option_name LIKE %s OR option_name LIKE %s",
		$wpdb->esc_like( '_transient_mai_analytics_' ) . '%',
		$wpdb->esc_like( '_transient_timeout_mai_analytics_' ) . '%'
	)
);

// Delete meta keys from all meta tables.
$meta_keys = "'mai_views','mai_views_web','mai_views_app','mai_trending','mai_views_synced_at'";

$wpdb->query( "DELETE FROM $wpdb->postmeta WHERE meta_key IN ($meta_keys)" );
$wpdb->query( "DELETE FROM $wpdb->termmeta WHERE meta_key IN ($meta_keys)" );
$wpdb->query( "DELETE FROM $wpdb->usermeta WHERE meta_key IN ($meta_keys)" );

// Clear every scheduled cron hook. Written out by name because uninstall runs
// without the plugin's autoloader. Keep in step with Mai\Analytics\Cron::HOOKS.
wp_clear_scheduled_hook( 'mai_analytics_cron_sync' );
wp_clear_scheduled_hook( 'mai_analytics_provider_catchup' );
wp_clear_scheduled_hook( 'mai_analytics_prune_trending' );
wp_clear_scheduled_hook( 'mai_analytics_prune_trending_now' );
