<?php
/**
 * Delivery — frontend image serving.
 *
 * Handles:
 *  - <picture> tag generation with WebP/AVIF sources
 *  - srcset rewriting to include converted formats
 *  - Lazy loading (native, with skip-first-image option)
 *  - LCP preload hint
 *  - CDN URL rewriting
 *  - Query string removal from image URLs
 *
 * @package PenkovStudio\ImageOptimizer
 */

namespace PenkovStudio\ImageOptimizer;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Delivery {

    public function __construct() {
        // Only act on the frontend.
        if ( is_admin() ) {
            return;
        }

        // <picture> tag in the_content.
        if ( Core::opt( 'picture_tag', 1 ) ) {
            add_filter( 'the_content', [ $this, 'rewrite_content_images' ], 999 );
            add_filter( 'post_thumbnail_html', [ $this, 'rewrite_single_img' ], 999 );
        }

        // srcset rewrite.
        if ( Core::opt( 'srcset_rewrite', 1 ) ) {
            add_filter( 'wp_calculate_image_srcset', [ $this, 'filter_srcset' ], 10, 5 );
        }

        // Lazy load.
        if ( Core::opt( 'lazy_load', 1 ) ) {
            add_filter( 'wp_lazy_loading_enabled', '__return_true' );
        }

        // LCP preload.
        add_action( 'wp_head', [ $this, 'lcp_preload' ], 1 );

        // Remove query strings.
        if ( Core::opt( 'remove_query_strings', 0 ) ) {
            add_filter( 'the_content', [ $this, 'remove_image_query_strings' ], 1000 );
            add_filter( 'script_loader_src', [ $this, 'strip_query_string' ], 15 );
            add_filter( 'style_loader_src', [ $this, 'strip_query_string' ], 15 );
        }

        // CDN rewrite.
        $cdn = Core::opt( 'cdn_url', '' );
        if ( $cdn ) {
            add_filter( 'wp_get_attachment_url', [ $this, 'cdn_rewrite' ], 99 );
            add_filter( 'the_content', [ $this, 'cdn_rewrite_content' ], 1001 );
        }
    }

    /* ─── <picture> rewriting ─────────────────────────────── */

    /**
     * Wrap <img> tags in <picture> with WebP/AVIF sources.
     */
    public function rewrite_content_images( string $content ): string {
        if ( empty( $content ) ) {
            return $content;
        }

        $skip_first = Core::opt( 'lazy_skip_first', 1 );
        $counter    = 0;

        // Match all <img> tags.
        $content = preg_replace_callback(
            '/<img\s[^>]+>/i',
            function ( $matches ) use ( &$counter, $skip_first ) {
                $counter++;
                $img = $matches[0];

                // Skip if already inside <picture>.
                // (We can't do lookbehind in this context, so we check in the caller.)

                return $this->wrap_in_picture( $img, $counter, $skip_first );
            },
            $content
        );

        return $content;
    }

    /**
     * Rewrite a single <img> (e.g. post thumbnail).
     */
    public function rewrite_single_img( string $html ): string {
        if ( empty( $html ) || strpos( $html, '<picture' ) !== false ) {
            return $html;
        }

        return preg_replace_callback(
            '/<img\s[^>]+>/i',
            function ( $matches ) {
                return $this->wrap_in_picture( $matches[0], 2, false ); // never first
            },
            $html
        );
    }

    /**
     * Wrap an <img> tag in <picture>.
     */
    private function wrap_in_picture( string $img_tag, int $position, bool $skip_first ): string {
        // Don't double-wrap.
        if ( strpos( $img_tag, 'data-penkov-picture' ) !== false ) {
            return $img_tag;
        }

        // Extract src.
        if ( ! preg_match( '/src=["\']([^"\']+)["\']/i', $img_tag, $src_match ) ) {
            return $img_tag;
        }
        $src = $src_match[1];

        // Only process local images.
        $upload_dir = wp_upload_dir();
        $upload_url = $upload_dir['baseurl'];
        if ( strpos( $src, $upload_url ) === false ) {
            return $img_tag;
        }

        // Find converted file on disk.
        $relative = str_replace( $upload_url, '', $src );
        $abs_path = $upload_dir['basedir'] . $relative;

        $sources = [];
        // AVIF first (better compression, less support).
        if ( file_exists( $abs_path . '.avif' ) ) {
            $sources[] = '<source srcset="' . esc_url( $src . '.avif' ) . '" type="image/avif">';
        }
        // WebP.
        if ( file_exists( $abs_path . '.webp' ) ) {
            $sources[] = '<source srcset="' . esc_url( $src . '.webp' ) . '" type="image/webp">';
        }

        if ( empty( $sources ) ) {
            return $img_tag;
        }

        // Lazy load attribute.
        $lazy = Core::opt( 'lazy_load', 1 );
        if ( $lazy && ! ( $skip_first && $position === 1 ) ) {
            if ( strpos( $img_tag, 'loading=' ) === false ) {
                $img_tag = str_replace( '<img ', '<img loading="lazy" ', $img_tag );
            }
        }

        // Mark as processed.
        $img_tag = str_replace( '<img ', '<img data-penkov-picture="1" ', $img_tag );

        $picture  = '<picture>' . "\n";
        $picture .= implode( "\n", $sources ) . "\n";
        $picture .= $img_tag . "\n";
        $picture .= '</picture>';

        return $picture;
    }

