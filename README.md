# Woo RS Product Sync

A WordPress/WooCommerce plugin that syncs products from [RepairShopr](https://www.repairshopr.com/) to WooCommerce. Products are kept in sync via real-time webhooks and optional scheduled API polling.

## Features

- **Webhook sync** -- Receives RepairShopr webhook POST requests and immediately creates or updates the matching WooCommerce product.
- **Scheduled auto-sync** -- Configurable cron job that polls the RepairShopr API and syncs all products on a recurring interval.
- **Manual full sync** -- One-click sync from the admin dashboard with a progress bar.
- **Category mapping** -- Map RepairShopr product categories to WooCommerce categories. Only mapped categories are synced. Categories are auto-discovered from webhooks, product meta, and the RS API.
- **OpenAI description rewriting** -- Optionally rewrite product descriptions using OpenAI when a product is created or its description changes. Supports multiple models (GPT-5 Nano, GPT-5 Mini, GPT-5, GPT-5.2, GPT-4.1) with per-model configuration for reasoning vs non-reasoning models.
- **Detailed sync logging** -- Tracks every sync action (created, updated, skipped) with field-level change details. Configurable logging levels.
- **Webhook logging** -- Raw webhook payloads are logged for debugging.
- **Encrypted API key storage** -- Both RepairShopr and OpenAI API keys are encrypted at rest.
- **Rate limiting** -- Built-in rate limiter for RepairShopr API calls (160 calls per 5 minutes).

## Requirements

- WordPress 5.0+
- WooCommerce 3.0+
- PHP 7.2+

## Installation

1. Download or clone this repository into `wp-content/plugins/woo-rs-product-sync/`.
2. Activate the plugin in WordPress under **Plugins**.
3. Go to **WooCommerce > Woo RS Product Sync** to configure.

## Configuration

### Settings tab

- **RS API Key** -- Your RepairShopr API key.
- **RS API URL** -- Your RepairShopr API base URL (e.g. `https://your-subdomain.repairshopr.com/api/v1`).
- **Auto-Sync** -- Enable/disable scheduled cron sync and set the interval in minutes.
- **New Product Status** -- Default WooCommerce status for newly created products (Published, Pending Review, or Draft). Products marked disabled in RepairShopr are always set to Draft.
- **Logging Level** -- No logging, changes only, or all sync activity.

### Webhook setup

The plugin registers a REST API endpoint at:

```
https://your-site.com/wp-json/woo-rs-product-sync/v1/webhook?key=YOUR_API_KEY
```

Copy this URL from the Settings tab and paste it into RepairShopr's webhook configuration.

### Category mapping

Go to the **Categories** tab to map RepairShopr categories to WooCommerce categories. Products in unmapped categories are skipped during sync.

### OpenAI description rewriting

When enabled, product descriptions are sent to OpenAI for rewriting on:
- New product creation (always)
- Existing product updates (only when the description changes)

Configure your OpenAI API key, model, and system prompt on the Settings tab. Use the **Test OpenAI** section to preview results with a sample product before going live.

## How sync works

### Field mapping

| RepairShopr Field | WooCommerce Field |
|---|---|
| `name` | Product name |
| `description` | Product description |
| `long_description` | Short description |
| `price_retail` | Regular price |
| `quantity` | Stock quantity |
| `sort_order` | Menu order |
| `maintain_stock` | Manage stock |
| `disabled` | Status (forces Draft) |
| `taxable` | Tax status |
| `product_category` | Product categories (via mapping) |

Additional RepairShopr fields (`price_cost`, `price_wholesale`, `upc_code`, `condition`, `physical_location`, `warranty`, etc.) are stored as post meta for reference.

### Product matching

Products are matched by RepairShopr product ID:
1. First checks WooCommerce SKU (RS product ID is stored as the SKU)
2. Falls back to `_rs_product_id` post meta

### Update behavior

- Product updates never change WooCommerce publish status unless the RepairShopr `disabled` flag is true (forces Draft).
- Field changes use type-aware comparison (float epsilon for prices, integer cast for quantities, normalized string comparison for text).

## Admin pages

The plugin adds a menu item under **WooCommerce** with four tabs:

- **Dashboard** -- Sync status overview, last cron run stats, and manual full sync button.
- **Settings** -- API keys, sync options, OpenAI configuration, and test tool.
- **Categories** -- RepairShopr to WooCommerce category mapping interface.
- **Logs** -- Sync activity log and raw webhook log with expandable payload details.

## License

GPL-2.0-or-later
