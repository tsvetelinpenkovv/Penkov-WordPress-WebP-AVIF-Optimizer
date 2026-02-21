<?php
/**
 * Server capabilities detection.
 *
 * Detects Imagick / GD availability, WebP & AVIF support,
 * memory limits, etc.
 *
 * @package PenkovStudio\ImageOptimizer
 */

namespace PenkovStudio\ImageOptimizer;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Capabilities {

    /** @var array Cached capability flags. */
    private $caps = [];

    public function __construct() {
        $this->detect();
    }

    /**
     * Run all detection once.
     */
    private function detect(): void {
        // Imagick
        $this->caps['imagick'] = extension_loaded( 'imagick' ) && class_exists( '\\Imagick' );
        $this->caps['imagick_webp'] = false;
        $this->caps['imagick_avif'] = false;

        if ( $this->caps['imagick'] ) {
            $formats = \Imagick::queryFormats();
            $this->caps['imagick_webp'] = in_array( 'WEBP', $formats, true );
            $this->caps['imagick_avif'] = in_array( 'AVIF', $formats, true );
        }

        // GD
        $this->caps['gd'] = extension_loaded( 'gd' ) && function_exists( 'gd_info' );
        $this->caps['gd_webp'] = false;
        $this->caps['gd_avif'] = false;

        if ( $this->caps['gd'] ) {
            $info = gd_info();
            $this->caps['gd_webp'] = ! empty( $info['WebP Support'] );
            $this->caps['gd_avif'] = ! empty( $info['AVIF Support'] );
        }

        // Composite flags: can we produce the format at all?
        $this->caps['webp'] = $this->caps['imagick_webp'] || $this->caps['gd_webp'];
        $this->caps['avif'] = $this->caps['imagick_avif'] || $this->caps['gd_avif'];

        // Memory / time.
        $this->caps['memory_limit'] = wp_convert_hr_to_bytes( ini_get( 'memory_limit' ) );
        $this->caps['max_execution'] = (int) ini_get( 'max_execution_time' );
        $this->caps['upload_max']    = wp_max_upload_size();
    }

    /**
     * Check a capability.
     *
     * @param string $key e.g. 'webp', 'avif', 'imagick'.
     * @return bool
     */
    public function has( string $key ): bool {
        return ! empty( $this->caps[ $key ] );
    }

    /**
     * Get a capability value.
     *
     * @param string $key
     * @return mixed
     */
    public function get( string $key ) {
        return $this->caps[ $key ] ?? null;
    }

    /**
     * Return all capabilities (for admin screen).
     *
     * @return array
     */
    public function all(): array {
        return $this->caps;
    }

    /**
     * Preferred engine for a given format.
     *
     * @param string $format 'webp' or 'avif'.
     * @return string 'imagick', 'gd', or '' if unsupported.
     */
    public function engine_for( string $format ): string {
        if ( $this->has( 'imagick_' . $format ) ) {
            return 'imagick';
        }
        if ( $this->has( 'gd_' . $format ) ) {
            return 'gd';
        }
        return '';
    }
}
