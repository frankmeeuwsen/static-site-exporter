<?php
/**
 * Plugin Name: Static Site Exporter to Kinsta
 * Plugin URI: https://example.com
 * Description: Export WordPress site to static HTML and deploy to GitHub/Kinsta Static Hosting
 * Version: 2.2.0
 * Author: Monique Dubbelman
 * License: GPL v2 or later
 *
 * Changelog:
 * 2.2.0 - MAJOR FIX: Comprehensive URL conversion for CSS/JS/images
 *       - Improved make_urls_relative() to handle escaped and encoded URLs
 *       - Fixes JavaScript-escaped URLs (http:\/\/)
 *       - Fixes URL-encoded URLs (http%3A%2F%2F)
 *       - Fixes broken CSS, images, and responsive viewport issues
 * 2.1.1 - CRITICAL FIX: Exclude Simply Static temp files and debug logs from export
 *       - Added recursive_copy_with_exclusions() method
 *       - Prevents 355MB debug file from being exported
 *       - Reduces export size from 473MB to ~118MB
 *       - Excludes: simply-static/, static-export/, static-export-temp/
 * 2.1.0 - Fixed AJAX timeout issues for GitHub push with large sites
 *       - Implemented chunked AJAX processing for GitHub uploads
 *       - Added resumable progress tracking with transients
 *       - Processes files in batches of 10-20 per AJAX call
 *       - Real-time progress updates in admin interface
 * 2.0.1 - Fixed memory exhaustion issues for large sites (473MB+)
 *       - Improved file scanning with memory-efficient methods
 *       - Added automatic memory limit increase to 512MB
 *       - Added periodic garbage collection during uploads
 */

if (!defined('ABSPATH')) exit;

class WP_Static_Exporter {
    // Configuration constants
    const BATCH_SIZE = 50;              // Files per GitHub batch (LEGACY - kept for backward compatibility)
    const CHUNK_SIZE = 15;              // Files per AJAX chunk (new chunked processing)
    const MAX_FILE_SIZE = 10485760;     // 10MB in bytes
    const MAX_EXPORT_SIZE = 524288000;  // 500MB in bytes
    const GITHUB_TREE_LIMIT = 5000;     // GitHub tree item limit
    const API_TIMEOUT = 60;             // API timeout in seconds
    const PAGE_TIMEOUT = 30;            // Page fetch timeout
    const BATCH_DELAY = 1;              // Seconds between batches (reduced from 2)
    const MAX_RETRIES = 3;              // API retry attempts
    const LOG_MAX_SIZE = 102400;        // 100KB max log size
    const DEBOUNCE_TIME = 120;          // 2 minutes
    const SCHEDULE_DELAY = 30;          // 30 seconds
    const CHUNK_TIMEOUT = 45;           // Timeout for chunked AJAX requests (seconds)

    private $export_dir;
    private $temp_dir;
    private $encryption_key;

