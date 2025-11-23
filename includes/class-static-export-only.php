<?php
/**
 * Static Export Only - ZIP Download
 *
 * Exports WordPress site to static HTML as a ZIP download.
 * No deployment, no Git - just a clean static export.
 *
 * Uses batched processing to handle large sites without timeout.
 *
 * @package StaticSiteExporter
 * @since 2.6.0
 */

if (!defined('ABSPATH')) exit;

class Static_Export_Only {

    /** @var string Base URL for the site */
    private $base_url;

    /** @var string Default base URL */
    private $default_base_url = 'https://diggingthedigital.com';

    /** @var string Temporary directory for export */
    private $temp_dir;

    /** @var int Batch size for processing */
    private $batch_size = 25;

    /** @var array Export statistics */
    private $stats = [
        'pages' => 0,
        'posts' => 0,
        'files' => 0,
        'size' => 0
    ];

    /** @var array Log messages */
    private $log = [];

    /**
     * Constructor - register hooks
     */
    public function __construct() {
        // Get base URL from settings or use default
        $this->base_url = get_option('export_only_base_url', $this->default_base_url);

        // Batched export handlers
        add_action('wp_ajax_export_only_init', [$this, 'ajax_export_init']);
        add_action('wp_ajax_export_only_batch', [$this, 'ajax_export_batch']);
        add_action('wp_ajax_export_only_finalize', [$this, 'ajax_export_finalize']);

        // Other handlers
        add_action('wp_ajax_export_only_download', [$this, 'ajax_download_zip']);
        add_action('wp_ajax_export_only_status', [$this, 'ajax_get_status']);
        add_action('wp_ajax_export_only_cleanup', [$this, 'ajax_cleanup']);
        add_action('wp_ajax_export_only_save_settings', [$this, 'ajax_save_settings']);
    }

    /**
     * Render the Export Only tab content
     */
    public function render_tab() {
        $site_url = get_site_url();
        ?>
        <div class="export-only-container">
            <div class="export-only-header">
                <h2>Export Only (ZIP Download)</h2>
                <p class="description">
                    Export je complete WordPress site als statische HTML bestanden in een ZIP archief.
                    <br><strong>Let op:</strong> Afbeeldingen worden niet meegenomen - de paden worden relatief gemaakt
                    zodat ze werken met je lokale <code>/wp-content/uploads/</code> folder.
                </p>
            </div>

            <!-- Settings Section -->
            <div class="export-only-settings" style="margin-bottom: 25px; padding: 15px; background: #f8f9fa; border: 1px solid #e2e4e7; border-radius: 4px;">
                <h3 style="margin: 0 0 15px 0; font-size: 14px;">Instellingen</h3>
                <table class="form-table" style="margin: 0;">
                    <tr>
                        <th scope="row" style="padding: 10px 10px 10px 0; width: 150px;">
                            <label for="export-only-base-url">Base URL</label>
                        </th>
                        <td style="padding: 10px 0;">
                            <input type="url" id="export-only-base-url" class="regular-text"
                                   value="<?php echo esc_attr($this->base_url); ?>"
                                   placeholder="https://example.com"
                                   style="width: 350px;">
                            <button type="button" id="export-only-save-settings" class="button" style="margin-left: 10px;">
                                Opslaan
                            </button>
                            <span id="export-only-save-status" style="margin-left: 10px; display: none;"></span>
                            <p class="description" style="margin-top: 8px;">
                                De productie URL voor je statische site. Wordt gebruikt in sitemap.xml, manifest.json, etc.
                                <br><small>Huidige WordPress URL: <code><?php echo esc_html($site_url); ?></code></small>
                            </p>
                        </td>
                    </tr>
                </table>
            </div>

            <div class="export-only-info">
                <table class="widefat striped">
                    <tbody>
                        <tr>
                            <td><strong>Base URL</strong></td>
                            <td><code id="display-base-url"><?php echo esc_html($this->base_url); ?></code></td>
                        </tr>
                        <tr>
                            <td><strong>Wat wordt geexporteerd</strong></td>
                            <td>
                                Alle gepubliceerde pagina's en posts<br>
                                <small class="text-muted">Inclusief: statische reacties, canonical URLs</small>
                            </td>
                        </tr>
                        <tr>
                            <td><strong>Wat wordt verwijderd</strong></td>
                            <td>
                                Zoekformulieren, reactieformulieren, login elementen
                            </td>
                        </tr>
                        <tr>
                            <td><strong>Optimalisaties</strong></td>
                            <td>
                                HTML minificatie, lazy loading, preload hints
                            </td>
                        </tr>
                        <tr>
                            <td><strong>Extra bestanden</strong></td>
                            <td>
                                sitemap.xml, robots.txt, manifest.json, service worker, 404.html, offline.html
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <div class="export-only-actions">
                <button id="export-only-start" class="button button-primary button-hero">
                    <span class="dashicons dashicons-download"></span>
                    Genereer ZIP Export
                </button>

                <button id="export-only-download" class="button button-hero" style="display:none;">
                    <span class="dashicons dashicons-media-archive"></span>
                    Download ZIP
                </button>
            </div>

            <div id="export-only-progress" class="export-only-progress" style="display:none;">
                <div class="progress-wrapper">
                    <div class="progress-bar-container">
                        <div class="progress-bar" style="width: 0%;"></div>
                    </div>
                    <span class="progress-text">Voorbereiden...</span>
                </div>
            </div>

            <div id="export-only-log" class="export-only-log"></div>

            <div id="export-only-stats" class="export-only-stats" style="display:none;">
                <h3>Export Statistieken</h3>
                <div class="stats-grid">
                    <div class="stat-item">
                        <span class="stat-value" id="stat-pages">0</span>
                        <span class="stat-label">Pagina's</span>
                    </div>
                    <div class="stat-item">
                        <span class="stat-value" id="stat-posts">0</span>
                        <span class="stat-label">Posts</span>
                    </div>
                    <div class="stat-item">
                        <span class="stat-value" id="stat-files">0</span>
                        <span class="stat-label">Bestanden</span>
                    </div>
                    <div class="stat-item">
                        <span class="stat-value" id="stat-size">0 KB</span>
                        <span class="stat-label">Grootte</span>
                    </div>
                </div>
            </div>
        </div>
        <?php
    }

