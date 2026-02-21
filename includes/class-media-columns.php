<?php
/**
 * Media Library columns.
 *
 * Adds "Optimized" and "Savings" columns to the WP Media list view.
 *
 * @package PenkovStudio\ImageOptimizer
 */

namespace PenkovStudio\ImageOptimizer;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Media_Columns {

    public function __construct() {
        if ( ! is_admin() ) {
            return;
        }

        add_filter( 'manage_media_columns', [ $this, 'add_columns' ] );
        add_action( 'manage_media_custom_column', [ $this, 'render_column' ], 10, 2 );
    }

    /**
     * Register custom columns.
     */
    public function add_columns( array $columns ): array {
        $columns['penkov_status']  = __( 'Penkov Optimized', 'penkov-optimizer' );
        $columns['penkov_savings'] = __( 'Savings', 'penkov-optimizer' );
        return $columns;
    }

    /**
     * Render column content.
     */
    public function render_column( string $column, int $post_id ): void {
        $mime = get_post_mime_type( $post_id );
        if ( ! in_array( $mime, [ 'image/jpeg', 'image/png', 'image/gif' ], true ) ) {
            echo '—';
            return;
        }

        if ( $column === 'penkov_status' ) {
            $optimized = get_post_meta( $post_id, '_penkov_optimized', true );
            $status    = (string) get_post_meta( $post_id, '_penkov_opt_status', true );
            $error     = (string) get_post_meta( $post_id, '_penkov_opt_error', true );

            if ( $optimized ) {
                if ( $status === 'optimized' ) {
                    echo '<span class="dashicons dashicons-yes-alt" style="color:#46b450;" title="Optimized"></span> ';
                    echo '<small style="color:#46b450;">Optimized</small>';
                } elseif ( $status === 'partial' ) {
                    echo '<span class="dashicons dashicons-yes-alt" style="color:#dba617;" title="Partially optimized"></span> ';
                    echo '<small style="color:#dba617;">Partial</small>';
                } else {
                    $title = $error ? esc_attr( $error ) : 'Skipped';
                    echo '<span class="dashicons dashicons-warning" style="color:#d63638;" title="' . $title . '"></span> ';
                    echo '<small style="color:#d63638;">Skipped</small>';
                    if ( $error ) {
                        echo '<br><small style="color:#777;">' . esc_html( $error ) . '</small>';
                    }
                }

                // Show optimize button (re-optimize).
                printf(
                    ' <button class="button button-small penkov-opt-single" data-id="%d" title="Re-optimize">↻</button>',
                    esc_attr( $post_id )
                );

                // Restore button if backup exists.
                static $backup = null;
                if ( null === $backup ) {
                    $backup = new Backup( new Logger() );
                }
                if ( $backup->has_backup( $post_id ) ) {
                    printf(
                        ' <button class="button button-small penkov-restore-single" data-id="%d" title="Restore original">⟲</button>',
                        esc_attr( $post_id )
                    );
                }
            } else {
                echo '<span class="dashicons dashicons-minus" style="color:#999;" title="Not optimized"></span> ';
                echo '<small style="color:#999;">No</small>';
                printf(
                    ' <button class="button button-small penkov-opt-single" data-id="%d" title="Optimize now">⚡</button>',
                    esc_attr( $post_id )
                );
            }
        }

        if ( $column === 'penkov_savings' ) {
            $data = get_post_meta( $post_id, '_penkov_opt_data', true );
            if ( is_array( $data ) && ! empty( $data['savings'] ) ) {
                $percent = $data['original_size'] > 0
                    ? round( ( $data['savings'] / $data['original_size'] ) * 100, 1 )
                    : 0;
                printf(
                    '<strong style="color:#0073aa;">-%s%%</strong><br><small>%s → %s</small>',
                    esc_html( $percent ),
                    esc_html( size_format( $data['original_size'] ) ),
                    esc_html( size_format( $data['optimized_size'] ) )
                );
            } else {
                echo '—';
            }
        }
    }
}
