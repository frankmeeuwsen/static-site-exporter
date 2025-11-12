jQuery(document).ready(function($) {
    let exportCompleted = false;
    let githubPushed = false;

    const $exportBtn = $('#export-site');
    const $githubBtn = $('#push-github');
    const $kinstaBtn = $('#deploy-kinsta');
    const $log = $('#export-log');
    const $progress = $('#export-progress');
    const $progressBar = $('.progress-bar');

    function log(message, type = 'info') {
        const timestamp = new Date().toLocaleTimeString();
        const colorClass = type === 'error' ? 'log-error' : type === 'success' ? 'log-success' : 'log-info';
        $log.append(`<span class="${colorClass}">[${timestamp}] ${message}</span>\n`);
        $log.scrollTop($log[0].scrollHeight);
    }

    function showProgress(percent, text) {
        $progress.show();
        $progressBar.css('width', percent + '%').text(text || percent + '%');
    }

    function hideProgress() {
        $progress.hide();
        $progressBar.css('width', '0%');
    }

    function enableButton($btn) {
        $btn.prop('disabled', false).removeClass('disabled');
    }

    function disableButton($btn) {
        $btn.prop('disabled', true).addClass('disabled');
    }

    function setLoading($btn, loading) {
        if (loading) {
            $btn.prop('disabled', true)
                .find('.dashicons')
                .addClass('dashicons-update')
                .addClass('spin-animation');
        } else {
            $btn.prop('disabled', false)
                .find('.dashicons')
                .removeClass('dashicons-update')
                .removeClass('spin-animation');
        }
    }

    /**
     * Enhanced error handler (v2.1.1)
     * Provides context-aware error messages and recovery instructions
     */
    function handleError(xhr, status, error, context) {
        const errorDetails = xhr.responseJSON?.data || error;
        const httpCode = xhr.status;

        // Context-specific error messages
        if (status === 'timeout') {
            log(`✗ Request timed out after 60 seconds`, 'error');

            if (context === 'chunk') {
                log('→ This may be due to slow network or large files in this batch', 'info');
                log('→ Click "Push to GitHub" to resume from where it left off', 'info');
            } else {
                log('→ Try again or check your server configuration', 'info');
            }

        } else if (httpCode === 401) {
            log('✗ Authentication failed', 'error');

            if (context === 'github') {
                log('→ Your GitHub token may be invalid or expired', 'error');
                log('→ Please check your token in Settings and save again', 'error');
            } else if (context === 'kinsta') {
                log('→ Your Kinsta API key may be invalid', 'error');
                log('→ Please verify your API key in Settings', 'error');
            }

        } else if (httpCode === 404) {
            log('✗ Resource not found (404)', 'error');

            if (context === 'github') {
                log('→ Repository or branch not found', 'error');
                log('→ Verify repository name and that you have access', 'error');
                log('→ Check that the branch exists', 'info');
            }

        } else if (httpCode === 403) {
            log('✗ Access forbidden (403)', 'error');
            log('→ You may not have permission to access this resource', 'error');

            if (context === 'github') {
                log('→ Check that your GitHub token has "repo" permissions', 'error');
            }

        } else if (httpCode === 429) {
            log('✗ Rate limit exceeded (429)', 'error');
            log('→ GitHub API rate limit reached', 'error');
            log('→ Wait an hour or use a different token', 'info');

        } else if (httpCode >= 500) {
            log('✗ Server error (' + httpCode + ')', 'error');
            log('→ The server encountered an error', 'error');
            log('→ Try again in a few minutes', 'info');

        } else {
            // Generic error
            log('✗ Error: ' + errorDetails, 'error');

            if (context === 'chunk') {
                log('→ You can try clicking "Push to GitHub" again to resume', 'info');
            }
        }
    }

    // Export site
    $exportBtn.on('click', function() {
        $log.empty();
        log('Initializing export process...', 'info');

        setLoading($exportBtn, true);
        showProgress(10, 'Starting...');

        $.ajax({
            url: staticExporter.ajax_url,
            type: 'POST',
            data: {
                action: 'export_static_site',
                nonce: staticExporter.nonce
            },
            success: function(response) {
                showProgress(100, 'Complete!');

                if (response.success) {
                    log('✓ Export completed successfully!', 'success');

                    // Log export stats if available
                    if (response.data && response.data.stats) {
                        const stats = response.data.stats;
                        log('→ Total files exported: ' + stats.total_files, 'info');

                        if (stats.absolute_urls_found > 0) {
                            log('⚠ Warning: Found absolute URLs in ' + stats.absolute_urls_found + ' files', 'error');
                            log('→ URLs may need manual fixing', 'info');
                        }
                    }

                    exportCompleted = true;
                    enableButton($githubBtn);

                    setTimeout(hideProgress, 2000);
                } else {
                    log('✗ Export failed: ' + response.data, 'error');
                    hideProgress();
                }
            },
            error: function(xhr, status, error) {
                handleError(xhr, status, error, 'export');
                hideProgress();
            },
            complete: function() {
                setLoading($exportBtn, false);
            }
        });
    });

    // Push to GitHub - CHUNKED VERSION (v2.1.0+)
    $githubBtn.on('click', function() {
        if (!exportCompleted) {
            log('⚠ Please export the site first', 'error');
            return;
        }

        log('Initializing GitHub push...', 'info');
        setLoading($githubBtn, true);
        showProgress(5, 'Initializing...');

        // Step 1: Initialize the push
        $.ajax({
            url: staticExporter.ajax_url,
            type: 'POST',
            data: {
                action: 'github_push_init',
                nonce: staticExporter.nonce
            },
            success: function(response) {
                if (response.success) {
                    const totalChunks = response.data.total_chunks;
                    const totalFiles = response.data.total_files;
                    const chunkSize = response.data.chunk_size;

                    log('Found ' + totalFiles + ' files to upload', 'info');
                    log('Processing in ' + totalChunks + ' batches of ' + chunkSize + ' files', 'info');

                    // Calculate estimated time
                    const estimatedMinutes = Math.ceil(totalChunks * 20 / 60);
                    log('Estimated time: ~' + estimatedMinutes + ' minutes', 'info');

                    showProgress(10, 'Starting upload...');

                    // Step 2: Upload chunks sequentially
                    uploadChunks(0, totalChunks, totalFiles);
                } else {
                    log('✗ Initialization failed: ' + response.data, 'error');
                    hideProgress();
                    setLoading($githubBtn, false);
                }
            },
            error: function(xhr, status, error) {
                handleError(xhr, status, error, 'github');
                hideProgress();
                setLoading($githubBtn, false);
            }
        });
    });

    // Function to upload chunks recursively (v2.1.1 - enhanced error handling)
    function uploadChunks(currentChunk, totalChunks, totalFiles) {
        if (currentChunk >= totalChunks) {
            // All chunks uploaded, finalize
            finalizeGithubPush();
            return;
        }

        const chunkNum = currentChunk + 1;
        const progress = 10 + ((currentChunk / totalChunks) * 80); // 10-90%

        log('Uploading batch ' + chunkNum + '/' + totalChunks + '...', 'info');
        showProgress(progress, 'Batch ' + chunkNum + '/' + totalChunks);

        $.ajax({
            url: staticExporter.ajax_url,
            type: 'POST',
            data: {
                action: 'github_push_chunk',
                nonce: staticExporter.nonce,
                chunk: currentChunk
            },
            timeout: 60000, // 60 second timeout per chunk
            success: function(response) {
                if (response.success) {
                    const data = response.data;
                    log('✓ Batch ' + chunkNum + ' complete: ' + data.processed_files + '/' + data.total_files + ' files (' + data.progress + '%)', 'success');

                    // Upload next chunk
                    uploadChunks(currentChunk + 1, totalChunks, totalFiles);
                } else {
                    log('✗ Batch ' + chunkNum + ' failed: ' + response.data, 'error');
                    hideProgress();
                    setLoading($githubBtn, false);
                }
            },
            error: function(xhr, status, error) {
                log('✗ Batch ' + chunkNum + ' failed', 'error');
                handleError(xhr, status, error, 'chunk');
                hideProgress();
                setLoading($githubBtn, false);
            }
        });
    }

    // Function to finalize the GitHub push (v2.1.1 - enhanced logging)
    function finalizeGithubPush() {
        log('Finalizing GitHub push...', 'info');
        log('Creating git tree and commit...', 'info');
        showProgress(95, 'Creating commit...');

        $.ajax({
            url: staticExporter.ajax_url,
            type: 'POST',
            data: {
                action: 'github_push_finalize',
                nonce: staticExporter.nonce
            },
            timeout: 60000,
            success: function(response) {
                showProgress(100, 'Complete!');

                if (response.success) {
                    log('✓ Successfully pushed to GitHub!', 'success');
                    log('→ Commit: ' + response.data.commit_sha, 'success');
                    log('→ Files uploaded: ' + response.data.files, 'success');
                    log('→ Check your repository for the new commit', 'info');

                    githubPushed = true;
                    enableButton($kinstaBtn);

                    setTimeout(hideProgress, 2000);
                } else {
                    log('✗ Finalization failed: ' + response.data, 'error');
                    hideProgress();
                }
                setLoading($githubBtn, false);
            },
            error: function(xhr, status, error) {
                handleError(xhr, status, error, 'github');
                hideProgress();
                setLoading($githubBtn, false);
            }
        });
    }

    // Deploy to Kinsta
    $kinstaBtn.on('click', function() {
        if (!githubPushed) {
            log('⚠ Please push to GitHub first', 'error');
            return;
        }

        log('Triggering Kinsta deployment...', 'info');
        setLoading($kinstaBtn, true);
        showProgress(30, 'Connecting to Kinsta...');

        $.ajax({
            url: staticExporter.ajax_url,
            type: 'POST',
            data: {
                action: 'deploy_to_kinsta',
                nonce: staticExporter.nonce
            },
            success: function(response) {
                showProgress(100, 'Deployed!');

                if (response.success) {
                    log('✓ Successfully deployed to Kinsta!', 'success');
                    log('→ Your site should be live in 2-5 minutes', 'info');
                    log('→ Check the Kinsta dashboard for deployment status', 'info');

                    setTimeout(hideProgress, 2000);
                } else {
                    log('✗ Kinsta deployment failed: ' + response.data, 'error');
                    hideProgress();
                }
            },
            error: function(xhr, status, error) {
                handleError(xhr, status, error, 'kinsta');
                hideProgress();
            },
            complete: function() {
                setLoading($kinstaBtn, false);
            }
        });
    });

    // Add CSS for spinning animation
    $('<style>')
        .prop('type', 'text/css')
        .html(`
            @keyframes spin {
                from { transform: rotate(0deg); }
                to { transform: rotate(360deg); }
            }
            .spin-animation {
                animation: spin 1s linear infinite;
            }
        `)
        .appendTo('head');

    // Poll for log updates
    function pollLogs() {
        $.ajax({
            url: staticExporter.ajax_url,
            type: 'POST',
            data: {
                action: 'get_export_log',
                nonce: staticExporter.nonce
            },
            success: function(response) {
                if (response.success && response.data.log) {
                    const lines = response.data.log.split('\n');
                    const lastFive = lines.slice(-5).join('\n');

                    if (lastFive.trim()) {
                        $log.text(response.data.log);
                        $log.scrollTop($log[0].scrollHeight);
                    }
                }
            }
        });
    }

    // Poll every 2 seconds while exporting
    let pollInterval;
    $exportBtn.on('click', function() {
        pollInterval = setInterval(pollLogs, 2000);
    });

    $(document).on('ajaxComplete', function() {
        if (pollInterval) {
            clearInterval(pollInterval);
        }
    });
});
