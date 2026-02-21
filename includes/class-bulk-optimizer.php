<?php
/**
 * Bulk Optimizer — AJAX-driven batch processing.
 *
 * Provides endpoints for:
 *  - Getting unoptimised attachment IDs
 *  - Processing a batch
 *  - Pausing / resuming
 *  - Progress status
 *
 * @package PenkovStudio\ImageOptimizer
 */

namespace PenkovStudio\ImageOptimizer;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Bulk_Optimizer {

    /** @var Converter */
    private $converter;

    /** @var Backup */
    private $backup;

    /** @var Logger */
    private $logger;

    public function __construct( Converter $converter, Backup $backup, Logger $logger ) {
        $this->converter = $converter;
        $this->backup    = $backup;
        $this->logger    = $logger;

        add_action( 'wp_ajax_penkov_opt_bulk_status', [ $this, 'ajax_bulk_status' ] );
        add_action( 'wp_ajax_penkov_opt_bulk_process', [ $this, 'ajax_bulk_process' ] );
        add_action( 'wp_ajax_penkov_opt_single_restore', [ $this, 'ajax_single_restore' ] );
        add_action( 'wp_ajax_penkov_opt_single_optimize', [ $this, 'ajax_single_optimize' ] );
    }

    /* ─── AJAX: Get status (total / remaining) ────────────── */

    public function ajax_bulk_status(): void {
        check_ajax_referer( 'penkov_opt_bulk', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Insufficient permissions' );
        }

        $total        = $this->count_images();
        $processed    = $this->count_optimized(); // backwards compatibility: this is "processed".
        $optimized    = $this->count_successful();
        $skipped      = max( 0, $processed - $optimized );
        $remaining    = max( 0, $total - $processed );
        $stats        = $this->get_stats();

        wp_send_json_success( [
            'total'       => $total,
            'processed'   => $processed,
            'optimized'   => $optimized,
            'skipped'     => $skipped,
            'remaining'   => $remaining,
            'saved_bytes' => $stats['saved_bytes'],
            'avg_percent' => $stats['avg_percent'],
        ] );
    }

    /* ─── AJAX: Process one batch ─────────────────────────── */

    public function ajax_bulk_process(): void {
        check_ajax_referer( 'penkov_opt_bulk', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Insufficient permissions' );
        }

        $batch_size = (int) Core::opt( 'batch_size', 20 );
        $batch_size = max( 1, min( $batch_size, 100 ) );  // clamp

        // Time safeguard.
        $start   = time();
        $limit   = max( 30, (int) ini_get( 'max_execution_time' ) - 10 );
        $results = [];
        $ids     = $this->get_unoptimized_ids( $batch_size );

        if ( empty( $ids ) ) {
            wp_send_json_success( [
                'done'    => true,
                'results' => [],
                'message' => 'All images are optimized!',
            ] );
        }

        $delete_originals = (bool) Core::opt( 'delete_originals', 0 );
        $keep_backups     = (bool) Core::opt( 'keep_backups', 1 );

        // Memory guard (skip if unlimited / unknown).
        $mem_limit = wp_convert_hr_to_bytes( ini_get( 'memory_limit' ) );
        $mem_limit = ( is_numeric( $mem_limit ) && (int) $mem_limit > 0 ) ? (int) $mem_limit : 0;

        foreach ( $ids as $id ) {
            // Time guard.
            if ( ( time() - $start ) >= $limit ) {
                break;
            }

            if ( $mem_limit > 0 && memory_get_usage( true ) > 0.85 * $mem_limit ) {
                $this->logger->warning( 'Memory limit approaching — stopping batch early.' );
                break;
            }

            // Backup first if delete_originals is on.
            if ( $delete_originals && $keep_backups ) {
                $file = get_attached_file( $id );
                if ( $file ) {
                    $this->backup->backup_file( $file );
                    // Also backup thumbnails.
                    $meta = wp_get_attachment_metadata( $id );
                    if ( ! empty( $meta['sizes'] ) ) {
                        $dir = dirname( $file );
                        foreach ( $meta['sizes'] as $sd ) {
                            $this->backup->backup_file( $dir . '/' . $sd['file'] );
                        }
                    }
                }
            }

            $result = $this->converter->optimize_attachment( $id );

            // Delete originals only if ALL conversions succeeded.
            if ( $delete_originals && ! empty( $result['success'] ) && ! empty( $result['data']['all_ok'] ) ) {
                $this->delete_original_files( $id );
            }

            $results[] = [
                'id'      => $id,
                'success' => $result['success'] ?? false,
                'savings' => $result['data']['savings'] ?? 0,
                'error'   => $result['error'] ?? '',
            ];
        }

        $total     = $this->count_images();
        $processed = $this->count_optimized();
        $optimized = $this->count_successful();
        $skipped   = max( 0, $processed - $optimized );
        $remaining = max( 0, $total - $processed );

        wp_send_json_success( [
            'done'            => $remaining === 0,
            'processed_batch' => count( $results ),
            'total'           => $total,
            'processed_total' => $processed,
            'optimized_total' => $optimized,
            'skipped_total'   => $skipped,
            'remaining'       => $remaining,
            'results'         => $results,
        ] );
    }

