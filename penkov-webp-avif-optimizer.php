<?php
/**
 * Plugin Name: Penkov WebP/AVIF Optimizer
 * Plugin URI:  https://penkovstudio.eu/plugins/webp-avif-optimizer
 * Description: Високопроизводителен оптимизатор на изображения — конвертира в WebP/AVIF, компресира агресивно, доставя автоматично с <picture>/srcset, поддържа lazy load, LCP preload и CDN rewrite. Разработен от PenkovStudio.eu.
 * Version:     1.0.1
 * Author:      PenkovStudio.eu
 * Author URI:  https://penkovstudio.eu
 * License:     GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: penkov-optimizer
 * Domain Path: /languages
 * Requires at least: 5.8
 * Requires PHP: 7.4
 *
 * @package PenkovStudio\ImageOptimizer
 */

namespace PenkovStudio\ImageOptimizer;

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/*──────────────────────────────────────────────────────────────
 | Constants
 ──────────────────────────────────────────────────────────────*/
define( 'PENKOV_OPT_VERSION', '1.0.1' );
define( 'PENKOV_OPT_FILE', __FILE__ );
define( 'PENKOV_OPT_DIR', plugin_dir_path( __FILE__ ) );
define( 'PENKOV_OPT_URL', plugin_dir_url( __FILE__ ) );
define( 'PENKOV_OPT_BASENAME', plugin_basename( __FILE__ ) );
define( 'PENKOV_OPT_SLUG', 'penkov-optimizer' );
define( 'PENKOV_OPT_PREFIX', 'penkov_opt_' );

/*──────────────────────────────────────────────────────────────
 | Autoloader
 ──────────────────────────────────────────────────────────────*/
spl_autoload_register( function ( $class ) {
    $prefix = 'PenkovStudio\\ImageOptimizer\\';
    if ( strpos( $class, $prefix ) !== 0 ) {
        return;
    }

    $relative = substr( $class, strlen( $prefix ) );
    $parts    = explode( '\\', $relative );
    $file     = 'class-' . strtolower( str_replace( '_', '-', array_pop( $parts ) ) ) . '.php';

    // Map sub-namespaces to directories.
    $sub = strtolower( implode( '/', $parts ) );
    $dir = $sub ? PENKOV_OPT_DIR . $sub . '/' : PENKOV_OPT_DIR . 'includes/';

    $path = $dir . $file;
    if ( file_exists( $path ) ) {
        require_once $path;
    }

    // Also try admin directory.
    $admin_path = PENKOV_OPT_DIR . 'admin/' . $file;
    if ( ! file_exists( $path ) && file_exists( $admin_path ) ) {
        require_once $admin_path;
    }
});

/*──────────────────────────────────────────────────────────────
 | Bootstrap
 ──────────────────────────────────────────────────────────────*/

/**
 * Return the singleton Core instance.
 *
 * @return Core
 */
function penkov_optimizer() {
    return Core::instance();
}

// Fire it up.
add_action( 'plugins_loaded', __NAMESPACE__ . '\\penkov_optimizer' );

/*──────────────────────────────────────────────────────────────
 | Plugin list links (Settings / Bulk / Docs)
 ──────────────────────────────────────────────────────────────*/

add_filter( 'plugin_action_links_' . PENKOV_OPT_BASENAME, function ( array $links ): array {
    if ( ! current_user_can( 'manage_options' ) ) {
        return $links;
    }

    $settings_url = admin_url( 'admin.php?page=penkov-optimizer&tab=settings' );
    $bulk_url     = admin_url( 'admin.php?page=penkov-optimizer&tab=bulk' );

    $action_links = [
        '<a href="' . esc_url( $settings_url ) . '">' . esc_html__( 'Settings', 'penkov-optimizer' ) . '</a>',
        '<a href="' . esc_url( $bulk_url ) . '">' . esc_html__( 'Bulk Optimize', 'penkov-optimizer' ) . '</a>',
    ];

    return array_merge( $action_links, $links );
} );

add_filter( 'plugin_row_meta', function ( array $links, string $file ): array {
    if ( $file !== PENKOV_OPT_BASENAME ) {
        return $links;
    }

    $links[] = '<a href="' . esc_url( admin_url( 'admin.php?page=penkov-optimizer&tab=dashboard' ) ) . '">' . esc_html__( 'Dashboard', 'penkov-optimizer' ) . '</a>';
    $links[] = '<a href="' . esc_url( 'https://penkovstudio.eu' ) . '" target="_blank" rel="noopener">' . esc_html__( 'Support', 'penkov-optimizer' ) . '</a>';

    return $links;
}, 10, 2 );

/*──────────────────────────────────────────────────────────────
 | Activation / Deactivation
 ──────────────────────────────────────────────────────────────*/
register_activation_hook( __FILE__, function () {
    // Create backup directory.
    $upload_dir = wp_upload_dir();
    $backup_dir = $upload_dir['basedir'] . '/penkov-backups';
    if ( ! file_exists( $backup_dir ) ) {
        wp_mkdir_p( $backup_dir );
        // Protect with .htaccess.
        file_put_contents( $backup_dir . '/.htaccess', "Deny from all\n" );
    }

    // Create logs directory.
    $log_dir = PENKOV_OPT_DIR . 'logs';
    if ( ! file_exists( $log_dir ) ) {
        wp_mkdir_p( $log_dir );
        file_put_contents( $log_dir . '/.htaccess', "Deny from all\n" );
    }

    // Default options.
    $defaults = [
        'format'              => 'webp',        // webp | avif | both
        'quality'             => 82,
        'preset'              => 'balanced',     // aggressive | balanced | safe
        'auto_optimize'       => 1,
        'delete_originals'    => 0,
        'keep_backups'        => 1,
        'backup_days'         => 30,
        'skip_under_kb'       => 5,
        'exclude_folders'     => '',
        'exclude_patterns'    => '',
        'lazy_load'           => 1,
        'lazy_skip_first'     => 1,
        'lcp_selector'        => '',
        'cdn_url'             => '',
        'remove_query_strings'=> 0,
        'batch_size'          => 20,
        'animated_gif_policy' => 'skip',         // skip | convert
        'picture_tag'         => 1,
        'srcset_rewrite'      => 1,
    ];

    foreach ( $defaults as $key => $value ) {
        if ( get_option( PENKOV_OPT_PREFIX . $key ) === false ) {
            update_option( PENKOV_OPT_PREFIX . $key, $value );
        }
    }

    // Schedule cron for backup cleanup.
    if ( ! wp_next_scheduled( 'penkov_opt_cleanup_backups' ) ) {
        wp_schedule_event( time(), 'daily', 'penkov_opt_cleanup_backups' );
    }
});

register_deactivation_hook( __FILE__, function () {
    wp_clear_scheduled_hook( 'penkov_opt_cleanup_backups' );
    wp_clear_scheduled_hook( 'penkov_opt_background_bulk' );
});
