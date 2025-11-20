=== Static Site Exporter to Kinsta ===
Contributors: Monique Dubbelman
Tags: static site, export, github, kinsta, deployment, git
Requires at least: 5.0
Tested up to: 6.4
Stable tag: 2.5.3
License: GPLv2 or later

Export WordPress site to static HTML and deploy to GitHub/Kinsta Static Hosting with one-click deployment workflow.

== Description ==

This plugin exports your WordPress site to static HTML files and deploys them to GitHub and Kinsta Static Hosting with a simple, reliable one-click workflow.

Features:
* One-click export and deployment via WordPress admin
* Incremental exports (only changed content)
* Direct Git deployment via shell commands
* Activity Status Dashboard - track all exports and deployments
* Reset Export Lock tool for troubleshooting
* Auto-repair Git repository if corrupted
* Auto-export on content changes (posts, pages, menus, widgets, etc.)
* Real-time logging and progress tracking
* Automatic URL conversion to relative paths
* No terminal commands needed!

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

= 2.5.3 =
* BUGFIX: Fix git command not found error
* Use full path to git binary (/usr/local/bin/git)
* Fixes error code 127 when deploying via PHP exec()

= 2.5.2 =
* BUGFIX: Auto-repair Git repository
* Automatically repair Git repository if .git directory is missing
* Fetches from remote and resets to latest commit
* No more manual Git repair needed

= 2.5.1 =
* NEW FEATURE: Reset Export Lock Button
* Added "Reset Export Lock" button in admin interface
* Clear stuck export locks without terminal access
* Includes confirmation dialog for safety
* Also clears GitHub push state

= 2.5.0 =
* NEW FEATURE: Activity Status Dashboard
* Visual dashboard showing last manual and auto exports
* Track last manual and auto GitHub pushes
* See what content was exported (page/post titles)
* View commit SHAs and file counts
* Human-readable timestamps

= 2.4.0 =
* WORKFLOW OPTIMIZATION: Simplified deployment process
* Export directly to Git repository location (no more rsync needed!)
* One-click deployment via Git shell commands
* Faster and more reliable than GitHub API approach
* Simplified admin interface workflow

= 2.3.0 =
* PERFORMANCE: Incremental export and push
* Track last modified time for posts/pages
* Only export changed content since last export
* Compare file hashes with GitHub before pushing
* Only push changed files to GitHub
* Dramatically reduces export and push time

= 2.2.0 =
* MAJOR FIX: Comprehensive URL conversion for CSS/JS/images
* Improved make_urls_relative() to handle escaped and encoded URLs
* Fixes JavaScript-escaped URLs and encoded characters

= 2.1.0 =
* MAJOR FIX: Resolved AJAX timeout issues for large sites
* NEW: Chunked AJAX processing for GitHub uploads
* NEW: Resumable upload progress tracking
* IMPROVED: Better error handling and user feedback

= 1.0.0 =
* Initial release
* Auto-export on content changes
* GitHub integration
* Kinsta deployment

== Disclaimer ==

This plugin is provided "as-is" without any warranty of any kind, express or implied. Use at your own risk.

The author is not responsible for any data loss, deployment failures, or other issues that may arise from using this plugin. Always test thoroughly on a staging environment before using in production.

== Support ==

This is a personal project. No official support is provided, but:

* Bug reports welcome via GitHub Issues: https://github.com/mdubbelm/static-site-exporter/issues
* Feature suggestions welcome
* Pull requests welcome
* Questions? Open a discussion on GitHub

Response times may vary. For urgent issues, please consider hiring a WordPress developer.

== Contributing ==

Contributions are welcome! Please:

1. Fork the repository on GitHub
2. Create a feature branch
3. Make your changes with clear commit messages
4. Submit a pull request

All contributions will be reviewed. Please ensure your code follows WordPress coding standards.

== Credits ==

Created by Monique Dubbelman
Built with assistance from Claude (Anthropic)

Special thanks to the WordPress community for their excellent documentation and support.

== Privacy ==

This plugin:
* Stores GitHub tokens and Kinsta API keys encrypted in your WordPress database
* Makes API calls to GitHub and Kinsta APIs using your provided credentials
* Does not collect or transmit any user data to third parties
* Does not use cookies or tracking

Your credentials never leave your WordPress installation except when making authenticated API calls to GitHub and Kinsta.