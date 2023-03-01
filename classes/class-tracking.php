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
	public $user;

	function __construct() {
		$this->user = wp_get_current_user(); // Returns 0 if not logged in.
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
		add_action( 'wp_login', [ $this, 'login' ], 10, 2 );
		add_action( 'wp_head',  [ $this, 'page_view' ], 90 );
		// add_action( 'woocommerce_payment_complete', [ $this, 'payment_complete' ] );
	}

	/**
	 * Updates user property if user is not logged in when class is instantiated.
	 * Sends an event to Motomo to set the userID email based upon the login of a current user.
	 *
	 * @param string  $user_login Username.
	 * @param WP_User $user       Object of the logged-in user.
	 *
	 * @return void
	 */
	function login( $user_login, $user ) {
		$tracker = mai_analytics_tracker();

		if ( ! $tracker ) {
			return;
		}

		$this->user = $user;

		// Set the user id based upon the WordPress email for the user.
		$tracker->setUserId( $user->user_email );
		$this->debug( sprintf( 'tracker->setUserID result (%s)%s', $tracker->userId, PHP_EOL ) );

		// todo: track the login as a registered event and not a pageview
		$tracker->doTrackPageView( 'Account Log In' );
	}

	/**
	 * Sends an pageview event tracker.
	 *
	 * @since 0.1.0
	 *
	 * @return void
	 */
	function page_view ( ) {
		// Bail if not tracking.
		if ( ! $this->should_track() ) {
			return;
		}

		$tracker = mai_analytics_tracker();

		if ( ! $tracker ) {
			return;
		}

		// If we have a logged-in user, prep the username to be added to tracking
		if ( $this->user ) {

			$this->debug( sprintf( 'Current user email is %s', $this->user->user_email ) );

			$tracker->setUserId( $this->user->user_email );

			// todo: track the login as a registered event and not a pageview

			$this->debug( 'Checking for Woo Memberships Plans and Teams' );

			// check if we have any Woo Memberships plans/organizations.
			$plan_ids = $this->get_membership_plan_ids();
			$team     = $this->get_team( $plan_ids );

			// Handles plan IDs.
			if ( $plan_ids ) {
				$this->debug( sprintf( 'Woo Membership Plan IDs (%s)%s', implode( ', ', $plan_ids ), PHP_EOL ) );
			} else {
				$this->debug( sprintf( 'No Membership Plans%s', PHP_EOL ) );
			}

			// Handles teams as custom dimension.
			if ( $team ) {
				$this->debug( sprintf( 'Team name%s%s', $team, PHP_EOL ) );

				// Set the Team data as the 5th custom dimension
				$tracker->setCustomDimension( 5, $team );
			} else {
				$this->debug( sprintf( 'No Team name found%s', PHP_EOL ) );
			}
		}

		// Track title. Should we strip query strings here, or does Matomo handle it? Or maybe we want them?
		// $tracker->doTrackPageView( mai_analytics_get_title() );

		// Tracking with the URL from Matomo.
		$tracker->doTrackPageView( $tracker->pageUr );
	}

	/**
	 * Gets membership plan IDs.
	 *
	 * @since 0.1.0
	 *
	 * @return array|int[]
	 */
	function get_membership_plan_ids() {
		static $cache = [];

		if ( isset( $cache[ $this->user->ID ] ) ) {
			return $cache[ $this->user->ID ];
		}

		$cache[ $this->user->ID ] = [];

		// Bail if Woo Memberships is not active.
		if ( ! ( class_exists( 'WooCommerce' ) && function_exists( 'wc_memberships_get_user_memberships' ) ) ) {
			return $cache[ $this->user->ID ];
		}

		// Get active memberships.
		$memberships = wc_memberships_get_user_memberships( $this->user->ID, array( 'status' => 'active' ) );

		if ( $memberships ) {
			// Get active membership IDs.
			$cache[ $this->user->ID ] = wp_list_pluck( $memberships, 'plan_id' );
		}

		return $cache[ $this->user->ID ];
	}

	/**
	 * Gets a team name.
	 *
	 * This should eventually use WooCommerce Team Memberships and get team ID/data.
	 * right in the plugin. For now, we'll use a filter.
	 *
	 * @since 0.1.0
	 *
	 * @param array|int[] $plan_ids
	 *
	 * @return string
	 */
	function get_team( $plan_ids ) {
		/**
		 * Filter to manually add team per-site.
		 *
		 * @return string
		 */
		$team = apply_filters( 'mai_analytics_team_name', $this->user->ID, $plan_ids );

		return $team;

		// /**
		//  * Gets Qwikcoach team name from plan IDs.
		//  *
		//  * @param int   $user_id
		//  * @param int[] $plan_ids
		//  *
		//  * @return string
		//  */
		// add_filter( 'mai_analytics_team_name', function( $user_id, $plan_ids ) {
		// 	$team = '';

		// 	// Bail if no user or plan IDs.
		// 	if ( ! ( $user_id && $plan_ids ) ) {
		// 		return $team;
		// 	}

		// 	/**
		// 	 * Organization data.
		// 	 * key is the WooCommerce Membership plan_id.
		// 	 * values are the organization name
		// 	 *
		// 	 * NOTE: A user will only be tracked by 1 organization.
		// 	 * It will use the last organization/plan they are in, in the loop.
		// 	 */

		// 	$org_data = [
		// 		7830 => [
		// 			'name' => 'Atlantic Metro'
		// 		],
		// 		7176 => [
		// 			'name' => 'PeopleTek'
		// 		],
		// 		12978 => [
		// 			'name' => 'Telaid'
		// 		],
		// 		15061 => [
		// 			'name' => 'Telaid Meetings Group 1'
		// 		],
		// 		15382 => [
		// 			'name' => 'Telaid Meetings Group 2'
		// 		],
		// 		15016 => [
		// 			'name' => 'The Mentoring and Coaching Program'
		// 		],
		// 	];

		// 	// Loop through organizations.
		// 	foreach ( $org_data as $plan_id => $values ) {
		// 		// Skip if user is not part of this membership.
		// 		if ( ! in_array( $plan_id, $plan_ids ) ) {
		// 			continue;
		// 		}
		// 		// Add the data layer values.
		// 		return $values['name'];
		// 	}

		// 	return '';
		// });
	}


	/**
	 * Track data when payment is complete.
	 *
	 * @since TBD
	 *
	 * @param int $order_id
	 *
	 * @return void
	 */
	function payment_complete( $order_id ) {
		$tracker = mai_analytics_tracker();

		if ( ! $tracker ) {
			return;
		}

		$order = wc_get_order( $order_id );
		$user  = $order->get_user();

		if ( $user ){
			// Do something with the user.
		}
	}

	/**
	 * If on a page that we should be tracking.
	 *
	 * @since 0.1.0
	 *
	 * @return bool
	 */
	function should_track() {
		static $cache = null;

		if ( ! is_null( $cache ) ) {
			return $cache;
		}

		$cache = false;

		// bail if we are in an ajax call
		if ( wp_doing_ajax() ) {
			return $cache;
		}

		if ( wp_is_json_request() ) {
			return $cache;
		}

		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			return $cache;
		}

		// Bail if admin page and we're not tracking
		if ( defined( 'MAI_ANALYTICS_ADMIN' ) && ! MAI_ANALYTICS_ADMIN && is_admin() ) {
			return $cache;
		}

		// we got here, set cache and let's track it
		$cache = true;

		return $cache;
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
