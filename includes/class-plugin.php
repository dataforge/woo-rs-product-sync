<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class WOO_RS_Plugin {

    private static $instance = null;

    public static function instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        WOO_RS_Webhook::init();
        WOO_RS_Admin::init();
        WOO_RS_Category_Map::init();
        WOO_RS_Cron::init();
        WOO_RS_Updater::init();
    }

    /**
     * Plugin activation: create DB tables, migrate old data, generate API key, schedule cron.
     */
    public static function activate() {
        self::create_table();
        self::create_sync_log_table();
        self::migrate_old_data();

        if ( ! get_option( 'woo_rs_product_sync_api_key' ) ) {
            update_option( 'woo_rs_product_sync_api_key', wp_generate_password( 32, false ) );
        }

        WOO_RS_Cron::schedule();
    }

    /**
     * Plugin deactivation: flush rewrite rules, unschedule cron.
     */
    public static function deactivate() {
        flush_rewrite_rules();
        WOO_RS_Cron::unschedule();
    }

    /**
     * Create the webhook log table.
     */
    private static function create_table() {
        global $wpdb;

        $table  = $wpdb->prefix . WOO_RS_PRODUCT_SYNC_TABLE;
        $charset = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE {$table} (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            received_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            http_method VARCHAR(10),
            headers LONGTEXT,
            payload LONGTEXT,
            source_ip VARCHAR(45),
            INDEX idx_received (received_at)
        ) {$charset};";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta( $sql );
    }

    /**
     * Create the sync log table.
     */
    private static function create_sync_log_table() {
        global $wpdb;

        $table   = $wpdb->prefix . WOO_RS_SYNC_LOG_TABLE;
        $charset = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE {$table} (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            synced_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            rs_product_id BIGINT UNSIGNED,
            wc_product_id BIGINT UNSIGNED,
            action VARCHAR(20),
            source VARCHAR(20),
            changes LONGTEXT,
            INDEX idx_synced_at (synced_at),
            INDEX idx_rs_product_id (rs_product_id)
        ) {$charset};";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta( $sql );

        update_option( 'woo_rs_product_sync_db_version', WOO_RS_PRODUCT_SYNC_VERSION );
    }

    /**
     * Migrate data from older plugin versions if present.
     */
    private static function migrate_old_data() {
        global $wpdb;

        $new_table = $wpdb->prefix . WOO_RS_PRODUCT_SYNC_TABLE;

        // Migrate from the original df_rs_ table
        $old_table = $wpdb->prefix . 'df_rs_webhook_log';
        $old_exists = $wpdb->get_var( "SHOW TABLES LIKE '{$old_table}'" );
        if ( $old_exists ) {
            $wpdb->query( "INSERT IGNORE INTO {$new_table} SELECT * FROM {$old_table}" );
            $wpdb->query( "DROP TABLE {$old_table}" );
        }

        // Migrate API key from df_rs_ prefix
        $old_key = get_option( 'df_rs_sync_api_key' );
        if ( $old_key && ! get_option( 'woo_rs_product_sync_api_key' ) ) {
            update_option( 'woo_rs_product_sync_api_key', $old_key );
        }
        delete_option( 'df_rs_sync_api_key' );
        delete_option( 'df_rs_sync_db_version' );

        // Migrate API key from woo_rs_sync_ prefix
        $old_key2 = get_option( 'woo_rs_sync_api_key' );
        if ( $old_key2 && ! get_option( 'woo_rs_product_sync_api_key' ) ) {
            update_option( 'woo_rs_product_sync_api_key', $old_key2 );
        }
        delete_option( 'woo_rs_sync_api_key' );
        delete_option( 'woo_rs_sync_db_version' );
    }
}
