<?php
/**
 * Uninstall routine.
 *
 * By default data is PRESERVED (withdrawal records have evidential/legal value).
 * Set the constant DBR54_DELETE_DATA_ON_UNINSTALL to true in wp-config.php to
 * force a full wipe.
 *
 * @package DB_Recesso_54bis
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

wp_clear_scheduled_hook( 'dbr54_retention_purge' );

if ( defined( 'DBR54_DELETE_DATA_ON_UNINSTALL' ) && DBR54_DELETE_DATA_ON_UNINSTALL ) {
	global $wpdb;
	$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}dbr54_recessi" );  // phpcs:ignore
	$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}dbr54_garanzia" ); // phpcs:ignore

	delete_option( 'dbr54_settings' );
	delete_option( 'dbr54_db_version' );
	delete_option( 'dbr54_flush_needed' );

	// Remove generated receipts.
	$dbr54_upload = wp_upload_dir();
	$dbr54_dir = trailingslashit( $dbr54_upload['basedir'] ) . 'dbr54-receipts';
	if ( is_dir( $dbr54_dir ) ) {
		foreach ( (array) glob( $dbr54_dir . '/*' ) as $dbr54_file ) {
			@unlink( $dbr54_file );
		}
		@rmdir( $dbr54_dir );
	}
}
