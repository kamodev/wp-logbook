<?php
/**
 * Uninstall: remove data only when the settings say so.
 *
 * @package WP_Logbook
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

wp_clear_scheduled_hook( 'wplb_qrz_sync' );

$wplb_settings = get_option( 'wplb_settings', array() );
if ( empty( $wplb_settings['delete_on_uninstall'] ) ) {
	return;
}

global $wpdb;
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}wplb_qsos" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

delete_option( 'wplb_settings' );
delete_option( 'wplb_qrz_api_key' );
delete_option( 'wplb_db_version' );
delete_metadata( 'user', 0, 'wplb_per_page', '', true );
