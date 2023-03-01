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
		add_action( 'wp_enqueue_scripts', [ $this, 'enqueue' ] );
		add_action( 'wp_login',           [ $this, 'login' ], 10, 2 );
		add_action( 'wp_footer',          [ $this, 'page_view' ], 99 );
		// add_action( 'woocommerce_payment_complete', [ $this, 'payment_complete' ] );
	}

	/**
	 * Enqueues script in footer if we're tracking the current page.
	 *
	 * @since 0.1.0
	 *
	 * @return void
	 */
	function enqueue() {
		$tracker = mai_analytics_tracker();

		if ( ! $tracker ) {
			return;
		}

		$version   = MAI_ANALYTICS_VERSION;
		$handle    = 'mai-analytics';
		$file      = "/assets/js/{$handle}.js"; // TODO: Add min suffix if not script debugging.
		$file_path = MAI_ANALYTICS_PLUGIN_DIR . $file;
		$file_url  = MAI_ANALYTICS_PLUGIN_URL . $file;

		if ( file_exists( $file_path ) ) {
			$version .= '.' . date( 'njYHi', filemtime( $file_path ) );

			wp_enqueue_script( $handle, $file_url, [], $version, true );
			wp_localize_script( $handle, 'maiAnalyticsVars',
				[
					'siteID' => mai_analytics_site_id(),
					'url'    => mai_analytics_url(),
					// 'token'  => mai_analytics_token(),
				]
			);
		}
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
		$tracker = mai_analytics_tracker();

		if ( ! $tracker ) {
			return;
		}

		$this->user = $user;

		// Set the user id based upon the WordPress email for the user.
		$tracker->setUserId( $user->user_email );
		$this->debug( sprintf( 'tracker->setUserID result (%s)', $tracker->userId ) );

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
				$this->debug( sprintf( 'Woo Membership Plan IDs (%s)', implode( ', ', $plan_ids ) ) );
			} else {
				$this->debug( 'No Membership Plans' );
			}

			// Handles teams as custom dimension.
			if ( $team ) {
				$this->debug( sprintf( 'Team name %s', $team ) );

				// Set the Team data as the 5th custom dimension
				$tracker->setCustomDimension( 5, $team );
			} else {
				$this->debug( 'No Team name found%s' );
			}
		}

		// Track title. Should we strip query strings here, or does Matomo handle it? Or maybe we want them?
		$tracker->doTrackPageView( $this->get_title() );
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
	}

	/**
	 * Gets current page title.
	 *
	 * @since 0.1.0
	 *
	 * @return string
	 */
	function get_title() {
		$title = '';

		if ( is_singular() ) {
			$title = get_the_title();

		} elseif ( is_front_page() ) {
			// This would only run if front page is not a static page, since is_singular() is first.
			$title = apply_filters( 'genesis_latest_posts_title', esc_html__( 'Latest Posts', 'mai-engine' ) );

		} elseif ( is_home() ) {
			// This would only run if front page and blog page are static pages, since is_front_page() is first.
			$title = get_the_title( get_option( 'page_for_posts' ) );

		} elseif ( class_exists( 'WooCommerce' ) && is_shop() ) {
			$title = get_the_title( wc_get_page_id( 'shop' ) );

		} elseif ( is_post_type_archive() && genesis_has_post_type_archive_support( mai_get_post_type() ) ) {
			$title = genesis_get_cpt_option( 'headline' );

			if ( ! $title ) {
				$title = post_type_archive_title( '', false );
			}
		} elseif ( is_category() || is_tag() || is_tax() ) {
			/**
			 * WP Query.
			 *
			 * @var WP_Query $wp_query WP Query object.
			 */
			global $wp_query;

			$term = is_tax() ? get_term_by( 'slug', get_query_var( 'term' ), get_query_var( 'taxonomy' ) ) : $wp_query->get_queried_object();

			if ( $term ) {
				$title = get_term_meta( $term->term_id, 'headline', true );

				if ( ! $title ) {
					$title = $term->name;
				}
			}
		} elseif ( is_search() ) {
			$title = apply_filters( 'genesis_search_title_text', esc_html__( 'Search results for: ', 'mai-engine' ) . get_search_query() );

		} elseif ( is_author() ) {
			$title = get_the_author_meta( 'headline', (int) get_query_var( 'author' ) );

			if ( ! $title ) {
				$title = get_the_author_meta( 'display_name', (int) get_query_var( 'author' ) );
			}
		} elseif ( is_date() ) {
			$title = __( 'Archives for ', 'mai-engine' );

			if ( is_day() ) {
				$title .= get_the_date();

			} elseif ( is_month() ) {
				$title .= single_month_title( ' ', false );

			} elseif ( is_year() ) {
				$title .= get_query_var( 'year' );
			}
		} elseif ( is_404() ) {
			$title = apply_filters( 'genesis_404_entry_title', esc_html__( 'Not found, error 404', 'mai-engine' ) );
		}

		$title = apply_filters( 'mai_analytics_page_title', $title );

		return esc_attr( $title );
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
