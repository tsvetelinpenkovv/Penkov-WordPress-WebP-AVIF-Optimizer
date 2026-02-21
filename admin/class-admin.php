<?php
/**
 * Admin interface — menus, settings, pages.
 *
 * Provides a modern tabbed interface with Dashboard, Settings,
 * Bulk Optimize, Advanced, and Logs tabs.
 *
 * @package PenkovStudio\ImageOptimizer
 */

namespace PenkovStudio\ImageOptimizer;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Admin {

    /** @var Core */
    private $core;

    public function __construct( Core $core ) {
        $this->core = $core;

        add_action( 'admin_menu', [ $this, 'register_menu' ] );
        add_action( 'admin_init', [ $this, 'register_settings' ] );
        add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_assets' ] );
    }

    /* ─── Menu ────────────────────────────────────────────── */

    public function register_menu(): void {
        add_menu_page(
            __( 'Penkov Optimizer', 'penkov-optimizer' ),
            __( 'PenkovStudio', 'penkov-optimizer' ),
            'manage_options',
            'penkov-optimizer',
            [ $this, 'render_page' ],
            'dashicons-images-alt2',
            80
        );

        add_submenu_page(
            'penkov-optimizer',
            __( 'WebP/AVIF Optimizer', 'penkov-optimizer' ),
            __( 'Image Optimizer', 'penkov-optimizer' ),
            'manage_options',
            'penkov-optimizer',
            [ $this, 'render_page' ]
        );
    }

    /* ─── Assets ──────────────────────────────────────────── */

    public function enqueue_assets( string $hook ): void {
        if ( strpos( $hook, 'penkov-optimizer' ) === false && $hook !== 'upload.php' ) {
            return;
        }

        wp_enqueue_style(
            'penkov-opt-admin',
            PENKOV_OPT_URL . 'assets/css/admin.css',
            [],
            PENKOV_OPT_VERSION
        );

        wp_enqueue_script(
            'penkov-opt-admin',
            PENKOV_OPT_URL . 'assets/js/admin.js',
            [ 'jquery' ],
            PENKOV_OPT_VERSION,
            true
        );

        wp_localize_script( 'penkov-opt-admin', 'PenkovOpt', [
            'ajax_url' => admin_url( 'admin-ajax.php' ),
            'nonce'    => wp_create_nonce( 'penkov_opt_bulk' ),
            'strings'  => [
                'confirm_delete'  => __( 'Are you SURE you want to enable "Delete Originals"? This cannot be undone if backups are disabled!', 'penkov-optimizer' ),
                'processing'      => __( 'Processing…', 'penkov-optimizer' ),
                'done'            => __( 'All images optimized!', 'penkov-optimizer' ),
                'paused'          => __( 'Paused', 'penkov-optimizer' ),
                'error'           => __( 'An error occurred', 'penkov-optimizer' ),
                'restored'        => __( 'Restored successfully', 'penkov-optimizer' ),
                'optimized'       => __( 'Optimized successfully', 'penkov-optimizer' ),
            ],
        ] );
    }

    /* ─── Settings API ────────────────────────────────────── */

    public function register_settings(): void {
        $fields = [
            'format', 'quality', 'preset', 'auto_optimize',
            'delete_originals', 'keep_backups', 'backup_days',
            'skip_under_kb', 'exclude_folders', 'exclude_patterns',
            'lazy_load', 'lazy_skip_first', 'lcp_selector',
            'cdn_url', 'remove_query_strings', 'batch_size',
            'animated_gif_policy', 'picture_tag', 'srcset_rewrite',
        ];

        foreach ( $fields as $field ) {
            register_setting( 'penkov_opt_settings', PENKOV_OPT_PREFIX . $field, [
                'sanitize_callback' => [ $this, 'sanitize_field' ],
            ] );
        }
    }

    /**
     * Generic sanitize callback.
     */
    public function sanitize_field( $value ) {
        if ( is_numeric( $value ) ) {
            return (int) $value;
        }
        return sanitize_textarea_field( $value );
    }

    /* ─── Main page renderer ──────────────────────────────── */

