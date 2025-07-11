<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class SitePulseWP_Cron {

    public static function init() {
        $options = get_option( 'sitepulsewp_settings' );
        if ( empty( $options['uptime_enabled'] ) ) {
            return; // Uptime monitor disabled.
        }

        add_action( 'wp', array( __CLASS__, 'setup_cron' ) );
        add_action( 'sitepulsewp_uptime_check', array( __CLASS__, 'run_uptime_check' ) );
        add_action( 'sitepulsewp_monitor_check', array( __CLASS__, 'run_monitor_checks' ) );
    }

    /**
     * Schedule cron if not exists
     */
    public static function setup_cron() {
        if ( ! wp_next_scheduled( 'sitepulsewp_uptime_check' ) ) {
            wp_schedule_event( time(), 'five_minutes', 'sitepulsewp_uptime_check' );
        }
        if ( ! wp_next_scheduled( 'sitepulsewp_monitor_check' ) ) {
            wp_schedule_event( time(), 'daily', 'sitepulsewp_monitor_check' );
        }
    }

    

    /**
     * Perform site ping
     */
    public static function run_uptime_check() {
        $url = home_url();
        $start = microtime(true);
        $response = wp_remote_get( $url, array( 'timeout' => 10 ) );
        $end = microtime(true);

        $response_time = round( ($end - $start) * 1000, 2 ); // ms

        if ( is_wp_error( $response ) ) {
            SitePulseWP_Logger::log( 'Uptime Check Failed', 'Error: ' . $response->get_error_message(), 0 );
        } else {
            $status = wp_remote_retrieve_response_code( $response );
            $details = sprintf( 'URL: %s | HTTP Status: %d | Response Time: %sms', $url, $status, $response_time );

            if ( $status != 200 ) {
                SitePulseWP_Logger::log( 'Downtime Detected', $details, 0 );
            } else {
                SitePulseWP_Logger::log( 'Uptime OK', $details, 0 );
            }
        }
    }

    public static function run_monitor_checks() {
        SitePulseWP_Monitor::run_checks();
    }

}
