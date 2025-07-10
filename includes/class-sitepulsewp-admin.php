<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class SitePulseWP_Admin {

    private static $instance = null;

    public static function instance() {
        if ( self::$instance == null ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_action( 'admin_menu', array( $this, 'add_admin_menu' ) );
        add_action( 'admin_post_sitepulsewp_export', array( $this, 'export_csv' ) );

        add_action( 'admin_init', array( $this, 'register_settings' ) );
    }

    public function add_admin_menu() {
        add_menu_page(
            'SitePulseWP',
            'SitePulseWP',
            'manage_options',
            'sitepulsewp',
            array( $this, 'admin_page' ),
            'dashicons-chart-area'
        );

        add_submenu_page(
            'sitepulsewp',
            'SitePulseWP Settings',
            'Settings',
            'manage_options',
            'sitepulsewp-settings',
            array( $this, 'settings_page' )
        );
    }

    public function admin_page() {
        global $wpdb;
        $table = $wpdb->prefix . 'sitepulsewp_logs';

        $event_type = isset($_GET['event_type']) ? sanitize_text_field($_GET['event_type']) : '';
        $paged = isset($_GET['paged']) ? absint($_GET['paged']) : 1;
        $per_page = 20;
        $offset = ($paged - 1) * $per_page;

        $where = '1=1';
        if ( ! empty($event_type) ) {
            $where .= $wpdb->prepare(' AND event_type = %s', $event_type);
        }

        $total = $wpdb->get_var( "SELECT COUNT(*) FROM $table WHERE $where" );
        $logs = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM $table WHERE $where ORDER BY created_at DESC LIMIT %d OFFSET %d", $per_page, $offset ) );

        $event_types = $wpdb->get_col( "SELECT DISTINCT event_type FROM $table ORDER BY event_type ASC" );

        echo '<div class="wrap">';
        echo '<h1>SitePulseWP Logs</h1>';

        echo '<form method="get">';
        echo '<input type="hidden" name="page" value="sitepulsewp">';
        echo '<select name="event_type">';
        echo '<option value="">All Types</option>';
        foreach ( $event_types as $type ) {
            printf( '<option value="%s"%s>%s</option>',
                esc_attr($type),
                selected($type, $event_type, false),
                esc_html($type)
            );
        }
        echo '</select>';
        submit_button( 'Filter', 'secondary', '', false );
        echo '</form>';

        echo '<p><a href="' . esc_url( admin_url( 'admin-post.php?action=sitepulsewp_export' ) ) . '" class="button button-primary">Export to CSV</a></p>';

        echo '<table class="widefat"><thead><tr><th>ID</th><th>Type</th><th>Details</th><th>Date</th></tr></thead><tbody>';
        if ( ! empty( $logs ) ) {
            foreach ( $logs as $log ) {
                echo "<tr><td>{$log->id}</td><td>{$log->event_type}</td><td>{$log->event_details}</td><td>{$log->created_at}</td></tr>";
            }
        } else {
            echo '<tr><td colspan="4">No logs found.</td></tr>';
        }
        echo '</tbody></table>';

        $total_pages = ceil( $total / $per_page );
        if ( $total_pages > 1 ) {
            echo '<div class="tablenav"><div class="tablenav-pages">';
            echo paginate_links( array(
                'base' => add_query_arg( 'paged', '%#%' ),
                'format' => '',
                'prev_text' => __('&laquo;'),
                'next_text' => __('&raquo;'),
                'total' => $total_pages,
                'current' => $paged
            ) );
            echo '</div></div>';
        }

        echo '</div>';
    }

    public function export_csv() {
        if ( ! current_user_can('manage_options') ) {
            wp_die('No permission.');
        }

        global $wpdb;
        $table = $wpdb->prefix . 'sitepulsewp_logs';
        $logs = $wpdb->get_results( "SELECT * FROM $table ORDER BY created_at DESC" );

        header( 'Content-Type: text/csv' );
        header( 'Content-Disposition: attachment; filename=sitepulsewp-logs.csv' );

        $output = fopen( 'php://output', 'w' );
        fputcsv( $output, array( 'ID', 'Type', 'Details', 'Date' ) );

        foreach ( $logs as $log ) {
            fputcsv( $output, array( $log->id, $log->event_type, $log->event_details, $log->created_at ) );
        }
        fclose( $output );
        exit;
    }

    public function register_settings() {
        register_setting( 'sitepulsewp_settings_group', 'sitepulsewp_settings' );

        add_settings_section(
            'sitepulsewp_main_section',
            'SitePulseWP Settings',
            null,
            'sitepulsewp-settings'
        );

        add_settings_field(
            'uptime_enabled',
            'Enable Uptime Monitor',
            array( $this, 'uptime_enabled_field' ),
            'sitepulsewp-settings',
            'sitepulsewp_main_section'
        );

        add_settings_field(
            'ping_interval',
            'Ping Interval',
            array( $this, 'ping_interval_field' ),
            'sitepulsewp-settings',
            'sitepulsewp_main_section'
        );

        add_settings_field(
            'log_retention',
            'Log Retention (days)',
            array( $this, 'log_retention_field' ),
            'sitepulsewp-settings',
            'sitepulsewp_main_section'
        );
    }

    public function uptime_enabled_field() {
        $options = get_option( 'sitepulsewp_settings' );
        $enabled = isset( $options['uptime_enabled'] ) ? (bool)$options['uptime_enabled'] : true;
        echo '<input type="checkbox" name="sitepulsewp_settings[uptime_enabled]" value="1" ' . checked( $enabled, true, false ) . '> Enable';
    }

    public function ping_interval_field() {
        $options = get_option( 'sitepulsewp_settings' );
        $value = isset( $options['ping_interval'] ) ? $options['ping_interval'] : 5;
        echo '<select name="sitepulsewp_settings[ping_interval]">';
        foreach ( [5, 10, 15] as $minutes ) {
            printf( '<option value="%d"%s>%d minutes</option>',
                $minutes,
                selected( $value, $minutes, false ),
                $minutes
            );
        }
        echo '</select>';
    }

    public function log_retention_field() {
        $options = get_option( 'sitepulsewp_settings' );
        $value = isset( $options['log_retention'] ) ? intval($options['log_retention']) : 30;
        echo '<input type="number" name="sitepulsewp_settings[log_retention]" value="' . esc_attr($value) . '" min="1" />';
    }

    public function settings_page() {
        echo '<div class="wrap">';
        echo '<h1>SitePulseWP Settings</h1>';
        echo '<form method="post" action="options.php">';
        settings_fields( 'sitepulsewp_settings_group' );
        do_settings_sections( 'sitepulsewp-settings' );
        submit_button();
        echo '</form>';
        echo '</div>';
    }

}
