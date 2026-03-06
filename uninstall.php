<?php
/**
 * WC AI Image Generator — Uninstall
 *
 * Fires when the plugin is deleted via the WordPress admin.
 *
 * - Drops wp_wcaig_cache table
 * - Drops wp_wcaig_usage_log table
 * - Deletes all wcaig_* options
 * - Deletes all wcaig_* transients
 * - Optionally deletes all media attachments titled wcaig_*
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

global $wpdb;

/* ------------------------------------------------------------------
 *  Drop custom tables
 * ----------------------------------------------------------------*/

$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}wcaig_cache" );
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}wcaig_usage_log" );

/* ------------------------------------------------------------------
 *  Delete options
 * ----------------------------------------------------------------*/

$option_keys = array(
	'wcaig_api_key',
	'wcaig_webhook_secret',
	'wcaig_model',
	'wcaig_task_type',
	'wcaig_poll_interval',
	'wcaig_max_poll_attempts',
	'wcaig_retry_count',
	'wcaig_preservation_rules',
	'wcaig_enable_logging',
	'wcaig_db_version',
);

foreach ( $option_keys as $key ) {
	delete_option( $key );
}

/* ------------------------------------------------------------------
 *  Delete transients
 * ----------------------------------------------------------------*/

$wpdb->query(
	"DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_wcaig_%' OR option_name LIKE '_transient_timeout_wcaig_%'"
);

/* ------------------------------------------------------------------
 *  Unschedule cron
 * ----------------------------------------------------------------*/

$timestamp = wp_next_scheduled( 'wcaig_orphan_cleanup' );
if ( $timestamp ) {
	wp_unschedule_event( $timestamp, 'wcaig_orphan_cleanup' );
}

/* ------------------------------------------------------------------
 *  Optionally delete generated media attachments
 *
 *  This deletes ALL attachments whose post_title starts with "wcaig_".
 *  Uncomment the block below (or control via a setting before uninstall)
 *  if you want a full cleanup.
 * ----------------------------------------------------------------*/

/*
$attachments = $wpdb->get_col(
	"SELECT ID FROM {$wpdb->posts} WHERE post_type = 'attachment' AND post_title LIKE 'wcaig_%'"
);

foreach ( $attachments as $att_id ) {
	wp_delete_attachment( (int) $att_id, true );
}
*/
