<?php

namespace Mai\Analytics;

use YahnisElsts\PluginUpdateChecker\v5\PucFactory;

class Plugin {

	/**
	 * Boots all plugin components. Called on plugins_loaded.
	 *
	 * @return void
	 */
	public static function init(): void {
		Migration::maybe_migrate();
		Database::maybe_update();
		self::apply_migrated_defaults();

		new Meta();
		new RestApi();
		new AdminRestApi();
		new Tracker();
		new Cron();
		new MaiGrid();

		// Register [mai_views] shortcode.
		add_shortcode( 'mai_views', 'mai_analytics_get_views' );

		if ( is_admin() ) {
			new Admin();
			new AdminSettings();
		}

		self::register_providers();
		self::setup_updater();

		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			new CLI();
		}
	}

	/**
	 * Applies migrated filter defaults from Mai Publisher.
	 *
	 * Mai Publisher stored trending_days and views_interval as DB options.
	 * Mai Analytics uses filters. This bridges the gap for migrated sites.
	 *
	 * @return void
	 */
	private static function apply_migrated_defaults(): void {
		$defaults = get_option( 'mai_analytics_migrated_defaults', [] );

		if ( empty( $defaults ) ) {
			return;
		}

		if ( ! empty( $defaults['trending_window'] ) ) {
			add_filter( 'mai_analytics_trending_window', function() use ( $defaults ) {
				return (int) $defaults['trending_window'];
			}, 5 ); // Priority 5 so site-specific filters at 10 can override.
		}

		if ( ! empty( $defaults['sync_interval'] ) ) {
			add_filter( 'mai_analytics_sync_interval', function() use ( $defaults ) {
				return (int) $defaults['sync_interval'];
			}, 5 );
		}
	}

	/**
	 * Registers built-in analytics providers.
	 *
	 * @return void
	 */
	private static function register_providers(): void {
		add_filter( 'mai_analytics_providers', function( array $providers ): array {
			$providers[] = new Providers\SiteKit();
			$providers[] = new Providers\Matomo();
			$providers[] = new Providers\Jetpack();

			return $providers;
		} );
	}

	/**
	 * Sets up the GitHub plugin updater.
	 *
	 * @return void
	 */
	private static function setup_updater(): void {
		// Skip updater when loaded as a Composer dependency inside another plugin.
		if ( str_contains( MAI_ANALYTICS_PLUGIN_DIR, '/vendor/' ) ) {
			return;
		}

		if ( ! class_exists( PucFactory::class ) ) {
			return;
		}

		$updater = PucFactory::buildUpdateChecker(
			'https://github.com/maithemewp/mai-analytics/',
			MAI_ANALYTICS_PLUGIN_FILE,
			'mai-analytics'
		);

		$updater->setBranch( 'main' );

		if ( defined( 'MAI_GITHUB_API_TOKEN' ) ) {
			$updater->setAuthentication( MAI_GITHUB_API_TOKEN );
		}

		if ( function_exists( 'mai_get_updater_icons' ) && $icons = mai_get_updater_icons() ) {
			$updater->addResultFilter(
				function ( $info ) use ( $icons ) {
					$info->icons = $icons;
					return $info;
				}
			);
		}
	}
}
