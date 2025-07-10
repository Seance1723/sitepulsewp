<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class SitePulseWP_Logger {

    public static function log( $type, $details ) {
        global $wpdb;
        $table = $wpdb->prefix . 'sitepulsewp_logs';

        $wpdb->insert(
            $table,
            array(
                'event_type'   => sanitize_text_field( $type ),
                // allow some html so diffs can be viewed nicely
                'event_details' => wp_kses_post( $details ),
                'created_at'   => current_time( 'mysql' )
            ),
            array( '%s', '%s', '%s' )
        );
    }

}
