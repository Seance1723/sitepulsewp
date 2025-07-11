<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Security monitoring utilities.
 */
class SitePulseWP_Security {

    /**
     * Register hooks for security monitoring.
     */
    public static function init() {
        add_action( 'wp_login_failed', array( __CLASS__, 'capture_failed_login' ) );
        add_action( 'sitepulsewp_security_summary', array( __CLASS__, 'daily_summary' ) );
    }

    /**
     * Log failed login attempts with IP address.
     */
    public static function capture_failed_login( $username ) {
        $ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( $_SERVER['REMOTE_ADDR'] ) : '';
        $details = sprintf( 'Username: %s, IP: %s', $username, $ip );
        SitePulseWP_Logger::log( 'Login Failed', $details, 0 );
    }

    /**
     * Daily security summary log.
     */
    public static function daily_summary() {
        global $wpdb;
        $table = $wpdb->prefix . 'sitepulsewp_logs';
        $since = date( 'Y-m-d H:i:s', strtotime( '-1 day' ) );
        $failed = $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $table WHERE event_type = 'Login Failed' AND created_at >= %s", $since ) );
        $details = sprintf( 'Failed logins past 24h: %d', intval( $failed ) );
        SitePulseWP_Logger::log( 'Security Summary', $details, 0 );
    }
}