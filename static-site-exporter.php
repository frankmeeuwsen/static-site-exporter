<?php
/**
 * Plugin Name: Static Site Exporter to Kinsta
 * Plugin URI: https://github.com/mdubbelm/static-site-exporter
 * Description: Export WordPress site to static HTML and deploy to GitHub/Kinsta Static Hosting
 * Version: 2.6.0
 * Author: Monique Dubbelman
 * License: GPL v2 or later
 *
 * Changelog:
 * 2.6.0 - NEW FEATURE: Export Only ZIP Download
 *       - Added new "Export Only" tab for standalone static site export
 *       - Generate ZIP download without Git/GitHub/Kinsta deployment
 *       - Automatic HTML transformations: remove search forms, comment forms, login elements
 *       - Performance optimizations: HTML minification, lazy loading, preload hints
 *       - Generate extra files: sitemap.xml, robots.txt, manifest.json, service worker, 404, offline page
 *       - Images not included - paths made relative for local storage
 * 2.5.3 - BUGFIX: Fix git command not found error
 *       - Use full path to git binary (/usr/local/bin/git)
 *       - Fixes error code 127 when deploying via PHP exec()
 * 2.5.2 - BUGFIX: Auto-repair Git repository
 *       - Automatically repair Git repository if .git directory is missing
 *       - Fetches from remote and resets to latest commit
 *       - No more manual Git repair needed
 * 2.5.1 - NEW FEATURE: Reset Export Lock Button
 *       - Added "Reset Export Lock" button in admin interface
 *       - Clear stuck export locks without terminal access
 *       - Includes confirmation dialog for safety
 *       - Also clears GitHub push state
 * 2.5.0 - NEW FEATURE: Activity Status Dashboard
 *       - Visual dashboard showing last manual and auto exports
 *       - Track last manual and auto GitHub pushes
 *       - See what content was exported (page/post titles)
 *       - View commit SHAs and file counts
 *       - Human-readable timestamps
 * 2.4.0 - WORKFLOW OPTIMIZATION: Simplified deployment process
 *       - Export directly to Git repository location (no more rsync needed!)
 *       - One-click deployment via Git shell commands
 *       - Faster and more reliable than GitHub API approach
 *       - Simplified admin interface workflow
 * 2.3.0 - PERFORMANCE: Incremental export and push (only changed files)
 *       - Track last modified time for posts/pages
 *       - Only export changed content since last export
 *       - Compare file hashes with GitHub before pushing
 *       - Only push changed files to GitHub
 *       - Dramatically reduces export and push time from minutes to seconds
 * 2.2.0 - MAJOR FIX: Comprehensive URL conversion for CSS/JS/images
 *       - Improved make_urls_relative() to handle escaped and encoded URLs
 *       - Fixes JavaScript-escaped URLs (http:\/\/)
 *       - Fixes URL-encoded URLs (http%3A%2F%2F)
 *       - Fixes broken CSS, images, and responsive viewport issues
 *       - Fixed session blocking timeout issue
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

// Include Export Only class
require_once plugin_dir_path(__FILE__) . 'includes/class-static-export-only.php';

class WP_Static_Exporter {
    // Configuration constants
    const BATCH_SIZE = 50;              // Files per GitHub batch (LEGACY - kept for backward compatibility)
    const CHUNK_SIZE = 10;              // Files per AJAX chunk (REDUCED from 15 - smaller chunks = more reliable)
    const MAX_FILE_SIZE = 10485760;     // 10MB in bytes
    const MAX_EXPORT_SIZE = 524288000;  // 500MB in bytes
    const GITHUB_TREE_LIMIT = 5000;     // GitHub tree item limit
    const API_TIMEOUT = 120;            // API timeout in seconds (INCREASED from 60)
    const PAGE_TIMEOUT = 90;            // Page fetch timeout (INCREASED from 30)
    const BATCH_DELAY = 2;              // Seconds between batches (INCREASED from 1)
    const MAX_RETRIES = 5;              // API retry attempts (INCREASED from 3)
    const LOG_MAX_SIZE = 102400;        // 100KB max log size
    const DEBOUNCE_TIME = 120;          // 2 minutes
    const SCHEDULE_DELAY = 30;          // 30 seconds
    const CHUNK_TIMEOUT = 120;          // Timeout for chunked AJAX requests (INCREASED from 45)
    const EXPORT_PAGE_DELAY = 1;        // NEW - delay between page exports
    const MAX_POSTS_PER_BATCH = 50;     // NEW - reduced from 100

    private $export_dir;
    private $temp_dir;
    private $encryption_key;
    private $export_only;

    public function __construct() {
        // Export directly to Git repository for seamless deployment
        // Get export directory from settings, or use default WP content directory
        $options = get_option('static_exporter_settings', array());
        $this->export_dir = !empty($options['export_directory'])
            ? $options['export_directory']
            : WP_CONTENT_DIR . '/static-export';
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

        // Git deployment via shell commands
        add_action('wp_ajax_git_deploy', array($this, 'ajax_git_deploy'));

        // Reset export lock
        add_action('wp_ajax_reset_export_lock', array($this, 'ajax_reset_export_lock'));

        // Auto-export triggers
        $this->setup_auto_export_hooks();

        // Initialize Export Only functionality
        $this->export_only = new Static_Export_Only();
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

        wp_enqueue_style('static-exporter-css', plugin_dir_url(__FILE__) . 'assets/style.css', array(), '2.6.0');
        wp_enqueue_script('static-exporter-js', plugin_dir_url(__FILE__) . 'assets/script.js', array('jquery'), '2.6.0', true);
        wp_enqueue_script('static-exporter-export-only-js', plugin_dir_url(__FILE__) . 'assets/js/export-only.js', array('jquery'), '2.6.0', true);
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

        // Get active tab from URL or default to export-only
        $active_tab = isset($_GET['tab']) ? sanitize_key($_GET['tab']) : 'export-only';
        ?>
        <div class="wrap">
            <h1>Static Site Exporter</h1>

            <?php
            // Display configuration status
            if (isset($_GET['settings-updated'])) {
                echo '<div class="notice notice-success"><p>Settings saved successfully!</p></div>';
            }

            $auto_export = $options['auto_export'] ?? false;
            $auto_deploy = $options['auto_deploy'] ?? false;

            if ($auto_export && $active_tab === 'full-deploy'): ?>
            <div class="notice notice-success">
                <p>
                    <strong>✓ Auto-Export is ENABLED</strong> - Your site will automatically export on content changes.
                    <?php if ($auto_deploy): ?>
                    <br>✓ Auto-Deploy is also enabled - Changes will be pushed to GitHub and Kinsta automatically.
                    <?php endif; ?>
                </p>
            </div>
            <?php endif; ?>

            <!-- Tab Navigation -->
            <div class="exporter-tabs">
                <a href="?page=static-site-exporter&tab=export-only"
                   class="exporter-tab <?php echo $active_tab === 'export-only' ? 'active' : ''; ?>">
                    <span class="dashicons dashicons-download"></span>
                    Export Only (ZIP)
                </a>
                <a href="?page=static-site-exporter&tab=full-deploy"
                   class="exporter-tab <?php echo $active_tab === 'full-deploy' ? 'active' : ''; ?>">
                    <span class="dashicons dashicons-cloud-upload"></span>
                    Full Deploy (Kinsta)
                </a>
            </div>

            <!-- Tab Content: Export Only -->
            <div class="exporter-tab-content <?php echo $active_tab === 'export-only' ? 'active' : ''; ?>">
                <?php $this->export_only->render_tab(); ?>
            </div>

            <!-- Tab Content: Full Deploy -->
            <div class="exporter-tab-content <?php echo $active_tab === 'full-deploy' ? 'active' : ''; ?>">
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
                            <tr>
                                <th><label for="export_directory">Export Directory</label></th>
                                <td>
                                    <div style="display: flex; gap: 10px; align-items: flex-start;">
                                        <input type="text" id="export_directory" name="static_exporter_settings[export_directory]"
                                               value="<?php echo esc_attr($options['export_directory'] ?? ''); ?>"
                                               class="large-text code" style="flex: 1; min-width: 400px;"
                                               placeholder="/Users/yourusername/Projecten/your-repo/public_static">
                                        <button type="button" class="button" id="paste-directory-path"
                                                style="white-space: nowrap;">
                                            <span class="dashicons dashicons-clipboard" style="margin-top: 3px;"></span> Plak Pad
                                        </button>
                                    </div>
                                    <p class="description" style="margin-top: 8px;">
                                        <strong>Full path to your Git repository where static files should be exported.</strong><br>
                                        Example: <code>/Users/username/Sites/my-static-site/public</code> or <code>/var/www/static-export</code><br>
                                        <span style="color: #d63638;">⚠ This should point to your Git repository, not the Local Sites folder!</span>
                                    </p>
                                    <script>
                                    document.addEventListener('DOMContentLoaded', function() {
                                        const pasteBtn = document.getElementById('paste-directory-path');
                                        const input = document.getElementById('export_directory');

                                        if (pasteBtn && input) {
                                            pasteBtn.addEventListener('click', async function() {
                                                try {
                                                    const text = await navigator.clipboard.readText();
                                                    if (text) {
                                                        input.value = text.trim();
                                                        input.focus();
                                                        // Visual feedback
                                                        pasteBtn.innerHTML = '<span class="dashicons dashicons-yes" style="margin-top: 3px; color: #46b450;"></span> Geplakt!';
                                                        setTimeout(() => {
                                                            pasteBtn.innerHTML = '<span class="dashicons dashicons-clipboard" style="margin-top: 3px;"></span> Plak Pad';
                                                        }, 2000);
                                                    }
                                                } catch (err) {
                                                    alert('Kon niet plakken vanuit clipboard. Gebruik Cmd+V in het veld.');
                                                }
                                            });
                                        }
                                    });
                                    </script>
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

                        <h2>Export Settings</h2>
                        <table class="form-table">
                            <tr>
                                <th><label for="url_structure">URL Structure</label></th>
                                <td>
                                    <label>
                                        <input type="radio" name="static_exporter_settings[url_structure]"
                                               value="directory" <?php checked($options['url_structure'] ?? 'directory', 'directory'); ?>>
                                        Directory style (/page/index.html) - SEO friendly, clean URLs
                                    </label><br>
                                    <label>
                                        <input type="radio" name="static_exporter_settings[url_structure]"
                                               value="flat" <?php checked($options['url_structure'] ?? 'directory', 'flat'); ?>>
                                        Flat style (/page.html) - Faster, simpler structure
                                    </label>
                                    <p class="description">Choose how URLs are structured in the export</p>
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
                    <h2>Activity Status</h2>
                    <?php echo $this->render_activity_status(); ?>
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

                        <button id="reset-lock" class="button button-secondary" style="margin-left: 20px;">
                            <span class="dashicons dashicons-unlock"></span> Reset Export Lock
                        </button>
                    </div>

                    <div id="export-log" class="export-log"></div>
                    <div id="export-progress" class="export-progress" style="display:none;">
                        <div class="progress-bar"></div>
                    </div>
                </div>
            </div>
            </div><!-- End Full Deploy tab content -->
        </div>
        <?php
    }

    /**
     * Render activity status dashboard
     */
    private function render_activity_status() {
        $last_manual_export = get_option('static_exporter_last_manual_export');
        $last_auto_export = get_option('static_exporter_last_auto_export');
        $last_manual_push = get_option('static_exporter_last_manual_push');
        $last_auto_push = get_option('static_exporter_last_auto_push');

        ob_start();
        ?>
        <div class="activity-status-dashboard">
            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <th width="20%">Action</th>
                        <th width="20%">Last Run</th>
                        <th width="15%">Type</th>
                        <th width="45%">Details</th>
                    </tr>
                </thead>
                <tbody>
                    <!-- Manual Export -->
                    <tr>
                        <td><strong>Export</strong></td>
                        <td>
                            <?php if ($last_manual_export): ?>
                                <span class="dashicons dashicons-clock" style="color: #2271b1;"></span>
                                <?php echo esc_html(human_time_diff($last_manual_export['timestamp'], current_time('timestamp'))); ?> ago
                                <br><small><?php echo esc_html(date('Y-m-d H:i:s', $last_manual_export['timestamp'])); ?></small>
                            <?php else: ?>
                                <span style="color: #999;">Never</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <span class="dashicons dashicons-admin-users"></span> Manual
                        </td>
                        <td>
                            <?php if ($last_manual_export): ?>
                                <?php if ($last_manual_export['type'] === 'full'): ?>
                                    <span class="dashicons dashicons-database-export" style="color: #2271b1;"></span>
                                    <strong>Full export</strong> - All content
                                <?php else: ?>
                                    <span class="dashicons dashicons-update" style="color: #46b450;"></span>
                                    <strong>Incremental</strong> - <?php echo absint($last_manual_export['items_count']); ?> items
                                <?php endif; ?>
                                <?php if (!empty($last_manual_export['items'])): ?>
                                    <br><small style="color: #666;">
                                        <?php echo esc_html(implode(', ', array_slice($last_manual_export['items'], 0, 3))); ?>
                                        <?php if (count($last_manual_export['items']) > 3): ?>
                                            and <?php echo absint(count($last_manual_export['items']) - 3); ?> more...
                                        <?php endif; ?>
                                    </small>
                                <?php endif; ?>
                            <?php else: ?>
                                -
                            <?php endif; ?>
                        </td>
                    </tr>

                    <!-- Auto Export -->
                    <tr>
                        <td></td>
                        <td>
                            <?php if ($last_auto_export): ?>
                                <span class="dashicons dashicons-clock" style="color: #72aee6;"></span>
                                <?php echo esc_html(human_time_diff($last_auto_export['timestamp'], current_time('timestamp'))); ?> ago
                                <br><small><?php echo esc_html(date('Y-m-d H:i:s', $last_auto_export['timestamp'])); ?></small>
                            <?php else: ?>
                                <span style="color: #999;">Never</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <span class="dashicons dashicons-update-alt"></span> Auto
                        </td>
                        <td>
                            <?php if ($last_auto_export): ?>
                                <span class="dashicons dashicons-update" style="color: #46b450;"></span>
                                <?php echo absint($last_auto_export['items_count']); ?> items
                                <?php if (!empty($last_auto_export['trigger'])): ?>
                                    <br><small style="color: #666;">Triggered by: <?php echo esc_html($last_auto_export['trigger']); ?></small>
                                <?php endif; ?>
                                <?php if (!empty($last_auto_export['items'])): ?>
                                    <br><small style="color: #666;">
                                        <?php echo esc_html(implode(', ', array_slice($last_auto_export['items'], 0, 3))); ?>
                                        <?php if (count($last_auto_export['items']) > 3): ?>
                                            and <?php echo absint(count($last_auto_export['items']) - 3); ?> more...
                                        <?php endif; ?>
                                    </small>
                                <?php endif; ?>
                            <?php else: ?>
                                -
                            <?php endif; ?>
                        </td>
                    </tr>

                    <!-- Manual Push -->
                    <tr style="border-top: 2px solid #ddd;">
                        <td><strong>GitHub Push</strong></td>
                        <td>
                            <?php if ($last_manual_push): ?>
                                <span class="dashicons dashicons-clock" style="color: #2271b1;"></span>
                                <?php echo esc_html(human_time_diff($last_manual_push['timestamp'], current_time('timestamp'))); ?> ago
                                <br><small><?php echo esc_html(date('Y-m-d H:i:s', $last_manual_push['timestamp'])); ?></small>
                            <?php else: ?>
                                <span style="color: #999;">Never</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <span class="dashicons dashicons-admin-users"></span> Manual
                        </td>
                        <td>
                            <?php if ($last_manual_push): ?>
                                <?php if ($last_manual_push['status'] === 'success'): ?>
                                    <span class="dashicons dashicons-yes" style="color: #46b450;"></span>
                                    <strong>Success</strong>
                                <?php else: ?>
                                    <span class="dashicons dashicons-warning" style="color: #d63638;"></span>
                                    <strong>No changes</strong>
                                <?php endif; ?>
                                <?php if (!empty($last_manual_push['files_count'])): ?>
                                    - <?php echo absint($last_manual_push['files_count']); ?> files
                                <?php endif; ?>
                                <?php if (!empty($last_manual_push['commit'])): ?>
                                    <br><small style="color: #666;">Commit: <?php echo esc_html(substr($last_manual_push['commit'], 0, 8)); ?></small>
                                <?php endif; ?>
                            <?php else: ?>
                                -
                            <?php endif; ?>
                        </td>
                    </tr>

                    <!-- Auto Push -->
                    <tr>
                        <td></td>
                        <td>
                            <?php if ($last_auto_push): ?>
                                <span class="dashicons dashicons-clock" style="color: #72aee6;"></span>
                                <?php echo esc_html(human_time_diff($last_auto_push['timestamp'], current_time('timestamp'))); ?> ago
                                <br><small><?php echo esc_html(date('Y-m-d H:i:s', $last_auto_push['timestamp'])); ?></small>
                            <?php else: ?>
                                <span style="color: #999;">Never</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <span class="dashicons dashicons-update-alt"></span> Auto
                        </td>
                        <td>
                            <?php if ($last_auto_push): ?>
                                <?php if ($last_auto_push['status'] === 'success'): ?>
                                    <span class="dashicons dashicons-yes" style="color: #46b450;"></span>
                                    <strong>Success</strong>
                                <?php else: ?>
                                    <span class="dashicons dashicons-warning" style="color: #d63638;"></span>
                                    <strong>No changes</strong>
                                <?php endif; ?>
                                <?php if (!empty($last_auto_push['files_count'])): ?>
                                    - <?php echo absint($last_auto_push['files_count']); ?> files
                                <?php endif; ?>
                            <?php else: ?>
                                -
                            <?php endif; ?>
                        </td>
                    </tr>
                </tbody>
            </table>

            <?php if (!$last_manual_export && !$last_auto_export && !$last_manual_push && !$last_auto_push): ?>
                <p style="text-align: center; color: #999; padding: 20px;">
                    <span class="dashicons dashicons-info" style="font-size: 20px;"></span><br>
                    No activity yet. Click "Export Static Site" to get started!
                </p>
            <?php endif; ?>
        </div>

        <style>
            .activity-status-dashboard {
                background: #fff;
                border: 1px solid #c3c4c7;
                border-radius: 4px;
                margin-bottom: 20px;
            }
            .activity-status-dashboard table {
                margin: 0;
            }
            .activity-status-dashboard td {
                padding: 12px 10px;
            }
            .activity-status-dashboard .dashicons {
                vertical-align: middle;
            }
        </style>
        <?php
        return ob_get_clean();
    }

    /**
     * Track export activity for status dashboard
     */
    private function track_export_activity($stats, $is_auto = false) {
        $option_name = $is_auto ? 'static_exporter_last_auto_export' : 'static_exporter_last_manual_export';

        // Determine export type
        $export_type = 'incremental';
        $items_count = isset($stats['pages']) ? $stats['pages'] + $stats['posts'] : 0;

        // Get list of exported items (titles)
        $exported_items = array();
        if (isset($stats['exported_posts'])) {
            foreach ($stats['exported_posts'] as $post_id) {
                $exported_items[] = get_the_title($post_id);
            }
        }

        $activity_data = array(
            'timestamp' => current_time('timestamp'),
            'type' => $export_type,
            'items_count' => $items_count,
            'items' => $exported_items,
            'trigger' => $is_auto ? ($_POST['trigger'] ?? 'auto') : 'manual'
        );

        update_option($option_name, $activity_data);
    }

    /**
     * Track push activity for status dashboard
     */
    private function track_push_activity($result, $is_auto = false) {
        $option_name = $is_auto ? 'static_exporter_last_auto_push' : 'static_exporter_last_manual_push';

        $activity_data = array(
            'timestamp' => current_time('timestamp'),
            'status' => $result['status'] ?? 'success',
            'files_count' => $result['files_count'] ?? null,
            'commit' => $result['commit_sha'] ?? null
        );

        update_option($option_name, $activity_data);
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

            // Track export activity
            $this->track_export_activity($validation['stats']);

            wp_send_json_success(array(
                'message' => 'Export completed',
                'stats' => $validation['stats']
            ));
        } catch (Exception $e) {
            $this->log('Error: ' . $e->getMessage());
            wp_send_json_error(esc_html($e->getMessage()));
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
            $available_mb = absint(round($available_space / 1024 / 1024));
            $required_mb = absint(round(self::MAX_EXPORT_SIZE / 1024 / 1024));
            throw new Exception(sprintf(
                'Insufficient disk space. Available: %sMB, Required: ~%sMB',
                $available_mb,
                $required_mb
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
        // PERMANENT TIMEOUT FIX: Set unlimited execution time
        @ini_set('max_execution_time', '0');
        @set_time_limit(0); // Unlimited
        @ignore_user_abort(true); // Continue even if user closes browser

        // Increase memory limit more aggressively
        @ini_set('memory_limit', '768M'); // Increased from 512M

        // Check for concurrent exports using transient lock
        $lock = get_transient('static_export_in_progress');
        if ($lock) {
            throw new Exception('An export is already in progress. Please wait for it to complete.');
        }

        // Set lock (increased to 2 hours)
        set_transient('static_export_in_progress', true, 7200); // 2 hours max

        try {
            $this->log('Starting incremental static site export...');

            // Get last export time
            $last_export_time = get_option('static_export_last_time', 0);
            $is_first_export = ($last_export_time == 0);

            if ($is_first_export) {
                $this->log('First export detected - exporting all content');
                // Clean directories on first export
                $this->clean_directory($this->export_dir);
                $this->clean_directory($this->temp_dir);
            } else {
                $last_export_date = date('Y-m-d H:i:s', $last_export_time);
                $this->log("Incremental export - checking changes since $last_export_date");
            }

            wp_mkdir_p($this->export_dir);
            wp_mkdir_p($this->temp_dir);

            // Close session to prevent blocking when fetching pages
            // This allows wp_remote_get() to fetch pages from the same site
            if (session_id()) {
                session_write_close();
            }

            $exported_count = 0;

            // Always export homepage (it may reflect recent changes)
            $this->export_page(home_url('/'), 'index.html');
            $exported_count++;

            // Get changed pages
            $pages_args = array(
                'post_type' => 'page',
                'post_status' => 'publish',
                'posts_per_page' => -1
            );

            if (!$is_first_export) {
                $pages_args['date_query'] = array(
                    array(
                        'column' => 'post_modified',
                        'after' => date('Y-m-d H:i:s', $last_export_time),
                        'inclusive' => false
                    )
                );
            }

            $pages = get_posts($pages_args);
            $this->log('Found ' . count($pages) . ' ' . ($is_first_export ? '' : 'changed ') . 'pages to export');

            // Get URL structure preference
            $url_structure = get_option('static_exporter_settings', array())['url_structure'] ?? 'directory';

            foreach ($pages as $page) {
                $url = get_permalink($page->ID);
                $path = parse_url($url, PHP_URL_PATH);

                if ($url_structure === 'flat') {
                    // Flat structure: /about.html
                    $filename = trim($path, '/') . '.html';
                } else {
                    // Directory structure: /about/index.html
                    $filename = trim($path, '/') . '/index.html';
                }

                $this->export_page($url, $filename);
                $exported_count++;
            }

            // Get changed posts (paginated with SMALLER batches to avoid memory issues)
            $posts_per_page = self::MAX_POSTS_PER_BATCH; // Now 50 instead of 100
            $offset = 0;
            $total_posts = 0;

            while (true) {
                // Reset time limit for each batch
                @set_time_limit(180);

                $posts_args = array(
                    'post_type' => 'post',
                    'post_status' => 'publish',
                    'numberposts' => $posts_per_page,
                    'offset' => $offset,
                    'orderby' => 'ID',
                    'order' => 'ASC'
                );

                if (!$is_first_export) {
                    $posts_args['date_query'] = array(
                        array(
                            'column' => 'post_modified',
                            'after' => date('Y-m-d H:i:s', $last_export_time),
                            'inclusive' => false
                        )
                    );
                }

                $posts = get_posts($posts_args);

                if (empty($posts)) {
                    break;
                }

                $total_posts += count($posts);
                if ($total_posts == count($posts)) {
                    // First batch
                    $this->log('Found ' . $total_posts . ($is_first_export ? '' : ' changed') . ' posts to export...');
                }

                foreach ($posts as $post) {
                    try {
                        $url = get_permalink($post->ID);
                        $path = parse_url($url, PHP_URL_PATH);

                        if ($url_structure === 'flat') {
                            // Flat structure: /post-name.html
                            $filename = trim($path, '/') . '.html';
                        } else {
                            // Directory structure: /post-name/index.html
                            $filename = trim($path, '/') . '/index.html';
                        }

                        $this->export_page($url, $filename);
                        $exported_count++;
                    } catch (Exception $e) {
                        $this->log("⚠ Skipped post {$post->ID}: " . $e->getMessage());
                        // Continue with other posts
                    }

                    // Periodic garbage collection
                    if ($exported_count % 20 === 0) {
                        gc_collect_cycles();
                    }
                }

                $offset += $posts_per_page;

                // Longer delay between batches to prevent timeouts
                if (!empty($posts) && count($posts) >= $posts_per_page) {
                    $this->log("Batch complete, short pause before next batch...");
                    sleep(2); // 2 second pause
                }

                // Break if we got fewer posts than requested (end of list)
                if (count($posts) < $posts_per_page) {
                    break;
                }
            }

            $this->log('Exported ' . $total_posts . ' posts total');

            // Copy assets (only if first export or if assets have changed)
            if ($is_first_export) {
                $this->log('Copying all assets...');
                $this->copy_assets();
            } else {
                $this->log('Checking for changed assets...');
                $this->copy_changed_assets($last_export_time);
            }

            // Create config files
            $this->create_config_files();

            // Retry any failed exports
            $this->retry_failed_exports();

            // Update last export time
            update_option('static_export_last_time', time());

            $this->log("✓ Export completed! Exported $exported_count files.");

        } finally {
            // Always release lock, even if export fails
            delete_transient('static_export_in_progress');
        }
    }

    private function export_page($url, $filename) {
        $max_attempts = 3;
        $last_error = null;

        for ($attempt = 1; $attempt <= $max_attempts; $attempt++) {
            try {
                // Reset time limit for each page
                @set_time_limit(120);

                if ($attempt > 1) {
                    $this->log("Retrying $url (attempt $attempt/$max_attempts)");
                    sleep(2 * $attempt); // Exponential backoff
                }

                $this->log("Exporting: $url" . ($attempt > 1 ? " (retry)" : ""));

                $response = wp_remote_get($url, array(
                    'timeout' => self::PAGE_TIMEOUT,
                    'sslverify' => true,
                    'httpversion' => '1.1',
                    'redirection' => 5,
                    'blocking' => true
                ));

                if (is_wp_error($response)) {
                    $last_error = $response->get_error_message();

                    // If it's a timeout, retry
                    if (strpos($last_error, 'timed out') !== false ||
                        strpos($last_error, 'timeout') !== false) {
                        if ($attempt < $max_attempts) {
                            continue; // Retry
                        }
                    }

                    throw new Exception("Failed to fetch " . esc_url($url) . ": " . esc_html($last_error));
                }

                $code = wp_remote_retrieve_response_code($response);
                if ($code !== 200) {
                    throw new Exception("Failed to fetch " . esc_url($url) . ": HTTP " . absint($code));
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
                    throw new Exception("Failed to write file: " . esc_html($filepath));
                }

                // Success - save checkpoint
                $this->update_export_checkpoint($filename);

                // Small delay to prevent server overload
                if (self::EXPORT_PAGE_DELAY > 0) {
                    usleep(self::EXPORT_PAGE_DELAY * 1000000); // Convert to microseconds
                }

                return; // Success!

            } catch (Exception $e) {
                $last_error = $e->getMessage();

                if ($attempt >= $max_attempts) {
                    // Log failure but don't stop entire export
                    $this->log("⚠ Failed to export $url after $max_attempts attempts: " . $last_error);
                    $this->add_failed_export($url, $filename, $last_error);
                    return; // Continue with other exports
                }
            }
        }
    }

    /**
     * Update export checkpoint for resumability
     */
    private function update_export_checkpoint($filename) {
        $checkpoints = get_transient('static_export_checkpoints') ?: array();
        $checkpoints[] = $filename;
        set_transient('static_export_checkpoints', $checkpoints, 7200);
    }

    /**
     * Track failed exports for retry later
     */
    private function add_failed_export($url, $filename, $error) {
        $failed = get_transient('static_export_failed') ?: array();
        $failed[] = array(
            'url' => $url,
            'filename' => $filename,
            'error' => $error,
            'time' => time()
        );
        set_transient('static_export_failed', $failed, 7200);
    }

    /**
     * Get list of failed exports
     */
    private function get_failed_exports() {
        return get_transient('static_export_failed') ?: array();
    }

    /**
     * Retry failed exports (call at end of export)
     */
    private function retry_failed_exports() {
        $failed = $this->get_failed_exports();

        if (empty($failed)) {
            return;
        }

        $this->log("Retrying " . count($failed) . " failed exports...");
        $retry_success = 0;
        $retry_failed = 0;

        foreach ($failed as $export) {
            try {
                $this->export_page($export['url'], $export['filename']);
                $retry_success++;
            } catch (Exception $e) {
                $retry_failed++;
                $this->log("Retry failed for {$export['url']}: " . $e->getMessage());
            }
        }

        $this->log("Retry complete: $retry_success succeeded, $retry_failed failed");
        delete_transient('static_export_failed');
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
     * Copy only changed assets since last export (v2.3.0)
     * Dramatically speeds up incremental exports
     */
    private function copy_changed_assets($last_export_time) {
        $copied_count = 0;

        // Check uploads for changed files
        $uploads_dir = wp_upload_dir();
        $source = $uploads_dir['basedir'];
        $dest = $this->export_dir . '/wp-content/uploads';

        if (file_exists($source)) {
            $copied_count += $this->recursive_copy_changed($source, $dest, $last_export_time, array(
                'simply-static',
                'static-export',
                'static-export-temp'
            ));
        }

        // Theme assets - only copy if theme files changed
        $theme_dir = get_stylesheet_directory();
        $theme_dest = $this->export_dir . '/wp-content/themes/' . get_stylesheet();
        $copied_count += $this->recursive_copy_changed($theme_dir, $theme_dest, $last_export_time);

        if ($copied_count > 0) {
            $this->log("Copied $copied_count changed asset files");
        } else {
            $this->log('No asset changes detected');
        }
    }

    /**
     * Recursively copy only files modified after a given timestamp
     */
    private function recursive_copy_changed($src, $dst, $since_time, $exclusions = array()) {
        if (!file_exists($src)) return 0;

        $copied = 0;
        $dir = opendir($src);
        @wp_mkdir_p($dst);

        while (false !== ($file = readdir($dir))) {
            if ($file == '.' || $file == '..') continue;

            // Check exclusions
            $skip = false;
            foreach ($exclusions as $exclusion) {
                if (stripos($file, $exclusion) !== false) {
                    $skip = true;
                    break;
                }
            }
            if ($skip) continue;

            $src_file = $src . '/' . $file;
            $dst_file = $dst . '/' . $file;

            if (is_dir($src_file)) {
                $copied += $this->recursive_copy_changed($src_file, $dst_file, $since_time, $exclusions);
            } else {
                // Only copy if file is newer than last export
                $file_mtime = filemtime($src_file);
                if ($file_mtime > $since_time) {
                    copy($src_file, $dst_file);
                    $copied++;
                }
            }
        }

        closedir($dir);
        return $copied;
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

            // Get all local files
            $all_files = $this->get_all_files_efficient($this->export_dir);
            $this->log("Scanning " . count($all_files) . " local files...");

            // Get existing GitHub tree to compare hashes (v2.3.0 - incremental push)
            $this->log("Fetching existing GitHub tree for comparison...");
            $existing_tree = $this->get_github_tree($token, $repo, $current_commit_sha);

            // Compare and filter to only changed files
            $files = $this->filter_changed_files($all_files, $existing_tree);
            $total_files = count($files);

            if ($total_files == 0) {
                $this->log("✓ No file changes detected - nothing to push!");
                wp_send_json_success(array(
                    'total_files' => 0,
                    'total_chunks' => 0,
                    'message' => 'No changes to push'
                ));
                return;
            }

            $this->log("Found $total_files changed files to upload (skipped " . (count($all_files) - $total_files) . " unchanged)");

            if (count($all_files) >= self::GITHUB_TREE_LIMIT) {
                throw new Exception(sprintf(
                    'Site has %d files, exceeds GitHub limit of %d',
                    count($all_files),
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
            wp_send_json_error(esc_html($e->getMessage()));
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

        // PERMANENT FIX: Set unlimited time for chunk processing
        @set_time_limit(0);
        @ignore_user_abort(true);
        @ini_set('max_execution_time', '0');

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
                    throw new Exception("Failed to create blob for " . esc_html($relative_path));
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
            wp_send_json_error(esc_html($e->getMessage()));
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
            wp_send_json_error(esc_html($e->getMessage()));
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

        $max_retries = absint(self::MAX_RETRIES);
        throw new Exception(esc_html($context) . " failed after " . $max_retries . " attempts: " . esc_html($last_error));
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
            throw new Exception('Invalid GitHub token (code ' . absint($user_code) . '). Please check your token and try again.');
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
            throw new Exception('GitHub API error: ' . esc_html($error_msg));
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
                        throw new Exception("Failed to create blob: " . esc_html($blob_error) . " (code " . absint($blob_code) . ")");
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
            throw new Exception('Failed to create tree: ' . esc_html($tree_error) . ' (code ' . absint($tree_code) . ')');
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
            throw new Exception('Failed to create commit: ' . esc_html($commit_error) . ' (code ' . absint($commit_code) . ')');
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
            throw new Exception('Failed to update branch: ' . esc_html($update_error) . ' (code ' . absint($update_code) . ')');
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
            wp_send_json_error(esc_html($e->getMessage()));
        }
    }

    /**
     * Repair Git repository if .git directory is missing
     */
    private function repair_git_repository($git_dir) {
        $this->log('Repairing Git repository...');

        // Get GitHub settings
        $settings = get_option('static_exporter_settings', array());
        $github_repo = isset($settings['github_repo']) ? $settings['github_repo'] : '';

        if (empty($github_repo)) {
            throw new Exception('Cannot repair repository: GitHub repository not configured in settings');
        }

        $remote_url = 'https://github.com/' . $github_repo . '.git';

        // Initialize Git repository using full path to git
        $git_bin = '/usr/local/bin/git';
        $commands = array(
            'cd ' . escapeshellarg($git_dir),
            $git_bin . ' init',
            $git_bin . ' remote add origin ' . escapeshellarg($remote_url),
            $git_bin . ' branch -M main',
            $git_bin . ' fetch origin main 2>&1',
            $git_bin . ' reset --hard origin/main 2>&1'
        );

        $full_command = implode(' && ', $commands);
        exec($full_command, $output, $return_code);

        if ($return_code !== 0) {
            $this->log('Git repair failed: ' . implode("\n", $output));
            throw new Exception('Failed to repair Git repository. Please check your GitHub settings.');
        }

        $this->log('✓ Git repository repaired successfully');
    }

    /**
     * AJAX handler for Git deployment via shell commands
     */
    public function ajax_git_deploy() {
        check_ajax_referer('static_exporter_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized');
        }

        $this->log('Starting Git deployment...');

        try {
            // Change to Git repository directory
            $git_dir = $this->export_dir;

            if (!file_exists($git_dir . '/.git')) {
                $this->log('Git repository not found. Attempting to repair...', 'warning');
                $this->repair_git_repository($git_dir);
            }

            // Execute git commands using full path to git
            $git_bin = '/usr/local/bin/git';
            $commands = array(
                'cd ' . escapeshellarg($git_dir),
                $git_bin . ' add -A',
                $git_bin . ' commit -m "Updated static site - ' . date('Y-m-d H:i') . '"',
                $git_bin . ' push origin main'
            );

            $full_command = implode(' && ', $commands) . ' 2>&1';
            $this->log('Executing: git add, commit, and push...');

            exec($full_command, $output, $return_code);

            $output_text = implode("\n", $output);

            // Always log the Git output for debugging
            $this->log('Git output: ' . $output_text);

            // Check if there were no changes to commit
            if (strpos($output_text, 'nothing to commit') !== false) {
                $this->log('✓ No changes to deploy (already up to date)');

                // Track as "no changes" push
                $this->track_push_activity(array(
                    'status' => 'no_changes'
                ));

                wp_send_json_success(array(
                    'message' => 'No changes to deploy - site is already up to date',
                    'output' => $output_text
                ));
                return;
            }

            if ($return_code !== 0) {
                // Check if it's just a "no changes" situation
                if (strpos($output_text, 'nothing to commit') === false &&
                    strpos($output_text, 'Already up to date') === false) {
                    $this->log('Git command failed with return code: ' . $return_code, 'error');
                    $this->log('Full output: ' . $output_text, 'error');
                    throw new Exception('Git command failed (code ' . absint($return_code) . '). Check log for details.');
                }
            }

            // Extract commit SHA from git output
            $commit_sha = null;
            if (preg_match('/\[main ([a-f0-9]+)\]/', $output_text, $matches)) {
                $commit_sha = $matches[1];
            }

            // Count changed files
            $files_count = null;
            if (preg_match('/(\d+) files? changed/', $output_text, $matches)) {
                $files_count = (int)$matches[1];
            } else if (preg_match('/(\d+) insertion/', $output_text, $matches)) {
                $files_count = 1; // At least one file if there are insertions
            }

            $this->log('✓ Successfully pushed to GitHub!');
            $this->log('Kinsta will automatically deploy in 2-5 minutes');

            // Track successful push
            $this->track_push_activity(array(
                'status' => 'success',
                'commit_sha' => $commit_sha,
                'files_count' => $files_count
            ));

            wp_send_json_success(array(
                'message' => 'Successfully deployed to GitHub! Kinsta will auto-deploy shortly.',
                'output' => $output_text
            ));

        } catch (Exception $e) {
            $this->log('Error: ' . $e->getMessage());
            wp_send_json_error(esc_html($e->getMessage()));
        }
    }

    public function ajax_reset_export_lock() {
        check_ajax_referer('static_exporter_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized');
        }

        try {
            // Clear export lock
            delete_transient('static_export_in_progress');

            // Clear push state
            delete_transient('github_push_state');

            // Optionally reset last export time (commented out by default)
            // delete_option('static_export_last_time');

            wp_send_json_success(array(
                'message' => 'Export lock cleared successfully. You can now start a new export.'
            ));

        } catch (Exception $e) {
            wp_send_json_error(esc_html($e->getMessage()));
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
            throw new Exception("Kinsta deployment failed: " . esc_html($error) . " (code " . absint($code) . ")");
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

    /**
     * Get GitHub tree for comparison (v2.3.0)
     * Returns array of path => sha mappings
     */
    private function get_github_tree($token, $repo, $commit_sha) {
        try {
            // Get commit to find tree SHA
            $commit_response = $this->api_request_with_retry(
                "https://api.github.com/repos/$repo/git/commits/$commit_sha",
                array(
                    'headers' => array(
                        'Authorization' => 'Bearer ' . $token,
                        'User-Agent' => 'WordPress-Static-Exporter',
                        'Accept' => 'application/vnd.github.v3+json'
                    ),
                    'timeout' => self::API_TIMEOUT,
                    'sslverify' => true
                ),
                'Get commit tree'
            );

            $commit_data = json_decode(wp_remote_retrieve_body($commit_response), true);
            $tree_sha = $commit_data['tree']['sha'] ?? null;

            if (!$tree_sha) {
                $this->log('Warning: Could not get tree SHA, will push all files');
                return array();
            }

            // Get full tree recursively
            $tree_response = $this->api_request_with_retry(
                "https://api.github.com/repos/$repo/git/trees/$tree_sha?recursive=1",
                array(
                    'headers' => array(
                        'Authorization' => 'Bearer ' . $token,
                        'User-Agent' => 'WordPress-Static-Exporter',
                        'Accept' => 'application/vnd.github.v3+json'
                    ),
                    'timeout' => self::API_TIMEOUT,
                    'sslverify' => true
                ),
                'Get repository tree'
            );

            $tree_data = json_decode(wp_remote_retrieve_body($tree_response), true);
            $tree = array();

            if (isset($tree_data['tree']) && is_array($tree_data['tree'])) {
                foreach ($tree_data['tree'] as $item) {
                    if ($item['type'] === 'blob') {
                        $tree[$item['path']] = $item['sha'];
                    }
                }
                $this->log('Retrieved ' . count($tree) . ' files from GitHub tree');
            }

            return $tree;

        } catch (Exception $e) {
            $this->log('Warning: Could not fetch GitHub tree: ' . $e->getMessage());
            $this->log('Will push all files as fallback');
            return array();
        }
    }

    /**
     * Filter files to only those that changed (v2.3.0)
     * Compares local file SHA with GitHub tree SHA
     */
    private function filter_changed_files($local_files, $github_tree) {
        // If no GitHub tree available, return all files
        if (empty($github_tree)) {
            return $local_files;
        }

        $changed_files = array();

        foreach ($local_files as $file) {
            $relative_path = str_replace($this->export_dir . '/', '', $file);
            $relative_path = str_replace('\\', '/', $relative_path);

            // Calculate local file SHA (same way GitHub does it)
            $content = file_get_contents($file);
            $local_sha = sha1('blob ' . strlen($content) . "\0" . $content);

            // Compare with GitHub SHA
            $github_sha = $github_tree[$relative_path] ?? null;

            if ($github_sha !== $local_sha) {
                // File is new or changed
                $changed_files[] = $file;
            }
        }

        return $changed_files;
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

            // Sanitize export directory path
            if (!empty($input['export_directory'])) {
                $sanitized['export_directory'] = sanitize_text_field($input['export_directory']);
            }

            // Validate and encrypt Kinsta API key if provided
            if (!empty($input['kinsta_api_key'])) {
                $sanitized['kinsta_api_key_encrypted'] = $wp_static_exporter->encrypt($input['kinsta_api_key']);
            } else {
                $existing = get_option('static_exporter_settings', array());
                $sanitized['kinsta_api_key_encrypted'] = $existing['kinsta_api_key_encrypted'] ?? '';
            }

            // Sanitize Kinsta site ID
            $sanitized['kinsta_site_id'] = sanitize_text_field($input['kinsta_site_id'] ?? '');

            // Sanitize URL structure
            $sanitized['url_structure'] = in_array($input['url_structure'] ?? 'directory', array('directory', 'flat'))
                ? $input['url_structure']
                : 'directory';

            // Checkboxes
            $sanitized['auto_export'] = !empty($input['auto_export']) ? 1 : 0;
            $sanitized['auto_deploy'] = !empty($input['auto_deploy']) ? 1 : 0;

            return $sanitized;
        }
    ));
});

// Register cron hook for auto-export
add_action('static_exporter_auto_export', array($wp_static_exporter, 'perform_auto_export'));