    public function render_page(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        $active_tab = sanitize_key( $_GET['tab'] ?? 'dashboard' );
        $tabs = [
            'dashboard' => [ 'label' => __( 'Dashboard', 'penkov-optimizer' ),     'icon' => 'dashicons-chart-area' ],
            'settings'  => [ 'label' => __( 'Settings', 'penkov-optimizer' ),      'icon' => 'dashicons-admin-generic' ],
            'bulk'      => [ 'label' => __( 'Bulk Optimize', 'penkov-optimizer' ), 'icon' => 'dashicons-update' ],
            'advanced'  => [ 'label' => __( 'Advanced', 'penkov-optimizer' ),      'icon' => 'dashicons-admin-tools' ],
            'logs'      => [ 'label' => __( 'Logs', 'penkov-optimizer' ),          'icon' => 'dashicons-list-view' ],
        ];

        ?>
        <div class="wrap penkov-opt-wrap">

            <!-- Header -->
            <div class="penkov-opt-header">
                <h1>
                    <span class="dashicons dashicons-images-alt2"></span>
                    <?php esc_html_e( 'Penkov WebP/AVIF Optimizer', 'penkov-optimizer' ); ?>
                </h1>
                <span class="penkov-opt-version">v<?php echo esc_html( PENKOV_OPT_VERSION ); ?></span>
            </div>

            <!-- Tabs -->
            <nav class="penkov-opt-tabs">
                <?php foreach ( $tabs as $slug => $tab ) : ?>
                    <a href="<?php echo esc_url( admin_url( 'admin.php?page=penkov-optimizer&tab=' . $slug ) ); ?>"
                       class="penkov-opt-tab <?php echo $active_tab === $slug ? 'active' : ''; ?>">
                        <span class="dashicons <?php echo esc_attr( $tab['icon'] ); ?>"></span>
                        <?php echo esc_html( $tab['label'] ); ?>
                    </a>
                <?php endforeach; ?>
            </nav>

            <!-- Tab Content -->
            <div class="penkov-opt-content">
                <?php
                switch ( $active_tab ) {
                    case 'settings':
                        $this->render_settings_tab();
                        break;
                    case 'bulk':
                        $this->render_bulk_tab();
                        break;
                    case 'advanced':
                        $this->render_advanced_tab();
                        break;
                    case 'logs':
                        $this->render_logs_tab();
                        break;
                    default:
                        $this->render_dashboard_tab();
                        break;
                }
                ?>
            </div>

            <!-- Footer -->
            <div class="penkov-opt-footer">
                Made with ❤ by <a href="https://penkovstudio.eu" target="_blank" rel="noopener">PenkovStudio.eu</a>
            </div>

        </div>
        <?php
    }

    /* ─── Dashboard tab ───────────────────────────────────── */

