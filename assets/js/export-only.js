/**
 * Export Only - JavaScript Handler
 *
 * Handles the AJAX interactions for the Export Only ZIP download feature.
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
     * Start export generation
     */
    $startBtn.on('click', function() {
        // Clear previous state
        $log.empty();
        $stats.hide();
        $downloadBtn.hide();

        log('Start export generatie...', 'info');
        setButtonLoading($startBtn, true);
        updateProgress(10, 'Voorbereiden...');

        $.ajax({
            url: staticExporter.ajax_url,
            type: 'POST',
            data: {
                action: 'export_only_generate',
                nonce: staticExporter.nonce
            },
            timeout: 300000, // 5 minutes
            xhr: function() {
                const xhr = new window.XMLHttpRequest();
                // Progress simulation
                let progress = 10;
                const progressInterval = setInterval(function() {
                    if (progress < 90) {
                        progress += Math.random() * 5;
                        updateProgress(Math.min(progress, 90), 'Exporteren...');
                    }
                }, 1000);

                xhr.onload = function() {
                    clearInterval(progressInterval);
                };

                return xhr;
            },
            success: function(response) {
                updateProgress(100, 'Voltooid!');

                if (response.success) {
                    // Show log messages from server
                    if (response.data.log) {
                        response.data.log.forEach(function(msg) {
                            log(msg, 'info');
                        });
                    }

                    log('Export succesvol gegenereerd!', 'success');

                    // Update stats
                    updateStats(response.data.stats);

                    // Show download button
                    $downloadBtn.show();

                    hideProgress();
                } else {
                    log('Export mislukt: ' + (response.data.message || 'Onbekende fout'), 'error');

                    if (response.data.log) {
                        response.data.log.forEach(function(msg) {
                            log(msg, 'error');
                        });
                    }

                    hideProgress();
                }
            },
            error: function(xhr, status, error) {
                log('Export mislukt: ' + error, 'error');

                if (status === 'timeout') {
                    log('De export duurde te lang. Probeer het opnieuw of neem contact op met de beheerder.', 'error');
                }

                hideProgress();
            },
            complete: function() {
                setButtonLoading($startBtn, false);
            }
        });
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
