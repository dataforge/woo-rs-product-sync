# Woo RS Product Sync — Development Guide

## Plugin Overview
WordPress/WooCommerce plugin that syncs products from RepairShopr (RS) to WooCommerce via webhooks and scheduled API polling.

## Key Files
- `woo-rs-product-sync.php` — Main plugin file, constants, includes
- `includes/class-admin.php` — Admin UI (settings, dashboard, logs), AJAX handlers
- `includes/class-product-sync.php` — Core sync logic (create, update, match products)
- `includes/class-api-client.php` — RS API communication, rate limiting (160 calls/300s)
- `includes/class-webhook.php` — REST endpoint for RS webhooks
- `includes/class-cron.php` — Scheduled auto-sync (page-by-page processing)
- `includes/class-openai.php` — OpenAI description rewriting with per-model config
- `includes/class-category-map.php` — RS→WC category mapping
- `includes/class-encryption.php` — API key encryption
- `includes/class-updater.php` — GitHub auto-updater (native WP Update URI mechanism)
- `includes/class-logger.php` — Webhook logging
- `includes/class-plugin.php` — Plugin initialization, activation, DB tables
- `assets/admin.js` — Admin page JavaScript
- `assets/admin.css` — Admin page styles

## Important Behaviors
- Product matching: RS product ID → WC SKU, fallback to `_rs_product_id` meta
- Product updates never change WC publish status unless RS `disabled` flag is true (forces Draft)
- New products use the configured default status (draft/pending/publish setting)
- OpenAI models have different configs (reasoning vs non-reasoning) — see `WOO_RS_OpenAI::$model_config`
- Version bump in `woo-rs-product-sync.php` is needed when changing JS/CSS to bust browser cache

## Releasing a New Version

### Steps

1. **Bump the version** in `woo-rs-product-sync.php` (two places):
   - Plugin header: `* Version: X.Y.Z`
   - Constant: `define( 'WOO_RS_PRODUCT_SYNC_VERSION', 'X.Y.Z' );`

2. **Commit and push:**
   ```bash
   git add -A && git commit -m "Release vX.Y.Z — description" && git push origin main
   ```

3. **Build the release zip** (folder inside must be `woo-rs-product-sync/`):
   ```bash
   # From the plugin directory:
   cd /tmp && rm -rf woo-rs-build && mkdir woo-rs-build
   rsync -a --exclude='.git' --exclude='.claude' --exclude='_reference' --exclude='.gitignore' \
     /path/to/woo-rs-product-sync/ woo-rs-build/woo-rs-product-sync/
   cd woo-rs-build && zip -r woo-rs-product-sync-X.Y.Z.zip woo-rs-product-sync/
   ```

4. **Create the GitHub Release** with the zip attached:
   ```bash
   gh release create vX.Y.Z \
     --repo dataforge/woo-rs-product-sync \
     --title "vX.Y.Z — Short description" \
     --notes "Release notes here (markdown supported)" \
     /tmp/woo-rs-build/woo-rs-product-sync-X.Y.Z.zip
   ```

### How Auto-Updates Work
- The plugin uses WordPress's native `Update URI` header + `update_plugins_github.com` filter (WP 5.8+)
- `includes/class-updater.php` checks GitHub Releases API every 12 hours (cached via transient)
- It prefers the attached `.zip` asset (correct folder name) over GitHub's zipball
- A `fix_directory` filter is a safety net that renames the extracted folder if needed
- WordPress sites see the update in Dashboard > Updates and can one-click install
- No third-party library or plugin required

### Release Notes
- Always attach a properly-built zip — the folder inside must be `woo-rs-product-sync/` so manual installs via WP admin work correctly
- Old releases stay in GitHub history; the updater always checks the latest release
- GitHub API rate limit is 60 requests/hour unauthenticated — not an issue for update checks

## Ignored from Git
- `_reference/` — Old plugin version for reference
- `.claude/` — Claude Code local settings
- `CLAUDE.md` — Claude Code project instructions (server-specific)
