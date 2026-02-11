<?php
/**
 * Plugin Name:       Woo RS Product Sync
 * Plugin URI:        https://github.com/dataforge/woo-rs-product-sync
 * Description:       Syncs products from RepairShopr to WooCommerce via webhooks and scheduled API polling.
 * Version:           0.3.1
 * Author:            Dataforge
 * Author URI:        https://dataforge.us
 * License:           GPL-2.0-or-later
 * Text Domain:       woo-rs-product-sync
 * Requires PHP:      7.2
 * Update URI:        https://github.com/dataforge/woo-rs-product-sync
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'WOO_RS_PRODUCT_SYNC_VERSION', '0.3.1' );
define( 'WOO_RS_PRODUCT_SYNC_FILE', __FILE__ );
define( 'WOO_RS_PRODUCT_SYNC_DIR', plugin_dir_path( __FILE__ ) );
define( 'WOO_RS_PRODUCT_SYNC_URL', plugin_dir_url( __FILE__ ) );
define( 'WOO_RS_PRODUCT_SYNC_TABLE', 'woo_rs_webhook_log' );
define( 'WOO_RS_SYNC_LOG_TABLE', 'woo_rs_product_sync_log' );

require_once WOO_RS_PRODUCT_SYNC_DIR . 'includes/class-logger.php';
require_once WOO_RS_PRODUCT_SYNC_DIR . 'includes/class-encryption.php';
require_once WOO_RS_PRODUCT_SYNC_DIR . 'includes/class-api-client.php';
require_once WOO_RS_PRODUCT_SYNC_DIR . 'includes/class-openai.php';
require_once WOO_RS_PRODUCT_SYNC_DIR . 'includes/class-product-sync.php';
require_once WOO_RS_PRODUCT_SYNC_DIR . 'includes/class-category-map.php';
require_once WOO_RS_PRODUCT_SYNC_DIR . 'includes/class-cron.php';
require_once WOO_RS_PRODUCT_SYNC_DIR . 'includes/class-webhook.php';
require_once WOO_RS_PRODUCT_SYNC_DIR . 'includes/class-admin.php';
require_once WOO_RS_PRODUCT_SYNC_DIR . 'includes/class-updater.php';
require_once WOO_RS_PRODUCT_SYNC_DIR . 'includes/class-plugin.php';

register_activation_hook( __FILE__, array( 'WOO_RS_Plugin', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'WOO_RS_Plugin', 'deactivate' ) );

WOO_RS_Plugin::instance();
