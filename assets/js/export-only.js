/**
 * Export Only - JavaScript Handler
 *
 * Handles the AJAX interactions for the Export Only ZIP download feature.
 * Uses batched processing to handle large sites without timeout.
 *
 * @package StaticSiteExporter
 * @since 2.6.0
 */

jQuery(document).ready(function($) {
    'use strict';

    const $startBtn = $('#export-only-start');
    const $downloadBtn = $('#export-only-download');
    const $progress = $('#export-only-progress');
    const $progressBar = $progress.find('.progress-bar');
    const $progressText = $progress.find('.progress-text');
    const $log = $('#export-only-log');
    const $stats = $('#export-only-stats');
    const $baseUrlInput = $('#export-only-base-url');
    const $saveSettingsBtn = $('#export-only-save-settings');
    const $saveStatus = $('#export-only-save-status');
    const $displayBaseUrl = $('#display-base-url');

    // Export state
    let exportState = {
        totalItems: 0,
        totalBatches: 0,
        currentBatch: 0,
        isRunning: false
    };

    /**
     * Log a message to the export log
     */
    function log(message, type) {
        type = type || 'info';
        const timestamp = new Date().toLocaleTimeString('nl-NL');
        const colorClass = type === 'error' ? 'log-error' : type === 'success' ? 'log-success' : 'log-info';
        $log.append('<div class="log-entry ' + colorClass + '">[' + timestamp + '] ' + message + '</div>');
        $log.scrollTop($log[0].scrollHeight);
    }

    /**
     * Update progress bar
     */
    function updateProgress(percent, text) {
        $progress.show();
        $progressBar.css('width', percent + '%');
        $progressText.text(text || percent + '%');
    }

    /**
     * Hide progress bar
     */
    function hideProgress() {
        setTimeout(function() {
            $progress.hide();
            $progressBar.css('width', '0%');
        }, 1000);
    }

    /**
     * Update statistics display
     */
    function updateStats(stats) {
        if (!stats) return;

        $stats.show();
        $('#stat-pages').text(stats.pages || 0);
        $('#stat-posts').text(stats.posts || 0);
        $('#stat-files').text(stats.files || 0);

        // Format size
        const size = stats.size || 0;
        let sizeStr = size + ' B';
        if (size > 1024 * 1024) {
            sizeStr = (size / (1024 * 1024)).toFixed(2) + ' MB';
        } else if (size > 1024) {
            sizeStr = (size / 1024).toFixed(2) + ' KB';
        }
        $('#stat-size').text(sizeStr);
    }

    /**
     * Disable/enable buttons
     */
    function setButtonLoading($btn, loading) {
        if (loading) {
            $btn.prop('disabled', true).addClass('loading');
            $btn.find('.dashicons').addClass('spin');
        } else {
            $btn.prop('disabled', false).removeClass('loading');
            $btn.find('.dashicons').removeClass('spin');
        }
    }

    /**
     * Initialize export - count items and prepare batches
     */
    function initExport() {
        return new Promise(function(resolve, reject) {
            $.ajax({
                url: staticExporter.ajax_url,
                type: 'POST',
                data: {
                    action: 'export_only_init',
                    nonce: staticExporter.nonce
                },
                timeout: 60000,
                success: function(response) {
                    if (response.success) {
                        exportState.totalItems = response.data.total_items;
                        exportState.totalBatches = response.data.total_batches;
                        exportState.currentBatch = 0;

                        log('Export geinitialiseerd: ' + response.data.total_pages + ' pagina\'s, ' +
                            response.data.total_posts + ' posts', 'info');
                        log('Verwerken in ' + response.data.total_batches + ' batches van ' +
                            response.data.batch_size + ' items', 'info');

                        resolve(response.data);
                    } else {
                        reject(response.data || 'Initialisatie mislukt');
                    }
                },
                error: function(xhr, status, error) {
                    reject('Initialisatie mislukt: ' + error);
                }
            });
        });
    }

    /**
     * Process a single batch
     */
    function processBatch(batchNum) {
        return new Promise(function(resolve, reject) {
            $.ajax({
                url: staticExporter.ajax_url,
                type: 'POST',
                data: {
                    action: 'export_only_batch',
                    nonce: staticExporter.nonce,
                    batch: batchNum
                },
                timeout: 120000, // 2 minutes per batch
                success: function(response) {
                    if (response.success) {
                        // Log batch messages
                        if (response.data.log) {
                            response.data.log.forEach(function(msg) {
                                log('  ' + msg, 'info');
                            });
                        }

                        // Update progress
                        const percent = Math.round((response.data.processed / exportState.totalItems) * 80) + 10;
                        updateProgress(percent, 'Batch ' + (batchNum + 1) + '/' + exportState.totalBatches);

                        // Update live stats
                        updateStats(response.data.stats);

                        resolve(response.data);
                    } else {
                        reject(response.data || 'Batch verwerking mislukt');
                    }
                },
                error: function(xhr, status, error) {
                    if (status === 'timeout') {
                        reject('Batch ' + (batchNum + 1) + ' timeout - probeer opnieuw');
                    } else {
                        reject('Batch ' + (batchNum + 1) + ' mislukt: ' + error);
                    }
                }
            });
        });
    }

    /**
     * Process all batches sequentially
     */
    async function processAllBatches() {
        for (let i = 0; i < exportState.totalBatches; i++) {
            if (!exportState.isRunning) {
                throw new Error('Export geannuleerd');
            }

            log('Verwerken batch ' + (i + 1) + '/' + exportState.totalBatches + '...', 'info');

            const result = await processBatch(i);

            if (result.done) {
                break;
            }
        }
    }

    /**
     * Finalize export - generate extra files and create ZIP
     */
    function finalizeExport() {
        return new Promise(function(resolve, reject) {
            $.ajax({
                url: staticExporter.ajax_url,
                type: 'POST',
                data: {
                    action: 'export_only_finalize',
                    nonce: staticExporter.nonce
                },
                timeout: 120000, // 2 minutes for finalization
                success: function(response) {
                    if (response.success) {
                        // Log finalization messages
                        if (response.data.log) {
                            response.data.log.forEach(function(msg) {
                                log(msg, 'info');
                            });
                        }

                        resolve(response.data);
                    } else {
                        reject(response.data.message || 'Finalisatie mislukt');
                    }
                },
                error: function(xhr, status, error) {
                    reject('Finalisatie mislukt: ' + error);
                }
            });
        });
    }

    /**
     * Start export generation (batched)
     */
    $startBtn.on('click', async function() {
        // Clear previous state
        $log.empty();
        $stats.hide();
        $downloadBtn.hide();

        log('Start export generatie...', 'info');
        setButtonLoading($startBtn, true);
        updateProgress(5, 'Initialiseren...');

        exportState.isRunning = true;

        try {
            // Step 1: Initialize
            await initExport();
            updateProgress(10, 'Exporteren...');

            // Step 2: Process all batches
            await processAllBatches();
            updateProgress(90, 'Finaliseren...');

            // Step 3: Finalize
            log('Finaliseren van export...', 'info');
            const finalResult = await finalizeExport();

            // Done!
            updateProgress(100, 'Voltooid!');
            log('Export succesvol gegenereerd!', 'success');

            // Update final stats
            updateStats(finalResult.stats);

            // Show download button
            $downloadBtn.show();

            hideProgress();

        } catch (error) {
            log('Export mislukt: ' + (error.message || error), 'error');
            hideProgress();
        } finally {
            exportState.isRunning = false;
            setButtonLoading($startBtn, false);
        }
    });

    /**
     * Download ZIP file
     */
    $downloadBtn.on('click', function() {
        log('Download starten...', 'info');

        // Create a hidden form to trigger download
        const $form = $('<form>', {
            method: 'POST',
            action: staticExporter.ajax_url
        });

        $form.append($('<input>', {
            type: 'hidden',
            name: 'action',
            value: 'export_only_download'
        }));

        $form.append($('<input>', {
            type: 'hidden',
            name: 'nonce',
            value: staticExporter.nonce
        }));

        $('body').append($form);
        $form.submit();
        $form.remove();

        log('Download gestart. Check je downloads folder.', 'success');

        // Hide download button after download
        setTimeout(function() {
            $downloadBtn.hide();
            $stats.hide();
        }, 2000);
    });

    /**
     * Save settings (Base URL)
     */
    $saveSettingsBtn.on('click', function() {
        const baseUrl = $baseUrlInput.val().trim();

        if (!baseUrl) {
            $saveStatus.text('Base URL is verplicht').css('color', '#d63638').show();
            return;
        }

        // Basic URL validation
        if (!baseUrl.match(/^https?:\/\/.+/)) {
            $saveStatus.text('Voer een geldige URL in (met http:// of https://)').css('color', '#d63638').show();
            return;
        }

        $saveSettingsBtn.prop('disabled', true);
        $saveStatus.text('Opslaan...').css('color', '#666').show();

        $.ajax({
            url: staticExporter.ajax_url,
            type: 'POST',
            data: {
                action: 'export_only_save_settings',
                nonce: staticExporter.nonce,
                base_url: baseUrl
            },
            success: function(response) {
                if (response.success) {
                    $saveStatus.text('✓ Opgeslagen').css('color', '#00a32a').show();
                    // Update the display in the info table
                    $displayBaseUrl.text(response.data.base_url);
                    setTimeout(function() {
                        $saveStatus.fadeOut();
                    }, 3000);
                } else {
                    $saveStatus.text('✗ ' + (response.data || 'Fout bij opslaan')).css('color', '#d63638').show();
                }
            },
            error: function(xhr, status, error) {
                $saveStatus.text('✗ Fout: ' + error).css('color', '#d63638').show();
            },
            complete: function() {
                $saveSettingsBtn.prop('disabled', false);
            }
        });
    });

    // Add CSS for spinning animation
    $('<style>')
        .prop('type', 'text/css')
        .html([
            '@keyframes spin {',
            '    from { transform: rotate(0deg); }',
            '    to { transform: rotate(360deg); }',
            '}',
            '.dashicons.spin {',
            '    animation: spin 1s linear infinite;',
            '}',
            '.button.loading {',
            '    opacity: 0.7;',
            '    cursor: wait;',
            '}'
        ].join('\n'))
        .appendTo('head');
});