    public function __construct() {
        $this->export_dir = WP_CONTENT_DIR . '/static-export';
        $this->temp_dir = WP_CONTENT_DIR . '/static-export-temp';
        $this->encryption_key = $this->get_encryption_key();

        add_action('admin_menu', array($this, 'add_admin_menu'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_scripts'));
        add_action('wp_ajax_export_static_site', array($this, 'ajax_export_static_site'));
        add_action('wp_ajax_push_to_github', array($this, 'ajax_push_to_github'));
        add_action('wp_ajax_deploy_to_kinsta', array($this, 'ajax_deploy_to_kinsta'));
        add_action('wp_ajax_get_export_log', array($this, 'ajax_get_export_log'));

        // New chunked GitHub push endpoints
        add_action('wp_ajax_github_push_init', array($this, 'ajax_github_push_init'));
        add_action('wp_ajax_github_push_chunk', array($this, 'ajax_github_push_chunk'));
        add_action('wp_ajax_github_push_finalize', array($this, 'ajax_github_push_finalize'));
        add_action('wp_ajax_github_push_status', array($this, 'ajax_github_push_status'));

        // Auto-export triggers
        $this->setup_auto_export_hooks();
    }

    /**
     * Get encryption key for securing credentials
     */
    private function get_encryption_key() {
        // Use WordPress security keys for encryption
        if (defined('AUTH_KEY') && AUTH_KEY) {
            return substr(AUTH_KEY, 0, 32);
        }
        return 'static-exporter-default-key-32';
    }

    /**
     * Encrypt sensitive data
     */
    public function encrypt($data) {
        if (empty($data)) return '';

        $iv = random_bytes(16);  // Use random_bytes() instead of deprecated openssl_random_pseudo_bytes()
        $encrypted = openssl_encrypt($data, 'AES-256-CBC', $this->encryption_key, 0, $iv);
        return base64_encode($iv . $encrypted);
    }

    /**
     * Decrypt sensitive data
     */
    public function decrypt($data) {
        if (empty($data)) return '';

        $data = base64_decode($data);
        $iv = substr($data, 0, 16);
        $encrypted = substr($data, 16);
        return openssl_decrypt($encrypted, 'AES-256-CBC', $this->encryption_key, 0, $iv);
    }

    private function setup_auto_export_hooks() {
        $options = get_option('static_exporter_settings', array());
        $auto_export = $options['auto_export'] ?? false;

        if (!$auto_export) return;

        // Post/Page save
        add_action('save_post', array($this, 'trigger_auto_export'), 10, 3);

        // Post/Page delete
        add_action('before_delete_post', array($this, 'trigger_auto_export_simple'));

        // Theme/Plugin changes
        add_action('switch_theme', array($this, 'trigger_auto_export_simple'));
        add_action('activated_plugin', array($this, 'trigger_auto_export_simple'));
        add_action('deactivated_plugin', array($this, 'trigger_auto_export_simple'));

        // Menu changes
        add_action('wp_update_nav_menu', array($this, 'trigger_auto_export_simple'));

        // Widget changes
        add_action('update_option_sidebars_widgets', array($this, 'trigger_auto_export_simple'));

        // Media changes
        add_action('add_attachment', array($this, 'trigger_auto_export_simple'));
        add_action('delete_attachment', array($this, 'trigger_auto_export_simple'));

        // Comment changes
        add_action('comment_post', array($this, 'trigger_auto_export_simple'));
        add_action('deleted_comment', array($this, 'trigger_auto_export_simple'));
    }

    public function trigger_auto_export($post_id, $post, $update) {
        // Avoid auto-saves and revisions
        if (wp_is_post_autosave($post_id) || wp_is_post_revision($post_id)) {
            return;
        }

        // Only trigger for published posts/pages
        if ($post->post_status !== 'publish') {
            return;
        }

        $this->schedule_export();
    }

    public function trigger_auto_export_simple() {
        $this->schedule_export();
    }

    private function schedule_export() {
        // Debounce: only schedule if not already scheduled
        $last_scheduled = get_transient('static_export_scheduled');
        if ($last_scheduled) {
            return;
        }

        // Set transient to prevent multiple rapid exports (matches schedule delay)
        set_transient('static_export_scheduled', true, self::SCHEDULE_DELAY + 5);

        // Schedule single event in 30 seconds (gives time for multiple changes)
        if (!wp_next_scheduled('static_exporter_auto_export')) {
            wp_schedule_single_event(time() + self::SCHEDULE_DELAY, 'static_exporter_auto_export');
        }
    }

    public function perform_auto_export() {
        $this->log('Auto-export triggered by content change');

        try {
            $this->do_export();

            $options = get_option('static_exporter_settings', array());
            $auto_deploy = $options['auto_deploy'] ?? false;

            if ($auto_deploy) {
                $this->log('Auto-deploy enabled, pushing to GitHub...');

                $token = $this->decrypt($options['github_token_encrypted'] ?? '');
                $this->push_to_github_api(
                    $token,
                    $options['github_repo'],
                    $options['github_branch'] ?? 'main'
                );

                $this->log('Deploying to Kinsta...');
                $api_key = $this->decrypt($options['kinsta_api_key_encrypted'] ?? '');
                $this->deploy_to_kinsta_api(
                    $api_key,
                    $options['kinsta_site_id']
                );
            }

            $this->log('Auto-export completed successfully');

        } catch (Exception $e) {
            $this->log('Auto-export error: ' . $e->getMessage());
        }

        delete_transient('static_export_scheduled');
    }

    public function add_admin_menu() {
        add_menu_page(
            'Static Site Exporter',
            'Static Exporter',
            'manage_options',
            'static-site-exporter',
            array($this, 'admin_page'),
            'dashicons-upload',
            80
        );
    }

    public function enqueue_scripts($hook) {
        if ($hook !== 'toplevel_page_static-site-exporter') return;

        wp_enqueue_style('static-exporter-css', plugin_dir_url(__FILE__) . 'assets/style.css');
        wp_enqueue_script('static-exporter-js', plugin_dir_url(__FILE__) . 'assets/script.js', array('jquery'), '2.0', true);
        wp_localize_script('static-exporter-js', 'staticExporter', array(
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('static_exporter_nonce')
        ));
    }

    public function admin_page() {
        $options = get_option('static_exporter_settings', array());

        // Get decrypted values for display validation (not shown in fields)
        $github_token = $this->decrypt($options['github_token_encrypted'] ?? '');
        $kinsta_api_key = $this->decrypt($options['kinsta_api_key_encrypted'] ?? '');
        ?>
        <div class="wrap">
            <h1>Static Site Exporter to Kinsta</h1>

            <?php
            // Display configuration status
            if (isset($_GET['settings-updated'])) {
                echo '<div class="notice notice-success"><p>Settings saved successfully!</p></div>';
            }

            $auto_export = $options['auto_export'] ?? false;
            $auto_deploy = $options['auto_deploy'] ?? false;

            if ($auto_export): ?>
            <div class="notice notice-success">
                <p>
                    <strong>✓ Auto-Export is ENABLED</strong> - Your site will automatically export on content changes.
                    <?php if ($auto_deploy): ?>
                    <br>✓ Auto-Deploy is also enabled - Changes will be pushed to GitHub and Kinsta automatically.
                    <?php endif; ?>
                </p>
            </div>
            <?php endif; ?>

            <div class="static-exporter-container">
                <div class="exporter-section">
                    <h2>GitHub Settings</h2>
                    <form method="post" action="options.php">
                        <?php
                        settings_fields('static_exporter_settings');
                        ?>
                        <table class="form-table">
                            <tr>
                                <th><label for="github_token">GitHub Personal Access Token</label></th>
                                <td>
                                    <input type="password" id="github_token" name="static_exporter_settings[github_token]"
                                           value="" class="regular-text" placeholder="<?php echo !empty($github_token) ? '••••••••••••••••' : 'Enter your GitHub token'; ?>">
                                    <p class="description">
                                        Create token at: GitHub Settings → Developer settings → Personal access tokens<br>
                                        <?php if (!empty($github_token)): ?>
                                        <span style="color: green;">✓ Token is configured</span>
                                        <?php else: ?>
                                        <span style="color: red;">⚠ Token not configured</span>
                                        <?php endif; ?>
                                    </p>
                                </td>
                            </tr>
                            <tr>
                                <th><label for="github_repo">GitHub Repository</label></th>
                                <td>
                                    <input type="text" id="github_repo" name="static_exporter_settings[github_repo]"
                                           value="<?php echo esc_attr($options['github_repo'] ?? ''); ?>" class="regular-text"
                                           placeholder="username/repository-name" required>
                                    <p class="description">Format: username/repository-name</p>
                                </td>
                            </tr>
                            <tr>
                                <th><label for="github_branch">Branch</label></th>
                                <td>
                                    <input type="text" id="github_branch" name="static_exporter_settings[github_branch]"
                                           value="<?php echo esc_attr($options['github_branch'] ?? 'main'); ?>" class="regular-text">
                                </td>
                            </tr>
                        </table>

                        <h2>Kinsta Settings</h2>
                        <table class="form-table">
                            <tr>
                                <th><label for="kinsta_api_key">Kinsta API Key</label></th>
                                <td>
                                    <input type="password" id="kinsta_api_key" name="static_exporter_settings[kinsta_api_key]"
                                           value="" class="regular-text" placeholder="<?php echo !empty($kinsta_api_key) ? '••••••••••••••••' : 'Enter your Kinsta API key'; ?>">
                                    <p class="description">
                                        <?php if (!empty($kinsta_api_key)): ?>
                                        <span style="color: green;">✓ API Key is configured</span>
                                        <?php else: ?>
                                        <span style="color: red;">⚠ API Key not configured</span>
                                        <?php endif; ?>
                                    </p>
                                </td>
                            </tr>
                            <tr>
                                <th><label for="kinsta_site_id">Kinsta Site ID</label></th>
                                <td>
                                    <input type="text" id="kinsta_site_id" name="static_exporter_settings[kinsta_site_id]"
                                           value="<?php echo esc_attr($options['kinsta_site_id'] ?? ''); ?>" class="regular-text">
                                </td>
                            </tr>
                        </table>

                        <h2>Automation Settings</h2>
                        <table class="form-table">
                            <tr>
                                <th><label for="auto_export">Auto-Export on Changes</label></th>
                                <td>
                                    <label>
                                        <input type="checkbox" id="auto_export" name="static_exporter_settings[auto_export]"
                                               value="1" <?php checked($options['auto_export'] ?? false, 1); ?>>
                                        Automatically export site when content changes
                                    </label>
                                    <p class="description">Triggers export when posts, pages, menus, widgets, or media are updated</p>
                                </td>
                            </tr>
                            <tr>
                                <th><label for="auto_deploy">Auto-Deploy to Kinsta</label></th>
                                <td>
                                    <label>
                                        <input type="checkbox" id="auto_deploy" name="static_exporter_settings[auto_deploy]"
                                               value="1" <?php checked($options['auto_deploy'] ?? false, 1); ?>>
                                        Automatically push to GitHub and deploy to Kinsta after export
                                    </label>
                                    <p class="description">⚠️ Requires GitHub and Kinsta settings to be configured</p>
                                </td>
                            </tr>
                        </table>

                        <?php submit_button('Save Settings'); ?>
                    </form>
                </div>

                <div class="exporter-section">
                    <h2>Export & Deploy</h2>

                    <div class="export-actions">
                        <button id="export-site" class="button button-primary button-large">
                            <span class="dashicons dashicons-download"></span> Export Static Site
                        </button>

                        <button id="push-github" class="button button-secondary button-large" disabled>
                            <span class="dashicons dashicons-upload"></span> Push to GitHub
                        </button>

                        <button id="deploy-kinsta" class="button button-secondary button-large" disabled>
                            <span class="dashicons dashicons-cloud-upload"></span> Deploy to Kinsta
                        </button>
                    </div>

                    <div id="export-log" class="export-log"></div>
                    <div id="export-progress" class="export-progress" style="display:none;">
                        <div class="progress-bar"></div>
                    </div>
                </div>
            </div>
        </div>
        <?php
    }

    /**
     * AJAX handler for getting export log
     */
    public function ajax_get_export_log() {
        check_ajax_referer('static_exporter_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized');
        }

        $log = get_option('static_exporter_log', '');
        wp_send_json_success(array('log' => $log));
    }

    public function ajax_export_static_site() {
        check_ajax_referer('static_exporter_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized');
        }

        try {
            // Pre-flight checks
            $this->check_export_feasibility();

            $this->do_export();

            // Post-export validation
            $validation = $this->validate_export();
            if (!$validation['success']) {
                throw new Exception('Export validation failed: ' . implode(', ', $validation['errors']));
            }

            wp_send_json_success(array(
                'message' => 'Export completed',
                'stats' => $validation['stats']
            ));
        } catch (Exception $e) {
            $this->log('Error: ' . $e->getMessage());
            wp_send_json_error($e->getMessage());
        }
    }

