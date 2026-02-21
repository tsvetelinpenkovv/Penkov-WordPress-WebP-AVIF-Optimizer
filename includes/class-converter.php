<?php
/**
 * Image Converter — WebP & AVIF conversion engine.
 *
 * Uses Imagick when available, falls back to GD.
 * Handles all WP image sizes for a given attachment.
 *
 * Safety strategy for "Delete originals":
 * ─ Conversion must succeed for ALL sizes before deletion.
 * ─ Backup is created first (if backups enabled).
 * ─ Files used as direct URLs in theme/plugin are NOT deleted (heuristic check).
 * ─ URL references in DB are updated via safe search-replace.
 *
 * @package PenkovStudio\ImageOptimizer
 */

namespace PenkovStudio\ImageOptimizer;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Converter {

    /** @var Logger */
    private $logger;

    /** @var Capabilities */
    private $caps;

    public function __construct( Logger $logger, Capabilities $caps ) {
        $this->logger = $logger;
        $this->caps   = $caps;
    }

    /* ─── public API ──────────────────────────────────────── */

    /**
     * Optimise a single attachment (all sizes).
     *
     * @param int        $attachment_id
     * @param array|null $metadata  Optional pre-fetched metadata.
     * @return array     Result data (savings, etc.).
     */
    public function optimize_attachment( int $attachment_id, ?array $metadata = null ): array {
        if ( ! $metadata ) {
            $metadata = wp_get_attachment_metadata( $attachment_id );
        }

        $file = get_attached_file( $attachment_id );
        if ( ! $file || ! file_exists( $file ) ) {
            $error = 'File not found';
            $this->logger->error( "File not found for attachment #{$attachment_id}" );
            $this->mark_processed( $attachment_id, [
                'status'         => 'missing',
                'error'          => $error,
                'original_size'  => 0,
                'optimized_size' => 0,
                'savings'        => 0,
                'formats'        => [],
                'all_ok'         => false,
                'date'           => current_time( 'mysql' ),
            ] );
            return [ 'success' => false, 'error' => $error ];
        }

        $mime = get_post_mime_type( $attachment_id );

        // Skip under a minimum size.
        $min_kb = (int) Core::opt( 'skip_under_kb', 5 );
        if ( $min_kb > 0 ) {
            $size = filesize( $file );
            if ( $size !== false && $size < ( $min_kb * 1024 ) ) {
                $error = 'Skipped (under size threshold)';
                $this->logger->info( "Skipped small file (#{$attachment_id})" );
                $this->mark_processed( $attachment_id, [
                    'status'         => 'skipped_small',
                    'error'          => $error,
                    'original_size'  => (int) $size,
                    'optimized_size' => (int) $size,
                    'savings'        => 0,
                    'formats'        => [],
                    'all_ok'         => true,
                    'date'           => current_time( 'mysql' ),
                ] );
                return [ 'success' => false, 'error' => $error ];
            }
        }

        // Skip excluded patterns.
        if ( $this->is_excluded( $file ) ) {
            $error = 'Excluded';
            $this->logger->info( "Skipped (excluded): #{$attachment_id}" );
            $this->mark_processed( $attachment_id, [
                'status'         => 'skipped_excluded',
                'error'          => $error,
                'original_size'  => (int) filesize( $file ),
                'optimized_size' => (int) filesize( $file ),
                'savings'        => 0,
                'formats'        => [],
                'all_ok'         => true,
                'date'           => current_time( 'mysql' ),
            ] );
            return [ 'success' => false, 'error' => $error ];
        }

        // Skip animated GIF if policy = skip.
        if ( $mime === 'image/gif' && Core::opt( 'animated_gif_policy', 'skip' ) === 'skip' ) {
            if ( $this->is_animated_gif( $file ) ) {
                $error = 'Animated GIF skipped';
                $this->logger->info( "Skipped animated GIF: #{$attachment_id}" );
                $this->mark_processed( $attachment_id, [
                    'status'         => 'skipped_animated_gif',
                    'error'          => $error,
                    'original_size'  => (int) filesize( $file ),
                    'optimized_size' => (int) filesize( $file ),
                    'savings'        => 0,
                    'formats'        => [],
                    'all_ok'         => true,
                    'date'           => current_time( 'mysql' ),
                ] );
                return [ 'success' => false, 'error' => $error ];
            }
        }

        $format  = Core::opt( 'format', 'webp' );     // webp | avif | both
        $quality = (int) Core::opt( 'quality', 82 );
        $formats_to_do = $this->resolve_formats( $format );

        if ( empty( $formats_to_do ) ) {
            $error = 'No supported output format available on this server';
            $this->logger->error( "{$error} (#{$attachment_id})" );
            $this->mark_processed( $attachment_id, [
                'status'         => 'error_no_engine',
                'error'          => $error,
                'original_size'  => (int) filesize( $file ),
                'optimized_size' => (int) filesize( $file ),
                'savings'        => 0,
                'formats'        => [],
                'all_ok'         => false,
                'date'           => current_time( 'mysql' ),
            ] );
            return [ 'success' => false, 'error' => $error ];
        }

        $original_size = (int) filesize( $file );
        $all_ok        = true;
        $total_saved   = 0;
        $generated     = []; // format => size
        $errors        = [];

        // ── Convert main file ──
        foreach ( $formats_to_do as $fmt ) {
            $result = $this->convert_file( $file, $fmt, $quality, $mime );
            if ( $result['success'] ) {
                $generated[ $fmt ] = (int) $result['size'];
            } else {
                $all_ok = false;
                $errors[] = "{$fmt}: " . ( $result['error'] ?? 'Unknown error' );
                $this->logger->warning( "Conversion failed ({$fmt}) for: {$file}", [ 'error' => $result['error'] ?? '' ] );
            }
        }

        // Savings are calculated against the BEST generated format (smallest size).
        $best_main = ! empty( $generated ) ? min( $generated ) : 0;
        if ( $best_main > 0 && $best_main < $original_size ) {
            $total_saved += ( $original_size - $best_main );
        }

        $any_generated = ! empty( $generated );

        // ── Convert thumbnails ──
        if ( ! empty( $metadata['sizes'] ) ) {
            $dir = dirname( $file );
            foreach ( $metadata['sizes'] as $size_name => $size_data ) {
                $thumb_file = $dir . '/' . $size_data['file'];
                if ( ! file_exists( $thumb_file ) ) {
                    continue;
                }
                $thumb_original  = (int) filesize( $thumb_file );
                $thumb_generated = [];
                foreach ( $formats_to_do as $fmt ) {
                    $result = $this->convert_file( $thumb_file, $fmt, $quality, $mime );
                    if ( $result['success'] ) {
                        $thumb_generated[ $fmt ] = (int) $result['size'];
                    } else {
                        $all_ok = false;
                        $errors[] = "{$fmt} (thumb {$size_name}): " . ( $result['error'] ?? 'Unknown error' );
                    }
                }

                if ( ! empty( $thumb_generated ) ) {
                    $any_generated = true;
                }

                $best_thumb = ! empty( $thumb_generated ) ? min( $thumb_generated ) : 0;
                if ( $best_thumb > 0 && $best_thumb < $thumb_original ) {
                    $total_saved += ( $thumb_original - $best_thumb );
                }
            }
        }

        // ── Save metadata ──
        $new_size = $best_main > 0 ? $best_main : $original_size;

        $status = $any_generated ? ( $all_ok ? 'optimized' : 'partial' ) : 'skipped';

        $opt_data = [
            'status'         => $status,
            'error'          => ! empty( $errors ) ? implode( ' | ', array_slice( $errors, 0, 5 ) ) : '',
            'original_size'  => $original_size,
            'optimized_size' => (int) $new_size,
            'savings'        => max( 0, (int) $total_saved ),
            'formats'        => $formats_to_do,
            'generated'      => array_keys( $generated ),
            'all_ok'         => $all_ok,
            'date'           => current_time( 'mysql' ),
        ];

        $this->mark_processed( $attachment_id, $opt_data );

        if ( $status === 'optimized' || $status === 'partial' ) {
            $this->logger->success(
                "Optimized #{$attachment_id}: saved " . size_format( $opt_data['savings'] ),
                [ 'formats' => $formats_to_do ]
            );
            return [ 'success' => true, 'data' => $opt_data ];
        }

        $this->logger->info( "Skipped #{$attachment_id}", [ 'reason' => $opt_data['error'] ] );
        return [ 'success' => false, 'error' => $opt_data['error'] ?: 'Skipped', 'data' => $opt_data ];
    }

