<?php
/**
 * Mai Matomo Tracker Module.
 *  - This code extends the Mai Theme & related plugin functionallity to use Matomo Anlytics
 *  - required Matomo Analytics to be implemented
 *
 * @package   BizBudding
 * @link      https://bizbudding.com/
 * @version   0.01
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


class bbmtm {

    const bbmtm_DEBUG = 0;      // Works INSIDE of a class definition.
    private $matomoTracker;
    private $matomoSiteId;
    private $matomoUrl;
    private $matomoToken;
    private $matomoTrackAdmin;
        
    function __construct() {

        global $matomoTracker;

        require_once __DIR__ . '/matomo-php-tracker/MatomoTracker.php';

        // bail if not using Matomo Analytics (defined in WP Config)
        if (! defined('MAI_MATOMO')) {
            return false;
        }

        // set conditionals in wp-config.php or overwrite with defaults here
        if (defined('MAI_MTM_ID')) 
            $this->matomoSiteId = (int) MAI_MTM_ID; // Site ID
            else
                $this->matomoSiteId = 0; 
    
        if (defined('MAI_MTM_TOKEN'))
            $this->matomoToken = MAI_MTM_TOKEN; // authentication token
            else
                $this->matomoToken = ""; 
        
        if (defined('MAI_MTM_URL')) 
            $this->matomoUrl = MAI_MTM_URL;
            else
                $this->matomoUrl = "https://analytics.bizbudding.com";

        // Instantiate the Matomo object
        $this->matomoTracker = new MatomoTracker((int)$this->matomoSiteId, $this->matomoUrl);
        
        // Set authentication token
        $this->matomoTracker->setTokenAuth($this->matomoToken);

        // if someone logs in, let's make sure to capture that login as an event
        add_action('wp_login', array( $this, 'set_userid_on_login'), 10, 2);

        // load tracker with username on wp_head
        add_action('wp_head',  array( $this, 'doHeadTrackPageView'), 90);
    }

	/**
	 * push a log message to the console
	 *
	 * @return  array
	 */
    function console_log($output, $with_script_tags = true) {
        $js_code = 'console.log(' . json_encode(sprintf("BB-Debug: %s", $output), JSON_HEX_TAG) . ');';
        if ($with_script_tags) {
            $js_code = '<script>' .  $js_code . '</script>';
        }
        echo $js_code;
    }


    
    /**
	 * sends an pageview event tracker to Matomo when WP Head is loaded
	 *
	 * @return  NULL
	 */
     function doHeadTrackPageView ( ) {

        // bail if we are not supposed to track it
        if (! $this->track_it() ) return;

        // if we have a logged-in user, prep the username to be added to tracking
        if (is_user_logged_in()) {
            $current_user = wp_get_current_user();
            if ( ! ( $current_user instanceof WP_User ) )  $current_user->user_email = ""; 

            if (bbmtm_DEBUG) $this->console_log(sprintf("current user email is %s", $current_user->user_email));
            $this->matomoTracker->setUserId( $current_user->user_email );
        }

        // todo: track the login as a registered event and not a pageview
        $this->matomoTracker->doTrackPageView ($this->get_title());

        return;
    }

    
    /**
	 * sends an event to Motomo to set the userID email based upon the login of a current user
	 *
	 * @return  array
	 */
    function set_userid_on_login ( $user_login, $user ) {

        // global $matomoTracker;

        // set the user id based upon the WordPress email for the user
        $this->matomoTracker->setUserId( $user_login );
        if (bbmtm_DEBUG) error_log (sprintf("BB-Debug: matomoTracker->setUserID result (%s)\n", $this->matomoTracker->userId));

        // todo: track the login as a registered event and not a pageview
        $this->matomoTracker->doTrackPageView ("Account Log In");


        return;
    }

    /**
	 * sends an pageview event tracker to Matomo when WP Head is loaded
	 *
	 * @return boolean
	 */    
    function track_it() {

        // bail if we are in an ajax call
        if ( wp_doing_ajax() ) {
            return false;
        }

        if ( wp_is_json_request() ) {
            return false;
        }

        if ( defined( 'WP_CLI' ) && WP_CLI ) {
            return false;
        }
        
        // check to see if we should be logging admin pages visisted
        if (defined('MAI_MTM_TRACK_ADMIN'))
            $this->matomoTrackAdmin = MAI_MTM_TRACK_ADMIN; // track admin pages if true
        else
            $this->matomoTrackAdmin = false; 

        // bail if admin page and we're not tracking
        if ((! $this->matomoTrackAdmin) && (is_admin())) {
            return false;
        }            
        // we got here, so let's track it
        return true;
    }

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
    
        return $title;
    }
    

}

