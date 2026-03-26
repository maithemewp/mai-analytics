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
		Database::maybe_update();

		new Meta();
		new RestApi();
		new AdminRestApi();
		new Tracker();
		new Cron();
		new MaiGrid();

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
	 * Registers built-in analytics providers.
	 *
	 * @return void
	 */
	private static function register_providers(): void {
		add_filter( 'mai_analytics_providers', function( array $providers ): array {
			$providers[] = new Providers\SiteKit();
			$providers[] = new Providers\Matomo();

			return $providers;
		} );
	}

	/**
	 * Sets up the GitHub plugin updater.
	 *
	 * @return void
	 */
	private static function setup_updater(): void {
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
