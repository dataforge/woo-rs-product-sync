# Woo RS Product Sync — Development Guide

## Plugin Overview

WordPress/WooCommerce plugin that syncs products from RepairShopr (RS) to WooCommerce via webhooks and scheduled API polling.

## Key Files

- `woo-rs-product-sync.php` — Main plugin file, constants, includes
- `includes/class-admin.php` — Admin UI (settings, dashboard, logs), AJAX handlers
- `includes/class-product-sync.php` — Core sync logic (create, update, match products)
- `includes/class-api-client.php` — RS API communication and shared rate limiting
- `includes/class-cron.php` — Scheduled auto-sync and resumable rate-limit continuations
- `includes/class-locks.php` — Database-backed product and rate-limit locks
- `includes/class-webhook.php` — REST endpoint for RS webhooks
- `includes/class-openai.php` — OpenAI description rewriting with per-model configuration
- `includes/class-category-map.php` — RS-to-WooCommerce category mapping
- `includes/class-encryption.php` — API key encryption
- `includes/class-updater.php` — GitHub auto-updater using WordPress's native Update URI mechanism
- `includes/class-logger.php` — Webhook logging
- `includes/class-plugin.php` — Plugin initialization, activation, and database tables
- `assets/admin.js` — Admin page JavaScript
- `assets/admin.css` — Admin page styles

## Important Behaviors

- A WooCommerce product is updated only when its `_rs_product_id` meta matches the RS product ID; a matching SKU alone is not sufficient.
- A product disabled in RS is drafted. When it is re-enabled, only a draft created by this plugin is restored; an administrator's manual draft is preserved.
- New products use the configured default status (draft, pending, or publish).
- The shared RepairShopr API limit is 160 calls per 60 seconds. Cron sync saves a category/page cursor and resumes after rate limiting.
- Product writes use a database-backed lock, but the lock is held only for the fast DB-write path: the optional OpenAI rewrite is deferred until after the per-product lock is released.
- OpenAI rewrites are throttled (default 25 per 60 seconds, configurable via `woo_rs_product_sync_openai_max_rewrites`). A failed or throttled rewrite is marked pending (`_rs_openai_rewrite_pending`) and retried on the next sync of that product.
- OpenAI models have different configurations; see `WOO_RS_OpenAI::$model_config`.
- Bump `WOO_RS_PRODUCT_SYNC_VERSION` whenever JavaScript or CSS changes to invalidate browser caches.

## Releasing a New Version

This repository uses the manual release flow. It has no `.github/workflows/release.yml`, so pushing a tag does not build or publish a release automatically.

1. Bump the version in `woo-rs-product-sync.php` in both places:

   - Plugin header: `* Version: X.Y.Z`
   - Constant: `define( 'WOO_RS_PRODUCT_SYNC_VERSION', 'X.Y.Z' );`

2. Lint every PHP file and ensure the worktree diff is clean:

   ```powershell
   rg --files -g '*.php' | ForEach-Object { php -l $_ }
   git diff --check
   ```

3. Commit only the intended release changes and push `main`:

   ```powershell
   git add woo-rs-product-sync.php includes/<changed-files> DEVELOPMENT.md
   git commit -m "Release vX.Y.Z"
   git push origin main
   ```

4. Create and push the matching annotated tag:

   ```powershell
   git tag -a vX.Y.Z -m "Release vX.Y.Z"
   git push origin vX.Y.Z
   ```

5. Build the installable ZIP with Python. Do not use PowerShell's `Compress-Archive` or .NET's `ZipFile`; they can write backslashes into ZIP paths.

   ```powershell
   $out = Join-Path $env:TEMP 'woo-rs-product-sync.zip'
   $env:OUT_ZIP = $out
   @'
   import os, zipfile
   slug = 'woo-rs-product-sync'
   src = os.getcwd()
   out = os.environ['OUT_ZIP']
   excluded_dirs = {'.git', '.github', '.claude', 'node_modules', '_zips', '_reference', 'tests'}
   excluded_files = {'.gitignore', '.gitattributes', 'CLAUDE.md'}
   with zipfile.ZipFile(out, 'w', zipfile.ZIP_DEFLATED) as zf:
       for root, dirs, files in os.walk(src):
           dirs[:] = [d for d in dirs if d not in excluded_dirs]
           for name in files:
               if name not in excluded_files and not name.endswith('.zip'):
                   full = os.path.join(root, name)
                   rel = os.path.relpath(full, src).replace(os.sep, '/')
                   zf.write(full, f'{slug}/{rel}')
   '@ | python -
   ```

6. Verify that the ZIP has forward-slash paths and one top-level `woo-rs-product-sync/` folder:

   ```powershell
   @'
   import sys, zipfile
   names = zipfile.ZipFile(sys.argv[1]).namelist()
   assert names and all('\\' not in name for name in names), 'BACKSLASH FOUND'
   assert all(name.startswith('woo-rs-product-sync/') for name in names), 'INVALID ROOT'
   assert 'woo-rs-product-sync/woo-rs-product-sync.php' in names, 'PLUGIN FILE MISSING'
   print(f'OK: {len(names)} entries')
   '@ | python - $out
   ```

7. Publish the GitHub release and attach that ZIP:

   ```powershell
   gh release create vX.Y.Z $out `
     --repo dataforge/woo-rs-product-sync `
     --title 'vX.Y.Z' `
     --generate-notes
   ```

8. Verify the release and its asset:

   ```powershell
   gh release view vX.Y.Z --repo dataforge/woo-rs-product-sync
   ```

The tag must be `vX.Y.Z` and must match the plugin header version. The updater strips the leading `v` before comparing versions. Its attached ZIP asset is preferred over GitHub's source zipball.

## How Auto-Updates Work

- The plugin uses WordPress's native `Update URI` header and `update_plugins_github.com` filter (WordPress 5.8+).
- `includes/class-updater.php` checks the GitHub Releases API every 12 hours using a transient cache.
- It prefers the attached `.zip` asset, whose top-level folder must be `woo-rs-product-sync/`.
- A `fix_directory` filter is a backup safety net if an updater extracts another folder name.
- WordPress sites see the update in Dashboard > Updates and can install it with one click.

## Ignored from Git

- `_reference/` — Old plugin version for reference
- `.claude/` — Claude Code local settings
- `CLAUDE.md` — Claude Code project instructions
