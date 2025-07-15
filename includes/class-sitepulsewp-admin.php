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
        add_action( 'admin_post_sitepulsewp_export_month', array( $this, 'export_month_csv' ) );
        add_action( 'admin_post_sitepulsewp_delete_backup', array( $this, 'delete_backup' ) );
        add_action( 'admin_post_sitepulsewp_restore_backup', array( $this, 'restore_backup' ) );
        add_action( 'admin_post_sitepulsewp_backup_now', array( $this, 'backup_now' ) );
        add_action( 'admin_post_sitepulsewp_download_backup', array( $this, 'download_backup' ) );
        add_action( 'wp_ajax_sitepulsewp_backup_now', array( $this, 'backup_now_ajax' ) );
        add_action( 'admin_init', array( $this, 'register_settings' ) );
        add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_scripts' ) );
    }

    public function add_admin_menu() {
        add_menu_page(
            'SitePulseWP',
            'SitePulseWP',
            'manage_options',
            'sitepulsewp-dashboard',
            array( $this, 'dashboard_page' ),
            'dashicons-chart-area'
        );

        add_submenu_page(
            'sitepulsewp-dashboard',
            'Dashboard',
            'Dashboard',
            'manage_options',
            'sitepulsewp-dashboard',
            array( $this, 'dashboard_page' )
        );

        add_submenu_page(
            'sitepulsewp-dashboard',
            'Activity Log',
            'Activity Log',
            'manage_options',
            'sitepulsewp-activity-log',
            array( $this, 'activity_log_page' )
        );

        add_submenu_page(
            'sitepulsewp-dashboard',
            'Settings',
            'Settings',
            'manage_options',
            'sitepulsewp-settings',
            array( $this, 'settings_page' )
        );

        add_submenu_page(
            'sitepulsewp-dashboard',
            'Backups',
            'Backups',
            'manage_options',
            'sitepulsewp-backups',
            array( $this, 'backups_page' )
        );
    }

    /**
     * Enqueue admin scripts.
     */
    public function enqueue_scripts( $hook ) {
        if ( false !== strpos( $hook, 'sitepulsewp-activity-log' ) ) {
            wp_enqueue_script( 'jquery' );
            wp_enqueue_script( 'thickbox' );
            wp_enqueue_style( 'thickbox' );
        }

        if ( false !== strpos( $hook, 'sitepulsewp-dashboard' ) ) {
            wp_enqueue_script( 'chartjs', 'https://cdn.jsdelivr.net/npm/chart.js', array(), null );
        }
        if ( false !== strpos( $hook, 'sitepulsewp-backups' ) ) {
            wp_enqueue_script( 'jquery' );
        }
    }

    /**
     * Output modal markup and JavaScript used for viewing log details.
     */
    private function render_log_modal_js() {
        echo '<style>.spwp-modal-overlay{position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(0,0,0,.7);display:flex;align-items:center;justify-content:center;z-index:100000;}';
        echo '.spwp-modal{background:#fff;padding:20px;max-width:600px;max-height:80%;overflow:auto;box-shadow:0 0 10px #000;}</style>';
        echo '<script type="text/javascript">jQuery(function($){$(".spwp-view").on("click",function(e){e.preventDefault();var t=$(this).data("target"),c=$(t).html();var o=$("<div class=\"spwp-modal-overlay\"></div>").appendTo("body");$("<div class=\"spwp-modal\"></div>").html(c).appendTo(o);o.on("click",function(){o.remove();});});});</script>';
    }

    public function dashboard_page() {
        global $wpdb;
        $table = $wpdb->prefix . 'sitepulsewp_logs';
        $start = date( 'Y-m-01 00:00:00' );
        $logs = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM $table WHERE created_at >= %s AND event_type IN ('Uptime OK','Downtime Detected') ORDER BY created_at ASC", $start ) );

        $data = array();
        $up = 0;
        $down = 0;
        $total_rt = 0;
        $rt_samples = 0;
        foreach ( $logs as $log ) {
            $time = mysql2date( 'Y-m-d H:i', $log->created_at );
            $rt = null;
            if ( preg_match( '/Response Time:\s*(\d+(?:\.\d+)?)ms/', $log->event_details, $m ) ) {
                $rt = (float) $m[1];
                $total_rt += $rt;
                $rt_samples++;
            }
            if ( $log->event_type === 'Uptime OK' ) {
                $up++;
            } elseif ( $log->event_type === 'Downtime Detected' ) {
                $down++;
            }
            $data[] = array( 'time' => $time, 'response' => $rt, 'type' => $log->event_type );
        }

        $uptime_percent = ( $up + $down ) > 0 ? round( $up / ( $up + $down ) * 100, 1 ) : null;
        $avg_response  = $rt_samples > 0 ? round( $total_rt / $rt_samples, 2 ) : null;
        $failed_logins = $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $table WHERE event_type = 'Login Failed' AND created_at >= %s", $start ) );

        echo '<div class="wrap">';
        echo '<h1>SitePulseWP Dashboard</h1>';
        echo '<form method="get" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="margin-bottom:15px;">';
        echo '<input type="hidden" name="action" value="sitepulsewp_export_month" />';
        echo '<label>Month <input type="month" name="month" /></label> ';
        echo '<span style="margin:0 10px;">or</span>';
        echo '<label>From <input type="date" name="start" /></label> ';
        echo '<label>To <input type="date" name="end" /></label> ';
        submit_button( 'Download Report', 'secondary', 'submit', false );
        echo '</form>';
        echo '<style>.spwp-kpis{display:flex;gap:20px;margin-bottom:20px}.spwp-kpi{flex:1;padding:10px;border:1px solid #ccd0d4;background:#fff;border-radius:4px;text-align:center}.spwp-kpi h2{margin-top:0;font-size:16px}.spwp-kpi .value{font-size:24px;font-weight:700}</style>';
        echo '<div class="spwp-kpis">';
        echo '<div class="spwp-kpi"><h2>Uptime %</h2><div class="value">' . ( $uptime_percent !== null ? esc_html( $uptime_percent ) : '&mdash;' ) . '</div></div>';
        echo '<div class="spwp-kpi"><h2>Downtime Events</h2><div class="value">' . ( $down ? esc_html( $down ) : '&mdash;' ) . '</div></div>';
        echo '<div class="spwp-kpi"><h2>Avg Response (ms)</h2><div class="value">' . ( $avg_response !== null ? esc_html( $avg_response ) : '&mdash;' ) . '</div></div>';
        echo '<div class="spwp-kpi"><h2>Failed Logins</h2><div class="value">' . ( $failed_logins ? esc_html( $failed_logins ) : '&mdash;' ) . '</div></div>';
        echo '</div>';
        echo '<canvas id="spwp-chart" height="100"></canvas>';
        echo '<script type="text/javascript">var spwpData=' . wp_json_encode( $data ) . ';</script>';
        echo '<script type="text/javascript">\n';
        echo 'jQuery(function($){';
        echo 'if(window.Chart){';
        echo 'var labels = spwpData.map(function(d){return d.time});';
        echo 'var data = spwpData.map(function(d){return d.response===null?0:d.response});';
        echo 'var ctx = document.getElementById("spwp-chart").getContext("2d");';
        echo 'new Chart(ctx,{type:"line",data:{labels:labels,datasets:[{label:"Response Time (ms)",data:data,fill:false,borderColor:"#0073aa"}]},options:{scales:{y:{beginAtZero:true}}}});';
        echo '}';
        echo '});';
        echo '</script>';
        echo '</div>';
        $this->render_log_modal_js();
    }

    public function activity_log_page() {
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
        echo '<input type="hidden" name="page" value="sitepulsewp-activity-log">';
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
                $link = '<a href="#" class="spwp-view" data-target="#spwp-' . $log->id . '">View</a>';
                $detail_div = '<div id="spwp-' . $log->id . '" class="spwp-detail" style="display:none;">' . $details . $actions . '</div>';
                echo "<tr><td>{$log->id}</td><td>{$log->event_type}</td><td>{$user_name}</td><td>{$link}{$detail_div}</td><td>{$log->created_at}</td></tr>";
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
        
        echo '</div>';
        $this->render_log_modal_js();
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

    public function export_month_csv() {
        if ( ! current_user_can('manage_options') ) {
            wp_die('No permission.');
        }

        global $wpdb;
        $table = $wpdb->prefix . 'sitepulsewp_logs';
        $month = isset( $_GET['month'] ) ? sanitize_text_field( $_GET['month'] ) : '';
        $start = isset( $_GET['start'] ) ? sanitize_text_field( $_GET['start'] ) : '';
        $end   = isset( $_GET['end'] ) ? sanitize_text_field( $_GET['end'] ) : '';

        if ( $month && preg_match( '/^\d{4}-\d{2}$/', $month ) ) {
            $start_date = date( 'Y-m-01 00:00:00', strtotime( $month ) );
            $end_date   = date( 'Y-m-t 23:59:59', strtotime( $month ) );
        } else {
            $start_date = $start ? date( 'Y-m-d 00:00:00', strtotime( $start ) ) : date( 'Y-m-01 00:00:00' );
            $end_date   = $end ? date( 'Y-m-d 23:59:59', strtotime( $end ) ) : current_time( 'mysql' );
        }

        $logs = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM $table WHERE created_at >= %s AND created_at <= %s AND event_type IN ('Uptime OK','Downtime Detected') ORDER BY created_at ASC",
                $start_date,
                $end_date
            )
        );

        header( 'Content-Type: text/csv' );
        $filename = 'sitepulsewp-monitor-' . date( 'Ymd', strtotime( $start_date ) ) . '_to_' . date( 'Ymd', strtotime( $end_date ) ) . '.csv';
        header( 'Content-Disposition: attachment; filename=' . $filename );

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

        add_settings_field(
            'backup_enabled',
            'Enable Backups',
            array( $this, 'backup_enabled_field' ),
            'sitepulsewp-settings',
            'sitepulsewp_main_section'
        );

        add_settings_field(
            'backup_time',
            'Backup Time',
            array( $this, 'backup_time_field' ),
            'sitepulsewp-settings',
            'sitepulsewp_main_section'
        );

        add_settings_field(
            'backup_day',
            'Backup Day',
            array( $this, 'backup_day_field' ),
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

    public function backup_enabled_field() {
        $options = get_option( 'sitepulsewp_settings' );
        $enabled = isset( $options['backup_enabled'] ) ? (bool)$options['backup_enabled'] : false;
        echo '<input type="checkbox" name="sitepulsewp_settings[backup_enabled]" value="1" ' . checked( $enabled, true, false ) . '> Enable';
    }

    public function backup_time_field() {
        $options = get_option( 'sitepulsewp_settings' );
        $time = isset( $options['backup_time'] ) ? esc_attr( $options['backup_time'] ) : '';
        echo '<input type="time" name="sitepulsewp_settings[backup_time]" value="' . $time . '" />';
    }

    public function backup_day_field() {
        $options = get_option( 'sitepulsewp_settings' );
        $day = isset( $options['backup_day'] ) ? intval( $options['backup_day'] ) : 1;
        echo '<input type="number" name="sitepulsewp_settings[backup_day]" value="' . esc_attr( $day ) . '" min="1" max="31" />';
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

    public function backups_page() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( 'No permission.' );
        }
        if ( ! class_exists( 'ZipArchive' ) ) {
            if ( class_exists( 'PclZip' ) || file_exists( ABSPATH . 'wp-admin/includes/class-pclzip.php' ) || class_exists( 'PharData' ) ) {
                echo '<div class="notice notice-warning"><p>ZipArchive not available. Using fallback compression method.</p></div>';
            } else {
                echo '<div class="notice notice-error"><p>No PHP archive method available. Please enable ZipArchive or Phar.</p></div>';
            }
        }
        $files = SitePulseWP_Backup::list_backups();
        echo '<div class="wrap">';
        echo '<h1>Backups</h1>';
        $nonce = wp_create_nonce( 'spwp_backup' );
        echo '<p><button id="spwp-backup-btn" class="button button-primary" data-nonce="' . esc_attr( $nonce ) . '">Backup Now</button></p>';
        echo '<div id="spwp-backup-progress" style="display:none;width:100%;background:#eee;height:20px;margin-bottom:10px"><div id="spwp-backup-bar" style="background:#0073aa;height:100%;width:0"></div></div>';
        echo '<div id="spwp-backup-log"></div>';
        echo '<div id="spwp-backup-modal" style="display:none;position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(0,0,0,.7);z-index:100000;align-items:center;justify-content:center;">';
        echo '<div style="background:#fff;padding:20px;width: 320px;max-width:400px;position: absolute;left: 50%;top: 20vh;">';
        echo '<h2>Select Backup Options</h2>';
        echo '<label><input type="checkbox" value="theme" class="spwp-bk-opt"> Active Theme</label><br />';
        echo '<label><input type="checkbox" value="uploads" class="spwp-bk-opt"> Uploads Folder</label><br />';
        echo '<label><input type="checkbox" value="plugins" class="spwp-bk-opt"> Plugins Folder</label><br />';
        echo '<label><input type="checkbox" value="others" class="spwp-bk-opt"> Others (.htaccess and misc)</label><br />';
        echo '<label><input type="checkbox" value="db" class="spwp-bk-opt"> Database</label><br />';
        echo '<label><input type="checkbox" value="complete" id="spwp-bk-complete" class="spwp-bk-opt"> Complete Backup</label><br /><br />';
        echo '<button id="spwp-start-backup" class="button button-primary" data-nonce="' . esc_attr( $nonce ) . '">Start</button> ';
        echo '<button id="spwp-cancel-backup" class="button">Cancel</button>';
        echo '</div></div>';
        if ( empty( $files ) ) {
            echo '<p>No backups found.</p>';
        } else {
            echo '<table class="widefat"><thead><tr><th>File</th><th>Actions</th></tr></thead><tbody>';
            foreach ( $files as $file ) {
                $delete_url  = wp_nonce_url( admin_url( 'admin-post.php?action=sitepulsewp_delete_backup&file=' . urlencode( $file ) ), 'spwp_backup' );
                $restore_url = wp_nonce_url( admin_url( 'admin-post.php?action=sitepulsewp_restore_backup&file=' . urlencode( $file ) ), 'spwp_backup' );
                $download_url = wp_nonce_url( admin_url( 'admin-post.php?action=sitepulsewp_download_backup&file=' . urlencode( $file ) ), 'spwp_backup' );
                echo '<tr><td>' . esc_html( $file ) . '</td><td>';
                echo '<a class="button" href="' . esc_url( $download_url ) . '">Download</a> ';
                echo '<a class="button" href="' . esc_url( $restore_url ) . '">Restore</a> ';
                echo '<a class="button" href="' . esc_url( $delete_url ) . '">Delete</a>';
                echo '</td></tr>';
            }
            echo '</tbody></table>';
        }
        echo '</div>';
        echo '<script type="text/javascript">jQuery(function($){var m=$("#spwp-backup-modal");$("#spwp-backup-btn").on("click",function(e){e.preventDefault();m.show();});$("#spwp-cancel-backup").on("click",function(e){e.preventDefault();m.hide();});$("#spwp-bk-complete").on("change",function(){var c=$(this).is(":checked");m.find("input.spwp-bk-opt").not(this).prop("disabled",c);});function doBackup(parts,nonce,btn){btn.prop("disabled",true);m.hide();$("#spwp-backup-progress").show();$("#spwp-backup-bar").css("width","30%");$("#spwp-backup-log").text("Running backup...");$.post(ajaxurl,{action:"sitepulsewp_backup_now",nonce:nonce,parts:parts},function(r){if(r.success){$("#spwp-backup-bar").css("width","100%");$("#spwp-backup-log").text(r.data.message);setTimeout(function(){location.reload();},1000);}else{$("#spwp-backup-bar").css({width:"100%",background:"#dc3232"});$("#spwp-backup-log").text(r.data.message);btn.prop("disabled",false);}}).fail(function(){$("#spwp-backup-bar").css({width:"100%",background:"#dc3232"});$("#spwp-backup-log").text("Backup failed.");btn.prop("disabled",false);});}$("#spwp-start-backup").on("click",function(e){e.preventDefault();var btn=$(this),nonce=btn.data("nonce"),parts=[];m.find("input.spwp-bk-opt:checked").each(function(){parts.push($(this).val());});if($.inArray("complete",parts)!==-1){parts=["complete"];}doBackup(parts,nonce,btn);});});</script>';
    }

    public function delete_backup() {
        if ( ! current_user_can( 'manage_options' ) || ! check_admin_referer( 'spwp_backup' ) ) {
            wp_die( 'No permission.' );
        }
        $file = isset( $_GET['file'] ) ? sanitize_text_field( wp_unslash( $_GET['file'] ) ) : '';
        SitePulseWP_Backup::delete_backup( $file );
        wp_redirect( admin_url( 'admin.php?page=sitepulsewp-backups' ) );
        exit;
    }

    public function restore_backup() {
        if ( ! current_user_can( 'manage_options' ) || ! check_admin_referer( 'spwp_backup' ) ) {
            wp_die( 'No permission.' );
        }
        $file = isset( $_GET['file'] ) ? sanitize_text_field( wp_unslash( $_GET['file'] ) ) : '';
        SitePulseWP_Backup::restore_backup( $file );
        wp_redirect( admin_url( 'admin.php?page=sitepulsewp-backups' ) );
        exit;
    }

    public function backup_now() {
        if ( ! current_user_can( 'manage_options' ) || ! check_admin_referer( 'spwp_backup' ) ) {
            wp_die( 'No permission.' );
        }
        SitePulseWP_Backup::run_backup( true );
        wp_redirect( admin_url( 'admin.php?page=sitepulsewp-backups' ) );
        exit;
    }

    public function backup_now_ajax() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => 'No permission.' ) );
        }
        check_ajax_referer( 'spwp_backup', 'nonce' );

        $selected = isset( $_POST['parts'] ) ? array_map( 'sanitize_text_field', (array) $_POST['parts'] ) : array();
        $parts    = array();
        foreach ( array( 'theme', 'uploads', 'plugins', 'others', 'db', 'complete' ) as $key ) {
            $parts[ $key ] = in_array( $key, $selected, true );
        }
        $before = SitePulseWP_Backup::list_backups();
        SitePulseWP_Backup::run_backup( true, $parts );
        $after = SitePulseWP_Backup::list_backups();
        $new   = array_values( array_diff( $after, $before ) );

        if ( ! empty( $new ) ) {
            wp_send_json_success( array( 'message' => 'Backup created: ' . $new[0] ) );
        } else {
            wp_send_json_error( array( 'message' => 'Backup failed.' ) );
        }
    }


    public function download_backup() {
        if ( ! current_user_can( 'manage_options' ) || ! check_admin_referer( 'spwp_backup' ) ) {
            wp_die( 'No permission.' );
        }
        $file = isset( $_GET['file'] ) ? sanitize_text_field( wp_unslash( $_GET['file'] ) ) : '';
        $path = SitePulseWP_Backup::get_backup_path( $file );
        if ( ! file_exists( $path ) ) {
            wp_die( 'File not found.' );
        }
        header( 'Content-Type: application/zip' );
        header( 'Content-Disposition: attachment; filename=' . basename( $path ) );
        readfile( $path );
        exit;
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
            wp_redirect( admin_url( 'admin.php?page=sitepulsewp-activity-log' ) );
            exit;
        }

        global $wpdb;
        $table = $wpdb->prefix . 'sitepulsewp_logs';
        $log   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $table WHERE id = %d", $log_id ) );
        if ( ! $log ) {
            wp_redirect( admin_url( 'admin.php?page=sitepulsewp-activity-log' ) );
            exit;
        }

        $data = json_decode( $log->event_details, true );
        if ( empty( $data['prev_revision'] ) || empty( $data['post_id'] ) ) {
            wp_redirect( admin_url( 'admin.php?page=sitepulsewp-activity-log' ) );
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