    /**
     * AJAX handler: Initialize export - count items and prepare batches
     */
    public function ajax_export_init() {
        check_ajax_referer('static_exporter_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized');
        }

        // Clean up any previous export
        $this->cleanup_temp_files();

        // Set up temp directory
        $this->temp_dir = WP_CONTENT_DIR . '/static-export-zip-' . time();
        update_option('export_only_temp_dir', $this->temp_dir);

        // Create temp directory
        if (!wp_mkdir_p($this->temp_dir)) {
            wp_send_json_error('Kon tijdelijke directory niet aanmaken');
        }

        // Count pages and posts
        $pages = get_posts([
            'post_type' => 'page',
            'post_status' => 'publish',
            'posts_per_page' => -1,
            'fields' => 'ids'
        ]);

        $posts = get_posts([
            'post_type' => 'post',
            'post_status' => 'publish',
            'posts_per_page' => -1,
            'fields' => 'ids'
        ]);

        $total_pages = count($pages);
        $total_posts = count($posts);
        $total_items = $total_pages + $total_posts + 1; // +1 for homepage

        // Calculate batches
        $total_batches = ceil($total_items / $this->batch_size);

        // Store export state
        $export_state = [
            'temp_dir' => $this->temp_dir,
            'page_ids' => $pages,
            'post_ids' => $posts,
            'total_pages' => $total_pages,
            'total_posts' => $total_posts,
            'total_items' => $total_items,
            'total_batches' => max(1, $total_batches),
            'current_batch' => 0,
            'processed' => 0,
            'stats' => [
                'pages' => 0,
                'posts' => 0,
                'files' => 0,
                'size' => 0
            ]
        ];

        update_option('export_only_state', $export_state);