    /**
     * Pre-flight checks before export
     */
    private function check_export_feasibility() {
        $this->log('Running pre-flight checks...');

        // Check disk space
        $available_space = @disk_free_space($this->export_dir);
        if ($available_space === false) {
            // If we can't check, proceed with warning
            $this->log('Warning: Could not check available disk space');
        } else if ($available_space < self::MAX_EXPORT_SIZE) {
            throw new Exception(sprintf(
                'Insufficient disk space. Available: %sMB, Required: ~%sMB',
                round($available_space / 1024 / 1024),
                round(self::MAX_EXPORT_SIZE / 1024 / 1024)
            ));
        }

        // Estimate total content size
        $uploads_dir = wp_upload_dir();
        $uploads_size = $this->get_directory_size($uploads_dir['basedir']);
        $theme_size = $this->get_directory_size(get_stylesheet_directory());
        $estimated_size = $uploads_size + $theme_size + (5 * 1024 * 1024); // +5MB for HTML

        $this->log(sprintf(
            'Estimated export size: %sMB (uploads: %sMB, theme: %sMB)',
            round($estimated_size / 1024 / 1024, 2),
            round($uploads_size / 1024 / 1024, 2),
            round($theme_size / 1024 / 1024, 2)
        ));

        if ($estimated_size > self::MAX_EXPORT_SIZE) {
            throw new Exception(sprintf(
                'Site is too large to export. Size: %sMB, Limit: %sMB',
                round($estimated_size / 1024 / 1024),
                round(self::MAX_EXPORT_SIZE / 1024 / 1024)
            ));
        }

        $this->log('✓ Pre-flight checks passed');
    }

    /**
     * Get directory size in bytes
     * Uses disk_usage command for efficiency to avoid memory issues with large directories
     */
    private function get_directory_size($path) {
        if (!is_dir($path)) return 0;

        // Try using system command first (more memory efficient)
        if (function_exists('exec')) {
            $output = array();
            $return_var = 0;
            @exec('du -sk ' . escapeshellarg($path) . ' 2>/dev/null', $output, $return_var);

            if ($return_var === 0 && !empty($output[0])) {
                // du -sk returns size in kilobytes
                $size_kb = (int) $output[0];
                return $size_kb * 1024; // Convert to bytes
            }
        }

        // Fallback to PHP method with memory optimization
        $size = 0;
        try {
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($path, RecursiveDirectoryIterator::SKIP_DOTS),
                RecursiveIteratorIterator::LEAVES_ONLY
            );

            foreach ($iterator as $file) {
                if ($file->isFile()) {
                    $size += $file->getSize();
                }

                // Prevent memory exhaustion - if we're checking a very large directory,
                // stop counting after a reasonable threshold and return estimate
                if ($size > self::MAX_EXPORT_SIZE * 2) {
                    $this->log('Warning: Directory is very large, size check stopped early');
                    return $size;
                }
            }
        } catch (Exception $e) {
            $this->log('Warning: Could not calculate size for ' . $path . ': ' . $e->getMessage());
            // Return 0 so pre-flight check doesn't fail, just warns
            return 0;
        }

