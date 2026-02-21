<?php
/**
 * Backup & Restore manager.
 *
 * Stores original images in /uploads/penkov-backups/ mirroring
 * the original directory structure. Supports restore (rollback).
 *
 * @package PenkovStudio\ImageOptimizer
 */

namespace PenkovStudio\ImageOptimizer;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Backup {

    /** @var string */
    private $backup_base;

    /** @var Logger */
    private $logger;

    public function __construct( Logger $logger ) {
        $this->logger = $logger;
        $upload_dir = wp_upload_dir();
        $this->backup_base = trailingslashit( $upload_dir['basedir'] ) . 'penkov-backups';
    }

    /**
     * Backup a file before deletion.
     *
     * @param string $original_path Full server path.
     * @return bool
     */
    public function backup_file( string $original_path ): bool {
        if ( ! file_exists( $original_path ) ) {
            return false;
        }

        $upload_dir = wp_upload_dir();
        $relative   = str_replace( trailingslashit( $upload_dir['basedir'] ), '', $original_path );
        $dest       = $this->backup_base . '/' . $relative;
        $dest_dir   = dirname( $dest );

        if ( ! is_dir( $dest_dir ) ) {
            wp_mkdir_p( $dest_dir );
        }

        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_copy
        $ok = copy( $original_path, $dest );
        if ( $ok ) {
            $this->logger->info( "Backup created: {$relative}" );
        } else {
            $this->logger->error( "Backup FAILED: {$relative}" );
        }
        return $ok;
    }

    /**
     * Restore a file from backup.
     *
     * @param int $attachment_id
     * @return bool
     */
    public function restore_attachment( int $attachment_id ): bool {
        $file = get_attached_file( $attachment_id );
        if ( ! $file ) {
            return false;
        }

        $upload_dir = wp_upload_dir();
        $relative   = str_replace( trailingslashit( $upload_dir['basedir'] ), '', $file );
        $backup     = $this->backup_base . '/' . $relative;

        if ( ! file_exists( $backup ) ) {
            $this->logger->warning( "No backup found for attachment #{$attachment_id}" );
            return false;
        }

        // Restore main file.
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_copy
        $ok = copy( $backup, $file );
        if ( ! $ok ) {
            $this->logger->error( "Restore FAILED for attachment #{$attachment_id}" );
            return false;
        }

        // Also restore thumbnails.
        $metadata = wp_get_attachment_metadata( $attachment_id );
        if ( ! empty( $metadata['sizes'] ) ) {
            $dir = dirname( $file );
            foreach ( $metadata['sizes'] as $size => $data ) {
                $thumb_backup = $this->backup_base . '/' . dirname( $relative ) . '/' . $data['file'];
                $thumb_dest   = $dir . '/' . $data['file'];
                if ( file_exists( $thumb_backup ) ) {
                    // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_copy
                    copy( $thumb_backup, $thumb_dest );
                }
            }
        }

        // Remove WebP/AVIF versions.
        $this->remove_converted_files( $attachment_id, $metadata );

        // Clear optimization meta.
        delete_post_meta( $attachment_id, '_penkov_optimized' );
        delete_post_meta( $attachment_id, '_penkov_opt_data' );

        $this->logger->success( "Restored attachment #{$attachment_id} from backup" );
        return true;
    }

    /**
     * Remove generated WebP/AVIF files for an attachment.
     */
    private function remove_converted_files( int $attachment_id, ?array $metadata ): void {
        $file = get_attached_file( $attachment_id );
        if ( ! $file ) {
            return;
        }

        $extensions = [ '.webp', '.avif' ];
        foreach ( $extensions as $ext ) {
            $converted = $file . $ext;
            if ( file_exists( $converted ) ) {
                // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
                unlink( $converted );
            }
        }

        if ( ! empty( $metadata['sizes'] ) ) {
            $dir = dirname( $file );
            foreach ( $metadata['sizes'] as $data ) {
                foreach ( $extensions as $ext ) {
                    $thumb = $dir . '/' . $data['file'] . $ext;
                    if ( file_exists( $thumb ) ) {
                        // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
                        unlink( $thumb );
                    }
                }
            }
        }
    }

    /**
     * Check if a backup exists for an attachment.
     *
     * @param int $attachment_id
     * @return bool
     */
    public function has_backup( int $attachment_id ): bool {
        $file = get_attached_file( $attachment_id );
        if ( ! $file ) {
            return false;
        }
        $upload_dir = wp_upload_dir();
        $relative   = str_replace( trailingslashit( $upload_dir['basedir'] ), '', $file );
        return file_exists( $this->backup_base . '/' . $relative );
    }

    /**
     * Cleanup backups older than X days.
     *
     * @param int $days
     */
    public function cleanup_old_backups( int $days ): void {
        if ( $days < 1 ) {
            return;
        }
        $threshold = time() - ( $days * DAY_IN_SECONDS );
        $this->cleanup_directory( $this->backup_base, $threshold );
        $this->logger->info( "Cleaned up backups older than {$days} days." );
    }

    /**
     * Recursively delete old files.
     */
    private function cleanup_directory( string $dir, int $threshold ): void {
        if ( ! is_dir( $dir ) ) {
            return;
        }
        $items = new \DirectoryIterator( $dir );
        foreach ( $items as $item ) {
            if ( $item->isDot() ) {
                continue;
            }
            if ( $item->isDir() ) {
                $this->cleanup_directory( $item->getPathname(), $threshold );
                // Remove empty dirs.
                if ( count( glob( $item->getPathname() . '/*' ) ) === 0 ) {
                    rmdir( $item->getPathname() );
                }
            } elseif ( $item->getMTime() < $threshold ) {
                // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
                unlink( $item->getPathname() );
            }
        }
    }

    /**
     * Get total backup directory size.
     *
     * @return int Bytes.
     */
    public function get_backup_size(): int {
        return $this->dir_size( $this->backup_base );
    }

    private function dir_size( string $dir ): int {
        $size = 0;
        if ( ! is_dir( $dir ) ) {
            return 0;
        }
        foreach ( new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $dir, \FilesystemIterator::SKIP_DOTS ) ) as $file ) {
            $size += $file->getSize();
        }
        return $size;
    }
}
