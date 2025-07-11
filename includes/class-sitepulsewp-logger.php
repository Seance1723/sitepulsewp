<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class SitePulseWP_Logger {

    public static function log( $type, $details, $user_id = null ) {
        global $wpdb;
        $table = $wpdb->prefix . 'sitepulsewp_logs';

        if ( $user_id === null ) {
            $user_id = get_current_user_id();
        }

        $wpdb->insert(
            $table,
            array(
                'event_type'   => sanitize_text_field( $type ),
                // allow some html so diffs can be viewed nicely
                'event_details' => wp_kses_post( $details ),
                'user_id'      => absint( $user_id ),
                'created_at'   => current_time( 'mysql' )
            ),
            array( '%s', '%s', '%d', '%s' )
        );
    }

}