        return $size;
    }

    /**
     * Validate export completeness
     */
    private function validate_export() {
        $this->log('Validating export...');

        $errors = array();
        $warnings = array();
        $stats = array();

        // Count exported files
        $files = $this->get_all_files($this->export_dir);
        $stats['total_files'] = count($files);

        // Check if index.html exists
        if (!file_exists($this->export_dir . '/index.html')) {
            $errors[] = 'Homepage (index.html) not found';
        }

        // Check for absolute URLs in HTML files (WARNING, not error)
        $absolute_url_count = 0;
        $html_files_checked = 0;
        $site_url = get_site_url();

        foreach ($files as $file) {
            if (substr($file, -5) === '.html') {
                $html_files_checked++;
                $content = file_get_contents($file);
                if (strpos($content, $site_url) !== false) {
                    $absolute_url_count++;
                }

                // Only check first 10 HTML files to save time
                if ($html_files_checked >= 10) break;
            }
        }

        if ($absolute_url_count > 0) {
            $warnings[] = sprintf(
                'Found absolute URLs in %d/%d HTML files checked',
                $absolute_url_count,
                $html_files_checked
            );
        }

        // Check for essential directories
        if (!is_dir($this->export_dir . '/wp-content/uploads')) {
            $warnings[] = 'Uploads directory not found (site may not have uploads)';
        }

        if (!is_dir($this->export_dir . '/wp-content/themes')) {
            $errors[] = 'Theme directory not found';
        }

        // Check file count against GitHub limit (WARNING at this stage)
        if ($stats['total_files'] >= self::GITHUB_TREE_LIMIT) {
            $warnings[] = sprintf(
                'Site has %d files, which meets or exceeds GitHub limit of %d. GitHub push will fail.',
                $stats['total_files'],
                self::GITHUB_TREE_LIMIT
            );
        }

        $stats['html_files_checked'] = $html_files_checked;
        $stats['absolute_urls_found'] = $absolute_url_count;

        // Log warnings if any
        if (!empty($warnings)) {
            foreach ($warnings as $warning) {
                $this->log('⚠ Warning: ' . $warning);
            }
        }

        if (empty($errors)) {
            $this->log('✓ Export validation passed' . (empty($warnings) ? '' : ' (with warnings)'));
            return array('success' => true, 'warnings' => $warnings, 'stats' => $stats);
        } else {
            $this->log('✗ Export validation failed: ' . implode('; ', $errors));
            return array('success' => false, 'errors' => $errors, 'warnings' => $warnings, 'stats' => $stats);
        }
    }

    private function do_export() {
        // Check for concurrent exports using transient lock
        $lock = get_transient('static_export_in_progress');
        if ($lock) {
            throw new Exception('An export is already in progress. Please wait for it to complete.');
        }

        // Set lock
        set_transient('static_export_in_progress', true, 3600); // 1 hour max

        try {
            $this->log('Starting static site export...');

            // Clean and create directories
            $this->clean_directory($this->export_dir);
            $this->clean_directory($this->temp_dir);

            wp_mkdir_p($this->export_dir);
            wp_mkdir_p($this->temp_dir);

            // Close session to prevent blocking when fetching pages
            // This allows wp_remote_get() to fetch pages from the same site
            if (session_id()) {
                session_write_close();
            }

            // Export homepage
            $this->export_page(home_url('/'), 'index.html');

            // Export all pages
            $pages = get_pages();
            $this->log('Exporting ' . count($pages) . ' pages...');
            foreach ($pages as $page) {
                $url = get_permalink($page->ID);
                $path = parse_url($url, PHP_URL_PATH);
                $filename = trim($path, '/') . '/index.html';
                $this->export_page($url, $filename);
            }

            // Export all posts (paginated to avoid memory issues)
            $posts_per_page = 100;
            $offset = 0;
            $total_posts = 0;

            while (true) {
                $posts = get_posts(array(
                    'numberposts' => $posts_per_page,
                    'offset' => $offset,
                    'orderby' => 'ID',
                    'order' => 'ASC'
                ));

                if (empty($posts)) {
                    break;
                }

                $total_posts += count($posts);
                $this->log('Exporting posts ' . ($offset + 1) . '-' . ($offset + count($posts)) . '...');

                foreach ($posts as $post) {
                    $url = get_permalink($post->ID);
                    $path = parse_url($url, PHP_URL_PATH);
                    $filename = trim($path, '/') . '/index.html';
                    $this->export_page($url, $filename);
                }

                $offset += $posts_per_page;

                // Break if we got fewer posts than requested (end of list)
                if (count($posts) < $posts_per_page) {
                    break;
                }
            }

            $this->log('Exported ' . $total_posts . ' posts total');

            // Copy assets
            $this->copy_assets();

            // Create config files
            $this->create_config_files();

            $this->log('Export completed successfully!');

        } finally {
            // Always release lock, even if export fails
            delete_transient('static_export_in_progress');
        }
    }

    private function export_page($url, $filename) {
        $this->log("Exporting: $url");

        $response = wp_remote_get($url, array(
            'timeout' => self::PAGE_TIMEOUT,
            'sslverify' => true  // SECURITY FIX: Enable SSL verification
        ));

        if (is_wp_error($response)) {
            throw new Exception("Failed to fetch $url: " . $response->get_error_message());
        }

        $code = wp_remote_retrieve_response_code($response);
        if ($code !== 200) {
            throw new Exception("Failed to fetch $url: HTTP $code");
        }

        $html = wp_remote_retrieve_body($response);

        // Make URLs relative
        $html = $this->make_urls_relative($html);

        // Save file
        $filepath = $this->export_dir . '/' . $filename;
        $dir = dirname($filepath);

        if (!file_exists($dir)) {
            wp_mkdir_p($dir);
        }

        $result = @file_put_contents($filepath, $html);
        if ($result === false) {
            throw new Exception("Failed to write file: $filepath");
        }
    }

    private function make_urls_relative($html) {
        $site_url = get_site_url();
        $home_url = get_home_url();

        // Build different variations of the URL to replace
        $urls_to_replace = array($site_url, $home_url);

        // Add variations with trailing slash
        if (!str_ends_with($site_url, '/')) {
            $urls_to_replace[] = $site_url . '/';
        }
        if (!str_ends_with($home_url, '/')) {
            $urls_to_replace[] = $home_url . '/';
        }

        foreach ($urls_to_replace as $url) {
            // 1. Replace JavaScript-escaped URLs first (e.g., http:\/\/modub.local\/ → \/)
            $escaped_url = str_replace('/', '\/', $url);
            $html = str_replace($escaped_url, '', $html);

            // 2. Replace URL-encoded URLs (e.g., http%3A%2F%2Fmodub.local%2F)
            $encoded_url = urlencode($url);
            $html = str_replace($encoded_url, '', $html);

            // 3. Replace normal URLs
            $html = str_replace($url, '', $html);
        }

        // Fix protocol-relative URLs (// should become https://)
        $html = str_replace('href="//', 'href="https://', $html);
        $html = str_replace('src="//', 'src="https://', $html);
        $html = str_replace('url("//', 'url("https://', $html);

        // Clean up any remaining issues
        // Fix empty href/src that point to root
        $html = str_replace('href=""', 'href="/"', $html);
        $html = str_replace('src=""', 'src="/"', $html);

        // Fix standalone backslash-escaped slashes in JSON
        $html = str_replace('"\/"', '"/"', $html);
        $html = str_replace('":"\/"', '":"/"', $html);

        return $html;
    }

    private function copy_assets() {
        $this->log('Copying assets...');

        // Copy wp-content/uploads (excluding Simply Static temp files and debug logs)
        $uploads_dir = wp_upload_dir();
        $source = $uploads_dir['basedir'];
        $dest = $this->export_dir . '/wp-content/uploads';

        if (file_exists($source)) {
            // FIX v2.1.1: Exclude simply-static directory to prevent debug files from being exported
            $this->recursive_copy_with_exclusions($source, $dest, array(
                'simply-static', // Exclude Simply Static temp files and debug logs
                'static-export', // Exclude our own export directory if nested
                'static-export-temp' // Exclude our temp directory
            ));
        }

        // Copy theme assets
        $theme_dir = get_stylesheet_directory();
        $theme_dest = $this->export_dir . '/wp-content/themes/' . get_stylesheet();
        $this->recursive_copy($theme_dir, $theme_dest);
    }

    /**
     * Recursive copy with exclusions (v2.1.1)
     * Prevents copying unwanted directories like Simply Static temp files
     */
    private function recursive_copy_with_exclusions($src, $dst, $exclusions = array()) {
        if (!file_exists($src)) return;

        $dir = opendir($src);
        wp_mkdir_p($dst);

        while (($file = readdir($dir)) !== false) {
            if ($file != '.' && $file != '..') {
                // Check if file/directory should be excluded
                if (in_array($file, $exclusions)) {
                    $this->log("Excluding directory: $file");
                    continue;
                }

                if (is_dir($src . '/' . $file)) {
                    $this->recursive_copy_with_exclusions($src . '/' . $file, $dst . '/' . $file, $exclusions);
                } else {
                    copy($src . '/' . $file, $dst . '/' . $file);
                }
            }
        }

        closedir($dir);
    }

    /**
     * Original recursive copy without exclusions
     * Kept for backward compatibility (used for theme assets)
     */
    private function recursive_copy($src, $dst) {
        if (!file_exists($src)) return;

        $dir = opendir($src);
        wp_mkdir_p($dst);

        while (($file = readdir($dir)) !== false) {
            if ($file != '.' && $file != '..') {
                if (is_dir($src . '/' . $file)) {
                    $this->recursive_copy($src . '/' . $file, $dst . '/' . $file);
                } else {
                    copy($src . '/' . $file, $dst . '/' . $file);
                }
            }
        }

        closedir($dir);
    }

    private function create_config_files() {
        // Create _headers file for Kinsta
        $headers = "/*\n  X-Frame-Options: SAMEORIGIN\n  X-Content-Type-Options: nosniff\n  X-XSS-Protection: 1; mode=block";
        file_put_contents($this->export_dir . '/_headers', $headers);

        // Create .gitignore
        $gitignore = ".DS_Store\nThumbs.db";
        file_put_contents($this->export_dir . '/.gitignore', $gitignore);
    }

    /**
     * NEW CHUNKED GITHUB PUSH - Initialize the push process
     * This replaces the single long AJAX call with multiple short calls
     */
    public function ajax_github_push_init() {
        check_ajax_referer('static_exporter_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized');
        }

        $options = get_option('static_exporter_settings');
        $token = $this->decrypt($options['github_token_encrypted'] ?? '');
        $repo = $options['github_repo'] ?? '';
        $branch = $options['github_branch'] ?? 'main';

        if (empty($token) || empty($repo)) {
            wp_send_json_error('GitHub settings not configured.');
        }

        try {
            $this->log('Initializing GitHub push...');

            // Verify credentials
            $user_response = $this->api_request_with_retry("https://api.github.com/user", array(
                'headers' => array(
                    'Authorization' => 'Bearer ' . $token,
                    'User-Agent' => 'WordPress-Static-Exporter',
                    'Accept' => 'application/vnd.github.v3+json'
                ),
                'timeout' => self::API_TIMEOUT,
                'sslverify' => true
            ), 'GitHub authentication');

            $user_code = wp_remote_retrieve_response_code($user_response);
            if ($user_code !== 200) {
                throw new Exception('Invalid GitHub token');
            }

            // Get current commit SHA
            $ref_response = $this->api_request_with_retry("https://api.github.com/repos/$repo/git/refs/heads/$branch", array(
                'headers' => array(
                    'Authorization' => 'Bearer ' . $token,
                    'User-Agent' => 'WordPress-Static-Exporter',
                    'Accept' => 'application/vnd.github.v3+json'
                ),
                'timeout' => self::API_TIMEOUT,
                'sslverify' => true
            ), 'Get branch reference');

            $response_code = wp_remote_retrieve_response_code($ref_response);
            if ($response_code !== 200) {
                throw new Exception('Repository or branch not found');
            }

            $ref_data = json_decode(wp_remote_retrieve_body($ref_response), true);
            $current_commit_sha = $ref_data['object']['sha'] ?? null;

            if (!$current_commit_sha) {
                throw new Exception('Could not find current commit SHA');
            }

            // Get all files
            $files = $this->get_all_files_efficient($this->export_dir);
            $total_files = count($files);

            $this->log("Found $total_files files to upload");

            if ($total_files >= self::GITHUB_TREE_LIMIT) {
                throw new Exception(sprintf(
                    'Site has %d files, exceeds GitHub limit of %d',
                    $total_files,
                    self::GITHUB_TREE_LIMIT
                ));
            }

            // Store push state in transient (expires in 1 hour)
            $push_state = array(
                'token' => $token,
                'repo' => $repo,
                'branch' => $branch,
                'commit_sha' => $current_commit_sha,
                'files' => $files,
                'total_files' => $total_files,
                'processed_files' => 0,
                'tree_items' => array(),
                'current_chunk' => 0,
                'total_chunks' => (int) ceil($total_files / self::CHUNK_SIZE),
                'started_at' => time()
            );

            set_transient('github_push_state', $push_state, 3600);

            $this->log("Ready to upload in {$push_state['total_chunks']} chunks");

            wp_send_json_success(array(
                'total_files' => $total_files,
                'total_chunks' => $push_state['total_chunks'],
                'chunk_size' => self::CHUNK_SIZE
            ));

        } catch (Exception $e) {
            $this->log('✗ Initialization failed: ' . $e->getMessage());
            delete_transient('github_push_state');
            wp_send_json_error($e->getMessage());
        }
    }

    /**
     * Process a single chunk of files
     */
    public function ajax_github_push_chunk() {
        check_ajax_referer('static_exporter_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized');
        }

        // Extend execution time for this chunk
        @set_time_limit(self::CHUNK_TIMEOUT);

        $chunk_num = isset($_POST['chunk']) ? (int) $_POST['chunk'] : 0;

        $push_state = get_transient('github_push_state');
        if (!$push_state) {
            wp_send_json_error('Push state expired. Please restart the upload.');
        }

        try {
            $token = $push_state['token'];
            $repo = $push_state['repo'];
            $files = $push_state['files'];
            $tree_items = $push_state['tree_items'];

            $start_index = $chunk_num * self::CHUNK_SIZE;
            $chunk_files = array_slice($files, $start_index, self::CHUNK_SIZE);

            $this->log("Processing chunk " . ($chunk_num + 1) . "/" . $push_state['total_chunks'] . " (" . count($chunk_files) . " files)");

            foreach ($chunk_files as $file) {
                $relative_path = str_replace($this->export_dir . '/', '', $file);
                $relative_path = str_replace('\\', '/', $relative_path);

                // Skip very large files
                $file_size = filesize($file);
                if ($file_size > self::MAX_FILE_SIZE) {
                    $this->log("Skipping large file: $relative_path");
                    $push_state['processed_files']++;
                    continue;
                }

                $content = file_get_contents($file);

                // Create blob
                $blob_response = $this->api_request_with_retry(
                    "https://api.github.com/repos/$repo/git/blobs",
                    array(
                        'method' => 'POST',
                        'headers' => array(
                            'Authorization' => 'Bearer ' . $token,
                            'User-Agent' => 'WordPress-Static-Exporter',
                            'Content-Type' => 'application/json',
                            'Accept' => 'application/vnd.github.v3+json'
                        ),
                        'body' => json_encode(array(
                            'content' => base64_encode($content),
                            'encoding' => 'base64'
                        )),
                        'timeout' => self::API_TIMEOUT,
                        'sslverify' => true
                    ),
                    "Upload blob for $relative_path"
                );

                unset($content);

                $blob_code = wp_remote_retrieve_response_code($blob_response);
                if ($blob_code !== 201) {
                    throw new Exception("Failed to create blob for $relative_path");
                }

                $blob_data = json_decode(wp_remote_retrieve_body($blob_response), true);

                $tree_items[] = array(
                    'path' => $relative_path,
                    'mode' => '100644',
                    'type' => 'blob',
                    'sha' => $blob_data['sha']
                );

                $push_state['processed_files']++;

                unset($blob_response, $blob_data);
                gc_collect_cycles();
            }

            // Update state
            $push_state['tree_items'] = $tree_items;
            $push_state['current_chunk'] = $chunk_num + 1;
            set_transient('github_push_state', $push_state, 3600);

            $progress_percent = ($push_state['processed_files'] / $push_state['total_files']) * 100;

            wp_send_json_success(array(
                'chunk' => $chunk_num,
                'processed_files' => $push_state['processed_files'],
                'total_files' => $push_state['total_files'],
                'progress' => round($progress_percent, 1),
                'completed' => ($push_state['current_chunk'] >= $push_state['total_chunks'])
            ));

        } catch (Exception $e) {
            $this->log('✗ Chunk upload failed: ' . $e->getMessage());
            wp_send_json_error($e->getMessage());
        }
    }

    /**
     * Finalize the push by creating tree and commit
     */
    public function ajax_github_push_finalize() {
        check_ajax_referer('static_exporter_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized');
        }

        $push_state = get_transient('github_push_state');
        if (!$push_state) {
            wp_send_json_error('Push state expired');
        }

        try {
            $token = $push_state['token'];
            $repo = $push_state['repo'];
            $branch = $push_state['branch'];
            $current_commit_sha = $push_state['commit_sha'];
            $tree_items = $push_state['tree_items'];

            if (empty($tree_items)) {
                throw new Exception('No files were uploaded');
            }

            $this->log('Creating git tree with ' . count($tree_items) . ' items...');

            // Create the tree
            $tree_response = $this->api_request_with_retry(
                "https://api.github.com/repos/$repo/git/trees",
                array(
                    'method' => 'POST',
                    'headers' => array(
                        'Authorization' => 'Bearer ' . $token,
                        'User-Agent' => 'WordPress-Static-Exporter',
                        'Content-Type' => 'application/json',
                        'Accept' => 'application/vnd.github.v3+json'
                    ),
                    'body' => json_encode(array(
                        'tree' => $tree_items
                    )),
                    'timeout' => self::API_TIMEOUT,
                    'sslverify' => true
                ),
                'Create git tree'
            );

            $tree_code = wp_remote_retrieve_response_code($tree_response);
            if ($tree_code !== 201) {
                throw new Exception('Failed to create tree');
            }

            $tree_data = json_decode(wp_remote_retrieve_body($tree_response), true);
            $tree_sha = $tree_data['sha'] ?? null;

            if (!$tree_sha) {
                throw new Exception('Could not create tree');
            }

            $this->log('Creating commit...');

            // Create commit
            $commit_message = 'Deploy from WordPress - ' . date('Y-m-d H:i:s');
            $commit_response = $this->api_request_with_retry(
                "https://api.github.com/repos/$repo/git/commits",
                array(
                    'method' => 'POST',
                    'headers' => array(
                        'Authorization' => 'Bearer ' . $token,
                        'User-Agent' => 'WordPress-Static-Exporter',
                        'Content-Type' => 'application/json',
                        'Accept' => 'application/vnd.github.v3+json'
                    ),
                    'body' => json_encode(array(
                        'message' => $commit_message,
                        'tree' => $tree_sha,
                        'parents' => array($current_commit_sha)
                    )),
                    'timeout' => self::API_TIMEOUT,
                    'sslverify' => true
                ),
                'Create commit'
            );

            $commit_code = wp_remote_retrieve_response_code($commit_response);
            if ($commit_code !== 201) {
                throw new Exception('Failed to create commit');
            }

            $commit_data = json_decode(wp_remote_retrieve_body($commit_response), true);
            $new_commit_sha = $commit_data['sha'] ?? null;

            if (!$new_commit_sha) {
                throw new Exception('Could not create commit');
            }

            $this->log('Updating branch...');

            // Update branch
            $update_response = $this->api_request_with_retry(
                "https://api.github.com/repos/$repo/git/refs/heads/$branch",
                array(
                    'method' => 'PATCH',
                    'headers' => array(
                        'Authorization' => 'Bearer ' . $token,
                        'User-Agent' => 'WordPress-Static-Exporter',
                        'Content-Type' => 'application/json',
                        'Accept' => 'application/vnd.github.v3+json'
                    ),
                    'body' => json_encode(array(
                        'sha' => $new_commit_sha,
                        'force' => false
                    )),
                    'timeout' => self::API_TIMEOUT,
                    'sslverify' => true
                ),
                'Update branch reference'
            );

            $update_code = wp_remote_retrieve_response_code($update_response);
            if ($update_code !== 200) {
                throw new Exception('Failed to update branch');
            }

            $this->log('✓ Successfully pushed to GitHub!');
            $this->log('Commit: ' . substr($new_commit_sha, 0, 7));
            $this->log('Files: ' . count($tree_items));

            // Clean up
            delete_transient('github_push_state');

            wp_send_json_success(array(
                'message' => 'Pushed to GitHub',
                'commit_sha' => substr($new_commit_sha, 0, 7),
                'files' => count($tree_items)
            ));

        } catch (Exception $e) {
            $this->log('✗ Finalization failed: ' . $e->getMessage());
            wp_send_json_error($e->getMessage());
        }
    }

    /**
     * Get push status (for progress tracking)
     */
    public function ajax_github_push_status() {
        check_ajax_referer('static_exporter_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized');
        }

        $push_state = get_transient('github_push_state');

        if (!$push_state) {
            wp_send_json_success(array('status' => 'idle'));
        } else {
            wp_send_json_success(array(
                'status' => 'processing',
                'processed_files' => $push_state['processed_files'],
                'total_files' => $push_state['total_files'],
                'current_chunk' => $push_state['current_chunk'],
                'total_chunks' => $push_state['total_chunks'],
                'progress' => round(($push_state['processed_files'] / $push_state['total_files']) * 100, 1)
            ));
        }
    }

    /**
     * LEGACY: Keep old method for backward compatibility and auto-export
     * Now redirects to chunked processing for manual use
     */
    public function ajax_push_to_github() {
        check_ajax_referer('static_exporter_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized');
        }

        // For AJAX calls from the admin UI, redirect to chunked processing
        // This will be handled by the JavaScript
        wp_send_json_success(array('use_chunked' => true));
    }

    /**
     * Make API request with retry logic
     */
    private function api_request_with_retry($url, $args, $context = 'API request') {
        $last_error = null;

        for ($attempt = 1; $attempt <= self::MAX_RETRIES; $attempt++) {
            if ($attempt > 1) {
                $wait_time = pow(2, $attempt - 1); // Exponential backoff: 2, 4, 8 seconds
                $this->log("Retry attempt $attempt/" . self::MAX_RETRIES . " after {$wait_time}s...");
                sleep($wait_time);
            }

            $response = wp_remote_request($url, $args);

            if (!is_wp_error($response)) {
                return $response;
            }

            $last_error = $response->get_error_message();
            $this->log("Attempt $attempt failed: $last_error");
        }

        throw new Exception("$context failed after " . self::MAX_RETRIES . " attempts: $last_error");
    }

    private function push_to_github_api($token, $repo, $branch) {
        // Increase memory limit and execution time for large uploads
        @ini_set('memory_limit', '512M');
        @set_time_limit(900); // 15 minutes

        $this->log('Getting repository information...');

        // Verify credentials first
        $user_response = $this->api_request_with_retry("https://api.github.com/user", array(
            'headers' => array(
                'Authorization' => 'Bearer ' . $token,
                'User-Agent' => 'WordPress-Static-Exporter',
                'Accept' => 'application/vnd.github.v3+json'
            ),
            'timeout' => self::API_TIMEOUT,
            'sslverify' => true  // SECURITY FIX
        ), 'GitHub authentication');

        $user_code = wp_remote_retrieve_response_code($user_response);
        if ($user_code !== 200) {
            throw new Exception('Invalid GitHub token (code ' . $user_code . '). Please check your token and try again.');
        }

        // Get the current commit SHA
        $ref_response = $this->api_request_with_retry("https://api.github.com/repos/$repo/git/refs/heads/$branch", array(
            'headers' => array(
                'Authorization' => 'Bearer ' . $token,
                'User-Agent' => 'WordPress-Static-Exporter',
                'Accept' => 'application/vnd.github.v3+json'
            ),
            'timeout' => self::API_TIMEOUT,
            'sslverify' => true
        ), 'Get branch reference');

        $response_code = wp_remote_retrieve_response_code($ref_response);
        if ($response_code === 404) {
            throw new Exception('Repository or branch not found. Make sure the repo exists and you have access.');
        } else if ($response_code !== 200) {
            $error_body = json_decode(wp_remote_retrieve_body($ref_response), true);
            $error_msg = $error_body['message'] ?? 'Unknown error';
            throw new Exception('GitHub API error: ' . $error_msg);
        }

        $ref_data = json_decode(wp_remote_retrieve_body($ref_response), true);
        $current_commit_sha = $ref_data['object']['sha'] ?? null;

        if (!$current_commit_sha) {
            throw new Exception('Could not find current commit SHA');
        }

        $this->log('Current commit: ' . substr($current_commit_sha, 0, 7));

        // Get all files using memory-efficient method
        $files = $this->get_all_files_efficient($this->export_dir);
        $total_files = count($files);
        $this->log("Found $total_files files to upload");

        // Check file count limit (>= because GitHub limit is exactly 5000)
        if ($total_files >= self::GITHUB_TREE_LIMIT) {
            throw new Exception(sprintf(
                'Site has %d files, which meets or exceeds GitHub limit of %d files. Please reduce your site size by removing unnecessary media or splitting into multiple repositories.',
                $total_files,
                self::GITHUB_TREE_LIMIT
            ));
        }

        // Process in batches to avoid API limits
        $total_batches = (int) ceil($total_files / self::BATCH_SIZE);

        $this->log("Processing in $total_batches batches...");

        $all_tree_items = array();

        // Process files in batches to avoid memory issues
        $file_index = 0;
        for ($batch_num = 1; $batch_num <= $total_batches; $batch_num++) {
            $batch_start = ($batch_num - 1) * self::BATCH_SIZE;
            $batch_files = array_slice($files, $batch_start, self::BATCH_SIZE);

            $this->log("Batch $batch_num/$total_batches: Processing " . count($batch_files) . " files...");

            foreach ($batch_files as $file) {
                $relative_path = str_replace($this->export_dir . '/', '', $file);
                $relative_path = str_replace('\\', '/', $relative_path);

                // Skip very large files
                $file_size = filesize($file);
                if ($file_size > self::MAX_FILE_SIZE) {
                    $this->log(sprintf(
                        "Skipping large file: %s (%sMB)",
                        $relative_path,
                        round($file_size / 1024 / 1024, 2)
                    ));
                    continue;
                }

                $content = file_get_contents($file);

                // Create blob with retry logic
                try {
                    $blob_response = $this->api_request_with_retry(
                        "https://api.github.com/repos/$repo/git/blobs",
                        array(
                            'method' => 'POST',
                            'headers' => array(
                                'Authorization' => 'Bearer ' . $token,
                                'User-Agent' => 'WordPress-Static-Exporter',
                                'Content-Type' => 'application/json',
                                'Accept' => 'application/vnd.github.v3+json'
                            ),
                            'body' => json_encode(array(
                                'content' => base64_encode($content),
                                'encoding' => 'base64'
                            )),
                            'timeout' => self::API_TIMEOUT,
                            'sslverify' => true
                        ),
                        "Upload blob for $relative_path"
                    );

                    // Free memory immediately after upload
                    unset($content);

                    $blob_code = wp_remote_retrieve_response_code($blob_response);
                    if ($blob_code !== 201) {
                        $blob_body = json_decode(wp_remote_retrieve_body($blob_response), true);
                        $blob_error = $blob_body['message'] ?? 'Unknown error';
                        throw new Exception("Failed to create blob: $blob_error (code $blob_code)");
                    }

                    $blob_data = json_decode(wp_remote_retrieve_body($blob_response), true);

                    $all_tree_items[] = array(
                        'path' => $relative_path,
                        'mode' => '100644',
                        'type' => 'blob',
                        'sha' => $blob_data['sha']
                    );

                    // Clean up response data
                    unset($blob_response, $blob_data);

                    // Periodic garbage collection
                    $file_index++;
                    if ($file_index % 10 === 0) {
                        gc_collect_cycles();
                    }

                } catch (Exception $e) {
                    // Log error but continue with other files
                    $this->log("Error uploading $relative_path: " . $e->getMessage());
                    // Cleanup on error too
                    unset($content);
                }
            }

            $this->log("Batch $batch_num complete: " . count($all_tree_items) . " files ready");

            // Small delay between batches to avoid rate limiting
            if ($batch_num < $total_batches) {
                sleep(self::BATCH_DELAY);
            }
        }

        if (empty($all_tree_items)) {
            throw new Exception('No files were successfully uploaded to GitHub');
        }

        $this->log('Creating git tree...');

        // Create the tree
        $tree_response = $this->api_request_with_retry(
            "https://api.github.com/repos/$repo/git/trees",
            array(
                'method' => 'POST',
                'headers' => array(
                    'Authorization' => 'Bearer ' . $token,
                    'User-Agent' => 'WordPress-Static-Exporter',
                    'Content-Type' => 'application/json',
                    'Accept' => 'application/vnd.github.v3+json'
                ),
                'body' => json_encode(array(
                    'tree' => $all_tree_items
                )),
                'timeout' => self::API_TIMEOUT,
                'sslverify' => true
            ),
            'Create git tree'
        );

        $tree_code = wp_remote_retrieve_response_code($tree_response);
        if ($tree_code !== 201) {
            $tree_body = json_decode(wp_remote_retrieve_body($tree_response), true);
            $tree_error = $tree_body['message'] ?? 'Unknown error';
            throw new Exception('Failed to create tree: ' . $tree_error . ' (code ' . $tree_code . ')');
        }

        $tree_data = json_decode(wp_remote_retrieve_body($tree_response), true);
        $tree_sha = $tree_data['sha'] ?? null;

        if (!$tree_sha) {
            throw new Exception('Could not create tree');
        }

        $this->log('Creating commit...');

        // Create the commit
        $commit_message = 'Deploy from WordPress - ' . date('Y-m-d H:i:s');
        $commit_response = $this->api_request_with_retry(
            "https://api.github.com/repos/$repo/git/commits",
            array(
                'method' => 'POST',
                'headers' => array(
                    'Authorization' => 'Bearer ' . $token,
                    'User-Agent' => 'WordPress-Static-Exporter',
                    'Content-Type' => 'application/json',
                    'Accept' => 'application/vnd.github.v3+json'
                ),
                'body' => json_encode(array(
                    'message' => $commit_message,
                    'tree' => $tree_sha,
                    'parents' => array($current_commit_sha)
                )),
                'timeout' => self::API_TIMEOUT,
                'sslverify' => true
            ),
            'Create commit'
        );

        $commit_code = wp_remote_retrieve_response_code($commit_response);
        if ($commit_code !== 201) {
            $commit_body = json_decode(wp_remote_retrieve_body($commit_response), true);
            $commit_error = $commit_body['message'] ?? 'Unknown error';
            throw new Exception('Failed to create commit: ' . $commit_error . ' (code ' . $commit_code . ')');
        }

        $commit_data = json_decode(wp_remote_retrieve_body($commit_response), true);
        $new_commit_sha = $commit_data['sha'] ?? null;

        if (!$new_commit_sha) {
            throw new Exception('Could not create commit');
        }

        $this->log('Updating branch...');

        // Update the branch reference
        $update_response = $this->api_request_with_retry(
            "https://api.github.com/repos/$repo/git/refs/heads/$branch",
            array(
                'method' => 'PATCH',
                'headers' => array(
                    'Authorization' => 'Bearer ' . $token,
                    'User-Agent' => 'WordPress-Static-Exporter',
                    'Content-Type' => 'application/json',
                    'Accept' => 'application/vnd.github.v3+json'
                ),
                'body' => json_encode(array(
                    'sha' => $new_commit_sha,
                    'force' => false
                )),
                'timeout' => self::API_TIMEOUT,
                'sslverify' => true
            ),
            'Update branch reference'
        );

        $update_code = wp_remote_retrieve_response_code($update_response);
        if ($update_code !== 200) {
            $update_body = json_decode(wp_remote_retrieve_body($update_response), true);
            $update_error = $update_body['message'] ?? 'Unknown error';
            throw new Exception('Failed to update branch: ' . $update_error . ' (code ' . $update_code . ')');
        }

        $this->log('✓ Successfully pushed to GitHub!');
        $this->log('Commit: ' . substr($new_commit_sha, 0, 7));
        $this->log('Files: ' . count($all_tree_items));
    }

    public function ajax_deploy_to_kinsta() {
        check_ajax_referer('static_exporter_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized');
        }

        $options = get_option('static_exporter_settings');
        $api_key = $this->decrypt($options['kinsta_api_key_encrypted'] ?? '');
        $site_id = $options['kinsta_site_id'] ?? '';

        if (empty($api_key) || empty($site_id)) {
            wp_send_json_error('Kinsta settings not configured. Please save your Kinsta API key and Site ID in the settings above.');
        }

        $this->log('Deploying to Kinsta...');

        try {
            $this->deploy_to_kinsta_api($api_key, $site_id);
            $this->log('Successfully deployed to Kinsta!');
            wp_send_json_success(array('message' => 'Deployed to Kinsta'));
        } catch (Exception $e) {
            $this->log('Error: ' . $e->getMessage());
            wp_send_json_error($e->getMessage());
        }
    }

    private function deploy_to_kinsta_api($api_key, $site_id) {
        $response = $this->api_request_with_retry(
            "https://api.kinsta.com/v2/sites/$site_id/deployments",
            array(
                'method' => 'POST',
                'headers' => array(
                    'Authorization' => 'Bearer ' . $api_key,
                    'Content-Type' => 'application/json'
                ),
                'body' => json_encode(array(
                    'trigger' => 'manual'
                )),
                'timeout' => self::API_TIMEOUT,
                'sslverify' => true
            ),
            'Kinsta deployment'
        );

        $code = wp_remote_retrieve_response_code($response);
        if ($code < 200 || $code >= 300) {
            $body = json_decode(wp_remote_retrieve_body($response), true);
            $error = $body['message'] ?? 'Unknown error';
            throw new Exception("Kinsta deployment failed: $error (code $code)");
        }

        $this->log('✓ Kinsta deployment triggered successfully');
    }

    /**
     * Get all files - memory efficient version for large directories
     */
    private function get_all_files_efficient($dir) {
        $files = array();

        if (!file_exists($dir)) return $files;

        try {
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS),
                RecursiveIteratorIterator::LEAVES_ONLY
            );

            foreach ($iterator as $file) {
                if ($file->isFile()) {
                    $files[] = $file->getPathname();
                }

                // Free memory periodically
                if (count($files) % 500 === 0) {
                    gc_collect_cycles();
                }
            }
        } catch (Exception $e) {
            $this->log('Error scanning directory: ' . $e->getMessage());
        }

        return $files;
    }

    /**
     * Legacy method - kept for backwards compatibility
     */
    private function get_all_files($dir) {
        return $this->get_all_files_efficient($dir);
    }

    private function clean_directory($dir) {
        if (!file_exists($dir)) return;

        $files = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($files as $file) {
            if ($file->isDir()) {
                rmdir($file->getRealPath());
            } else {
                unlink($file->getRealPath());
            }
        }

        rmdir($dir);
    }

    /**
     * Log message with rotation
     */
    private function log($message) {
        $current_log = get_option('static_exporter_log', '');
        $new_entry = date('[Y-m-d H:i:s] ') . $message . "\n";

        // Rotate log if too large
        if (strlen($current_log) > self::LOG_MAX_SIZE) {
            // Keep only last 50% of log, but cut at line boundary
            $lines = explode("\n", $current_log);
            $total_lines = count($lines);
            $keep_lines = (int)($total_lines / 2);
            $lines = array_slice($lines, -$keep_lines);
            $current_log = "... (log rotated) ...\n" . implode("\n", $lines);
        }

        update_option('static_exporter_log', $current_log . $new_entry);
    }
}