    private function render_dashboard_tab(): void {
        $total     = $this->core->bulk->count_images();
        $processed = $this->core->bulk->count_optimized();
        $optimized = $this->core->bulk->count_successful();
        $stats     = $this->core->bulk->get_stats();
        $caps      = $this->core->capabilities->all();
        $recent    = $this->core->logger->get_recent( 20 );

        ?>
        <!-- Stats Cards -->
        <div class="penkov-cards">
            <div class="penkov-card penkov-card-blue">
                <div class="penkov-card-icon"><span class="dashicons dashicons-format-gallery"></span></div>
                <div class="penkov-card-body">
                    <h3><?php echo esc_html( $optimized ); ?> / <?php echo esc_html( $total ); ?></h3>
                    <p>
                        <?php esc_html_e( 'Images Optimized', 'penkov-optimizer' ); ?>
                        <?php if ( $processed > $optimized ) : ?>
                            <br><small style="opacity:.8;"><?php echo esc_html( sprintf( __( 'Processed: %d', 'penkov-optimizer' ), $processed ) ); ?></small>
                        <?php endif; ?>
                    </p>
                </div>
            </div>

            <div class="penkov-card penkov-card-green">
                <div class="penkov-card-icon"><span class="dashicons dashicons-download"></span></div>
                <div class="penkov-card-body">
                    <h3><?php echo esc_html( size_format( $stats['saved_bytes'], 2 ) ); ?></h3>
                    <p><?php esc_html_e( 'Space Saved', 'penkov-optimizer' ); ?></p>
                </div>
            </div>

            <div class="penkov-card penkov-card-orange">
                <div class="penkov-card-icon"><span class="dashicons dashicons-performance"></span></div>
                <div class="penkov-card-body">
                    <h3><?php echo esc_html( $stats['avg_percent'] ); ?>%</h3>
                    <p><?php esc_html_e( 'Average Reduction', 'penkov-optimizer' ); ?></p>
                </div>
            </div>

            <div class="penkov-card penkov-card-purple">
                <div class="penkov-card-icon"><span class="dashicons dashicons-backup"></span></div>
                <div class="penkov-card-body">
                    <h3><?php echo esc_html( size_format( $this->core->backup->get_backup_size(), 2 ) ); ?></h3>
                    <p><?php esc_html_e( 'Backup Size', 'penkov-optimizer' ); ?></p>
                </div>
            </div>
        </div>

        <!-- Server Capabilities -->
        <div class="penkov-section">
            <h2><span class="dashicons dashicons-desktop"></span> <?php esc_html_e( 'Server Capabilities', 'penkov-optimizer' ); ?></h2>
            <table class="penkov-caps-table">
                <tr>
                    <td>Imagick</td>
                    <td><?php echo $caps['imagick'] ? '<span class="penkov-badge penkov-badge-ok">Available</span>' : '<span class="penkov-badge penkov-badge-warn">Not Available</span>'; ?></td>
                </tr>
                <tr>
                    <td>GD Library</td>
                    <td><?php echo $caps['gd'] ? '<span class="penkov-badge penkov-badge-ok">Available</span>' : '<span class="penkov-badge penkov-badge-warn">Not Available</span>'; ?></td>
                </tr>
                <tr>
                    <td>WebP Support</td>
                    <td><?php echo $caps['webp'] ? '<span class="penkov-badge penkov-badge-ok">✓ Supported</span>' : '<span class="penkov-badge penkov-badge-err">✗ Not Supported</span>'; ?></td>
                </tr>
                <tr>
                    <td>AVIF Support</td>
                    <td><?php echo $caps['avif'] ? '<span class="penkov-badge penkov-badge-ok">✓ Supported</span>' : '<span class="penkov-badge penkov-badge-warn">✗ Not Available</span>'; ?></td>
                </tr>
                <tr>
                    <td>Memory Limit</td>
                    <td><?php echo esc_html( size_format( $caps['memory_limit'] ) ); ?></td>
                </tr>
                <tr>
                    <td>Max Execution Time</td>
                    <td><?php echo esc_html( $caps['max_execution'] ); ?>s</td>
                </tr>
            </table>
        </div>

        <!-- Recent Operations -->
        <div class="penkov-section">
            <h2><span class="dashicons dashicons-list-view"></span> <?php esc_html_e( 'Recent Operations', 'penkov-optimizer' ); ?></h2>
            <?php if ( empty( $recent ) ) : ?>
                <p class="penkov-empty"><?php esc_html_e( 'No recent operations.', 'penkov-optimizer' ); ?></p>
            <?php else : ?>
                <table class="penkov-log-table">
                    <thead>
                        <tr><th>Time</th><th>Level</th><th>Message</th></tr>
                    </thead>
                    <tbody>
                    <?php foreach ( $recent as $entry ) : ?>
                        <tr class="penkov-log-<?php echo esc_attr( $entry['level'] ); ?>">
                            <td><code><?php echo esc_html( $entry['time'] ); ?></code></td>
                            <td><span class="penkov-badge penkov-badge-<?php echo esc_attr( $entry['level'] ); ?>"><?php echo esc_html( strtoupper( $entry['level'] ) ); ?></span></td>
                            <td><?php echo esc_html( $entry['message'] ); ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
        <?php
    }

    /* ─── Settings tab ────────────────────────────────────── */

