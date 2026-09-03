<?php
/**
 * Uninstall: remove review-queue items, plugin options and scheduled events.
 *
 * @package Theme Check
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

global $wpdb;

$ids = $wpdb->get_col( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE post_type = %s", 'etc_queue_item' ) );
foreach ( $ids as $id ) {
	wp_delete_post( (int) $id, true );
}

delete_option( 'etc_queue_retention_days' );
delete_option( 'envato_theme_check_message_template' );
wp_clear_scheduled_hook( 'etc_queue_cleanup' );
