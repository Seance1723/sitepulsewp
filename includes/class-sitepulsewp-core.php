<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class SitePulseWP_Core {

    private static $instance = null;

    public static function instance() {
        if ( self::$instance == null ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        // Module 3 — Content Monitor
        add_action( 'save_post', array( $this, 'log_post_save' ), 10, 3 );
        add_action( 'before_delete_post', array( $this, 'log_post_delete' ) );

        // Module 4 — User Actions
        add_action( 'wp_login', array( $this, 'log_user_login' ), 10, 2 );
        add_action( 'wp_login_failed', array( $this, 'log_login_failed' ) );
        add_action( 'wp_logout', array( $this, 'log_user_logout' ) );
        add_action( 'profile_update', array( $this, 'log_profile_update' ) );
        add_action( 'after_password_reset', array( $this, 'log_password_reset' ) );
        add_action( 'comment_post', array( $this, 'log_comment_post' ), 10, 3 );
        add_action( 'edit_comment', array( $this, 'log_comment_edit' ) );
        add_action( 'deleted_comment', array( $this, 'log_comment_delete' ) );

        // Module 5 — Plugin & Theme Logs
        add_action( 'activated_plugin', array( $this, 'log_plugin_activation' ), 10, 2 );
        add_action( 'deactivated_plugin', array( $this, 'log_plugin_deactivation' ), 10, 2 );
        add_action( 'upgrader_process_complete', array( $this, 'log_plugin_update' ), 10, 2 );
        add_action( 'switch_theme', array( $this, 'log_theme_switch' ), 10, 2 );

        // Module 6 — Media Monitor
        add_action( 'add_attachment', array( $this, 'log_media_add' ) );
        add_action( 'edit_attachment', array( $this, 'log_media_edit' ) );
        add_action( 'delete_attachment', array( $this, 'log_media_delete' ) );
    }

    /** --- Content Monitor --- */
    public function log_post_save( $post_ID, $post, $update ) {
        if ( wp_is_post_revision( $post_ID ) ) return;

        if ( $update ) {
            $revisions = wp_get_post_revisions( $post_ID );
            $prev_revision = current( $revisions ); // latest revision is previous version

            $details = array(
                'post_id' => $post_ID,
                'user_id' => get_current_user_id(),
                'new_title' => $post->post_title,
            );

            if ( $prev_revision ) {
                $old_post = get_post( $prev_revision );
                $details['prev_revision'] = $prev_revision->ID;
                $details['old_title'] = $old_post->post_title;
                $details['diff'] = sitepulsewp_generate_diff( $old_post->post_content, $post->post_content );
            }

            SitePulseWP_Logger::log( 'Post Updated', wp_json_encode( $details ) );
        } else {
            $details = array(
                'post_id' => $post_ID,
                'title'   => $post->post_title,
                'type'    => $post->post_type,
                'user_id' => get_current_user_id(),
            );
            SitePulseWP_Logger::log( 'New Post', wp_json_encode( $details ) );
        }
    }

    public function log_post_delete( $post_ID ) {
        $post = get_post( $post_ID );
        if ( ! $post ) return;

        $details = sprintf(
            'Post ID: %d, Title: %s, Type: %s, Author ID: %d',
            $post_ID,
            $post->post_title,
            $post->post_type,
            $post->post_author
        );
        SitePulseWP_Logger::log( 'Post Deleted', $details );
    }

    /** --- User Actions --- */
    public function log_user_login( $user_login, $user ) {
        $ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( $_SERVER['REMOTE_ADDR'] ) : '';
        $details = sprintf( 'User ID: %d, Username: %s, IP: %s', $user->ID, $user_login, $ip );
        SitePulseWP_Logger::log( 'User Login', $details );
    }

    public function log_login_failed( $username ) {
        $ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( $_SERVER['REMOTE_ADDR'] ) : '';
        $details = sprintf( 'Username: %s, IP: %s', $username, $ip );
        SitePulseWP_Logger::log( 'Login Failed', $details );
    }

    public function log_user_logout() {
        $user = wp_get_current_user();
        if ( $user->ID ) {
            $details = sprintf( 'User ID: %d, Username: %s', $user->ID, $user->user_login );
            SitePulseWP_Logger::log( 'User Logout', $details );
        }
    }

    public function log_profile_update( $user_id ) {
        $user = get_userdata( $user_id );
        if ( $user ) {
            $details = sprintf( 'User ID: %d, Username: %s', $user->ID, $user->user_login );
            SitePulseWP_Logger::log( 'Profile Updated', $details );
        }
    }

    public function log_password_reset( $user ) {
        $details = sprintf( 'User ID: %d, Username: %s', $user->ID, $user->user_login );
        SitePulseWP_Logger::log( 'Password Reset', $details );
    }

    public function log_comment_post( $comment_ID, $comment_approved, $commentdata ) {
        if ( $comment_approved ) {
            $details = sprintf(
                'Comment ID: %d, Post ID: %d, Author: %s',
                $comment_ID,
                $commentdata['comment_post_ID'],
                $commentdata['comment_author']
            );
            SitePulseWP_Logger::log( 'New Comment', $details );
        }
    }

    public function log_comment_edit( $comment_ID ) {
        $comment = get_comment( $comment_ID );
        if ( $comment ) {
            $details = sprintf(
                'Comment ID: %d, Post ID: %d, Author: %s',
                $comment->comment_ID,
                $comment->comment_post_ID,
                $comment->comment_author
            );
            SitePulseWP_Logger::log( 'Comment Edited', $details );
        }
    }

    public function log_comment_delete( $comment_ID ) {
        $details = sprintf( 'Comment ID: %d', $comment_ID );
        SitePulseWP_Logger::log( 'Comment Deleted', $details );
    }

    /** --- Plugin & Theme Logs --- */
    public function log_plugin_activation( $plugin, $network_wide ) {
        $details = sprintf( 'Plugin Activated: %s', $plugin );
        SitePulseWP_Logger::log( 'Plugin Activated', $details );
    }

    public function log_plugin_deactivation( $plugin, $network_wide ) {
        $details = sprintf( 'Plugin Deactivated: %s', $plugin );
        SitePulseWP_Logger::log( 'Plugin Deactivated', $details );
    }

    public function log_plugin_update( $upgrader, $hook_extra ) {
        if ( isset( $hook_extra['action'] ) && $hook_extra['action'] === 'update' && isset( $hook_extra['type'] ) && $hook_extra['type'] === 'plugin' ) {
            if ( ! empty( $hook_extra['plugins'] ) ) {
                $details = sprintf( 'Plugin(s) Updated: %s', implode( ', ', $hook_extra['plugins'] ) );
                SitePulseWP_Logger::log( 'Plugin Updated', $details );
            }
        }
    }

    public function log_theme_switch( $new_name, $new_theme ) {
        $details = sprintf( 'New Theme Activated: %s', $new_name );
        SitePulseWP_Logger::log( 'Theme Switched', $details );
    }

    /** --- Media Monitor --- */
    public function log_media_add( $post_ID ) {
        $attachment = get_post( $post_ID );
        if ( $attachment ) {
            $details = sprintf(
                'Attachment ID: %d, Title: %s, Uploaded By: %d',
                $post_ID,
                $attachment->post_title,
                $attachment->post_author
            );
            SitePulseWP_Logger::log( 'New Media Added', $details );
        }
    }

    public function log_media_edit( $post_ID ) {
        $attachment = get_post( $post_ID );
        if ( $attachment ) {
            $details = sprintf(
                'Attachment ID: %d, Title: %s, Edited By: %d',
                $post_ID,
                $attachment->post_title,
                $attachment->post_author
            );
            SitePulseWP_Logger::log( 'Media Edited', $details );
        }
    }

    public function log_media_delete( $post_ID ) {
        $details = sprintf( 'Attachment ID: %d', $post_ID );
        SitePulseWP_Logger::log( 'Media Deleted', $details );
    }

}
