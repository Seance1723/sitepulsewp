<?php
/*
Plugin Name: SitePulseWP
Plugin URI: https://example.com/sitepulsewp
Description: Monitor uptime, content changes, media updates, and plugin/theme modifications.
Version: 0.1.0
Author: SitePulse
License: GPL2
*/

if (!defined('ABSPATH')) exit;

class SitePulseWP {
    const LOG_TABLE = 'sitepulsewp_logs';

    public static function init() {
        register_activation_hook(__FILE__, [__CLASS__, 'activate']);
        register_deactivation_hook(__FILE__, [__CLASS__, 'deactivate']);
        register_uninstall_hook(__FILE__, [__CLASS__, 'uninstall']);
        add_action('admin_menu', [__CLASS__, 'add_admin_menu']);
        add_action('sitepulsewp_cron', [__CLASS__, 'run_checks']);
        add_action('transition_post_status', [__CLASS__, 'track_post_changes'], 10, 3);
        add_action('add_attachment', [__CLASS__, 'log_new_media']);
        add_action('edit_attachment', [__CLASS__, 'log_media_edit']);
        add_action('upgrader_process_complete', [__CLASS__, 'log_updates'], 10, 2);
    }

    public static function activate() {
        global $wpdb;
        $table = $wpdb->prefix . self::LOG_TABLE;
        $charset = $wpdb->get_charset_collate();
        $sql = "CREATE TABLE IF NOT EXISTS $table (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            event varchar(100) NOT NULL,
            details text NOT NULL,
            logged_at datetime NOT NULL,
            PRIMARY KEY  (id)
        ) $charset";
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql);
        if (!wp_next_scheduled('sitepulsewp_cron')) {
            wp_schedule_event(time(), 'hourly', 'sitepulsewp_cron');
        }
    }

    public static function deactivate() {
        wp_clear_scheduled_hook('sitepulsewp_cron');
    }

    public static function uninstall() {
        global $wpdb;
        $wpdb->query('DROP TABLE IF EXISTS ' . $wpdb->prefix . self::LOG_TABLE);
    }

    public static function add_admin_menu() {
        add_menu_page(
            'SitePulseWP Logs',
            'SitePulseWP',
            'manage_options',
            'sitepulsewp',
            [__CLASS__, 'render_admin_page'],
            'dashicons-chart-line',
            70
        );
    }

    public static function render_admin_page() {
        global $wpdb;
        $table = $wpdb->prefix . self::LOG_TABLE;
        $logs = $wpdb->get_results("SELECT * FROM $table ORDER BY logged_at DESC LIMIT 50", ARRAY_A);
        echo '<div class="wrap"><h1>SitePulseWP Logs</h1>';
        echo '<table class="widefat"><thead><tr><th>Date</th><th>Event</th><th>Details</th></tr></thead><tbody>';
        if ($logs) {
            foreach ($logs as $log) {
                $details = esc_html(print_r(maybe_unserialize($log['details']), true));
                echo '<tr><td>' . esc_html($log['logged_at']) . '</td><td>' . esc_html($log['event']) . '</td><td><pre>' . $details . '</pre></td></tr>';
            }
        } else {
            echo '<tr><td colspan="3">No logs yet.</td></tr>';
        }
        echo '</tbody></table></div>';
    }

    public static function log($event, $details) {
        global $wpdb;
        $wpdb->insert(
            $wpdb->prefix . self::LOG_TABLE,
            [
                'event' => $event,
                'details' => maybe_serialize($details),
                'logged_at' => current_time('mysql'),
            ],
            ['%s', '%s', '%s']
        );
    }

    public static function run_checks() {
        self::check_uptime();
        self::check_new_content();
    }

    private static function check_uptime() {
        $response = wp_remote_get(home_url());
        if (is_wp_error($response)) {
            self::log('downtime', $response->get_error_message());
        } else {
            $code = wp_remote_retrieve_response_code($response);
            if ($code >= 200 && $code < 300) {
                self::log('uptime', 'Site reachable with code ' . $code);
            } else {
                self::log('downtime', 'HTTP status ' . $code);
            }
        }
    }

    public static function track_post_changes($new_status, $old_status, $post) {
        if ($post->post_type !== 'post' && $post->post_type !== 'page') return;
        if ($old_status !== 'publish' && $new_status === 'publish') {
            self::log('new_content', ['ID' => $post->ID, 'title' => $post->post_title]);
        } elseif ($new_status === 'publish' && $old_status === 'publish') {
            self::log('content_edit', ['ID' => $post->ID, 'title' => $post->post_title]);
        }
    }

    private static function check_new_content() {
        // placeholder for future extension
    }

    public static function log_new_media($attachment_id) {
        $attachment = get_post($attachment_id);
        self::log('new_media', ['ID' => $attachment_id, 'file' => $attachment->post_title]);
    }

    public static function log_media_edit($attachment_id) {
        $attachment = get_post($attachment_id);
        self::log('edit_media', ['ID' => $attachment_id, 'file' => $attachment->post_title]);
    }

    public static function log_updates($upgrader, $hook_extra) {
        if (!empty($hook_extra['type'])) {
            self::log($hook_extra['type'] . '_update', $hook_extra);
        }
    }
}

SitePulseWP::init();