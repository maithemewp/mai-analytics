<?php

/**
 * Add organization data to Google Tag Manager dataLayer.
 *
 * This class checks if a logged in user is part of an organization.
 *
 * Each organization on QwikCoach needs it's own global membership that
 * any users must be in, in order for the data to be added to the dataLayer.
 *
 * @uses     DuracellTomi's Google Tag Manager for WordPress.
 * @link     https://wordpress.org/plugins/duracelltomi-google-tag-manager/
 *
 * @version  1.0.0
 */
class qc_GTM_Memberships {

	private $user_id;
	private $membership_ids;
	private $organizations;

	function __construct() {
		if ( ! is_user_logged_in() ) {
			return;
		}
		$this->user_id        = get_current_user_id();
		$this->membership_ids = $this->get_membership_ids( $this->user_id );
		if ( ! $this->membership_ids ) {
			return;
		}
		/**
		 * Organization data.
		 * key is the WooCommerce Membership plan_id.
		 * values are the organization name and GTM ID.
		 *
		 * NOTE: A user will only be tracked by 1 organization.
		 * It will use the last organization/plan they are in, in the loop.
		 */
		$this->organizations = [
			7176 => [
				'name' => 'PeopleTek',
				'gtm'  => 'GTM-M4JWRM7',
			],
			7830 => [
				'name' => 'Atlantic Metro',
				'gtm'  => 'GTM-MCK8X6N',
			],
			// 8220 => [
			// 	'name' => 'American Express',
			// 	'gtm'  => '',
			// ],
			12978 => [
				'name' => 'Telaid',
				'gtm'  => 'GTM-K8N7F3D',
			],
			15061 => [
				'name' => 'Telaid Meetings Group 1',
				'gtm'  => 'GTM-5PL7MXR',
			],
			15382 => [
				'name' => 'Telaid Meetings Group 2',
				'gtm'  => 'GTM-WLPGV6F',
			],
			15016 => [
				'name' => 'The Mentoring and Coaching Program',
				'gtm'  => 'GTM-M4JWRM7',
			],
		];
		// Filter datalayer, from DuracellTomi's Google Tag Manager plugin.
		add_filter( 'gtm4wp_compile_datalayer', array( $this, 'datalayer' ) );
		// These match Mai Ads & Extra Content hooks.
		add_action( 'wp_head',        array( $this, 'do_gtm_header' ) );
		add_action( 'genesis_before', array( $this, 'do_gtm_body' ), 4 );
	}

	/**
	 * Add data to Google Tag Manager dataLayer.
	 *
	 * @return  array
	 */
	function get_membership_ids() {
		// Bail if Woo Memberships is not active.
		if ( ! function_exists( 'wc_memberships_get_user_memberships' ) ) {
			return false;
		}
		// Get active memberships.
		$memberships = wc_memberships_get_user_memberships( $this->user_id, array( 'status' => 'active' ) );
		if ( ! $memberships ) {
			return false;
		}
		// Get active membership IDs.
		$ids = wp_list_pluck( $memberships, 'plan_id' );
		return $ids;
	}

	/**
	 * Add datalayer values.
	 *
	 * @return  array  The modified data layer values.
	 */
	function datalayer( $data ) {
		// Loop through organizations.
		foreach ( $this->organizations as $plan_id => $values ) {
			// Skip if user is not part of this membership.
			if ( ! in_array( $plan_id, $this->membership_ids ) ) {
				continue;
			}
			// Add the data layer values.
			$data['qwikcoachGroup'] = $values['name'];
		}
		return $data;
	}

	/**
	 * Add Google Tag Manager header code.
	 *
	 * @return  void
	 */
	function do_gtm_header() {
		// Loop through organizations.
		foreach ( $this->organizations as $plan_id => $values ) {
			// Skip if user is not part of this membership.
			if ( ! in_array( $plan_id, $this->membership_ids ) ) {
				continue;
			}
			// Output the header code.
			echo $this->get_gtm_header( $values['gtm'] );
		}
	}

	/**
	 * Add Google Tag Manager body code.
	 *
	 * @return  void
	 */
	function do_gtm_body() {
		// Loop through organizations.
		foreach ( $this->organizations as $plan_id => $values ) {
			// Skip if user is not part of this membership.
			if ( ! in_array( $plan_id, $this->membership_ids ) ) {
				continue;
			}
			// Output the body code.
			echo $this->get_gtm_body( $values['gtm'] );
		}
	}

	/**
	 * Get Google Tag Manager header code.
	 *
	 * @return  void
	 */
	function get_gtm_header( $id ) {
		return sprintf( "<!-- Google Tag Manager -->
		<script>(function(w,d,s,l,i){w[l]=w[l]||[];w[l].push({'gtm.start':
		new Date().getTime(),event:'gtm.js'});var f=d.getElementsByTagName(s)[0],
		j=d.createElement(s),dl=l!='dataLayer'?'&l='+l:'';j.async=true;j.src=
		'https://www.googletagmanager.com/gtm.js?id='+i+dl;f.parentNode.insertBefore(j,f);
		})(window,document,'script','dataLayer','%s');</script>
		<!-- End Google Tag Manager -->", $id );
	}

	/**
	 * Get Google Tag Manager body code.
	 *
	 * @return  void
	 */
	function get_gtm_body( $id ) {
		return sprintf( '<!-- Google Tag Manager (noscript) -->
		<noscript><iframe src="https://www.googletagmanager.com/ns.html?id=%s"
		height="0" width="0" style="display:none;visibility:hidden"></iframe></noscript>
		<!-- End Google Tag Manager (noscript) -->', $id );
	}
}

/**
 * Instantiate the class.
 *
 * @return  void
 */
add_action( 'after_setup_theme', function() {
	new qc_GTM_Memberships;
});