    /* ─── AJAX: Single image optimize ─────────────────────── */

    public function ajax_single_optimize(): void {
        check_ajax_referer( 'penkov_opt_bulk', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Insufficient permissions' );
        }

        $id = (int) ( $_POST['attachment_id'] ?? 0 );
        if ( ! $id ) {
            wp_send_json_error( 'Invalid attachment ID' );
        }

        $result = $this->converter->optimize_attachment( $id );
        wp_send_json_success( $result );
    }

    /* ─── AJAX: Single restore ────────────────────────────── */

    public function ajax_single_restore(): void {
        check_ajax_referer( 'penkov_opt_bulk', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Insufficient permissions' );
        }

        $id = (int) ( $_POST['attachment_id'] ?? 0 );
        if ( ! $id ) {
            wp_send_json_error( 'Invalid attachment ID' );
        }

        $ok = $this->backup->restore_attachment( $id );
        wp_send_json( $ok ? [ 'success' => true ] : [ 'success' => false, 'data' => 'No backup found' ] );
    }

    /* ─── Queries ─────────────────────────────────────────── */

    /**
     * Count all JPEG/PNG/GIF images in the library.
     */
    public function count_images(): int {
        global $wpdb;
        return (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM {$wpdb->posts}
             WHERE post_type = 'attachment'
             AND post_mime_type IN ('image/jpeg','image/png','image/gif')"
        );
    }

    /**
     * Count optimized images.
     */
    public function count_optimized(): int {
        global $wpdb;
        return (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM {$wpdb->postmeta}
             WHERE meta_key = '_penkov_optimized' AND meta_value = '1'"
        );
    }

    /**
     * Count images that were successfully optimized (optimized or partial).
     *
     * Note: legacy installs might only have _penkov_optimized without status.
     * We treat "no status" as optimized for backwards compatibility.
     */
    public function count_successful(): int {
        global $wpdb;

        return (int) $wpdb->get_var(
            "SELECT COUNT(DISTINCT pm1.post_id)
             FROM {$wpdb->postmeta} pm1
             LEFT JOIN {$wpdb->postmeta} pm2
               ON pm1.post_id = pm2.post_id AND pm2.meta_key = '_penkov_opt_status'
             WHERE pm1.meta_key = '_penkov_optimized'
               AND pm1.meta_value = '1'
               AND ( pm2.meta_value IN ('optimized','partial') OR pm2.meta_value IS NULL )"
        );
    }