    /**
     * Mark an attachment as processed, so Bulk Optimization never gets stuck
     * re-processing the same problematic ID (missing files, exclusions, etc.).
     */
    private function mark_processed( int $attachment_id, array $opt_data ): void {
        $status = $opt_data['status'] ?? 'optimized';
        update_post_meta( $attachment_id, '_penkov_optimized', 1 );
        update_post_meta( $attachment_id, '_penkov_opt_status', $status );
        if ( ! empty( $opt_data['error'] ) ) {
            update_post_meta( $attachment_id, '_penkov_opt_error', (string) $opt_data['error'] );
        } else {
            delete_post_meta( $attachment_id, '_penkov_opt_error' );
        }
        update_post_meta( $attachment_id, '_penkov_opt_data', $opt_data );
    }

    /* ─── single file conversion ──────────────────────────── */

    /**
     * Convert a single image file to a given format.
     *
     * Output file: <original_path>.<format> e.g. image.jpg.webp
     *
     * @param string $source  Full path.
     * @param string $format  'webp' or 'avif'.
     * @param int    $quality 0-100.
     * @param string $mime    Source MIME type.
     * @return array ['success'=>bool, 'path'=>string, 'size'=>int, 'error'=>string]
     */
    public function convert_file( string $source, string $format, int $quality, string $mime = '' ): array {
        $dest = $source . '.' . $format;

        $engine = $this->caps->engine_for( $format );
        if ( ! $engine ) {
            return [ 'success' => false, 'error' => "No engine for {$format}", 'path' => '', 'size' => 0 ];
        }

        try {
            if ( $engine === 'imagick' ) {
                $ok = $this->convert_imagick( $source, $dest, $format, $quality );
            } else {
                $ok = $this->convert_gd( $source, $dest, $format, $quality, $mime );
            }

            if ( $ok && file_exists( $dest ) ) {
                $new_size = filesize( $dest );
                $orig_size = filesize( $source );

                // If converted file is LARGER than original, delete it.
                if ( $new_size >= $orig_size ) {
                    // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
                    unlink( $dest );
                    return [
                        'success' => false,
                        'error'   => 'Converted file larger than original — skipped',
                        'path'    => '',
                        'size'    => 0,
                    ];
                }

                return [ 'success' => true, 'path' => $dest, 'size' => $new_size, 'error' => '' ];
            }

            return [ 'success' => false, 'error' => 'Conversion produced no output', 'path' => '', 'size' => 0 ];

        } catch ( \Throwable $e ) {
            return [ 'success' => false, 'error' => $e->getMessage(), 'path' => '', 'size' => 0 ];
        }
    }

