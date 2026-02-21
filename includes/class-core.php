<?php
/**
 * Core plugin class (singleton).
 *
 * Initialises every sub-system: converter, delivery, admin, cron, etc.
 *
 * @package PenkovStudio\ImageOptimizer
 */

namespace PenkovStudio\ImageOptimizer;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class Core {

    /** @var self|null */
    private static $instance = null;

    /** @var Converter */
    public $converter;

    /** @var Delivery */
    public $delivery;

    /** @var Backup */
    public $backup;

    /** @var Logger */
    public $logger;

    /** @var Media_Columns */
    public $media_columns;

    /** @var Bulk_Optimizer */
    public $bulk;

    /** @var Cron */
    public $cron;

    /** @var Capabilities */
    public $capabilities;

    /**
     * Singleton accessor.
     */
    public static function instance(): self {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Constructor — wires everything up.
     */
    private function __construct() {
        $this->logger       = new Logger();
        $this->capabilities = new Capabilities();
        $this->backup       = new Backup( $this->logger );
        $this->converter    = new Converter( $this->logger, $this->capabilities );
        $this->delivery     = new Delivery();
        $this->bulk         = new Bulk_Optimizer( $this->converter, $this->backup, $this->logger );
        $this->cron         = new Cron( $this->bulk );
        $this->media_columns = new Media_Columns();

        // Auto-optimise new uploads.
        add_filter( 'wp_generate_attachment_metadata', [ $this, 'on_upload' ], 10, 2 );

        // Admin area.
        if ( is_admin() ) {
            new Admin( $this );
        }
    }

    /* ─── helpers ──────────────────────────────────────────── */

    /**
     * Get a plugin option with default fallback.
     *
     * @param string $key     Option key (without prefix).
     * @param mixed  $default Default value.
     * @return mixed
     */
    public static function opt( string $key, $default = false ) {
        return get_option( PENKOV_OPT_PREFIX . $key, $default );
    }

    /**
     * Auto-optimise on upload.
     *
     * @param array $metadata Attachment metadata.
     * @param int   $attachment_id
     * @return array
     */
    public function on_upload( array $metadata, int $attachment_id ): array {
        if ( ! self::opt( 'auto_optimize', 1 ) ) {
            return $metadata;
        }

        $mime = get_post_mime_type( $attachment_id );
        $allowed = [ 'image/jpeg', 'image/png', 'image/gif' ];
        if ( ! in_array( $mime, $allowed, true ) ) {
            return $metadata;
        }

        // Check skip-under-KB.
        $file = get_attached_file( $attachment_id );
        $min_kb = (int) self::opt( 'skip_under_kb', 5 );
        if ( $file && filesize( $file ) < $min_kb * 1024 ) {
            return $metadata;
        }

        // Run optimisation (converter handles format selection).
        $this->converter->optimize_attachment( $attachment_id, $metadata );

        return $metadata;
    }
}
