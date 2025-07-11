<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class SitePulseWP_Activator {
    public static function activate() {
        global $wpdb;

        $table_name = $wpdb->prefix . 'sitepulsewp_logs';
        $charset_collate = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE $table_name (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            event_type VARCHAR(100) NOT NULL,
            event_details LONGTEXT NOT NULL,
            user_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY  (id)
        ) $charset_collate;";

        require_once( ABSPATH . 'wp-admin/includes/upgrade.php' );
        dbDelta( $sql );

        $ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( $_SERVER['REMOTE_ADDR'] ) : '';
        SitePulseWP_Logger::log( 'Plugin Activated', 'SitePulseWP plugin activated from IP: ' . $ip, get_current_user_id() );
    }

    public static function deactivate() {
        wp_clear_scheduled_hook( 'sitepulsewp_uptime_check' );

        $ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( $_SERVER['REMOTE_ADDR'] ) : '';
        SitePulseWP_Logger::log( 'Plugin Deactivated', 'SitePulseWP plugin deactivated from IP: ' . $ip, get_current_user_id() );
    }
}
