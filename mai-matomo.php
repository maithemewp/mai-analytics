<?php

/**
 * Plugin Name:     Mai Matomo
 * Plugin URI:      https://bizbudding.com/
 * Description:     Matomo helper plugin for BizBudding/Mai Theme.
 * Version:         0.0.3
 *
 * Author:          BizBudding
 * Author URI:      https://bizbudding.com
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) exit;

define ('bbmtm_DEBUG', true);

require_once __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/classes/bbmtm.php';

/**
 * Instantiate the class.
 *
 * @return  void
 */

 add_action( 'after_setup_theme', function() {
// add_action( 'template_redirect', function() {
    new bbmtm;

});
    