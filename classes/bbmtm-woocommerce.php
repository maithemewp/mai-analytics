<?php
/**
 * Mai Matomo WooCommerce interface.
 *  - set and track Woo events, memberships, subscriptions, etc.
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



function get_membership_ids( $user_id) {

	$memberships = NULL;

	// Bail if Woo Memberships is not active.
	if ( ! function_exists( 'wc_memberships_get_user_memberships' ) ) {
		return NULL;
	}

	// Get active memberships.
	$memberships = wc_memberships_get_user_memberships( $user_id, array( 'status' => 'active' ) );
		if ( ! $memberships ) {
			return false;
		}
		// Get active membership IDs.
		$ids = wp_list_pluck( $memberships, 'plan_id' );
		return $ids;
}

function get_organization_data ($ids) {

		/**
		 * Organization data.
		 * key is the WooCommerce Membership plan_id.
		 * values are the organization name 
		 *
		 * NOTE: A user will only be tracked by 1 organization.
		 * It will use the last organization/plan they are in, in the loop.
		 */

		$org_data = [
			7830 => [
				'name' => 'Atlantic Metro'
			],
			7176 => [
				'name' => 'PeopleTek'
			],
			12978 => [
				'name' => 'Telaid'
			],
			15061 => [
				'name' => 'Telaid Meetings Group 1'
			],
			15382 => [
				'name' => 'Telaid Meetings Group 2'
			],
			15016 => [
				'name' => 'The Mentoring and Coaching Program'
			],
		];

		// Loop through organizations.
		foreach ( $org_data as $plan_id => $values ) {
			// Skip if user is not part of this membership.
			if ( ! in_array( $plan_id, $ids ) ) {
				continue;
			}
			// Add the data layer values.
			return $values['name'];
		}

		return NULL;

}