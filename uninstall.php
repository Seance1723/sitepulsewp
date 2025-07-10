<?php
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) exit;

global $wpdb;
$table = $wpdb->prefix . 'sitepulsewp_logs';
$wpdb->query( "DROP TABLE IF EXISTS $table" );