    private function render_settings_tab(): void {
        ?>
        <form method="post" action="options.php" id="penkov-settings-form">
            <?php settings_fields( 'penkov_opt_settings' ); ?>

            <!-- Format & Quality -->
            <div class="penkov-section">
                <h2><span class="dashicons dashicons-format-image"></span> <?php esc_html_e( 'Format & Quality', 'penkov-optimizer' ); ?></h2>

                <div class="penkov-field-row">
                    <label><?php esc_html_e( 'Output Format', 'penkov-optimizer' ); ?></label>
                    <div class="penkov-toggle-group">
                        <?php $fmt = Core::opt( 'format', 'webp' ); ?>
                        <label class="penkov-toggle-btn <?php echo $fmt === 'webp' ? 'active' : ''; ?>">
                            <input type="radio" name="<?php echo PENKOV_OPT_PREFIX; ?>format" value="webp" <?php checked( $fmt, 'webp' ); ?>> WebP
                        </label>
                        <label class="penkov-toggle-btn <?php echo $fmt === 'avif' ? 'active' : ''; ?>">
                            <input type="radio" name="<?php echo PENKOV_OPT_PREFIX; ?>format" value="avif" <?php checked( $fmt, 'avif' ); ?>> AVIF
                        </label>
                        <label class="penkov-toggle-btn <?php echo $fmt === 'both' ? 'active' : ''; ?>">
                            <input type="radio" name="<?php echo PENKOV_OPT_PREFIX; ?>format" value="both" <?php checked( $fmt, 'both' ); ?>> WebP + AVIF
                        </label>
                    </div>
                    <?php if ( ! $this->core->capabilities->has( 'avif' ) ) : ?>
                        <p class="penkov-notice-inline penkov-notice-warn">
                            <span class="dashicons dashicons-warning"></span>
                            <?php esc_html_e( 'AVIF is not supported on this server. Only WebP will be generated.', 'penkov-optimizer' ); ?>
                        </p>
                    <?php endif; ?>
                </div>

                <div class="penkov-field-row">
                    <label for="penkov-quality"><?php esc_html_e( 'Quality', 'penkov-optimizer' ); ?> <span class="penkov-quality-val"><?php echo esc_html( Core::opt( 'quality', 82 ) ); ?></span></label>
                    <input type="range" id="penkov-quality" name="<?php echo PENKOV_OPT_PREFIX; ?>quality"
                           min="10" max="100" step="1"
                           value="<?php echo esc_attr( Core::opt( 'quality', 82 ) ); ?>">
                    <div class="penkov-presets">
                        <?php $preset = Core::opt( 'preset', 'balanced' ); ?>
                        <button type="button" class="penkov-preset-btn <?php echo $preset === 'aggressive' ? 'active' : ''; ?>" data-quality="60" data-preset="aggressive">🔥 Aggressive (60)</button>
                        <button type="button" class="penkov-preset-btn <?php echo $preset === 'balanced' ? 'active' : ''; ?>" data-quality="82" data-preset="balanced">⚖️ Balanced (82)</button>
                        <button type="button" class="penkov-preset-btn <?php echo $preset === 'safe' ? 'active' : ''; ?>" data-quality="92" data-preset="safe">🛡️ Safe (92)</button>
                    </div>
                    <input type="hidden" name="<?php echo PENKOV_OPT_PREFIX; ?>preset" id="penkov-preset-input" value="<?php echo esc_attr( $preset ); ?>">
                </div>

                <div class="penkov-field-row">
                    <label>
                        <input type="checkbox" name="<?php echo PENKOV_OPT_PREFIX; ?>auto_optimize" value="1" <?php checked( Core::opt( 'auto_optimize', 1 ), 1 ); ?>>
                        <?php esc_html_e( 'Auto-optimize new uploads', 'penkov-optimizer' ); ?>
                    </label>
                </div>

                <div class="penkov-field-row">
                    <label for="penkov-skip-kb"><?php esc_html_e( 'Skip images under (KB)', 'penkov-optimizer' ); ?></label>
                    <input type="number" id="penkov-skip-kb" name="<?php echo PENKOV_OPT_PREFIX; ?>skip_under_kb"
                           value="<?php echo esc_attr( Core::opt( 'skip_under_kb', 5 ) ); ?>" min="0" max="500" class="small-text">
                </div>

                <div class="penkov-field-row">
                    <label><?php esc_html_e( 'Animated GIF Policy', 'penkov-optimizer' ); ?></label>
                    <?php $gif = Core::opt( 'animated_gif_policy', 'skip' ); ?>
                    <select name="<?php echo PENKOV_OPT_PREFIX; ?>animated_gif_policy">
                        <option value="skip" <?php selected( $gif, 'skip' ); ?>><?php esc_html_e( 'Skip animated GIFs', 'penkov-optimizer' ); ?></option>
                        <option value="convert" <?php selected( $gif, 'convert' ); ?>><?php esc_html_e( 'Convert (may lose animation)', 'penkov-optimizer' ); ?></option>
                    </select>
                </div>

                <div class="penkov-field-row">
                    <label for="penkov-batch"><?php esc_html_e( 'Batch Size', 'penkov-optimizer' ); ?></label>
                    <input type="number" id="penkov-batch" name="<?php echo PENKOV_OPT_PREFIX; ?>batch_size"
                           value="<?php echo esc_attr( Core::opt( 'batch_size', 20 ) ); ?>" min="1" max="100" class="small-text">
                    <p class="description"><?php esc_html_e( 'Number of images per AJAX batch (10–50 recommended).', 'penkov-optimizer' ); ?></p>
                </div>
            </div>

            <!-- Delete Originals (DANGER ZONE) -->
            <div class="penkov-section penkov-section-danger" id="penkov-danger-zone">
                <h2><span class="dashicons dashicons-warning"></span> <?php esc_html_e( 'Danger Zone: Delete Originals', 'penkov-optimizer' ); ?></h2>

                <div class="penkov-danger-card">
                    <p class="penkov-danger-text">
                        <strong><?php esc_html_e( '⚠️ WARNING:', 'penkov-optimizer' ); ?></strong>
                        <?php esc_html_e( 'Enabling this option will permanently delete original JPEG/PNG/GIF files after successful WebP/AVIF conversion. If backups are disabled, THIS CANNOT BE UNDONE. Make sure you understand the risks.', 'penkov-optimizer' ); ?>
                    </p>

                    <div class="penkov-field-row">
                        <label class="penkov-danger-toggle">
                            <input type="checkbox" name="<?php echo PENKOV_OPT_PREFIX; ?>delete_originals" value="1"
                                   id="penkov-delete-originals"
                                   <?php checked( Core::opt( 'delete_originals', 0 ), 1 ); ?>>
                            <?php esc_html_e( 'Delete original files after successful conversion', 'penkov-optimizer' ); ?>
                        </label>
                    </div>

                    <div class="penkov-field-row penkov-danger-confirm" style="display:none;" id="penkov-danger-confirm-wrap">
                        <label>
                            <input type="checkbox" id="penkov-understand-risk">
                            <?php esc_html_e( 'I understand the risk and have reviewed my backup settings', 'penkov-optimizer' ); ?>
                        </label>
                    </div>
                </div>

                <div class="penkov-field-row">
                    <label>
                        <input type="checkbox" name="<?php echo PENKOV_OPT_PREFIX; ?>keep_backups" value="1" <?php checked( Core::opt( 'keep_backups', 1 ), 1 ); ?>>
                        <?php esc_html_e( 'Keep backups of originals (in /uploads/penkov-backups/)', 'penkov-optimizer' ); ?>
                    </label>
                </div>

                <div class="penkov-field-row">
                    <label for="penkov-backup-days"><?php esc_html_e( 'Keep backups for (days)', 'penkov-optimizer' ); ?></label>
                    <input type="number" id="penkov-backup-days" name="<?php echo PENKOV_OPT_PREFIX; ?>backup_days"
                           value="<?php echo esc_attr( Core::opt( 'backup_days', 30 ) ); ?>" min="1" max="365" class="small-text">
                </div>
            </div>

            <!-- Exclusions -->
            <div class="penkov-section">
                <h2><span class="dashicons dashicons-dismiss"></span> <?php esc_html_e( 'Exclusions', 'penkov-optimizer' ); ?></h2>

                <div class="penkov-field-row">
                    <label for="penkov-exclude-folders"><?php esc_html_e( 'Exclude Folders (one per line)', 'penkov-optimizer' ); ?></label>
                    <textarea id="penkov-exclude-folders" name="<?php echo PENKOV_OPT_PREFIX; ?>exclude_folders" rows="3" class="large-text"><?php echo esc_textarea( Core::opt( 'exclude_folders', '' ) ); ?></textarea>
                </div>

                <div class="penkov-field-row">
                    <label for="penkov-exclude-patterns"><?php esc_html_e( 'Exclude File Patterns (one per line, e.g. logo-*)', 'penkov-optimizer' ); ?></label>
                    <textarea id="penkov-exclude-patterns" name="<?php echo PENKOV_OPT_PREFIX; ?>exclude_patterns" rows="3" class="large-text"><?php echo esc_textarea( Core::opt( 'exclude_patterns', '' ) ); ?></textarea>
                </div>
            </div>

            <?php submit_button( __( 'Save Settings', 'penkov-optimizer' ), 'primary penkov-save-btn' ); ?>
        </form>
        <?php
    }

