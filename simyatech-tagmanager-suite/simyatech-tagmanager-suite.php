<?php
/**
 * Plugin Name: SimyaTech Tag Manager Suite
 * Plugin URI:  https://github.com/enigma01Z313/simyatech-tagmanager-suite
 * Description: Pushes the Bookly booking funnel (step views, booking start, payment started, booking completed) into the Google Tag Manager dataLayer, and marks every page with its slug / English base slug for GTM triggers.
 * Version:     1.0.0
 * Author:      Farzin Ahmadi
 * License:     GPLv3
 * Text Domain: simyatech-tagmanager-suite
 *
 * The plugin never modifies Bookly. It only observes Bookly's public AJAX
 * actions and DOM, and reads booking data through Bookly's own entity API.
 */

defined( 'ABSPATH' ) || exit;

define( 'STMS_VERSION', '1.0.0' );
define( 'STMS_FILE', __FILE__ );
define( 'STMS_PATH', plugin_dir_path( __FILE__ ) );
define( 'STMS_URL', plugin_dir_url( __FILE__ ) );

require_once STMS_PATH . 'includes/class-stms-language.php';
require_once STMS_PATH . 'includes/class-stms-page-meta.php';
require_once STMS_PATH . 'includes/class-stms-bookly-data.php';
require_once STMS_PATH . 'includes/class-stms-ajax.php';
require_once STMS_PATH . 'includes/class-stms-assets.php';
require_once STMS_PATH . 'includes/class-stms-plugin.php';

STMS_Plugin::instance()->init();
