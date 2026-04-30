<?php

// Exit if not called by WordPress during plugin uninstall.
defined( 'WP_UNINSTALL_PLUGIN' ) || die;

global $wpdb;

// Drop the buffer table.
$table = $wpdb->prefix . 'mai_analytics_buffer';
$wpdb->query( "DROP TABLE IF EXISTS `$table`" );

// Delete all plugin options.
$wpdb->query( "DELETE FROM $wpdb->options WHERE option_name LIKE 'mai_analytics_%'" );

// Delete transients (stored as _transient_mai_analytics_* and _transient_timeout_mai_analytics_*).
$wpdb->query( "DELETE FROM $wpdb->options WHERE option_name LIKE '_transient_mai_analytics_%' OR option_name LIKE '_transient_timeout_mai_analytics_%'" );

// Delete meta keys from all meta tables.
$meta_keys = "'mai_views','mai_views_web','mai_views_app','mai_trending','mai_views_synced_at'";

$wpdb->query( "DELETE FROM $wpdb->postmeta WHERE meta_key IN ($meta_keys)" );
$wpdb->query( "DELETE FROM $wpdb->termmeta WHERE meta_key IN ($meta_keys)" );
$wpdb->query( "DELETE FROM $wpdb->usermeta WHERE meta_key IN ($meta_keys)" );

// Clear scheduled cron.
wp_clear_scheduled_hook( 'mai_analytics_cron_sync' );
wp_clear_scheduled_hook( 'mai_analytics_provider_catchup' );