    /* ─── Bulk Optimize tab ───────────────────────────────── */

    private function render_bulk_tab(): void {
        $total     = $this->core->bulk->count_images();
        $processed = $this->core->bulk->count_optimized(); // processed
        $optimized = $this->core->bulk->count_successful();
        $skipped   = max( 0, $processed - $optimized );
        $remaining = max( 0, $total - $processed );

        ?>
        <div class="penkov-section">
            <h2><span class="dashicons dashicons-update"></span> <?php esc_html_e( 'Bulk Optimization', 'penkov-optimizer' ); ?></h2>

            <div class="penkov-bulk-stats">
                <div class="penkov-stat"><strong id="penkov-total"><?php echo esc_html( $total ); ?></strong> <?php esc_html_e( 'Total images', 'penkov-optimizer' ); ?></div>
                <div class="penkov-stat"><strong id="penkov-processed"><?php echo esc_html( $processed ); ?></strong> <?php esc_html_e( 'Processed', 'penkov-optimizer' ); ?></div>
                <div class="penkov-stat"><strong id="penkov-optimized"><?php echo esc_html( $optimized ); ?></strong> <?php esc_html_e( 'Optimized', 'penkov-optimizer' ); ?></div>
                <div class="penkov-stat"><strong id="penkov-skipped"><?php echo esc_html( $skipped ); ?></strong> <?php esc_html_e( 'Skipped', 'penkov-optimizer' ); ?></div>
                <div class="penkov-stat penkov-stat-highlight"><strong id="penkov-remaining"><?php echo esc_html( $remaining ); ?></strong> <?php esc_html_e( 'Remaining', 'penkov-optimizer' ); ?></div>
            </div>

            <!-- Progress Bar -->
            <div class="penkov-progress-wrap" style="display:none;" id="penkov-progress-wrap">
                <div class="penkov-progress-bar">
                    <div class="penkov-progress-fill" id="penkov-progress-fill" style="width:0%"></div>
                </div>
                <div class="penkov-progress-text">
                    <span id="penkov-progress-text">0%</span>
                    <span id="penkov-progress-detail"></span>
                </div>
            </div>

            <!-- Log area -->
            <div class="penkov-bulk-log" id="penkov-bulk-log" style="display:none;">
                <pre id="penkov-bulk-log-content"></pre>
            </div>

            <!-- Actions -->
            <div class="penkov-bulk-actions">
                <button type="button" class="button button-primary button-hero" id="penkov-start-bulk"
                    <?php echo $remaining === 0 ? 'disabled' : ''; ?>>
                    <span class="dashicons dashicons-controls-play"></span>
                    <?php esc_html_e( 'Start Bulk Optimization', 'penkov-optimizer' ); ?>
                </button>
                <button type="button" class="button button-secondary" id="penkov-pause-bulk" style="display:none;">
                    <span class="dashicons dashicons-controls-pause"></span>
                    <?php esc_html_e( 'Pause', 'penkov-optimizer' ); ?>
                </button>
                <button type="button" class="button button-secondary" id="penkov-resume-bulk" style="display:none;">
                    <span class="dashicons dashicons-controls-play"></span>
                    <?php esc_html_e( 'Resume', 'penkov-optimizer' ); ?>
                </button>
            </div>

            <?php if ( $remaining === 0 && $total > 0 ) : ?>
                <div class="penkov-notice penkov-notice-success">
                    <span class="dashicons dashicons-yes-alt"></span>
                    <?php esc_html_e( 'All images are optimized! 🎉', 'penkov-optimizer' ); ?>
                </div>
            <?php endif; ?>
        </div>
        <?php
    }

