<?php

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * The event tracking class.
 *
 * TODO: Use this. Currently not instantiated.
 */
class Mai_Analytics_Event_Tracking {
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
		add_action( 'wp_login',                     [ $this, 'login' ], 10, 2 );
		add_action( 'woocommerce_payment_complete', [ $this, 'payment_complete' ] );
	}

	/**
	 * Updates user property if user is not logged in when class is instantiated.
	 * Sends an event to Motomo to set the userID email based upon the login of a current user.
	 *
	 * @since 0.1.0
	 *
	 * @param string  $user_login Username.
	 * @param WP_User $user       Object of the logged-in user.
	 *
	 * @return void
	 */
	function login( $user_login, $user ) {
		if ( ! mai_analytics_should_track() ) {
			return;
		}

	}

	/**
	 * Track data when payment is complete.
	 *
	 * @since 0.1.0
	 *
	 * @param int $order_id
	 *
	 * @return void
	 */
	function payment_complete( $order_id ) {
		if ( ! mai_analytics_should_track() ) {
			return;
		}

		$order = wc_get_order( $order_id );
		$user  = $order->get_user();

		if ( $user ){
			// Do something with the user.
		}
	}
}