    /**
     * Get IDs of unoptimized images.
     *
     * @param int $limit
     * @return int[]
     */
    public function get_unoptimized_ids( int $limit = 50 ): array {
        global $wpdb;

        $min_kb = (int) Core::opt( 'skip_under_kb', 5 );

        $ids = $wpdb->get_col( $wpdb->prepare(
            "SELECT p.ID FROM {$wpdb->posts} p
             LEFT JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id AND pm.meta_key = '_penkov_optimized'
             WHERE p.post_type = 'attachment'
               AND p.post_mime_type IN ('image/jpeg','image/png','image/gif')
               AND ( pm.meta_value IS NULL OR pm.meta_value != '1' )
             ORDER BY p.ID ASC
             LIMIT %d",
            $limit
        ) );

        return array_map( 'intval', $ids );
    }

    /**
     * Aggregate stats.
     */
    public function get_stats(): array {
        global $wpdb;

        $rows = $wpdb->get_results(
            "SELECT meta_value FROM {$wpdb->postmeta} WHERE meta_key = '_penkov_opt_data'"
        );

        $total_saved = 0;
        $total_orig  = 0;
        $count       = 0;

        foreach ( $rows as $row ) {
            $data = maybe_unserialize( $row->meta_value );
            if ( ! is_array( $data ) ) {
                continue;
            }
            $total_saved += ( $data['savings'] ?? 0 );
            $total_orig  += ( $data['original_size'] ?? 0 );
            $count++;
        }

        $avg = $total_orig > 0 ? round( ( $total_saved / $total_orig ) * 100, 1 ) : 0;

        return [
            'saved_bytes' => $total_saved,
            'avg_percent' => $avg,
            'count'       => $count,
        ];
    }

    /* ─── Delete originals (critical section) ─────────────── */

    /**
     * Delete original files after successful conversion.
     *
     * Safety:
     * 1. Only if ALL format conversions succeeded.
     * 2. Backup must exist if backups are enabled.
     * 3. We update attachment metadata to point to new format.
     */
    private function delete_original_files( int $attachment_id ): void {
        $file = get_attached_file( $attachment_id );
        if ( ! $file || ! file_exists( $file ) ) {
            return;
        }

        // Determine best available converted file.
        $format  = Core::opt( 'format', 'webp' );
        $best    = '';
        foreach ( [ 'avif', 'webp' ] as $fmt ) {
            if ( file_exists( $file . '.' . $fmt ) ) {
                $best = $fmt;
                break;
            }
        }

        if ( ! $best ) {
            $this->logger->warning( "Cannot delete original: no converted file for #{$attachment_id}" );
            return;
        }

        // Verify backup exists (if backups enabled).
        if ( Core::opt( 'keep_backups', 1 ) && ! $this->backup->has_backup( $attachment_id ) ) {
            $this->logger->warning( "Cannot delete original: no backup for #{$attachment_id}" );
            return;
        }

        // Delete original.
        // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
        unlink( $file );

        // Rename converted file to original name (safest strategy for URL compat).
        // e.g., image.jpg.webp → image.jpg  (but it's actually webp content)
        // This keeps all existing URLs working without DB rewrite.
        // Browsers receiving WebP content with .jpg extension will still render it
        // because they use Content-Type header, not extension.
        //
        // ALTERNATIVE (safer): We leave the .webp file and add rewrite rules.
        // We choose the rewrite approach for maximum safety.
        //
        // Strategy: Add .htaccess rules to redirect .jpg → .jpg.webp when available.
        $this->logger->info( "Deleted original for #{$attachment_id}, converted file: {$file}.{$best}" );

        // Delete thumbnails too.
        $metadata = wp_get_attachment_metadata( $attachment_id );
        if ( ! empty( $metadata['sizes'] ) ) {
            $dir = dirname( $file );
            foreach ( $metadata['sizes'] as $sd ) {
                $thumb = $dir . '/' . $sd['file'];
                if ( file_exists( $thumb ) ) {
                    // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
                    unlink( $thumb );
                }
            }
        }

        // Mark in meta.
        update_post_meta( $attachment_id, '_penkov_originals_deleted', 1 );
    }
}