    /* ─── Advanced tab ────────────────────────────────────── */

    private function render_advanced_tab(): void {
        ?>
        <form method="post" action="options.php">
            <?php settings_fields( 'penkov_opt_settings' ); ?>

            <!-- Lazy Load -->
            <div class="penkov-section">
                <h2><span class="dashicons dashicons-visibility"></span> <?php esc_html_e( 'Lazy Loading', 'penkov-optimizer' ); ?></h2>

                <div class="penkov-field-row">
                    <label>
                        <input type="checkbox" name="<?php echo PENKOV_OPT_PREFIX; ?>lazy_load" value="1" <?php checked( Core::opt( 'lazy_load', 1 ), 1 ); ?>>
                        <?php esc_html_e( 'Enable native lazy loading (loading="lazy")', 'penkov-optimizer' ); ?>
                    </label>
                </div>

                <div class="penkov-field-row">
                    <label>
                        <input type="checkbox" name="<?php echo PENKOV_OPT_PREFIX; ?>lazy_skip_first" value="1" <?php checked( Core::opt( 'lazy_skip_first', 1 ), 1 ); ?>>
                        <?php esc_html_e( 'Skip lazy loading for first image (hero/above fold)', 'penkov-optimizer' ); ?>
                    </label>
                </div>
            </div>

            <!-- LCP Preload -->
            <div class="penkov-section">
                <h2><span class="dashicons dashicons-performance"></span> <?php esc_html_e( 'LCP Preload', 'penkov-optimizer' ); ?></h2>

                <div class="penkov-field-row">
                    <label for="penkov-lcp"><?php esc_html_e( 'LCP Image (Attachment ID or full URL)', 'penkov-optimizer' ); ?></label>
                    <input type="text" id="penkov-lcp" name="<?php echo PENKOV_OPT_PREFIX; ?>lcp_selector"
                           value="<?php echo esc_attr( Core::opt( 'lcp_selector', '' ) ); ?>" class="regular-text"
                           placeholder="e.g. 42 or https://example.com/wp-content/uploads/hero.jpg">
                    <p class="description"><?php esc_html_e( 'Pre-loads the LCP image with <link rel="preload"> for faster rendering.', 'penkov-optimizer' ); ?></p>
                </div>
            </div>

            <!-- Delivery -->
            <div class="penkov-section">
                <h2><span class="dashicons dashicons-admin-site-alt3"></span> <?php esc_html_e( 'Delivery', 'penkov-optimizer' ); ?></h2>

                <div class="penkov-field-row">
                    <label>
                        <input type="checkbox" name="<?php echo PENKOV_OPT_PREFIX; ?>picture_tag" value="1" <?php checked( Core::opt( 'picture_tag', 1 ), 1 ); ?>>
                        <?php esc_html_e( 'Use <picture> tag for WebP/AVIF delivery', 'penkov-optimizer' ); ?>
                    </label>
                </div>

                <div class="penkov-field-row">
                    <label>
                        <input type="checkbox" name="<?php echo PENKOV_OPT_PREFIX; ?>srcset_rewrite" value="1" <?php checked( Core::opt( 'srcset_rewrite', 1 ), 1 ); ?>>
                        <?php esc_html_e( 'Rewrite srcset/sizes for responsive images', 'penkov-optimizer' ); ?>
                    </label>
                </div>

                <div class="penkov-field-row">
                    <label>
                        <input type="checkbox" name="<?php echo PENKOV_OPT_PREFIX; ?>remove_query_strings" value="1" <?php checked( Core::opt( 'remove_query_strings', 0 ), 1 ); ?>>
                        <?php esc_html_e( 'Remove query strings from image URLs', 'penkov-optimizer' ); ?>
                    </label>
                </div>
            </div>

            <!-- CDN -->
            <div class="penkov-section">
                <h2><span class="dashicons dashicons-networking"></span> <?php esc_html_e( 'CDN', 'penkov-optimizer' ); ?></h2>

                <div class="penkov-field-row">
                    <label for="penkov-cdn"><?php esc_html_e( 'CDN Base URL', 'penkov-optimizer' ); ?></label>
                    <input type="url" id="penkov-cdn" name="<?php echo PENKOV_OPT_PREFIX; ?>cdn_url"
                           value="<?php echo esc_attr( Core::opt( 'cdn_url', '' ) ); ?>" class="regular-text"
                           placeholder="https://cdn.example.com">
                    <p class="description"><?php esc_html_e( 'Leave empty to disable. Image URLs will be rewritten to this base.', 'penkov-optimizer' ); ?></p>
                </div>
            </div>

            <!-- Cache Plugin Compatibility -->
            <div class="penkov-section">
                <h2><span class="dashicons dashicons-shield"></span> <?php esc_html_e( 'Cache Plugin Compatibility', 'penkov-optimizer' ); ?></h2>
                <div class="penkov-compat-info">
                    <p><?php esc_html_e( 'Penkov Optimizer is designed to work with popular cache plugins. Notes:', 'penkov-optimizer' ); ?></p>
                    <ul>
                        <li><strong>LiteSpeed Cache:</strong> <?php esc_html_e( 'Works automatically. Clear cache after bulk optimization.', 'penkov-optimizer' ); ?></li>
                        <li><strong>WP Rocket:</strong> <?php esc_html_e( 'Disable WP Rocket\'s WebP feature to avoid conflicts. Clear cache after optimization.', 'penkov-optimizer' ); ?></li>
                        <li><strong>W3 Total Cache:</strong> <?php esc_html_e( 'Enable browser cache and CDN integration in W3TC settings.', 'penkov-optimizer' ); ?></li>
                    </ul>
                    <p class="description"><?php esc_html_e( 'After bulk optimization, always purge your cache plugin\'s cache.', 'penkov-optimizer' ); ?></p>
                </div>
            </div>

            <?php submit_button( __( 'Save Advanced Settings', 'penkov-optimizer' ), 'primary penkov-save-btn' ); ?>
        </form>
        <?php
    }