// Initialize plugin
$wp_static_exporter = new WP_Static_Exporter();

// Register settings with validation
add_action('admin_init', function() use ($wp_static_exporter) {
    register_setting('static_exporter_settings', 'static_exporter_settings', array(
        'sanitize_callback' => function($input) use ($wp_static_exporter) {
            $sanitized = array();

            // Validate and encrypt GitHub token if provided
            if (!empty($input['github_token'])) {
                $sanitized['github_token_encrypted'] = $wp_static_exporter->encrypt($input['github_token']);
            } else {
                // Keep existing encrypted token
                $existing = get_option('static_exporter_settings', array());
                $sanitized['github_token_encrypted'] = $existing['github_token_encrypted'] ?? '';
            }

            // Validate GitHub repository format (allow dots, underscores, hyphens)
            if (!empty($input['github_repo'])) {
                if (preg_match('/^[a-zA-Z0-9_.-]+\/[a-zA-Z0-9_.-]+$/', $input['github_repo'])) {
                    $sanitized['github_repo'] = sanitize_text_field($input['github_repo']);
                } else {
                    add_settings_error(
                        'static_exporter_settings',
                        'github_repo',
                        'GitHub repository must be in format: username/repository-name'
                    );
                    $sanitized['github_repo'] = '';
                }
            }

            // Sanitize branch
            $sanitized['github_branch'] = sanitize_text_field($input['github_branch'] ?? 'main');

            // Validate and encrypt Kinsta API key if provided
            if (!empty($input['kinsta_api_key'])) {
                $sanitized['kinsta_api_key_encrypted'] = $wp_static_exporter->encrypt($input['kinsta_api_key']);
            } else {
                $existing = get_option('static_exporter_settings', array());
                $sanitized['kinsta_api_key_encrypted'] = $existing['kinsta_api_key_encrypted'] ?? '';
            }

            // Sanitize Kinsta site ID
            $sanitized['kinsta_site_id'] = sanitize_text_field($input['kinsta_site_id'] ?? '');

            // Checkboxes
            $sanitized['auto_export'] = !empty($input['auto_export']) ? 1 : 0;
            $sanitized['auto_deploy'] = !empty($input['auto_deploy']) ? 1 : 0;

            return $sanitized;
        }
    ));
});

// Register cron hook for auto-export
add_action('static_exporter_auto_export', array($wp_static_exporter, 'perform_auto_export'));
