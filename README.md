# Static Site Exporter to Kinsta

Export WordPress site to static HTML and deploy to GitHub/Kinsta Static Hosting with one-click deployment workflow.

[![WordPress](https://img.shields.io/badge/WordPress-5.0%2B-blue.svg)](https://wordpress.org/)
[![License](https://img.shields.io/badge/License-GPL%20v2%2B-blue.svg)](https://www.gnu.org/licenses/gpl-2.0.html)
[![Version](https://img.shields.io/badge/Version-2.5.3-green.svg)](https://github.com/mdubbelm/static-site-exporter)

## 🚀 Features

- **One-click export and deployment** via WordPress admin
- **Incremental exports** - only changed content
- **Configurable export directory** - set your Git repository path
- **Direct Git deployment** via shell commands (no GitHub API complexity!)
- **Activity Status Dashboard** - track all exports and deployments
- **Auto-export on content changes** (posts, pages, menus, widgets, etc.)
- **Auto-repair Git repository** if corrupted
- **Reset Export Lock** tool for troubleshooting
- **Automatic URL conversion** to relative paths
- **Real-time logging** and progress tracking
- **No terminal commands needed!**

## 📋 Requirements

- WordPress 5.0 or higher
- PHP 7.4 or higher
- Git installed on server (`/usr/local/bin/git`)
- Write permissions to export directory
- GitHub Personal Access Token
- Kinsta API Key (for Kinsta deployment)

## 🔧 Installation

1. Upload the plugin files to `/wp-content/plugins/static-site-exporter/`
2. Activate the plugin through the 'Plugins' menu in WordPress
3. Go to **WordPress Admin → Static Exporter**
4. Configure your settings:
   - Export Directory (path to your Git repository)
   - GitHub Personal Access Token (with repo permissions)
   - GitHub Repository (format: `username/repository-name`)
   - Kinsta API Key and Site ID

## ⚙️ Configuration

### Required Settings

- **Export Directory**: Full path to your Git repository (e.g., `/var/www/static-site/public`)
- **GitHub Token**: Personal Access Token with `repo` permissions
- **GitHub Repository**: Format `username/repository-name`
- **GitHub Branch**: Usually `main` or `master`
- **Kinsta API Key**: From Kinsta dashboard
- **Kinsta Site ID**: Your Kinsta static site ID

### Optional Settings

- **Auto-Export**: Automatically export when content changes
- **Auto-Deploy**: Automatically push to GitHub and trigger Kinsta deployment

## 🎯 Usage

### Manual Export and Deploy

1. Go to **WordPress Admin → Static Exporter**
2. Click **"Export Static Site"**
3. Wait for export to complete
4. Click **"Push to GitHub"**
5. Wait 2-5 minutes for Kinsta auto-deployment

### Auto-Export (Recommended)

Enable auto-export in settings. The plugin will automatically:
- Export when you publish/update posts or pages
- Export when you change menus or widgets
- Push to GitHub automatically
- Trigger Kinsta deployment

**Note:** Auto-export has a 30-second delay and 2-minute cooldown to batch changes.

## 🔒 Security

This plugin:
- ✅ Encrypts all credentials (GitHub token, Kinsta API key) using WordPress AUTH_KEY
- ✅ Escapes all outputs to prevent XSS
- ✅ Uses WordPress nonces for AJAX security
- ✅ Validates and sanitizes all user inputs
- ✅ Follows WordPress coding standards
- ✅ Passes WordPress.org Plugin Checker

**Security Audit:** 2025-11-20 - All security checks PASS

## 📊 Activity Dashboard

Track your export and deployment history:
- **Last Manual Export**: When, type (full/incremental), pages exported
- **Last Auto Export**: When and what triggered it
- **Last Manual Push**: Commit SHA, file count, timestamp
- **Last Auto Push**: Same details for automatic deployments

## 🛠️ Troubleshooting

### Export Stuck?

Click **"Reset Export Lock"** in the admin interface to clear stuck exports.

### Git Repository Corrupted?

The plugin automatically repairs corrupted Git repositories. Just click "Push to GitHub" again.

### URLs Not Working?

The plugin automatically converts all URLs to relative paths. If you see issues, check that your export directory is correctly configured.

### Need to Force Full Export?

Visit: `http://your-site.local/reset-export-time.php` (must be logged in as admin)

## ⚠️ Disclaimer

This plugin is provided **"as-is"** without any warranty of any kind, express or implied. Use at your own risk.

The author is not responsible for any data loss, deployment failures, or other issues that may arise from using this plugin. Always test thoroughly on a staging environment before using in production.

## 💬 Support

This is a personal project. **No official support is provided**, but:

- 🐛 **Bug reports** welcome via [GitHub Issues](https://github.com/mdubbelm/static-site-exporter/issues)
- 💡 **Feature suggestions** welcome
- 🤝 **Pull requests** welcome
- ❓ **Questions?** Open a discussion on GitHub

Response times may vary. For urgent issues, please consider hiring a WordPress developer.

## 🤝 Contributing

Contributions are welcome! Please:

1. Fork the repository on GitHub
2. Create a feature branch (`git checkout -b feature/amazing-feature`)
3. Make your changes with clear commit messages
4. Ensure your code follows [WordPress Coding Standards](https://developer.wordpress.org/coding-standards/wordpress-coding-standards/)
5. Submit a pull request

All contributions will be reviewed. Thank you for helping improve this plugin!

## 📜 License

This plugin is licensed under the **GPL v2 or later**.

See [LICENSE](LICENSE) for details.

## 👏 Credits

**Created by:** Monique Dubbelman
**Built with assistance from:** Claude (Anthropic)

Special thanks to the WordPress community for their excellent documentation and support.

## 🔐 Privacy

This plugin:
- Stores GitHub tokens and Kinsta API keys **encrypted** in your WordPress database
- Makes API calls to GitHub and Kinsta APIs using your provided credentials
- Does **not** collect or transmit any user data to third parties
- Does **not** use cookies or tracking

Your credentials never leave your WordPress installation except when making authenticated API calls to GitHub and Kinsta.

## 📚 Documentation

For detailed documentation, see:
- [WordPress Plugin Handbook](https://developer.wordpress.org/plugins/)
- [GitHub API Documentation](https://docs.github.com/en/rest)
- [Kinsta API Documentation](https://kinsta.com/docs/kinsta-api/)

## 🗂️ Changelog

### 2.5.3 (2025-11-20)
- **SECURITY**: Comprehensive security audit - all checks PASS
- **NEW**: Configurable export directory via WordPress settings
- **NEW**: "Paste Path" clipboard button for easy path entry
- **FIX**: Plugin URI (changed from example.com to GitHub URL)
- **FIX**: Removed .gitignore from plugin directory
- **FIX**: Escaped all Exception messages (15+ locations)
- **FIX**: Escaped all CLI outputs in test script
- **FIX**: Added version numbers to enqueued resources

### 2.5.2 (2025-11-13)
- **BUGFIX**: Auto-repair Git repository
- Automatically repair Git repository if .git directory is missing

### 2.5.1 (2025-11-13)
- **NEW FEATURE**: Reset Export Lock Button
- Clear stuck export locks without terminal access

### 2.5.0 (2025-11-13)
- **NEW FEATURE**: Activity Status Dashboard
- Track all exports and deployments with timestamps

### 2.4.0 (2025-11-13)
- **WORKFLOW OPTIMIZATION**: Simplified deployment process
- Export directly to Git repository (no rsync needed!)

### 2.3.0
- **PERFORMANCE**: Incremental export and push
- Only export changed content since last export

### 2.2.0
- **MAJOR FIX**: Comprehensive URL conversion for CSS/JS/images

### 2.1.0
- **MAJOR FIX**: Resolved AJAX timeout issues for large sites

### 1.0.0
- Initial release

---

**Made with ❤️ for the WordPress community**
