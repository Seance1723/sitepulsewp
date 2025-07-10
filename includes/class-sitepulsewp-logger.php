<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class SitePulseWP_Logger {

    public static function log( $type, $details ) {
        global $wpdb;
        $table = $wpdb->prefix . 'sitepulsewp_logs';

        $wpdb->insert(
            $table,
            array(
                'event_type' => sanitize_text_field( $type ),
                'event_details' => sanitize_textarea_field( $details ),
                'created_at' => current_time( 'mysql' )
            ),
            array( '%s', '%s', '%s' )
        );
    }

}
