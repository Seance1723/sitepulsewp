<?php
/**
 * Plugin Name: SitePulseWP
 * Description: Monitors uptime/downtime, content changes, and plugin/theme logs for your WordPress site.
 * Version: 1.0.7
 * Author: Your Name
 * Text Domain: sitepulsewp
 */

if ( ! defined( 'ABSPATH' ) ) exit;

define( 'SITEPULSEWP_VERSION', '1.0.7' );
define( 'SITEPULSEWP_PATH', plugin_dir_path( __FILE__ ) );
define( 'SITEPULSEWP_URL', plugin_dir_url( __FILE__ ) );

require_once SITEPULSEWP_PATH . 'includes/activator.php';
require_once SITEPULSEWP_PATH . 'includes/class-sitepulsewp-core.php';
require_once SITEPULSEWP_PATH . 'includes/class-sitepulsewp-admin.php';
require_once SITEPULSEWP_PATH . 'includes/class-sitepulsewp-logger.php';
require_once SITEPULSEWP_PATH . 'includes/class-sitepulsewp-monitor.php';
require_once SITEPULSEWP_PATH . 'includes/class-sitepulsewp-security.php';
require_once SITEPULSEWP_PATH . 'includes/class-sitepulsewp-cron.php';
require_once SITEPULSEWP_PATH . 'includes/class-sitepulsewp-backup.php';
require_once SITEPULSEWP_PATH . 'includes/functions-helpers.php';

register_activation_hook( __FILE__, array( 'SitePulseWP_Activator', 'activate' ) );
register_uninstall_hook( __FILE__, 'sitepulsewp_uninstall' );

function sitepulsewp_uninstall() {
    global $wpdb;
    $table = $wpdb->prefix . 'sitepulsewp_logs';
    $wpdb->query( "DROP TABLE IF EXISTS $table" );
}

add_filter( 'cron_schedules', function( $schedules ) {
    $schedules['five_minutes'] = array(
        'interval' => 300, // 5 min
        'display'  => 'Every Five Minutes'
    );
    return $schedules;
} );

register_deactivation_hook( __FILE__, array( 'SitePulseWP_Activator', 'deactivate' ) );


function sitepulsewp_init() {
    SitePulseWP_Core::instance();
    SitePulseWP_Security::init();
    if ( is_admin() ) {
        SitePulseWP_Admin::instance();
    }
    SitePulseWP_Cron::init();
    SitePulseWP_Backup::init();
}
add_action( 'plugins_loaded', 'sitepulsewp_init' );
