<?php
/**
 * Simple file-based logger.
 *
 * Writes to /logs/penkov-optimizer-YYYY-MM-DD.log
 * Also stores last 200 entries in a transient for admin display.
 *
 * @package PenkovStudio\ImageOptimizer
 */

namespace PenkovStudio\ImageOptimizer;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Logger {

    const TRANSIENT_KEY = 'penkov_opt_recent_logs';
    const MAX_RECENT    = 200;

    /** @var string */
    private $log_dir;

    public function __construct() {
        $this->log_dir = PENKOV_OPT_DIR . 'logs';
        if ( ! is_dir( $this->log_dir ) ) {
            wp_mkdir_p( $this->log_dir );
        }
    }

    /**
     * Log a message.
     *
     * @param string $level   info | warning | error | success
     * @param string $message
     * @param array  $context Optional context data.
     */
    public function log( string $level, string $message, array $context = [] ): void {
        $entry = [
            'time'    => current_time( 'mysql' ),
            'level'   => $level,
            'message' => $message,
            'context' => $context,
        ];

        // File log.
        $file = $this->log_dir . '/penkov-optimizer-' . current_time( 'Y-m-d' ) . '.log';
        $line = sprintf(
            "[%s] [%s] %s %s\n",
            $entry['time'],
            strtoupper( $level ),
            $message,
            $context ? wp_json_encode( $context, JSON_UNESCAPED_UNICODE ) : ''
        );
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
        file_put_contents( $file, $line, FILE_APPEND | LOCK_EX );

        // Recent transient (for admin).
        $recent = get_transient( self::TRANSIENT_KEY );
        if ( ! is_array( $recent ) ) {
            $recent = [];
        }
        array_unshift( $recent, $entry );
        $recent = array_slice( $recent, 0, self::MAX_RECENT );
        set_transient( self::TRANSIENT_KEY, $recent, DAY_IN_SECONDS );
    }

    /**
     * Shorthand methods.
     */
    public function info( string $msg, array $ctx = [] ): void {
        $this->log( 'info', $msg, $ctx );
    }

    public function success( string $msg, array $ctx = [] ): void {
        $this->log( 'success', $msg, $ctx );
    }

    public function warning( string $msg, array $ctx = [] ): void {
        $this->log( 'warning', $msg, $ctx );
    }

    public function error( string $msg, array $ctx = [] ): void {
        $this->log( 'error', $msg, $ctx );
    }

    /**
     * Get recent log entries.
     *
     * @param int $limit
     * @return array
     */
    public function get_recent( int $limit = 20 ): array {
        $recent = get_transient( self::TRANSIENT_KEY );
        if ( ! is_array( $recent ) ) {
            return [];
        }
        return array_slice( $recent, 0, $limit );
    }

    /**
     * Clear log files older than X days.
     *
     * @param int $days
     */
    public function cleanup( int $days = 30 ): void {
        $files = glob( $this->log_dir . '/penkov-optimizer-*.log' );
        if ( ! $files ) {
            return;
        }
        $threshold = time() - ( $days * DAY_IN_SECONDS );
        foreach ( $files as $file ) {
            if ( filemtime( $file ) < $threshold ) {
                // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
                unlink( $file );
            }
        }
    }
}
