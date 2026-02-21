<?php
/**
 * Uninstall — clean up all plugin data.
 *
 * @package PenkovStudio\ImageOptimizer
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
    exit;
}

global $wpdb;

// Remove all options.
$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE 'penkov_opt_%'" );

// Remove all post meta.
$wpdb->query( "DELETE FROM {$wpdb->postmeta} WHERE meta_key IN ('_penkov_optimized', '_penkov_opt_data', '_penkov_originals_deleted')" );

// Remove transients.
delete_transient( 'penkov_opt_recent_logs' );

// Clear scheduled events.
wp_clear_scheduled_hook( 'penkov_opt_cleanup_backups' );
wp_clear_scheduled_hook( 'penkov_opt_background_bulk' );

// Note: We do NOT delete backup files or converted WebP/AVIF files
// on uninstall to avoid data loss. Users should remove these manually
// if desired (/uploads/penkov-backups/).
