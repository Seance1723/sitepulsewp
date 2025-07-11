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
        add_action( 'admin_post_sitepulsewp_rollback', array( $this, 'rollback_post' ) );
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

        echo '<table class="widefat"><thead><tr><th>ID</th><th>Type</th><th>User</th><th>Details</th><th>Date</th></tr></thead><tbody>';
        if ( ! empty( $logs ) ) {
            foreach ( $logs as $log ) {
                $details = $log->event_details;
                $actions = '';
                if ( $log->event_type === 'Post Updated' ) {
                    $data = json_decode( $log->event_details, true );
                    if ( $data ) {
                        $details  = sprintf( 'Post ID: %d, User ID: %d, Old Title: %s, New Title: %s',
                            isset( $data['post_id'] ) ? $data['post_id'] : 0,
                            isset( $data['user_id'] ) ? $data['user_id'] : 0,
                            isset( $data['old_title'] ) ? esc_html( $data['old_title'] ) : '',
                            isset( $data['new_title'] ) ? esc_html( $data['new_title'] ) : ''
                        );
                        if ( ! empty( $data['diff'] ) ) {
                            $details .= '<div class="sitepulsewp-diff">' . $data['diff'] . '</div>';
                        }
                        if ( isset( $data['prev_revision'], $data['post_id'] ) ) {
                            $url = esc_url( admin_url( 'admin-post.php?action=sitepulsewp_rollback&log_id=' . $log->id ) );
                            $actions = '<p><a class="button" href="' . $url . '">Rollback</a></p>';
                        }
                    }
                }
                $user_name = 'System';
                if ( $log->user_id ) {
                    $u = get_userdata( $log->user_id );
                    if ( $u ) {
                        $user_name = $u->user_login;
                    }
                }
                $detail_toggle = '<button type="button" class="button sitepulsewp-toggle-details" data-target="spwp-' . $log->id . '">Show Details</button>';
                $detail_div = '<div id="spwp-' . $log->id . '" class="sitepulsewp-details" style="display:none;">' . $details . $actions . '</div>';
                echo "<tr><td>{$log->id}</td><td>{$log->event_type}</td><td>{$user_name}</td><td>{$detail_toggle}{$detail_div}</td><td>{$log->created_at}</td></tr>";
            }
        } else {
            echo '<tr><td colspan="5">No logs found.</td></tr>';
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

        echo '<script type="text/javascript">\n';
        echo 'jQuery(function($){\n';
        echo '  $(".sitepulsewp-toggle-details").on("click", function(){\n';
        echo '    var target = $("#" + $(this).data("target"));\n';
        echo '    target.toggle();\n';
        echo '    $(this).text(target.is(":visible") ? "Hide Details" : "Show Details");\n';
        echo '  });\n';
        echo '});\n';
        echo '</script>';
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
        fputcsv( $output, array( 'ID', 'Type', 'User', 'Details', 'Date' ) );

        foreach ( $logs as $log ) {
            $user_name = '';
            if ( $log->user_id ) {
                $u = get_userdata( $log->user_id );
                if ( $u ) {
                    $user_name = $u->user_login;
                }
            }
            fputcsv( $output, array( $log->id, $log->event_type, $user_name, $log->event_details, $log->created_at ) );
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

    /**
     * Rollback a post to a previous revision based on a log entry.
     */
    public function rollback_post() {
        if ( ! current_user_can( 'edit_posts' ) ) {
            wp_die( 'No permission.' );
        }

        $log_id = isset( $_GET['log_id'] ) ? absint( $_GET['log_id'] ) : 0;
        if ( ! $log_id ) {
            wp_redirect( admin_url( 'admin.php?page=sitepulsewp' ) );
            exit;
        }

        global $wpdb;
        $table = $wpdb->prefix . 'sitepulsewp_logs';
        $log   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $table WHERE id = %d", $log_id ) );
        if ( ! $log ) {
            wp_redirect( admin_url( 'admin.php?page=sitepulsewp' ) );
            exit;
        }

        $data = json_decode( $log->event_details, true );
        if ( empty( $data['prev_revision'] ) || empty( $data['post_id'] ) ) {
            wp_redirect( admin_url( 'admin.php?page=sitepulsewp' ) );
            exit;
        }

        $revision_id = absint( $data['prev_revision'] );
        $post_id     = absint( $data['post_id'] );

        $restored = wp_restore_post_revision( $revision_id );

        if ( $restored ) {
            SitePulseWP_Logger::log( 'Post Rolled Back', wp_json_encode( [ 'post_id' => $post_id, 'revision_id' => $revision_id ] ), get_current_user_id() );
        }

        wp_redirect( admin_url( 'post.php?post=' . $post_id . '&action=edit' ) );
        exit;
    }

}