<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class SitePulseWP_Backup {
    const CRON_HOOK = 'sitepulsewp_run_backup';

    public static function init() {
        add_action( 'init', array( __CLASS__, 'schedule_backup' ) );
        add_action( 'update_option_sitepulsewp_settings', array( __CLASS__, 'schedule_backup' ), 10, 2 );
        add_action( self::CRON_HOOK, array( __CLASS__, 'run_backup' ) );
    }

    public static function schedule_backup() {
        $options = get_option( 'sitepulsewp_settings' );
        $enabled = isset( $options['backup_enabled'] ) && $options['backup_enabled'];
        $time    = isset( $options['backup_time'] ) ? $options['backup_time'] : '';
        $day     = isset( $options['backup_day'] ) ? intval( $options['backup_day'] ) : 1;
        $time    = isset( $options['backup_time'] ) ? strtotime( $options['backup_time'] ) : false;

        if ( ! $enabled || ! $time ) {
            wp_clear_scheduled_hook( self::CRON_HOOK );
            return;
        }

        $scheduled = wp_next_scheduled( self::CRON_HOOK );
        $timestamp = self::next_schedule_time( $day, $time );

        if ( $scheduled && absint( $scheduled ) !== $timestamp ) {
            wp_clear_scheduled_hook( self::CRON_HOOK );
            $scheduled = false;
        }
        if ( ! $scheduled ) {
            wp_schedule_single_event( $timestamp, self::CRON_HOOK );
        }
    }

    private static function next_schedule_time( $day, $time ) {
        $time_parts = explode( ':', date( 'H:i', strtotime( $time ) ) );
        $hour  = intval( $time_parts[0] );
        $min   = intval( $time_parts[1] );
        $year  = date( 'Y' );
        $month = date( 'n' );
        $day   = intval( $day );
        $day   = min( $day, cal_days_in_month( CAL_GREGORIAN, $month, $year ) );
        $next  = mktime( $hour, $min, 0, $month, $day, $year );
        if ( $next <= time() ) {
            $month++;
            if ( $month > 12 ) {
                $month = 1;
                $year++;
            }
            $day = min( $day, cal_days_in_month( CAL_GREGORIAN, $month, $year ) );
            $next = mktime( $hour, $min, 0, $month, $day, $year );
        }
        return $next;
    }

    public static function run_backup( $force = false ) {
        $options = get_option( 'sitepulsewp_settings' );
        $enabled = isset( $options['backup_enabled'] ) && $options['backup_enabled'];
        $day     = isset( $options['backup_day'] ) ? intval( $options['backup_day'] ) : 1;
        if ( ! $force ) {
            if ( ! $enabled ) {
                return;
            }
            $current_day = min( $day, intval( date( 't' ) ) );
            if ( intval( date( 'j' ) ) !== $current_day ) {
                return;
            }
        }
        set_time_limit( 0 );
        $upload_dir = wp_upload_dir();
        $backup_dir = trailingslashit( $upload_dir['basedir'] ) . 'sitepulsewp-backups';
        if ( ! file_exists( $backup_dir ) ) {
            wp_mkdir_p( $backup_dir );
        }

        $domain   = parse_url( home_url(), PHP_URL_HOST );
        $filename = $domain . '_' . date( 'Ymd-His' ) . '.zip';
        $filepath = trailingslashit( $backup_dir ) . $filename;

        if ( ! class_exists( 'ZipArchive' ) ) {
            SitePulseWP_Logger::log( 'Backup Failed', 'ZipArchive not available', get_current_user_id() );
            return;
        }

        $zip = new ZipArchive();
        if ( $zip->open( $filepath, ZipArchive::CREATE ) === true ) {
            $sql = self::generate_db_dump();
            $zip->addFromString( 'database.sql', $sql );
            self::zip_dir( ABSPATH, $zip, ABSPATH );
            $zip->close();
            SitePulseWP_Logger::log( 'Backup Created', $filename, get_current_user_id() );
        }
    }

    private static function generate_db_dump() {
        global $wpdb;
        $tables = $wpdb->get_col( 'SHOW TABLES' );
        $sql = '';
        foreach ( $tables as $table ) {
            $create = $wpdb->get_row( "SHOW CREATE TABLE `$table`", ARRAY_N );
            if ( isset( $create[1] ) ) {
                $sql .= $create[1] . ";\n\n";
            }
            $rows = $wpdb->get_results( "SELECT * FROM `$table`", ARRAY_A );
            foreach ( $rows as $row ) {
                $vals = array();
                foreach ( $row as $val ) {
                    if ( $val === null ) {
                        $vals[] = 'NULL';
                    } else {
                        $vals[] = "'" . esc_sql( addslashes( $val ) ) . "'";
                    }
                }
                $sql .= "INSERT INTO `$table` (`" . implode( '`,`', array_keys( $row ) ) . "`) VALUES(" . implode( ',', $vals ) . ");\n";
            }
            $sql .= "\n";
        }
        return $sql;
    }

    private static function zip_dir( $dir, $zip, $base ) {
        $files = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $dir, FilesystemIterator::SKIP_DOTS ), RecursiveIteratorIterator::SELF_FIRST );
        foreach ( $files as $file ) {
            $path = str_replace( $base, '', $file->getPathname() );
            if ( $file->isDir() ) {
                $zip->addEmptyDir( ltrim( $path, '/' ) );
            } else {
                $zip->addFile( $file->getPathname(), ltrim( $path, '/' ) );
            }
        }
    }

    public static function list_backups() {
        $upload_dir = wp_upload_dir();
        $backup_dir = trailingslashit( $upload_dir['basedir'] ) . 'sitepulsewp-backups';
        if ( ! file_exists( $backup_dir ) ) {
            return array();
        }
        $files = glob( $backup_dir . '/*.zip' );
        return $files ? array_map( 'basename', $files ) : array();
    }

    public static function get_backup_path( $file ) {
        $upload_dir = wp_upload_dir();
        $backup_dir = trailingslashit( $upload_dir['basedir'] ) . 'sitepulsewp-backups';
        return $backup_dir . '/' . basename( $file );
    }

    public static function delete_backup( $file ) {
        $upload_dir = wp_upload_dir();
        $backup_dir = trailingslashit( $upload_dir['basedir'] ) . 'sitepulsewp-backups';
        $path = $backup_dir . '/' . basename( $file );
        if ( file_exists( $path ) ) {
            unlink( $path );
            SitePulseWP_Logger::log( 'Backup Deleted', basename( $file ), get_current_user_id() );
        }
    }

    public static function restore_backup( $file ) {
        $upload_dir = wp_upload_dir();
        $backup_dir = trailingslashit( $upload_dir['basedir'] ) . 'sitepulsewp-backups';
        $path = $backup_dir . '/' . basename( $file );
        if ( ! file_exists( $path ) ) {
            return false;
        }

        if ( ! class_exists( 'ZipArchive' ) ) {
            return false;
        }
        
        $zip = new ZipArchive();
        if ( $zip->open( $path ) !== true ) {
            return false;
        }
        $sql = $zip->getFromName( 'database.sql' );
        $zip->extractTo( ABSPATH );
        $zip->close();
        if ( $sql ) {
            self::import_db_dump( $sql );
        }
        SitePulseWP_Logger::log( 'Backup Restored', basename( $file ), get_current_user_id() );
        return true;
    }

    private static function import_db_dump( $sql ) {
        global $wpdb;
        if ( ! empty( $wpdb->dbh ) ) {
            mysqli_multi_query( $wpdb->dbh, $sql );
            while ( mysqli_more_results( $wpdb->dbh ) ) {
                mysqli_next_result( $wpdb->dbh );
            }
        }
    }
}