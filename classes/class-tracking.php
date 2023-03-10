<?php
/**
 * Mai Matomo Tracker Module.
 *  - This code extends the Mai Theme & related plugin functionallity to use Matomo Anlytics
 *  - required Matomo Analytics to be implemented
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

class Mai_Analytics_Tracking {
	private $user;
	private $user_email;
	private $dimension;

	/**
	 * Construct the class.
	 *
	 * @return void
	 */
	function __construct() {
		$this->hooks();
	}

	/**
	 * Runs frontend hooks.
	 *
	 * @since 0.1.0
	 *
	 * @return void
	 */
	function hooks() {
		add_action( 'wp_enqueue_scripts', [ $this, 'enqueue' ] );
	}

	/**
	 * Enqueues script in footer if we're tracking the current page.
	 *
	 * This should not be necessary yet, if we have the main Matomo header script.
	 *
	 * @since TBD
	 *
	 * @return void
	 */
	function enqueue() {
		// Bail if not tracking.
		if ( ! mai_analytics_should_track() ) {
			return;
		}

		$tracker = mai_analytics_tracker();

		// Bail if no a valid tracker.
		if ( ! $tracker ) {
			return;
		}

		// Set user.
		$this->user = wp_get_current_user(); // Returns 0 if not logged in.

		// Set vars for JS.
		$vars = [
			'siteId'     => mai_analytics_site_id(),
			'trackerUrl' => mai_analytics_url(),
			'token'      => mai_analytics_token(),
			'userId'     => $this->user ? $this->user->user_email : '',
			'dimensions' => $this->get_custom_dimensions(),
		];

		$version   = MAI_ANALYTICS_VERSION;
		$handle    = 'mai-analytics';
		$file      = "/assets/js/{$handle}.js"; // TODO: Add min suffix if not script debugging.
		$file_path = MAI_ANALYTICS_PLUGIN_DIR . $file;
		$file_url  = MAI_ANALYTICS_PLUGIN_URL . $file;

		if ( file_exists( $file_path ) ) {
			$version .= '.' . date( 'njYHi', filemtime( $file_path ) );

			wp_enqueue_script( $handle, $file_url, [], $version, false );
			wp_localize_script( $handle, 'maiAnalyticsVars', $vars );
		}
	}

	/**
	 * Gets custom dimensions.
	 *
	 * @since TBD
	 *
	 * @return array
	 */
	function get_custom_dimensions() {
		$dimensions = [];

		if ( ! $this->user ) {
			return $dimensions;
		}

		$dimensions = $this->set_dimension_5( $dimensions );

		return $dimensions;
	}

	/**
	 * Sets custom dimension 5.
	 *
	 * There is a filter that passes generic args for the group.
	 * This leaves us open to use dimension 5 for any sort of User Grouping we want, not just WooCommerce.
	 * We could use WP User Groups (taxonomy) or anything else, without modifying the plugin code.
	 *
	 * @since TBD
	 *
	 * @param array $dimensions The exising dimensions.
	 *
	 * @return array
	 */
	function set_dimension_5( $dimensions ) {
		$args = [];
		$args = $this->set_membership_plan_ids( $args );
		$args = $this->set_user_taxonomies( $args );

		/**
		 * Filter to manually add group per-site.
		 *
		 * @param string $name    The group name (empty for now).
		 * @param int    $user_id The logged in user ID.
		 * @param array  $args    The user data args.
		 *
		 * @return string
		 */
		$name  = '';
		$group = apply_filters( 'mai_analytics_group_name', $name, $this->user->ID, $args );
		$group = trim( esc_html( $group ) );

		// Handles group as custom dimension.
		if ( $group ) {
			mai_analytics_debug( sprintf( 'Group name: %s', $group ) );

			// Set the Group data.
			$dimensions[5] = $group;

		} else {
			mai_analytics_debug( 'No Group name found' );
		}

		return $dimensions;
	}

	/**
	 * Sets membership plan IDs.
	 *
	 * @since TBD
	 *
	 * @param array $args
	 *
	 * @return array
	 */
	function set_membership_plan_ids( $args ) {
		$plan_ids = mai_analytics_get_membership_plan_ids( $this->user->ID );

		// Handles plan IDs.
		if ( $plan_ids ) {
			$args['plan_ids'] = $plan_ids;
			mai_analytics_debug( sprintf( 'Woo Membership Plan IDs: %s', implode( ', ', $args['plan_ids'] ) ) );
		} else {
			mai_analytics_debug( 'No Woo Membership Plans' );
		}

		return $args;
	}

	/**
	 * Sets user taxonomies.
	 *
	 * @since TBD
	 *
	 * @param array $args
	 *
	 * @return array
	 */
	function set_user_taxonomies( $args ) {
		$taxonomies = mai_analytics_get_user_taxonomies( $this->user->ID );

		// If taxonomies.
		if ( $taxonomies ) {
			foreach ( $taxonomies as $name => $values ) {
				$args[ $name ] = $values;
				mai_analytics_debug( sprintf( 'User Taxonomy "%s": %s', $name, implode( ', ', array_values( $values ) ) ) );
			}
		}

		return $args;
	}
}