        wp_send_json_success([
            'message' => 'Export geinitialiseerd',
            'total_pages' => $total_pages,
            'total_posts' => $total_posts,
            'total_items' => $total_items,
            'total_batches' => $export_state['total_batches'],
            'batch_size' => $this->batch_size
        ]);
    }

    /**
     * AJAX handler: Process a batch of items
     */
    public function ajax_export_batch() {
        check_ajax_referer('static_exporter_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized');
        }

        // Get export state
        $state = get_option('export_only_state');
        if (!$state) {
            wp_send_json_error('Export niet geinitialiseerd. Start opnieuw.');
        }

        $this->temp_dir = $state['temp_dir'];
        $batch_num = isset($_POST['batch']) ? intval($_POST['batch']) : 0;

        // Calculate which items to process in this batch
        $start_index = $batch_num * $this->batch_size;
        $items_to_process = [];
        $log_messages = [];

        // First, handle homepage in batch 0
        if ($batch_num === 0) {
            $homepage_result = $this->export_homepage();
            if ($homepage_result) {
                $log_messages[] = 'Homepage geexporteerd';
            }
            $start_index = 0; // Start processing pages/posts from 0
        }

        // Combine page and post IDs
        $all_ids = array_merge(
            array_map(function($id) { return ['type' => 'page', 'id' => $id]; }, $state['page_ids']),
            array_map(function($id) { return ['type' => 'post', 'id' => $id]; }, $state['post_ids'])
        );

        // Adjust start index for homepage
        $adjusted_start = ($batch_num === 0) ? 0 : ($start_index - 1);
        $items_in_batch = array_slice($all_ids, $adjusted_start, $this->batch_size);

        $pages_processed = 0;
        $posts_processed = 0;

        foreach ($items_in_batch as $item) {
            $post = get_post($item['id']);
            if (!$post) continue;

            $result = $this->export_single_content($post);

            if ($item['type'] === 'page') {
                $pages_processed++;
                $state['stats']['pages']++;
            } else {
                $posts_processed++;
                $state['stats']['posts']++;
            }
        }

        if ($pages_processed > 0) {
            $log_messages[] = "{$pages_processed} pagina's geexporteerd";
        }
        if ($posts_processed > 0) {
            $log_messages[] = "{$posts_processed} posts geexporteerd";
        }

        // Update state
        $state['current_batch'] = $batch_num + 1;
        $state['processed'] += count($items_in_batch) + ($batch_num === 0 ? 1 : 0);
        update_option('export_only_state', $state);

        // Check if more batches needed
        $all_done = $state['current_batch'] >= $state['total_batches'];

        wp_send_json_success([
            'batch' => $batch_num,
            'processed' => $state['processed'],
            'total' => $state['total_items'],
            'done' => $all_done,
            'next_batch' => $all_done ? null : $batch_num + 1,
            'log' => $log_messages,
            'stats' => $state['stats']
        ]);
    }

    /**
     * AJAX handler: Finalize export - generate extra files and create ZIP
     */
    public function ajax_export_finalize() {
        check_ajax_referer('static_exporter_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized');
        }

        // Get export state
        $state = get_option('export_only_state');
        if (!$state) {
            wp_send_json_error('Export niet geinitialiseerd. Start opnieuw.');
        }

        $this->temp_dir = $state['temp_dir'];
        $this->stats = $state['stats'];
        $log_messages = [];

        try {
            // Generate extra files
            $log_messages[] = 'Genereren van extra bestanden...';

            $this->generate_sitemap();
            $log_messages[] = '  sitemap.xml gegenereerd';

            $this->generate_robots();
            $log_messages[] = '  robots.txt gegenereerd';

            $this->generate_manifest();
            $log_messages[] = '  manifest.json gegenereerd';

            $this->generate_service_worker();
            $log_messages[] = '  sw.js (service worker) gegenereerd';

            $this->generate_offline_page();
            $log_messages[] = '  offline.html gegenereerd';

            $this->generate_404_page();
            $log_messages[] = '  404.html gegenereerd';

            $this->generate_redirects();
            $log_messages[] = '  _redirects gegenereerd';

            // Copy theme assets
            $log_messages[] = 'Kopieren van theme assets...';
            $this->copy_theme_assets();
            $log_messages[] = '  Theme assets gekopieerd';

            // Create ZIP
            $log_messages[] = 'Maken van ZIP archief...';
            $zip_path = $this->create_zip();

            // Calculate final stats
            $this->stats['size'] = filesize($zip_path);
            $this->stats['files'] = $this->count_files($this->temp_dir);

            update_option('export_only_zip_path', $zip_path);
            update_option('export_only_stats', $this->stats);

            $log_messages[] = 'Export voltooid!';

            wp_send_json_success([
                'message' => 'Export succesvol gegenereerd',
                'stats' => $this->stats,
                'log' => $log_messages
            ]);

        } catch (Exception $e) {
            wp_send_json_error([
                'message' => $e->getMessage(),
                'log' => $log_messages
            ]);
        }
    }

    /**
     * AJAX handler: Download the generated ZIP
     */
    public function ajax_download_zip() {
        check_ajax_referer('static_exporter_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized');
        }

        $zip_path = get_option('export_only_zip_path');

        if (!$zip_path || !file_exists($zip_path)) {
            wp_die('ZIP bestand niet gevonden. Genereer eerst een nieuwe export.');
        }

        $filename = 'static-export-' . gmdate('Y-m-d-His') . '.zip';

        // Send download headers
        header('Content-Type: application/zip');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Content-Length: ' . filesize($zip_path));
        header('Pragma: public');
        header('Cache-Control: must-revalidate');

        readfile($zip_path);

        // Cleanup after download
        $this->cleanup_temp_files();

        exit;
    }

    /**
     * AJAX handler: Get current export status
     */
    public function ajax_get_status() {
        check_ajax_referer('static_exporter_nonce', 'nonce');

        wp_send_json_success([
            'stats' => get_option('export_only_stats', []),
            'has_zip' => file_exists(get_option('export_only_zip_path', ''))
        ]);
    }

    /**
     * AJAX handler: Cleanup temporary files
     */
    public function ajax_cleanup() {
        check_ajax_referer('static_exporter_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized');
        }

        $this->cleanup_temp_files();
        wp_send_json_success('Cleanup voltooid');
    }

    /**
     * AJAX handler: Save settings
     */
    public function ajax_save_settings() {
        check_ajax_referer('static_exporter_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized');
        }

        $base_url = isset($_POST['base_url']) ? esc_url_raw(trim($_POST['base_url'])) : '';

        // Validate URL
        if (empty($base_url)) {
            wp_send_json_error('Base URL is verplicht');
        }

        // Remove trailing slash for consistency
        $base_url = rtrim($base_url, '/');

        // Save to options
        update_option('export_only_base_url', $base_url);

        // Update instance variable
        $this->base_url = $base_url;

        wp_send_json_success([
            'message' => 'Instellingen opgeslagen',
            'base_url' => $base_url
        ]);
    }

    /**
     * Export the homepage
     */
    private function export_homepage() {
        $url = home_url('/');
        $html = $this->fetch_url($url);

        if ($html) {
            $html = $this->transform_html($html);
            $this->save_file('index.html', $html);
            return true;
        }
        return false;
    }

    /**
     * Export a single post/page
     */
    private function export_single_content($post) {
        $url = get_permalink($post);
        $html = $this->fetch_url($url);

        if (!$html) {
            return false;
        }

        // Transform HTML
        $html = $this->transform_html($html);

        // Determine file path based on URL structure
        $path = $this->url_to_path($url);
        $this->save_file($path, $html);

        return true;
    }

    /**
     * Fetch URL content
     */
    private function fetch_url($url) {
        // Close session to prevent blocking
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }

        $response = wp_remote_get($url, [
            'timeout' => 30,
            'sslverify' => false,
            'httpversion' => '1.1',
            'redirection' => 5,
            'blocking' => true,
            'cookies' => []
        ]);

        if (is_wp_error($response)) {
            return false;
        }

        $code = wp_remote_retrieve_response_code($response);
        if ($code !== 200) {
            return false;
        }

        return wp_remote_retrieve_body($response);
    }

    /**
     * Transform HTML content
     * - Remove search forms
     * - Remove comment forms
     * - Remove login elements
     * - Make URLs relative
     * - Add lazy loading
     * - Minify HTML
     */
    private function transform_html($html) {
        // 1. Remove search forms
        $html = $this->remove_search_forms($html);

        // 2. Remove comment form (keep existing comments)
        $html = $this->remove_comment_form($html);

        // 3. Remove login elements
        $html = $this->remove_login_elements($html);

        // 4. Make URLs relative (but keep image paths)
        $html = $this->make_urls_relative($html);

        // 5. Add lazy loading to images
        $html = $this->add_lazy_loading($html);

        // 6. Add preload hints
        $html = $this->add_preload_hints($html);

        // 7. Minify HTML
        $html = $this->minify_html($html);

        return $html;
    }

    /**
     * Remove search forms from HTML
     */
    private function remove_search_forms($html) {
        // Remove search form by role
        $html = preg_replace('/<form[^>]*role=["\']search["\'][^>]*>.*?<\/form>/is', '', $html);

        // Remove search form by class
        $html = preg_replace('/<form[^>]*class=["\'][^"\']*search[^"\']*["\'][^>]*>.*?<\/form>/is', '', $html);

        // Remove search form by action containing 's='
        $html = preg_replace('/<form[^>]*action=["\'][^"\']*\?s=[^"\']*["\'][^>]*>.*?<\/form>/is', '', $html);

        // Remove WordPress default search widget
        $html = preg_replace('/<aside[^>]*class=["\'][^"\']*widget_search[^"\']*["\'][^>]*>.*?<\/aside>/is', '', $html);

        // Remove search containers
        $html = preg_replace('/<div[^>]*class=["\'][^"\']*search-form[^"\']*["\'][^>]*>.*?<\/div>/is', '', $html);

        return $html;
    }

    /**
     * Remove comment form but keep existing comments
     */
    private function remove_comment_form($html) {
        // Remove the respond section (comment form)
        $html = preg_replace('/<div[^>]*id=["\']respond["\'][^>]*>.*?<\/div>(?:\s*<\/div>)*/is', '', $html);

        // Remove comment form specifically
        $html = preg_replace('/<form[^>]*id=["\']commentform["\'][^>]*>.*?<\/form>/is', '', $html);

        // Remove "Leave a reply" headers
        $html = preg_replace('/<h[1-6][^>]*id=["\']reply-title["\'][^>]*>.*?<\/h[1-6]>/is', '', $html);

        // Remove comment reply links
        $html = preg_replace('/<a[^>]*class=["\'][^"\']*comment-reply-link[^"\']*["\'][^>]*>.*?<\/a>/is', '', $html);

        return $html;
    }

    /**
     * Remove login-related elements
     */
    private function remove_login_elements($html) {
        // Remove login forms
        $html = preg_replace('/<form[^>]*action=["\'][^"\']*wp-login[^"\']*["\'][^>]*>.*?<\/form>/is', '', $html);

        // Remove login links
        $html = preg_replace('/<a[^>]*href=["\'][^"\']*wp-login[^"\']*["\'][^>]*>.*?<\/a>/is', '', $html);

        // Remove admin bar
        $html = preg_replace('/<div[^>]*id=["\']wpadminbar["\'][^>]*>.*?<\/div>/is', '', $html);

        // Remove admin bar styles
        $html = preg_replace('/<style[^>]*id=["\']admin-bar[^"\']*["\'][^>]*>.*?<\/style>/is', '', $html);

        // Remove body class for admin bar
        $html = preg_replace('/\s*admin-bar\s*/i', ' ', $html);

        // Remove wp-admin links
        $html = preg_replace('/<a[^>]*href=["\'][^"\']*wp-admin[^"\']*["\'][^>]*>.*?<\/a>/is', '', $html);

        return $html;
    }

    /**
     * Make URLs relative while keeping image paths
     */
    private function make_urls_relative($html) {
        $site_url = get_site_url();
        $home_url = home_url();

        // List of URL variations to replace
        $urls_to_replace = [
            // Escaped URLs (JavaScript)
            str_replace('/', '\\/', $site_url) => '',
            str_replace('/', '\\/', $home_url) => '',
            // URL encoded
            rawurlencode($site_url) => '',
            rawurlencode($home_url) => '',
            // HTTPS versions
            $site_url => '',
            $home_url => '',
            // HTTP versions (in case)
            str_replace('https://', 'http://', $site_url) => '',
            str_replace('https://', 'http://', $home_url) => '',
        ];

        foreach ($urls_to_replace as $search => $replace) {
            $html = str_replace($search, $replace, $html);
        }

        // Fix protocol-relative URLs
        $html = preg_replace('/(?<=["\']\/{2,}(?=[^\/])/', 'https://', $html);

        // Fix empty href/src
        $html = preg_replace('/href=["\']\s*["\']/', 'href="/"', $html);
        $html = preg_replace('/src=["\']\s*["\']/', 'src="/"', $html);

        // Ensure wp-content paths start with /
        $html = preg_replace('/(?<=["\'])wp-content/', '/wp-content', $html);

        // Clean up JSON escaped slashes
        $html = str_replace('\\/', '/', $html);

        return $html;
    }

    /**
     * Add lazy loading to images
     */
    private function add_lazy_loading($html) {
        // Add loading="lazy" to img tags that don't have it
        $html = preg_replace_callback(
            '/<img(?![^>]*loading=)[^>]*>/i',
            function($matches) {
                $img = $matches[0];
                // Don't add to images that are likely above the fold
                if (strpos($img, 'logo') !== false || strpos($img, 'header') !== false) {
                    return $img;
                }
                return str_replace('<img', '<img loading="lazy"', $img);
            },
            $html
        );

        // Add loading="lazy" to iframes
        $html = preg_replace_callback(
            '/<iframe(?![^>]*loading=)[^>]*>/i',
            function($matches) {
                return str_replace('<iframe', '<iframe loading="lazy"', $matches[0]);
            },
            $html
        );

        return $html;
    }

    /**
     * Add preload hints for critical resources
     */
    private function add_preload_hints($html) {
        $preload_hints = [];

        // Find first stylesheet
        if (preg_match('/<link[^>]*rel=["\']stylesheet["\'][^>]*href=["\']([^"\']+)["\'][^>]*>/i', $html, $matches)) {
            $preload_hints[] = '<link rel="preload" href="' . esc_attr($matches[1]) . '" as="style">';
        }

        // Find web fonts (woff2)
        if (preg_match_all('/url\(["\']?([^"\'()]+\.woff2)["\']?\)/i', $html, $matches)) {
            foreach (array_slice(array_unique($matches[1]), 0, 3) as $font) {
                $preload_hints[] = '<link rel="preload" href="' . esc_attr($font) . '" as="font" type="font/woff2" crossorigin>';
            }
        }

        // Insert preload hints in head
        if (!empty($preload_hints)) {
            $hints_html = implode("\n    ", $preload_hints);
            $html = preg_replace('/<head([^>]*)>/i', "<head$1>\n    " . $hints_html, $html, 1);
        }

        return $html;
    }

    /**
     * Minify HTML
     */
    private function minify_html($html) {
        // Remove HTML comments (except IE conditionals)
        $html = preg_replace('/<!--(?!\[if).*?-->/s', '', $html);

        // Remove whitespace between tags
        $html = preg_replace('/>\s+</', '> <', $html);

        // Collapse multiple spaces to single space
        $html = preg_replace('/\s{2,}/', ' ', $html);

        // Remove whitespace around block elements
        $html = preg_replace('/\s*(<\/?(?:html|head|body|div|section|article|header|footer|nav|aside|main|p|h[1-6]|ul|ol|li|table|tr|td|th|form)[^>]*>)\s*/i', '$1', $html);

        // Trim
        $html = trim($html);

        return $html;
    }

    /**
     * Convert URL to file path
     */
    private function url_to_path($url) {
        $path = wp_parse_url($url, PHP_URL_PATH);
        $path = trim($path, '/');

        if (empty($path)) {
            return 'index.html';
        }

        // Directory style: /page/ -> page/index.html
        return $path . '/index.html';
    }

    /**
     * Save file to temp directory
     */
    private function save_file($path, $content) {
        $full_path = $this->temp_dir . '/' . $path;
        $dir = dirname($full_path);

        if (!is_dir($dir)) {
            wp_mkdir_p($dir);
        }

        file_put_contents($full_path, $content);
    }

    /**
     * Generate sitemap.xml
     */
    private function generate_sitemap() {
        $urls = [];

        // Homepage
        $urls[] = [
            'loc' => $this->base_url . '/',
            'priority' => '1.0',
            'changefreq' => 'daily'
        ];

        // Pages
        $pages = get_posts(['post_type' => 'page', 'post_status' => 'publish', 'posts_per_page' => -1]);
        foreach ($pages as $page) {
            $urls[] = [
                'loc' => str_replace(home_url(), $this->base_url, get_permalink($page)),
                'lastmod' => get_the_modified_date('c', $page),
                'priority' => '0.8',
                'changefreq' => 'weekly'
            ];
        }

        // Posts
        $posts = get_posts(['post_type' => 'post', 'post_status' => 'publish', 'posts_per_page' => -1]);
        foreach ($posts as $post) {
            $urls[] = [
                'loc' => str_replace(home_url(), $this->base_url, get_permalink($post)),
                'lastmod' => get_the_modified_date('c', $post),
                'priority' => '0.6',
                'changefreq' => 'monthly'
            ];
        }

        // Build XML
        $xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
        $xml .= '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";

        foreach ($urls as $url) {
            $xml .= "  <url>\n";
            $xml .= "    <loc>" . esc_url($url['loc']) . "</loc>\n";
            if (!empty($url['lastmod'])) {
                $xml .= "    <lastmod>" . esc_html($url['lastmod']) . "</lastmod>\n";
            }
            $xml .= "    <changefreq>" . esc_html($url['changefreq']) . "</changefreq>\n";
            $xml .= "    <priority>" . esc_html($url['priority']) . "</priority>\n";
            $xml .= "  </url>\n";
        }

        $xml .= '</urlset>';

        $this->save_file('sitemap.xml', $xml);
    }

    /**
     * Generate robots.txt
     */
    private function generate_robots() {
        $robots = "User-agent: *\n";
        $robots .= "Allow: /\n";
        $robots .= "\n";
        $robots .= "# Sitemap\n";
        $robots .= "Sitemap: {$this->base_url}/sitemap.xml\n";

        $this->save_file('robots.txt', $robots);
    }

    /**
     * Generate manifest.json for PWA
     */
    private function generate_manifest() {
        $site_name = get_bloginfo('name');
        $site_description = get_bloginfo('description');

        $manifest = [
            'name' => $site_name,
            'short_name' => substr($site_name, 0, 12),
            'description' => $site_description,
            'start_url' => '/',
            'display' => 'standalone',
            'background_color' => '#ffffff',
            'theme_color' => '#1e3a5f',
            'icons' => [
                [
                    'src' => '/wp-content/uploads/favicon-192.png',
                    'sizes' => '192x192',
                    'type' => 'image/png'
                ],
                [
                    'src' => '/wp-content/uploads/favicon-512.png',
                    'sizes' => '512x512',
                    'type' => 'image/png'
                ]
            ]
        ];

        $this->save_file('manifest.json', wp_json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }

    /**
     * Generate service worker for offline support
     */
    private function generate_service_worker() {
        $sw = <<<'JS'
const CACHE_NAME = 'static-site-v1';
const OFFLINE_URL = '/offline.html';

// Install event - cache offline page
self.addEventListener('install', event => {
    event.waitUntil(
        caches.open(CACHE_NAME).then(cache => {
            return cache.addAll([
                OFFLINE_URL,
                '/',
                '/manifest.json'
            ]);
        })
    );
    self.skipWaiting();
});

// Activate event - cleanup old caches
self.addEventListener('activate', event => {
    event.waitUntil(
        caches.keys().then(cacheNames => {
            return Promise.all(
                cacheNames
                    .filter(name => name !== CACHE_NAME)
                    .map(name => caches.delete(name))
            );
        })
    );
    self.clients.claim();
});

// Fetch event - serve from cache, fallback to network, then offline page
self.addEventListener('fetch', event => {
    if (event.request.mode === 'navigate') {
        event.respondWith(
            fetch(event.request)
                .catch(() => {
                    return caches.match(OFFLINE_URL);
                })
        );
    } else {
        event.respondWith(
            caches.match(event.request).then(response => {
                return response || fetch(event.request).then(fetchResponse => {
                    // Cache successful responses
                    if (fetchResponse.ok) {
                        const responseClone = fetchResponse.clone();
                        caches.open(CACHE_NAME).then(cache => {
                            cache.put(event.request, responseClone);
                        });
                    }
                    return fetchResponse;
                });
            })
        );
    }
});
JS;

        $this->save_file('sw.js', $sw);
    }

    /**
     * Generate offline page
     */
    private function generate_offline_page() {
        $site_name = esc_html(get_bloginfo('name'));

        $html = <<<HTML
<!DOCTYPE html>
<html lang="nl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Offline - {$site_name}</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Oxygen, Ubuntu, sans-serif;
            background: linear-gradient(135deg, #1e3a5f 0%, #2d5a87 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            color: #fff;
            text-align: center;
            padding: 20px;
        }
        .container {
            max-width: 500px;
        }
        .icon {
            font-size: 80px;
            margin-bottom: 30px;
        }
        h1 {
            font-size: 2rem;
            margin-bottom: 15px;
        }
        p {
            font-size: 1.1rem;
            opacity: 0.9;
            line-height: 1.6;
            margin-bottom: 30px;
        }
        .btn {
            display: inline-block;
            padding: 12px 30px;
            background: #fff;
            color: #1e3a5f;
            text-decoration: none;
            border-radius: 5px;
            font-weight: 600;
            transition: transform 0.2s;
        }
        .btn:hover {
            transform: scale(1.05);
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="icon">📡</div>
        <h1>Je bent offline</h1>
        <p>
            Het lijkt erop dat je geen internetverbinding hebt.
            Controleer je verbinding en probeer het opnieuw.
        </p>
        <a href="/" class="btn">Probeer opnieuw</a>
    </div>
</body>
</html>
HTML;

        $this->save_file('offline.html', $html);
    }

    /**
     * Generate 404 page
     */
    private function generate_404_page() {
        $site_name = esc_html(get_bloginfo('name'));

        $html = <<<HTML
<!DOCTYPE html>
<html lang="nl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Pagina niet gevonden - {$site_name}</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Oxygen, Ubuntu, sans-serif;
            background: #f5f5f5;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            color: #333;
            text-align: center;
            padding: 20px;
        }
        .container {
            max-width: 500px;
        }
        .error-code {
            font-size: 120px;
            font-weight: 700;
            color: #1e3a5f;
            line-height: 1;
            margin-bottom: 20px;
        }
        h1 {
            font-size: 1.8rem;
            margin-bottom: 15px;
            color: #1e3a5f;
        }
        p {
            font-size: 1.1rem;
            color: #666;
            line-height: 1.6;
            margin-bottom: 30px;
        }
        .btn {
            display: inline-block;
            padding: 12px 30px;
            background: #1e3a5f;
            color: #fff;
            text-decoration: none;
            border-radius: 5px;
            font-weight: 600;
            transition: background 0.2s;
        }
        .btn:hover {
            background: #2d5a87;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="error-code">404</div>
        <h1>Pagina niet gevonden</h1>
        <p>
            De pagina die je zoekt bestaat niet of is verplaatst.
            Ga terug naar de homepage om verder te zoeken.
        </p>
        <a href="/" class="btn">Naar homepage</a>
    </div>
</body>
</html>
HTML;

        $this->save_file('404.html', $html);
    }

    /**
     * Generate _redirects file for Netlify/Cloudflare
     */
    private function generate_redirects() {
        $redirects = "# Redirects for Netlify/Cloudflare Pages\n";
        $redirects .= "# Format: from to [status]\n\n";
        $redirects .= "# Handle 404s\n";
        $redirects .= "/* /404.html 404\n";

        $this->save_file('_redirects', $redirects);
    }

    /**
     * Copy theme CSS and JS (not images)
     */
    private function copy_theme_assets() {
        $theme_dir = get_stylesheet_directory();
        $theme_name = basename($theme_dir);
        $dest_dir = $this->temp_dir . '/wp-content/themes/' . $theme_name;

        // File extensions to copy
        $allowed_extensions = ['css', 'js', 'woff', 'woff2', 'ttf', 'eot', 'svg'];

        $this->copy_filtered_directory($theme_dir, $dest_dir, $allowed_extensions);
    }

    /**
     * Copy directory with file extension filter
     */
    private function copy_filtered_directory($src, $dst, $allowed_extensions) {
        if (!is_dir($src)) {
            return;
        }

        wp_mkdir_p($dst);

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($src, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST
        );

        foreach ($iterator as $item) {
            $dest_path = $dst . '/' . $iterator->getSubPathName();

            if ($item->isDir()) {
                wp_mkdir_p($dest_path);
            } else {
                $ext = strtolower(pathinfo($item->getFilename(), PATHINFO_EXTENSION));
                if (in_array($ext, $allowed_extensions)) {
                    copy($item->getPathname(), $dest_path);
                }
            }
        }
    }

    /**
     * Create ZIP archive
     */
    private function create_zip() {
        $zip_path = WP_CONTENT_DIR . '/static-export-' . time() . '.zip';

        $zip = new ZipArchive();
        if ($zip->open($zip_path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new Exception('Kon ZIP archief niet aanmaken');
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($this->temp_dir, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST
        );

        foreach ($iterator as $item) {
            $local_path = str_replace($this->temp_dir . '/', '', $item->getPathname());

            if ($item->isDir()) {
                $zip->addEmptyDir($local_path);
            } else {
                $zip->addFile($item->getPathname(), $local_path);
            }
        }

        $zip->close();

        return $zip_path;
    }

    /**
     * Count files in directory
     */
    private function count_files($dir) {
        $count = 0;
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS)
        );

        foreach ($iterator as $item) {
            if ($item->isFile()) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * Cleanup temporary files
     */
    private function cleanup_temp_files() {
        $temp_dir = get_option('export_only_temp_dir');
        $zip_path = get_option('export_only_zip_path');

        if ($temp_dir && is_dir($temp_dir)) {
            $this->recursive_delete($temp_dir);
        }

        if ($zip_path && file_exists($zip_path)) {
            unlink($zip_path);
        }

        delete_option('export_only_temp_dir');
        delete_option('export_only_zip_path');
        delete_option('export_only_stats');
        delete_option('export_only_state');
    }

    /**
     * Recursively delete directory
     */
    private function recursive_delete($dir) {
        if (!is_dir($dir)) {
            return;
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($iterator as $item) {
            if ($item->isDir()) {
                rmdir($item->getPathname());
            } else {
                unlink($item->getPathname());
            }
        }

        rmdir($dir);
    }

    /**
     * Add log message
     */
    private function log($message) {
        $this->log[] = '[' . gmdate('H:i:s') . '] ' . $message;
    }

    /**
     * Format file size
     */
    public static function format_size($bytes) {
        $units = ['B', 'KB', 'MB', 'GB'];
        $i = 0;
        while ($bytes >= 1024 && $i < count($units) - 1) {
            $bytes /= 1024;
            $i++;
        }
        return round($bytes, 2) . ' ' . $units[$i];
    }
}