    /* ─── Logs tab ────────────────────────────────────────── */

    private function render_logs_tab(): void {
        $recent = $this->core->logger->get_recent( 200 );
        ?>
        <div class="penkov-section">
            <h2><span class="dashicons dashicons-list-view"></span> <?php esc_html_e( 'Optimization Log', 'penkov-optimizer' ); ?></h2>

            <?php if ( empty( $recent ) ) : ?>
                <p class="penkov-empty"><?php esc_html_e( 'No log entries yet.', 'penkov-optimizer' ); ?></p>
            <?php else : ?>
                <div class="penkov-log-filters">
                    <button type="button" class="button penkov-log-filter active" data-filter="all">All</button>
                    <button type="button" class="button penkov-log-filter" data-filter="success">Success</button>
                    <button type="button" class="button penkov-log-filter" data-filter="info">Info</button>
                    <button type="button" class="button penkov-log-filter" data-filter="warning">Warning</button>
                    <button type="button" class="button penkov-log-filter" data-filter="error">Error</button>
                </div>

                <table class="penkov-log-table penkov-log-full">
                    <thead>
                        <tr><th width="170">Time</th><th width="90">Level</th><th>Message</th></tr>
                    </thead>
                    <tbody>
                    <?php foreach ( $recent as $entry ) : ?>
                        <tr class="penkov-log-<?php echo esc_attr( $entry['level'] ); ?>" data-level="<?php echo esc_attr( $entry['level'] ); ?>">
                            <td><code><?php echo esc_html( $entry['time'] ); ?></code></td>
                            <td><span class="penkov-badge penkov-badge-<?php echo esc_attr( $entry['level'] ); ?>"><?php echo esc_html( strtoupper( $entry['level'] ) ); ?></span></td>
                            <td><?php echo esc_html( $entry['message'] ); ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
        <?php
    }
}
