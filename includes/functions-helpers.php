<?php
if ( ! defined( 'ABSPATH' ) ) exit;

// Put common helpers here.
/**
 * Generate an HTML diff between two strings.
 *
 * @param string $old Old content.
 * @param string $new New content.
 * @return string HTML diff.
 */
function sitepulsewp_generate_diff( $old, $new ) {
    if ( ! function_exists( 'wp_text_diff' ) ) {
        require_once ABSPATH . 'wp-includes/wp-diff.php';
    }

    $diff = wp_text_diff( $old, $new );
    return $diff ? $diff : '';
}