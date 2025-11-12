=== Static Site Exporter to Kinsta ===
Contributors: Monique Dubbelman
Tags: static site, export, github, kinsta, deployment
Requires at least: 5.0
Tested up to: 6.4
Stable tag: 2.1.0
License: GPLv2 or later

Export WordPress site to static HTML and deploy to GitHub/Kinsta Static Hosting with automatic deployment on content changes.

== Description ==

This plugin exports your WordPress site to static HTML files and automatically deploys them to GitHub and Kinsta Static Hosting.

Features:
* Export entire site to static HTML
* Auto-export on content changes
* Push to GitHub repository (handles large sites up to 2750+ files)
* Deploy to Kinsta Static Hosting
* Real-time progress tracking with chunked uploads
* Resumable uploads for reliability

== Installation ==

1. Upload the plugin files to `/wp-content/plugins/static-site-exporter/`
2. Activate the plugin through the 'Plugins' menu
3. Configure GitHub and Kinsta settings
4. Click 'Export Static Site' to generate your first export

== Configuration ==

1. GitHub Personal Access Token (with repo permissions)
2. GitHub Repository (username/repository-name format)
3. Kinsta API Key
4. Kinsta Site ID
5. Enable auto-export and auto-deploy options

== Changelog ==

= 2.1.0 =
* MAJOR FIX: Resolved AJAX timeout issues for large sites (2750+ files, 473MB+)
* NEW: Chunked AJAX processing for GitHub uploads (15 files per batch)
* NEW: Resumable upload progress tracking using WordPress transients
* NEW: Real-time progress updates showing batch completion
* IMPROVED: Upload process now completes reliably without timeouts
* IMPROVED: Better error handling and user feedback

= 2.0.1 =
* Fixed memory exhaustion issues for large sites
* Improved file scanning with memory-efficient methods
* Added automatic memory limit increase to 512MB
* Added periodic garbage collection during uploads

= 1.0.0 =
* Initial release
* Auto-export on content changes
* GitHub integration
* Kinsta deployment