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

    public static function run_backup( $force = false, $parts = array() ) {
        $settings = get_option( 'sitepulsewp_settings' );
        $enabled  = isset( $settings['backup_enabled'] ) && $settings['backup_enabled'];
        $day      = isset( $settings['backup_day'] ) ? intval( $settings['backup_day'] ) : 1;
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

        $defaults = array(
            'theme'    => false,
            'uploads'  => false,
            'plugins'  => false,
            'others'   => false,
            'db'       => false,
            'complete' => false,
        );
        $parts = wp_parse_args( $parts, $defaults );
        if ( empty( array_filter( $parts ) ) ) {
            $parts['complete'] = true;
        }
        if ( $parts['complete'] ) {
            $parts = array_merge( $defaults, array( 'complete' => true, 'db' => true ) );
        }

        $timestamp = date( 'Ymd-His' );

        if ( $parts['complete'] ) {
            $filename = 'SPBKP_Complete_' . $timestamp . '.zip';
            $sql      = self::generate_db_dump();
            self::create_archive( array( ABSPATH ), $sql, trailingslashit( $backup_dir ) . $filename );
        } else {
            if ( $parts['theme'] ) {
                $theme_dir = WP_CONTENT_DIR . '/themes';
                if ( file_exists( $theme_dir ) ) {
                    $filename = 'SPBKP_Theme_' . $timestamp . '.zip';
                    self::create_archive( array( $theme_dir ), '', trailingslashit( $backup_dir ) . $filename );
                }
            }
            if ( $parts['uploads'] ) {
                $filename = 'SPBKP_Uploads_' . $timestamp . '.zip';
                self::create_archive( array( WP_CONTENT_DIR . '/uploads' ), '', trailingslashit( $backup_dir ) . $filename );
            }
            if ( $parts['plugins'] ) {
                $filename = 'SPBKP_Plugin_' . $timestamp . '.zip';
                self::create_archive( array( WP_PLUGIN_DIR ), '', trailingslashit( $backup_dir ) . $filename );
            }
            if ( $parts['others'] ) {
                $paths = array();
                if ( file_exists( ABSPATH . '.htaccess' ) ) {
                    $paths[] = ABSPATH . '.htaccess';
                }
                foreach ( glob( ABSPATH . '*', GLOB_NOSORT ) as $p ) {
                    $base = basename( $p );
                    if ( in_array( $base, array( 'wp-admin', 'wp-includes' ) ) ) {
                        continue;
                    }
                    if ( $base === 'wp-content' && is_dir( $p ) ) {
                        foreach ( glob( $p . '/*', GLOB_NOSORT ) as $sub ) {
                            $subbase = basename( $sub );
                            if ( in_array( $subbase, array( 'plugins', 'themes', 'uploads' ) ) ) {
                                continue;
                            }
                            $paths[] = $sub;
                        }
                        continue;
                    }
                    if ( preg_match( '/^wp-.*\.php$/', $base ) && 'wp-config.php' !== $base ) {
                        continue;
                    }
                    if ( in_array( $base, array( 'index.php', 'xmlrpc.php', 'readme.html', 'license.txt' ) ) ) {
                        continue;
                    }
                    $paths[] = $p;
                }
                $filename = 'SPBKP_Others_' . $timestamp . '.zip';
                self::create_archive( $paths, '', trailingslashit( $backup_dir ) . $filename );
            }
            if ( $parts['db'] ) {
                $filename = 'SPBKP_DB_' . $timestamp . '.zip';
                self::create_archive( array(), self::generate_db_dump(), trailingslashit( $backup_dir ) . $filename );
            }
        }
    }

    private static function create_archive( $paths, $sql, $filepath ) {
        $created  = false;
        $backup_dir = dirname( $filepath );

        if ( class_exists( 'ZipArchive' ) ) {
            $zip = new ZipArchive();
            if ( $zip->open( $filepath, ZipArchive::CREATE ) === true ) {
                if ( $sql ) {
                    $zip->addFromString( 'database.sql', $sql );
                }
                foreach ( $paths as $p ) {
                    if ( is_dir( $p ) ) {
                        self::zip_dir( $p, $zip, ABSPATH );
                    } else {
                        $zip->addFile( $p, ltrim( str_replace( ABSPATH, '', $p ), '/' ) );
                    }
                }
                $zip->close();
                $created = true;
            }
        } else {
            if ( ! class_exists( 'PclZip' ) && file_exists( ABSPATH . 'wp-admin/includes/class-pclzip.php' ) ) {
                require_once ABSPATH . 'wp-admin/includes/class-pclzip.php';
            }

            if ( class_exists( 'PclZip' ) ) {
                $tmp_sql = '';
                $p_paths = $paths;
                if ( $sql ) {
                    $tmp_sql = trailingslashit( $backup_dir ) . 'database.sql';
                    file_put_contents( $tmp_sql, $sql );
                    $p_paths[] = $tmp_sql;
                }

                $archive = new PclZip( $filepath );
                $archive->create( $p_paths, PCLZIP_OPT_REMOVE_PATH, ABSPATH );
                if ( $tmp_sql ) {
                    unlink( $tmp_sql );
                }
                $created = true;
            } elseif ( class_exists( 'PharData' ) ) {
                try {
                    $phar = new PharData( $filepath, 0, null, Phar::ZIP );
                    foreach ( $paths as $p ) {
                        if ( is_dir( $p ) ) {
                            $phar->buildFromIterator( new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $p, FilesystemIterator::SKIP_DOTS ) ), ABSPATH );
                        } else {
                            $phar->addFile( $p, ltrim( str_replace( ABSPATH, '', $p ), '/' ) );
                        }
                    }
                    if ( $sql ) {
                        $phar->addFromString( 'database.sql', $sql );
                    }
                    $created = true;
                } catch ( Exception $e ) {
                    $created = false;
                }
            }
        }

        $file = basename( $filepath );
        if ( $created ) {
            SitePulseWP_Logger::log( 'Backup Created', $file, get_current_user_id() );
        } else {
            SitePulseWP_Logger::log( 'Backup Failed', 'No archive method available', get_current_user_id() );
        }

        return $created;
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

        $sql = '';

        if ( class_exists( 'ZipArchive' ) ) {
            $zip = new ZipArchive();
            if ( $zip->open( $path ) !== true ) {
                return false;
            }
            $sql = $zip->getFromName( 'database.sql' );
            $zip->extractTo( ABSPATH );
            $zip->close();
        } else {
            if ( ! class_exists( 'PclZip' ) && file_exists( ABSPATH . 'wp-admin/includes/class-pclzip.php' ) ) {
                require_once ABSPATH . 'wp-admin/includes/class-pclzip.php';
            }

            if ( class_exists( 'PclZip' ) ) {
                $archive = new PclZip( $path );
                $archive->extract( PCLZIP_OPT_PATH, ABSPATH, PCLZIP_OPT_REPLACE_NEWER );
                $content = $archive->extract( PCLZIP_OPT_BY_NAME, 'database.sql', PCLZIP_OPT_EXTRACT_AS_STRING );
                if ( is_array( $content ) && isset( $content[0]['content'] ) ) {
                    $sql = $content[0]['content'];
                }
            } elseif ( class_exists( 'PharData' ) ) {
                try {
                    $phar = new PharData( $path );
                    $phar->extractTo( ABSPATH, null, true );
                    if ( isset( $phar['database.sql'] ) ) {
                        $sql = $phar['database.sql']->getContent();
                    }
                } catch ( Exception $e ) {
                    return false;
                }
            } else {
                return false;
            }
        }
        
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