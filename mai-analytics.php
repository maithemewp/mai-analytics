<?php

/**
 * Plugin Name:     Mai Analytics
 * Plugin URI:      https://bizbudding.com/
 * Description:     Track first-party analytics with Matomo.
 * Version:         0.1.0
 *
 * Author:          BizBudding
 * Author URI:      https://bizbudding.com
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) exit;

// Must be at the top of the file.
use YahnisElsts\PluginUpdateChecker\v5\PucFactory;

/**
 * Main Mai_Analytics Class.
 *
 * @since 0.1.0
 */
final class Mai_Analytics {

	/**
	 * @var Mai_Analytics The one true Mai_Analytics
	 *
	 * @since 0.1.0
	 */
	private static $instance;

	/**
	 * Main Mai_Analytics Instance.
	 *
	 * Insures that only one instance of Mai_Analytics exists in memory at any one
	 * time. Also prevents needing to define globals all over the place.
	 *
	 * @since 0.1.0
	 *
	 * @static var array $instance
	 *
	 * @uses Mai_Analytics::setup_constants() Setup the constants needed.
	 * @uses Mai_Analytics::includes() Include the required files.
	 * @uses Mai_Analytics::hooks() Activate, deactivate, etc.
	 *
	 * @see Mai_Analytics()
	 *
	 * @return object | Mai_Analytics The one true Mai_Analytics
	 */
	public static function instance() {
		if ( ! isset( self::$instance ) ) {
			// Setup the setup.
			self::$instance = new Mai_Analytics;
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
			define( 'MAI_ANALYTICS_VERSION', '0.1.0' );
		}

		// Plugin Folder Path.
		if ( ! defined( 'MAI_ANALYTICS_PLUGIN_DIR' ) ) {
			define( 'MAI_ANALYTICS_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
		}

		// Plugin Includes Path.
		if ( ! defined( 'MAI_ANALYTICS_INCLUDES_DIR' ) ) {
			define( 'MAI_ANALYTICS_INCLUDES_DIR', MAI_ANALYTICS_PLUGIN_DIR . 'includes/' );
		}

		// Plugin Classes Path.
		if ( ! defined( 'MAI_ANALYTICS_CLASSES_DIR' ) ) {
			define( 'MAI_ANALYTICS_CLASSES_DIR', MAI_ANALYTICS_PLUGIN_DIR . 'classes/' );
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
		foreach ( glob( MAI_ANALYTICS_INCLUDES_DIR . '*.php' ) as $file ) { include $file; }
		// Classes.
		foreach ( glob( MAI_ANALYTICS_CLASSES_DIR . '*.php' ) as $file ) { include $file; }
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
		// add_action( 'after_setup_theme', [ $this, 'classes' ], 99 );
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
		$track   = new Mai_Analytics_Tracking;
		$content = new Mai_Analytics_Content_Tracking;
	}
}

/**
 * The main function for that returns Mai_Analytics
 *
 * The main function responsible for returning the one true Mai_Analytics
 * Instance to functions everywhere.
 *
 * @access private
 *
 * @since 0.1.0
 *
 * @return object|Mai_Analytics The one true Mai_Analytics Instance.
 */
function mai_analytics_plugin() {
	return Mai_Analytics::instance();
}

// Get Mai_Analytics Running.
mai_analytics_plugin();
