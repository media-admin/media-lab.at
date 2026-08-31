<?php
/**
 * Uninstall-Skript für Media Lab Backup.
 * Wird ausgeführt wenn das Plugin aus WordPress gelöscht wird.
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

// Einstellungen löschen
delete_option( 'mlbkp_settings' );
delete_option( 'mlbkp_db_version' );

// Geplante Cron-Jobs entfernen
wp_clear_scheduled_hook( 'mlbkp_cron_backup_daily' );
wp_clear_scheduled_hook( 'mlbkp_cron_backup_weekly' );

// Datenbank-Tabelle löschen
global $wpdb;
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}mlb_logs" );

// Temp-Verzeichnis leeren
$temp_dir = WP_CONTENT_DIR . '/uploads/media-lab-backup/';
if ( is_dir( $temp_dir ) ) {
    $files = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator( $temp_dir, FilesystemIterator::SKIP_DOTS ),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ( $files as $file ) {
        $file->isDir() ? rmdir( $file->getPathname() ) : unlink( $file->getPathname() );
    }
    rmdir( $temp_dir );
}
