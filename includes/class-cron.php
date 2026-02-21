<?php
/**
 * WP-Cron tasks.
 *
 *  - Background bulk processing (if triggered).
 *  - Backup cleanup.
 *
 * @package PenkovStudio\ImageOptimizer
 */

namespace PenkovStudio\ImageOptimizer;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Cron {

    /** @var Bulk_Optimizer */
    private $bulk;

    public function __construct( Bulk_Optimizer $bulk ) {
        $this->bulk = $bulk;

        add_action( 'penkov_opt_cleanup_backups', [ $this, 'cleanup_backups' ] );
        add_action( 'penkov_opt_background_bulk', [ $this, 'background_batch' ] );
    }

    /**
     * Clean old backups based on retention setting.
     */
    public function cleanup_backups(): void {
        $days   = (int) Core::opt( 'backup_days', 30 );
        $backup = new Backup( new Logger() );
        $backup->cleanup_old_backups( $days );

        $logger = new Logger();
        $logger->cleanup( 60 ); // also clean logs older than 60 days
    }

    /**
     * Process a small batch in the background via WP-Cron.
     *
     * This is scheduled when the user starts a bulk but navigates away.
     */
    public function background_batch(): void {
        $ids = $this->bulk->get_unoptimized_ids( 10 );
        if ( empty( $ids ) ) {
            // All done, unschedule.
            wp_clear_scheduled_hook( 'penkov_opt_background_bulk' );
            return;
        }

        $converter = new Converter( new Logger(), new Capabilities() );
        foreach ( $ids as $id ) {
            $converter->optimize_attachment( $id );
        }

        // Re-schedule for next run if there's more work.
        $remaining = $this->bulk->count_images() - $this->bulk->count_optimized();
        if ( $remaining > 0 && ! wp_next_scheduled( 'penkov_opt_background_bulk' ) ) {
            wp_schedule_single_event( time() + 60, 'penkov_opt_background_bulk' );
        }
    }
}
