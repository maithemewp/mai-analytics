<?php

/**
 * Plugin Name:     Mai Analytics
 * Plugin URI:      https://bizbudding.com/
 * Description:     Track first-party analytics with Matomo.
 * Version:         0.4.7
 *
 * Author:          BizBudding
 * Author URI:      https://bizbudding.com
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) exit;

// Must be at the top of the file.
use YahnisElsts\PluginUpdateChecker\v5\PucFactory;

/**
 * Main Mai_Analytics_Plugin Class.
 *
 * @since 0.1.0
 */
final class Mai_Analytics_Plugin {

	/**
	 * @var Mai_Analytics_Plugin The one true Mai_Analytics_Plugin
	 *
	 * @since 0.1.0
	 */
	private static $instance;

	/**
	 * Main Mai_Analytics_Plugin Instance.
	 *
	 * Insures that only one instance of Mai_Analytics_Plugin exists in memory at any one
	 * time. Also prevents needing to define globals all over the place.
	 *
	 * @since 0.1.0
	 *
	 * @static var array $instance
	 *
	 * @uses Mai_Analytics_Plugin::setup_constants() Setup the constants needed.
	 * @uses Mai_Analytics_Plugin::includes() Include the required files.
	 * @uses Mai_Analytics_Plugin::hooks() Activate, deactivate, etc.
	 *
	 * @see Mai_Analytics_Plugin()
	 *
	 * @return object | Mai_Analytics_Plugin The one true Mai_Analytics_Plugin
	 */
	public static function instance() {
		if ( ! isset( self::$instance ) ) {
			// Setup the setup.
			self::$instance = new Mai_Analytics_Plugin;
			// Methods.
			self::$instance->setup_constants();
			self::$instance->includes();
			self::$instance->hooks();
		}

		return self::$instance;
	}

	/**
	 * Throw error on object clone.
	 *
	 * The whole idea of the singleton design pattern is that there is a single
	 * object therefore, we don't want the object to be cloned.
	 *
	 * @since 0.1.0
	 *
	 * @access protected
	 *
	 * @return void
	 */
	public function __clone() {
		// Cloning instances of the class is forbidden.
		_doing_it_wrong( __FUNCTION__, __( 'Cheatin&#8217; huh?', 'mai-analytics' ), '1.0' );
	}

	/**
	 * Disable unserializing of the class.
	 *
	 * @since 0.1.0
	 *
	 * @access protected
	 *
	 * @return void
	 */
	public function __wakeup() {
		// Unserializing instances of the class is forbidden.
		_doing_it_wrong( __FUNCTION__, __( 'Cheatin&#8217; huh?', 'mai-analytics' ), '1.0' );
	}

	/**
	 * Setup plugin constants.
	 *
	 * @access private
	 *
	 * @since 0.1.0
	 *
	 * @return void
	 */
	private function setup_constants() {
		// Plugin version.
		if ( ! defined( 'MAI_ANALYTICS_VERSION' ) ) {
			define( 'MAI_ANALYTICS_VERSION', '0.4.7' );
		}

		// Plugin Folder Path.
		if ( ! defined( 'MAI_ANALYTICS_PLUGIN_DIR' ) ) {
			define( 'MAI_ANALYTICS_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
		}

		// Plugin Folder URL.
		if ( ! defined( 'MAI_ANALYTICS_PLUGIN_URL' ) ) {
			define( 'MAI_ANALYTICS_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
		}

		// Plugin Root File.
		if ( ! defined( 'MAI_ANALYTICS_PLUGIN_FILE' ) ) {
			define( 'MAI_ANALYTICS_PLUGIN_FILE', __FILE__ );
		}

		// Plugin Base Name
		if ( ! defined( 'MAI_ANALYTICS_BASENAME' ) ) {
			define( 'MAI_ANALYTICS_BASENAME', dirname( plugin_basename( __FILE__ ) ) );
		}
	}

	/**
	 * Include required files.
	 *
	 * @access private
	 *
	 * @since 0.1.0
	 *
	 * @return void
	 */
	private function includes() {
		// Include vendor libraries.
		require_once __DIR__ . '/vendor/autoload.php';
		// Includes.
		foreach ( glob( MAI_ANALYTICS_PLUGIN_DIR . 'includes/*.php' ) as $file ) { include $file; }
		// Classes.
		foreach ( glob( MAI_ANALYTICS_PLUGIN_DIR . 'classes/*.php' ) as $file ) { include $file; }
		// Blocks.
		include MAI_ANALYTICS_PLUGIN_DIR . 'blocks/mai-analytics-tracker/block.php';
	}

	/**
	 * Run the hooks.
	 *
	 * @since 0.1.0
	 *
	 * @return void
	 */
	public function hooks() {
		add_action( 'plugins_loaded', [ $this, 'updater' ] );
		add_action( 'plugins_loaded', [ $this, 'classes' ], 8 ); // Before default of 10, so per-site code can run on plugins_loaded default.
	}

	/**
	 * Setup the updater.
	 *
	 * composer require yahnis-elsts/plugin-update-checker
	 *
	 * @since 0.1.0
	 *
	 * @uses https://github.com/YahnisElsts/plugin-update-checker/
	 *
	 * @return void
	 */
	public function updater() {
		// Bail if plugin updater is not loaded.
		if ( ! class_exists( 'YahnisElsts\PluginUpdateChecker\v5\PucFactory' ) ) {
			return;
		}

		// Setup the updater.
		$updater = PucFactory::buildUpdateChecker( 'https://github.com/maithemewp/mai-analytics/', __FILE__, 'mai-analytics' );

		// Set the stable branch.
		$updater->setBranch( 'main' );

		// Maybe set github api token.
		if ( defined( 'MAI_GITHUB_API_TOKEN' ) ) {
			$updater->setAuthentication( MAI_GITHUB_API_TOKEN );
		}

		// Add icons for Dashboard > Updates screen.
		if ( function_exists( 'mai_get_updater_icons' ) && $icons = mai_get_updater_icons() ) {
			$updater->addResultFilter(
				function ( $info ) use ( $icons ) {
					$info->icons = $icons;
					return $info;
				}
			);
		}
	}

	/**
	 * Instantiate classes.
	 *
	 * @since 0.1.0
	 *
	 * @return void
	 */
	function classes() {
		$settings  = new Mai_Analytics_Settings;
		$track     = new Mai_Analytics_Tracking;
		$visits    = new Mai_Analytics_Views;
		$content   = new Mai_Analytics_Content_Tracking;
	}
}

/**
 * The main function for that returns Mai_Analytics_Plugin
 *
 * The main function responsible for returning the one true Mai_Analytics_Plugin
 * Instance to functions everywhere.
 *
 * @access private
 *
 * @since 0.1.0
 *
 * @return object|Mai_Analytics_Plugin The one true Mai_Analytics_Plugin Instance.
 */
function mai_analytics_plugin() {
	return Mai_Analytics_Plugin::instance();
}

// Get Mai_Analytics_Plugin Running.
mai_analytics_plugin();
