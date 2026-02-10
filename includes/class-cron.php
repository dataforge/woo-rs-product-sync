<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class WOO_RS_Cron {

    const HOOK = 'woo_rs_product_sync_cron';

    public static function init() {
        add_filter( 'cron_schedules', array( __CLASS__, 'add_custom_interval' ) );
        add_action( self::HOOK, array( __CLASS__, 'run_sync' ) );
        add_action( 'wp_ajax_woo_rs_run_manual_sync', array( __CLASS__, 'ajax_manual_sync_batch' ) );
    }

    /**
     * Add custom cron interval based on settings.
     */
    public static function add_custom_interval( $schedules ) {
        $minutes = (int) get_option( 'woo_rs_product_sync_sync_interval', 60 );
        if ( $minutes < 1 ) {
            $minutes = 60;
        }

        $schedules['woo_rs_product_sync_interval'] = array(
            'interval' => $minutes * 60,
            'display'  => sprintf( 'Every %d minutes (RS Product Sync)', $minutes ),
        );

        return $schedules;
    }

    /**
     * Schedule the cron event.
     */
    public static function schedule() {
        $auto_sync = get_option( 'woo_rs_product_sync_auto_sync', 0 );

        if ( $auto_sync ) {
            if ( ! wp_next_scheduled( self::HOOK ) ) {
                wp_schedule_event( time(), 'woo_rs_product_sync_interval', self::HOOK );
            }
        }
    }

    /**
     * Unschedule the cron event.
     */
    public static function unschedule() {
        $timestamp = wp_next_scheduled( self::HOOK );
        if ( $timestamp ) {
            wp_unschedule_event( $timestamp, self::HOOK );
        }
    }

    /**
     * Reschedule: clear existing and re-register if auto-sync is on.
     */
    public static function reschedule() {
        self::unschedule();
        self::schedule();
    }

    /**
     * Cron callback: fetch all RS products and sync each one.
     */
    public static function run_sync() {
        $api_key = WOO_RS_API_Client::get_api_key();
        $api_url = WOO_RS_API_Client::get_api_url();

        if ( empty( $api_key ) || empty( $api_url ) ) {
            return;
        }

        $stats    = array( 'created' => 0, 'updated' => 0, 'skipped' => 0 );
        $page     = 1;
        $per_page = 100;

        do {
            $products = WOO_RS_API_Client::fetch_products_page( $page, $per_page );
            if ( is_wp_error( $products ) ) {
                break;
            }

            foreach ( $products as $rs_product ) {
                $result = WOO_RS_Product_Sync::sync_product( $rs_product, 'cron' );
                if ( isset( $result['action'] ) && isset( $stats[ $result['action'] ] ) ) {
                    $stats[ $result['action'] ]++;
                }
            }

            $page++;
        } while ( count( $products ) >= $per_page );

        update_option( 'woo_rs_product_sync_last_cron_run', array(
            'time'  => current_time( 'mysql', true ),
            'stats' => $stats,
        ) );
    }

    /**
     * AJAX handler for manual batch sync with progress tracking.
     */
    public static function ajax_manual_sync_batch() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Permission denied.' );
        }

        check_ajax_referer( 'woo_rs_product_sync_nonce', 'nonce' );

        $page      = isset( $_POST['page'] ) ? (int) $_POST['page'] : 1;
        $per_page  = isset( $_POST['per_page'] ) ? (int) $_POST['per_page'] : 50;

        $products = WOO_RS_API_Client::fetch_products_page( $page, $per_page );

        if ( is_wp_error( $products ) ) {
            wp_send_json_error( $products->get_error_message() );
        }

        $stats = array( 'created' => 0, 'updated' => 0, 'skipped' => 0 );

        foreach ( $products as $rs_product ) {
            $result = WOO_RS_Product_Sync::sync_product( $rs_product, 'manual' );
            if ( isset( $result['action'] ) && isset( $stats[ $result['action'] ] ) ) {
                $stats[ $result['action'] ]++;
            }
        }

        $more = count( $products ) >= $per_page;

        wp_send_json_success( array(
            'processed' => count( $products ),
            'stats'     => $stats,
            'more'      => $more,
            'next_page' => $more ? $page + 1 : null,
        ) );
    }

    /**
     * Get the next scheduled cron run time.
     */
    public static function get_next_run() {
        return wp_next_scheduled( self::HOOK );
    }
}
