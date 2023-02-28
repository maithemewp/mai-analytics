<?php
/**
 * Mai Matomo Tracker Module.
 *  - This code extends the Mai Theme & related plugin functionallity to use Matomo Anlytics
 *  - required Matomo Analytics to be implemented
 *
 * Use via `mai_analytics()` function.
 *
 * @package   BizBudding
 * @link      https://bizbudding.com/
 * @version   0.02
 * @author    BizBudding
 * @copyright Copyright © 2022 BizBudding
 * @license   GPL-2.0-or-later
 *

* Matomo - free/libre analytics platform
*
* For more information, see README.md
*
* @license released under BSD License http://www.opensource.org/licenses/bsd-license.php
* @link https://matomo.org/docs/tracking-api/
*
* @category Matomo
* @package MatomoTracker
*/

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) exit;

class Mai_Analytics_Tracker {
	/**
	 * @var Mai_Analytics_Tracker
	 *
	 * @since 0.1.0
	 */
	private static $instance;

	/**
	 * Main Mai_Analytics_Tracker Instance.
	 *
	 * Insures that only one instance of Mai_Analytics_Tracker exists in memory at any one
	 * time. Also prevents needing to define globals all over the place.
	 *
	 * @since 0.1.0
	 *
	 * @static var array $instance
	 *
	 * @return Mai_Analytics_Tracker|false
	 */
	public static function instance() {
		if ( ! isset( self::$instance ) ) {
			// Setup the setup.
			// self::$instance = new Mai_Analytics_Tracker;
			self::$instance = $object->get_instance();
		}

		return self::$instance;
	}

	/**
	 * Gets the main matomo tracker instance.
	 *
	 * @since 0.1.0
	 *
	 * @return MatomoTracker|false
	 */
	private function get_instance() {
		// Bail if not using Matomo Analytics (defined in wp-config.php).
		if ( ! ( defined( 'MAI_ANALYTICS' ) && MAI_ANALYTICS ) ) {
			return false;
		}

		// Bail if Matamo PHP library is not available.
		if ( ! class_exists( 'MatomoTracker' ) ) {
			return false;
		}

		// Tracker object.
		$object = new Mai_Analytics_Tracker;
		// Site ID
		$site_id = defined( 'MAI_ANALYTICS_SITE_ID' ) ? (int) MAI_ANALYTICS_SITE_ID : 0;
		// Matomo URL.
		$url = defined( 'MAI_ANALYTICS_URL' ) ? esc_url( MAI_ANALYTICS_URL ) : 'https://analytics.bizbudding.com';
		// Authentication token
		$token = defined( 'MAI_ANALYTICS_TOKEN' ) ? MAI_ANALYTICS_TOKEN : '';

		// Bail if we don't have the data we need.
		if ( ! ( $site_id && $url && $token ) ) {
			return false;
		}

		// Instantiate the Matomo object.
		$tracker = new MatomoTracker( $site_id, $url );

		// Set authentication token.
		$tracker->setTokenAuth( $token );

		return $tracker;
	}

	/**
	 * Push a log message to Spatie Ray and the console.
	 *
	 * @since 0.1.0
	 *
	 * @param string $log The log string.
	 * @param bool   $script Whether to add script tags if logging in console.
	 *
	 * @return void
	 */
	public function debug( $log, $script = true ) {
		if ( ! MAI_ANALYTICS_DEBUG ) {
			return;
		}

		$this->ray( $log );

		$console_log = sprintf( 'console.log( %s )', json_encode( "Mai Analytics: {$log}", JSON_HEX_TAG ) );

		if ( $script ) {
			$console_log = '<script>' .  $console_log . '</script>';
		}

		echo $console_log;
	}

	/**
	 * Debug via Spatie Ray.
	 *
	 * @link https://spatie.be/docs/ray/v1/the-ray-desktop-app/discovering-the-ray-app#content-connecting-to-remote-servers
	 *
	 * @since 0.1.0
	 *
	 * @param mixed $log
	 *
	 * @return void
	 */
	public function ray( $log ) {
		if ( ! function_exists( 'ray' ) ) {
			return;
		}

		ray( $log );
	}
}
