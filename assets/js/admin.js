/**
 * Penkov WebP/AVIF Optimizer — Admin JavaScript
 *
 * Handles:
 *  - Bulk optimization (start/pause/resume) via AJAX batches
 *  - Progress bar updates
 *  - Quality slider & preset switching
 *  - Delete Originals confirmation modal
 *  - Log filtering
 *  - Single optimize/restore buttons in Media Library
 *
 * @package PenkovStudio\ImageOptimizer
 */

(function ($) {
    'use strict';

    const PO = window.PenkovOpt || {};

    /* ═══════════════════════════════════════════════════════════
       State
       ═══════════════════════════════════════════════════════════ */
    let bulkRunning = false;
    let bulkPaused  = false;
    let totalImages = 0;
    let processedCount = 0;

    /* ═══════════════════════════════════════════════════════════
       Quality Slider & Presets
       ═══════════════════════════════════════════════════════════ */
    $(document).on('input', '#penkov-quality', function () {
        $('.penkov-quality-val').text(this.value);
        // Deactivate preset buttons when manual.
        $('.penkov-preset-btn').removeClass('active');
    });

    $(document).on('click', '.penkov-preset-btn', function () {
        const quality = $(this).data('quality');
        const preset  = $(this).data('preset');
        $('#penkov-quality').val(quality).trigger('input');
        $('.penkov-preset-btn').removeClass('active');
        $(this).addClass('active');
        $('#penkov-preset-input').val(preset);
        $('.penkov-quality-val').text(quality);
    });

    /* ═══════════════════════════════════════════════════════════
       Toggle Group (Format radio)
       ═══════════════════════════════════════════════════════════ */
    $(document).on('change', '.penkov-toggle-btn input', function () {
        $(this).closest('.penkov-toggle-group').find('.penkov-toggle-btn').removeClass('active');
        $(this).closest('.penkov-toggle-btn').addClass('active');
    });

    /* ═══════════════════════════════════════════════════════════
       Delete Originals — 2-Step Confirmation + Modal
       ═══════════════════════════════════════════════════════════ */
    $(document).on('change', '#penkov-delete-originals', function () {
        if (this.checked) {
            // Show the "I understand" checkbox.
            $('#penkov-danger-confirm-wrap').slideDown();
            // Prevent form submit until confirmed.
            this.checked = false;
            showDeleteModal();
        } else {
            $('#penkov-danger-confirm-wrap').slideUp();
            $('#penkov-understand-risk').prop('checked', false);
        }
    });

    function showDeleteModal() {
        const overlay = $('<div class="penkov-modal-overlay">');
        const modal = $(`
            <div class="penkov-modal">
                <h3><span class="dashicons dashicons-warning"></span> Confirm: Delete Originals</h3>
                <p>You are about to enable <strong>"Delete original files"</strong> after WebP/AVIF conversion.</p>
                <p><strong>This action is destructive.</strong> If backups are disabled or expire, original files cannot be recovered.</p>
                <p>Make sure you have:</p>
                <ul style="margin:8px 0 0 20px;font-size:13px;">
                    <li>✅ Enabled "Keep backups" in settings</li>
                    <li>✅ Tested conversion on a few images first</li>
                    <li>✅ A full site backup (recommended)</li>
                </ul>
                <div class="penkov-modal-actions">
                    <button type="button" class="button" id="penkov-modal-cancel">Cancel</button>
                    <button type="button" class="button button-primary" id="penkov-modal-confirm" style="background:#d63638;border-color:#d63638;">Yes, Enable</button>
                </div>
            </div>
        `);

        overlay.append(modal);
        $('body').append(overlay);

        overlay.on('click', '#penkov-modal-cancel', function () {
            overlay.remove();
        });

        overlay.on('click', '#penkov-modal-confirm', function () {
            $('#penkov-delete-originals').prop('checked', true);
            $('#penkov-danger-confirm-wrap').slideDown();
            overlay.remove();
        });

        // Close on overlay click.
        overlay.on('click', function (e) {
            if (e.target === overlay[0]) {
                overlay.remove();
            }
        });
    }

    /* ═══════════════════════════════════════════════════════════
       Bulk Optimization
       ═══════════════════════════════════════════════════════════ */
    $(document).on('click', '#penkov-start-bulk', function () {
        if (bulkRunning) return;

        bulkRunning  = true;
        bulkPaused   = false;
        processedCount = 0;

        // UI updates.
        $(this).hide();
        $('#penkov-pause-bulk').show();
        $('#penkov-resume-bulk').hide();
        $('#penkov-progress-wrap').slideDown();
        $('#penkov-bulk-log').slideDown();
        $('#penkov-bulk-log-content').empty();

        // Get initial status.
        $.post(PO.ajax_url, {
            action: 'penkov_opt_bulk_status',
            nonce: PO.nonce
        }, function (res) {
            if (res.success) {
                totalImages = res.data.total;
                processedCount = res.data.processed;
                updateBulkStats(res.data);
                updateProgress();
                runBatch();
            }
        });
    });

    $(document).on('click', '#penkov-pause-bulk', function () {
        bulkPaused = true;
        $(this).hide();
        $('#penkov-resume-bulk').show();
        logBulk('⏸ ' + PO.strings.paused);
    });

    $(document).on('click', '#penkov-resume-bulk', function () {
        bulkPaused = false;
        $(this).hide();
        $('#penkov-pause-bulk').show();
        logBulk('▶ Resuming...');
        runBatch();
    });

    function runBatch() {
        if (!bulkRunning || bulkPaused) return;

        $.post(PO.ajax_url, {
            action: 'penkov_opt_bulk_process',
            nonce: PO.nonce
        }, function (res) {
            if (!res.success) {
                logBulk('❌ ' + PO.strings.error);
                bulkRunning = false;
                resetBulkUI();
                return;
            }

            const data = res.data;

            // Log results.
            if (data.results && data.results.length) {
                data.results.forEach(function (r) {
                    if (r.success) {
                        logBulk('✅ #' + r.id + ' — saved ' + formatBytes(r.savings));
                    } else {
                        logBulk('⚠️ #' + r.id + ' — ' + (r.error || 'skipped'));
                    }
                });
            }

            if (typeof data.processed_total !== 'undefined') {
                processedCount = data.processed_total;
                totalImages = data.total || totalImages;
                updateBulkStats({
                    total: data.total,
                    processed: data.processed_total,
                    optimized: data.optimized_total,
                    skipped: data.skipped_total,
                    remaining: data.remaining
                });
            } else {
                // Backwards compatibility.
                processedCount += (data.processed || 0);
            }
            updateProgress();

            if (data.done) {
                logBulk('🎉 ' + PO.strings.done);
                bulkRunning = false;
                resetBulkUI();
            } else {
                // Continue next batch.
                setTimeout(runBatch, 200);
            }
        }).fail(function () {
            logBulk('❌ ' + PO.strings.error);
            bulkRunning = false;
            resetBulkUI();
        });
    }

    function updateProgress() {
        if (totalImages === 0) return;
        const pct = Math.min(100, Math.round((processedCount / totalImages) * 100));
        $('#penkov-progress-fill').css('width', pct + '%');
        $('#penkov-progress-text').text(pct + '%');
        $('#penkov-progress-detail').text(processedCount + ' / ' + totalImages);
        $('#penkov-remaining').text(Math.max(0, totalImages - processedCount));
    }

    function updateBulkStats(data) {
        if (!data) return;
        if (typeof data.total !== 'undefined') $('#penkov-total').text(data.total);
        if (typeof data.processed !== 'undefined') $('#penkov-processed').text(data.processed);
        if (typeof data.optimized !== 'undefined') $('#penkov-optimized').text(data.optimized);
        if (typeof data.skipped !== 'undefined') $('#penkov-skipped').text(data.skipped);
        if (typeof data.remaining !== 'undefined') $('#penkov-remaining').text(data.remaining);
    }

    function resetBulkUI() {
        $('#penkov-pause-bulk, #penkov-resume-bulk').hide();
        $('#penkov-start-bulk').show().prop('disabled', processedCount >= totalImages);
    }

    function logBulk(msg) {
        const $log = $('#penkov-bulk-log-content');
        const time = new Date().toLocaleTimeString();
        $log.append('[' + time + '] ' + msg + '\n');
        // Auto-scroll.
        const container = $('#penkov-bulk-log')[0];
        if (container) container.scrollTop = container.scrollHeight;
    }

    /* ═══════════════════════════════════════════════════════════
       Log Filters
       ═══════════════════════════════════════════════════════════ */
    $(document).on('click', '.penkov-log-filter', function () {
        const filter = $(this).data('filter');
        $('.penkov-log-filter').removeClass('active');
        $(this).addClass('active');

        if (filter === 'all') {
            $('.penkov-log-full tbody tr').show();
        } else {
            $('.penkov-log-full tbody tr').hide();
            $('.penkov-log-full tbody tr[data-level="' + filter + '"]').show();
        }
    });

    /* ═══════════════════════════════════════════════════════════
       Single Optimize / Restore (Media Library)
       ═══════════════════════════════════════════════════════════ */
    $(document).on('click', '.penkov-opt-single', function () {
        const $btn = $(this);
        const id   = $btn.data('id');
        $btn.prop('disabled', true).text('…');

        $.post(PO.ajax_url, {
            action: 'penkov_opt_single_optimize',
            nonce: PO.nonce,
            attachment_id: id
        }, function (res) {
            $btn.prop('disabled', false);
            if (res.success) {
                $btn.text('✓');
                setTimeout(function () { location.reload(); }, 800);
            } else {
                $btn.text('✗');
            }
        }).fail(function () {
            $btn.prop('disabled', false).text('✗');
        });
    });

    $(document).on('click', '.penkov-restore-single', function () {
        const $btn = $(this);
        const id   = $btn.data('id');

        if (!confirm('Restore original file for this image?')) return;

        $btn.prop('disabled', true).text('…');

        $.post(PO.ajax_url, {
            action: 'penkov_opt_single_restore',
            nonce: PO.nonce,
            attachment_id: id
        }, function (res) {
            $btn.prop('disabled', false);
            if (res.success) {
                $btn.text('✓');
                setTimeout(function () { location.reload(); }, 800);
            } else {
                $btn.text('✗');
            }
        }).fail(function () {
            $btn.prop('disabled', false).text('✗');
        });
    });

    /* ═══════════════════════════════════════════════════════════
       Helpers
       ═══════════════════════════════════════════════════════════ */
    function formatBytes(bytes) {
        if (bytes === 0) return '0 B';
        const k = 1024;
        const sizes = ['B', 'KB', 'MB', 'GB'];
        const i = Math.floor(Math.log(bytes) / Math.log(k));
        return parseFloat((bytes / Math.pow(k, i)).toFixed(1)) + ' ' + sizes[i];
    }

})(jQuery);
