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
                    exportCompleted = true;
                    enableButton($githubBtn);
                    
                    setTimeout(hideProgress, 2000);
                } else {
                    log('✗ Export failed: ' + response.data, 'error');
                    hideProgress();
                }
            },
            error: function(xhr, status, error) {
                log('✗ Export failed: ' + error, 'error');
                hideProgress();
            },
            complete: function() {
                setLoading($exportBtn, false);
            }
        });
    });
    
    // Push to GitHub
    $githubBtn.on('click', function() {
        if (!exportCompleted) {
            log('⚠ Please export the site first', 'error');
            return;
        }
        
        log('Starting GitHub push...', 'info');
        setLoading($githubBtn, true);
        showProgress(20, 'Connecting to GitHub...');
        
        $.ajax({
            url: staticExporter.ajax_url,
            type: 'POST',
            data: {
                action: 'push_to_github',
                nonce: staticExporter.nonce
            },
            xhr: function() {
                const xhr = new window.XMLHttpRequest();
                let lastProgress = 20;
                
                xhr.upload.addEventListener('progress', function(evt) {
                    if (evt.lengthComputable) {
                        const percentComplete = 20 + (evt.loaded / evt.total * 70);
                        if (percentComplete > lastProgress) {
                            lastProgress = percentComplete;
                            showProgress(percentComplete, 'Uploading files...');
                        }
                    }
                }, false);
                
                return xhr;
            },
            success: function(response) {
                showProgress(100, 'Pushed!');
                
                if (response.success) {
                    log('✓ Successfully pushed to GitHub!', 'success');
                    githubPushed = true;
                    enableButton($kinstaBtn);
                    
                    setTimeout(hideProgress, 2000);
                } else {
                    log('✗ GitHub push failed: ' + response.data, 'error');
                    hideProgress();
                }
            },
            error: function(xhr, status, error) {
                log('✗ GitHub push failed: ' + error, 'error');
                hideProgress();
            },
            complete: function() {
                setLoading($githubBtn, false);
            }
        });
    });
    
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
                    log('→ Your site should be live shortly', 'info');
                    
                    setTimeout(hideProgress, 2000);
                } else {
                    log('✗ Kinsta deployment failed: ' + response.data, 'error');
                    hideProgress();
                }
            },
            error: function(xhr, status, error) {
                log('✗ Kinsta deployment failed: ' + error, 'error');
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