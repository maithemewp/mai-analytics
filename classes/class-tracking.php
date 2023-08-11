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
	private $dimensions;

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
		add_filter( 'script_loader_tag',  [ $this, 'add_async' ], 10, 3 );
	}

	/**
	 * Enqueues script if we're tracking the current page.
	 * This should not be necessary yet, if we have the main Matomo header script.
	 *
	 * @since 0.1.0
	 *
	 * @return void
	 */
	function enqueue() {
		// Bail if not tracking.
		if ( ! mai_analytics_should_track() ) {
			return;
		}

		$tracker = mai_analytics_tracker();

		// Bail if no tracker.
		if ( ! $tracker ) {
			return;
		}

		// Set user.
		$this->user = wp_get_current_user(); // Returns 0 if not logged in.

		// Set vars for JS.
		$vars = [
			'siteId'     => mai_analytics_get_option( 'site_id' ),
			'trackerUrl' => mai_analytics_get_option( 'url' ),
			'token'      => mai_analytics_get_option( 'token' ),
			'userId'     => $this->user ? $this->user->user_email : '',
			'dimensions' => $this->get_custom_dimensions(),
		];

		// If singular or a term archive (all we care about now).
		if ( is_singular() || is_category() || is_tag() || is_tax() ) {
			$trending_days = (int) mai_analytics_get_option( 'trending_days' );
			$views_days    = (int) mai_analytics_get_option( 'views_days' );
			$interval      = (int) mai_analytics_get_option( 'views_interval' );

			// If we're fetching trending or popular counts.
			if ( ( $trending_days || $views_days ) && $interval ) {
				// Get page data and current timestamp.
				$page     = mai_analytics_get_current_page();
				$current  = current_datetime()->getTimestamp();

				// Get last updated timestamp.
				if ( is_singular() ) {
					$updated = get_post_meta( $page['id'], 'mai_views_updated', true );
				} else {
					$updated = get_term_meta( $page['id'], 'mai_views_updated', true );
				}

				// If last updated timestampe is more than N minutes (converted to seconds) ago.
				if ( ! $updated || $updated < ( $current - ( $interval * 60 ) ) ) {
					$vars['ajaxUrl'] = admin_url( 'admin-ajax.php' );
					$vars['nonce']   = wp_create_nonce( 'mai_analytics_views_nonce' );
					$vars['type']    = $page['type'];
					$vars['id']      = $page['id'];
					$vars['url']     = $page['url'];
					$vars['current'] = $current;
				}
			}
		}

		$version   = MAI_ANALYTICS_VERSION;
		$handle    = 'mai-analytics';
		$suffix    = defined( 'SCRIPT_DEBUG' ) && SCRIPT_DEBUG ? '' : '.min';
		$file      = "assets/js/{$handle}{$suffix}.js";
		$file_path = MAI_ANALYTICS_PLUGIN_DIR . $file;
		$file_url  = MAI_ANALYTICS_PLUGIN_URL . $file;

		if ( file_exists( $file_path ) ) {
			$version .= '.' . date( 'njYHi', filemtime( $file_path ) );

			wp_enqueue_script( $handle, $file_url, [], $version, true );
			wp_localize_script( $handle, 'maiAnalyticsVars', $vars );
		}
	}

	/**
	 * Gets custom dimensions.
	 *
	 * @since 0.1.0
	 *
	 * @return array
	 */
	function get_custom_dimensions() {
		$this->dimensions = [];

		if ( ! $this->user ) {
			return;
		}

		// TODO.
		$this->set_dimension_1();
		// TODO.
		$this->set_dimension_2();
		// TODO.
		$this->set_dimension_3();
		// TODO.
		$this->set_dimension_4();
		// User group/membership/team.
		$this->set_dimension_5();
		// Content age.
		$this->set_dimension_6();
		// Post category.
		$this->set_dimension_7();
		// Content length.
		$this->set_dimension_8();
		// Content type.
		$this->set_dimension_9();

		return $this->dimensions;
	}

	// TODO.
	function set_dimension_1() {}
	function set_dimension_2() {}
	function set_dimension_3() {}
	function set_dimension_4() {}

	/**
	 * Gets user group/membership/team.
	 *
	 * There is a filter that passes generic args for the group.
	 * This leaves us open to use dimension 5 for any sort of User Grouping we want, not just WooCommerce.
	 * We could use WP User Groups (taxonomy) or anything else, without modifying the plugin code.
	 *
	 * @since 0.1.0
	 *
	 * @return array
	 */
	function set_dimension_5() {
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
			mai_analytics_debug( sprintf( 'Group name / %s', $group ) );

			// Set the Group data.
			$this->dimensions[5] = $group;

		} else {
			mai_analytics_debug( 'No Group name found' );
		}

		return;
	}

	/**
	 * Gets content age.
	 *
	 * @since 0.1.0
	 *
	 * @return array
	 */
	function set_dimension_6() {
		$date = get_the_date( 'F j, Y' );

		if ( ! $date ) {
			return;
		}

		$range  = false;
		$date   = new DateTime( $date );
		$today  = new DateTime( 'now' );
		$days   = $today->diff( $date )->format( '%a' );
		$ranges = [
			[ 0, 29 ],
			[ 30, 89 ],
			[ 90, 179 ],
			[ 180, 364 ],
			[ 367, 729 ],
		];

		foreach ( $ranges as $index => $values ) {
			if ( ! filter_var( $days, FILTER_VALIDATE_INT,
				[
					'options' => [
						'min_range' => $values[0],
						'max_range' => $values[1],
					],
				],
			)) {
				continue;
			}

			$range = sprintf( '%s-%s', $values[0], $values[1] );
		}

		if ( ! $range && $days > 729 ) {
			$range = '2000+';
		}

		if ( ! $range ) {
			return;
		}

		$this->dimensions[6] = $range . ' days';
	}

	/**
	 * Gets post category.
	 *
	 * @todo Add support for CPT and Custom Taxonomies?
	 *
	 * @since 0.1.0
	 *
	 * @return array
	 */
	function set_dimension_7() {
		if ( ! is_singular( 'post' ) ) {
			return;
		}

		$primary = $this->get_primary_term( 'category', get_the_ID() );

		if ( ! $primary ) {
			return;
		}

		$this->dimensions[7] = $primary->name; // Term name.
	}

	/**
	 * Gets content length.
	 *
	 * @since 0.1.0
	 *
	 * @return array
	 */
	function set_dimension_8() {
		$range   = false;
		$content = '';

		if ( is_singular() ) {
			$content .= get_post_field( 'post_content', get_the_ID() );
		}
		// Get ads from Mai Archive Pages.
		elseif ( function_exists( 'maiap_get_archive_page' ) ) {
			$pages = [
				maiap_get_archive_page( true ),
				maiap_get_archive_page( false ),
			];

			$pages = array_filter( $pages );

			if ( $pages ) {
				foreach ( $pages as $page ) {
					$content .= $page->post_content;
				}
			}
		}

		if ( ! $content ) {
			return;
		}

		$content = mai_analytics_get_processed_content( $content );
		$count   = str_word_count( strip_tags( $content ) );
		$ranges  = [
			[ 0, 499 ],
			[ 500, 999 ],
			[ 1000, 1999 ],
		];

		foreach ( $ranges as $index => $values ) {
			if ( ! filter_var( $count, FILTER_VALIDATE_INT,
				[
					'options' => [
						'min_range' => $values[0],
						'max_range' => $values[1],
					],
				],
			)) {
				continue;
			}

			$range = sprintf( '%s-%s', $values[0], $values[1] );
		}

		if ( ! $range && $count > 1999 ) {
			$range = '2000+';
		}

		if ( ! $range ) {
			return;
		}

		$this->dimensions[8] = $range . ' words';
	}

	/**
	 * Gets content type.
	 *
	 * @since 0.1.0
	 *
	 * @return array
	 */
	function set_dimension_9() {
		// Uses readable name as the type. 'Post' instead of 'post'.
		$type = mai_analytics_get_current_page( 'name' );

		if ( ! $type ) {
			return;
		}

		$this->dimensions[9] = $type;
	}

	/**
	 * Sets membership plan IDs in args.
	 *
	 * @since 0.1.0
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
			mai_analytics_debug( sprintf( 'Woo Membership Plan IDs / %s', implode( ', ', $args['plan_ids'] ) ) );
		} else {
			mai_analytics_debug( 'No Woo Membership Plans' );
		}

		return $args;
	}

	/**
	 * Sets user taxonomies.
	 *
	 * @since 0.1.0
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
				mai_analytics_debug( sprintf( 'User Taxonomy / %s / %s', $name, implode( ', ', array_values( $values ) ) ) );
			}
		}

		return $args;
	}

	/**
	 * Gets the primary term of a post, by taxonomy.
	 * If Yoast Primary Term is used, return it,
	 * otherwise fallback to the first term.
	 *
	 * @version 1.3.0
	 *
	 * @since 0.1.0
	 *
	 * @link https://gist.github.com/JiveDig/5d1518f370b1605ae9c753f564b20b7f
	 * @link https://gist.github.com/jawinn/1b44bf4e62e114dc341cd7d7cd8dce4c
	 *
	 * @param string $taxonomy The taxonomy to get the primary term from.
	 * @param int    $post_id  The post ID to check.
	 *
	 * @return WP_Term|false The term object or false if no terms.
	 */
	function get_primary_term( $taxonomy = 'category', $post_id = false ) {;
		if ( ! $taxonomy ) {
			return false;
		}

		// If no post ID, set it.
		if ( ! $post_id ) {
			$post_id = get_the_ID();
		}

		// Bail if no post ID.
		if ( ! $post_id ) {
			return false;
		}

		// Setup caching.
		static $cache = null;

		// Maybe return cached value.
		if ( is_array( $cache ) ) {
			if ( isset( $cache[ $taxonomy ][ $post_id ] ) ) {
				return $cache[ $taxonomy ][ $post_id ];
			}
		} else {
			$cache = [];
		}

		// If checking for WPSEO.
		if ( class_exists( 'WPSEO_Primary_Term' ) ) {

			// Get the primary term.
			$wpseo_primary_term = new WPSEO_Primary_Term( $taxonomy, $post_id );
			$wpseo_primary_term = $wpseo_primary_term->get_primary_term( 'category', get_the_ID());;
			// If we have one, return it.
			if ( $wpseo_primary_term ) {
				$cache[ $taxonomy ][ $post_id ] = get_term( $wpseo_primary_term );
				return $cache[ $taxonomy ][ $post_id ];
			}
		}

		// We don't have a primary, so let's get all the terms.
		$terms = get_the_terms( $post_id, $taxonomy );

		// Bail if no terms.
		if ( ! $terms || is_wp_error( $terms ) ) {
			$cache[ $taxonomy ][ $post_id ] = false;
			return $cache[ $taxonomy ][ $post_id ];
		}

		// Get the first, and store in cache.
		$cache[ $taxonomy ][ $post_id ] = reset( $terms );

		// Return the first term.
		return $cache[ $taxonomy ][ $post_id ];
	}

	/**
	 * Add async tag to our script.
	 *
	 * @since 0.4.0
	 *
	 * @param string $tag    The `<script>` tag for the enqueued script.
	 * @param string $handle The script's registered handle.
	 * @param string $src    The script's source URL.
	 *
	 * @return void
	 */
	function add_async( $tag, $handle ) {
		if ( 'mai-analytics' !== $handle ) {
			return $tag;
		}

		if ( ! class_exists( 'WP_HTML_Tag_Processor' ) ) {
			return $tag;
		}

		$tags = new WP_HTML_Tag_Processor( $tag );

		while ( $tags->next_tag( 'script' ) ) {
			$tags->set_attribute( 'async', '' );
		}

		$tag = $tags->get_updated_html();

		return $tag;
	}
}
