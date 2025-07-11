<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Website monitoring utilities.
 */
class SitePulseWP_Monitor {

    /**
     * Run all monitoring checks.
     */
    public static function run_checks() {
        self::seo_check();
        self::performance_check();
        self::security_check();
        self::traffic_overview();
    }

    /**
     * Basic SEO health check.
     */
    public static function seo_check() {
        $response = wp_remote_get( home_url() );
        if ( is_wp_error( $response ) ) {
            return;
        }

        $body = wp_remote_retrieve_body( $response );
        if ( strpos( $body, 'name="description"' ) === false ) {
            SitePulseWP_Logger::log( 'SEO Warning', 'No meta description tag found on homepage', 0 );
        } else {
            SitePulseWP_Logger::log( 'SEO OK', 'Meta description found on homepage', 0 );
        }
    }

    /**
     * Measure homepage response time.
     */
    public static function performance_check() {
        $start    = microtime( true );
        $response = wp_remote_get( home_url(), array( 'timeout' => 10 ) );
        $end      = microtime( true );

        $time = round( ( $end - $start ) * 1000, 2 );

        if ( is_wp_error( $response ) ) {
            SitePulseWP_Logger::log( 'Performance Check Failed', $response->get_error_message(), 0 );
        } else {
            SitePulseWP_Logger::log( 'Performance Metric', 'Response time: ' . $time . 'ms', 0 );
        }
    }

    /**
     * Check for pending updates as a basic security metric.
     */
    public static function security_check() {
        $plugin_updates = get_plugin_updates();
        $plugin_count   = is_array( $plugin_updates ) ? count( $plugin_updates ) : 0;

        $core_updates = get_core_updates();
        $core_needed  = ( ! empty( $core_updates ) && ! empty( $core_updates[0]->response ) && 'upgrade' === $core_updates[0]->response ) ? 1 : 0;

        if ( $plugin_count || $core_needed ) {
            $details = sprintf( 'Pending updates - Core: %d, Plugins: %d', $core_needed, $plugin_count );
            SitePulseWP_Logger::log( 'Security Warning', $details, 0 );
        } else {
            SitePulseWP_Logger::log( 'Security OK', 'No pending updates', 0 );
        }
    }

    /**
     * Simple traffic overview using post and comment counts.
     */
    public static function traffic_overview() {
        $post_count    = wp_count_posts()->publish;
        $comment_count = wp_count_comments()->total_comments;

        $details = sprintf( 'Published Posts: %d, Total Comments: %d', $post_count, $comment_count );
        SitePulseWP_Logger::log( 'Traffic Overview', $details, 0 );
    }
}