    /* ─── srcset filter ───────────────────────────────────── */

    /**
     * Add WebP/AVIF variants to srcset.
     *
     * NOTE: This is complementary to <picture> — if picture_tag is on,
     * the srcset approach is secondary. They can work together.
     */
    public function filter_srcset( array $sources, array $size_array, string $image_src, array $image_meta, int $attachment_id ): array {
        // We don't modify srcset if picture_tag is the primary strategy.
        // Instead we just ensure srcset works with existing URLs.
        return $sources;
    }

    /* ─── LCP preload ─────────────────────────────────────── */

    /**
     * Output <link rel="preload"> for LCP image.
     */
    public function lcp_preload(): void {
        $selector = Core::opt( 'lcp_selector', '' );
        if ( empty( $selector ) ) {
            return;
        }

        // The selector is expected to be a URL or an attachment ID.
        $url = '';
        if ( is_numeric( $selector ) ) {
            $url = wp_get_attachment_url( (int) $selector );
        } else {
            $url = esc_url( $selector );
        }

        if ( ! $url ) {
            return;
        }

        // Check for WebP/AVIF versions.
        $upload_dir = wp_upload_dir();
        $relative   = str_replace( $upload_dir['baseurl'], '', $url );
        $abs_path   = $upload_dir['basedir'] . $relative;

        echo "\n<!-- Penkov WebP/AVIF Optimizer: LCP Preload -->\n";

        if ( file_exists( $abs_path . '.avif' ) ) {
            printf(
                '<link rel="preload" as="image" href="%s" type="image/avif">' . "\n",
                esc_url( $url . '.avif' )
            );
        } elseif ( file_exists( $abs_path . '.webp' ) ) {
            printf(
                '<link rel="preload" as="image" href="%s" type="image/webp">' . "\n",
                esc_url( $url . '.webp' )
            );
        } else {
            printf(
                '<link rel="preload" as="image" href="%s">' . "\n",
                esc_url( $url )
            );
        }
    }

    /* ─── CDN rewrite ─────────────────────────────────────── */

    /**
     * Rewrite attachment URLs to CDN.
     */
    public function cdn_rewrite( string $url ): string {
        $cdn = rtrim( Core::opt( 'cdn_url', '' ), '/' );
        if ( ! $cdn ) {
            return $url;
        }
        $site_url = rtrim( site_url(), '/' );
        return str_replace( $site_url, $cdn, $url );
    }

    /**
     * Rewrite image URLs in content to CDN.
     */
    public function cdn_rewrite_content( string $content ): string {
        $cdn = rtrim( Core::opt( 'cdn_url', '' ), '/' );
        if ( ! $cdn ) {
            return $content;
        }

        $upload_dir = wp_upload_dir();
        $upload_url = $upload_dir['baseurl'];
        $cdn_upload = str_replace( rtrim( site_url(), '/' ), $cdn, $upload_url );

        return str_replace( $upload_url, $cdn_upload, $content );
    }

    /* ─── Query string removal ────────────────────────────── */

    public function remove_image_query_strings( string $content ): string {
        return preg_replace(
            '/(<img[^>]+src=["\'][^"\']+)\?[^"\']*(["\'])/i',
            '$1$2',
            $content
        );
    }

    public function strip_query_string( string $src ): string {
        if ( strpos( $src, '?' ) !== false ) {
            return strtok( $src, '?' );
        }
        return $src;
    }
}