    /* ─── Imagick engine ──────────────────────────────────── */

    private function convert_imagick( string $src, string $dest, string $format, int $quality ): bool {
        $im = new \Imagick( $src );

        // Strip metadata to save space.
        $im->stripImage();

        // Set format.
        $im->setImageFormat( $format );
        $im->setImageCompressionQuality( $quality );

        if ( $format === 'webp' ) {
            $im->setOption( 'webp:method', '6' );      // max compression
            $im->setOption( 'webp:auto-filter', 'true' );
        }

        $ok = $im->writeImage( $dest );
        $im->clear();
        $im->destroy();

        return (bool) $ok;
    }

    /* ─── GD engine ───────────────────────────────────────── */

    private function convert_gd( string $src, string $dest, string $format, int $quality, string $mime ): bool {
        $image = $this->gd_load( $src, $mime );
        if ( ! $image ) {
            return false;
        }

        // Preserve alpha for PNG/GIF.
        imagepalettetotruecolor( $image );
        imagealphablending( $image, true );
        imagesavealpha( $image, true );

        $ok = false;
        if ( $format === 'webp' && function_exists( 'imagewebp' ) ) {
            $ok = imagewebp( $image, $dest, $quality );
        } elseif ( $format === 'avif' && function_exists( 'imageavif' ) ) {
            // imageavif quality range is 0-100, speed 0-10.
            $ok = imageavif( $image, $dest, $quality, 6 );
        }

        imagedestroy( $image );
        return $ok;
    }

    /**
     * Load image resource via GD.
     *
     * @return \GdImage|resource|false
     */
    private function gd_load( string $src, string $mime ) {
        if ( ! $mime ) {
            $mime = wp_check_filetype( $src )['type'] ?? '';
        }

        switch ( $mime ) {
            case 'image/jpeg':
                return imagecreatefromjpeg( $src );
            case 'image/png':
                return imagecreatefrompng( $src );
            case 'image/gif':
                return imagecreatefromgif( $src );
            default:
                return false;
        }
    }

    /* ─── helpers ──────────────────────────────────────────── */

    /**
     * Resolve which formats to produce.
     *
     * @param string $setting 'webp', 'avif', 'both'.
     * @return string[]
     */
    private function resolve_formats( string $setting ): array {
        $formats = [];
        if ( in_array( $setting, [ 'webp', 'both' ], true ) && $this->caps->has( 'webp' ) ) {
            $formats[] = 'webp';
        }
        if ( in_array( $setting, [ 'avif', 'both' ], true ) && $this->caps->has( 'avif' ) ) {
            $formats[] = 'avif';
        }
        // Fallback: if neither is available but webp was requested, still try.
        if ( empty( $formats ) && $this->caps->has( 'webp' ) ) {
            $formats[] = 'webp';
        }
        return $formats;
    }

    /**
     * Check if a GIF is animated.
     */
    private function is_animated_gif( string $file ): bool {
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
        $contents = file_get_contents( $file );
        return preg_match_all( '/\x00\x21\xF9\x04/', $contents ) > 1;
    }

    /**
     * Check exclusion rules.
     */
    private function is_excluded( string $file ): bool {
        $exclude_folders  = array_filter( array_map( 'trim', explode( "\n", Core::opt( 'exclude_folders', '' ) ) ) );
        $exclude_patterns = array_filter( array_map( 'trim', explode( "\n", Core::opt( 'exclude_patterns', '' ) ) ) );

        foreach ( $exclude_folders as $folder ) {
            if ( strpos( $file, $folder ) !== false ) {
                return true;
            }
        }

        $basename = basename( $file );
        foreach ( $exclude_patterns as $pattern ) {
            if ( fnmatch( $pattern, $basename ) ) {
                return true;
            }
        }

        return false;
    }
}
