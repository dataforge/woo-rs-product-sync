<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class WOO_RS_Cron {

    const HOOK                = 'woo_rs_product_sync_cron';
    const CONTINUATION_HOOK   = 'woo_rs_product_sync_continue';
    const CONTINUATION_OPTION = 'woo_rs_product_sync_continuation';

    public static function init() {
        add_filter( 'cron_schedules', array( __CLASS__, 'add_custom_interval' ) );
        add_action( self::HOOK, array( __CLASS__, 'run_sync' ) );
        add_action( self::CONTINUATION_HOOK, array( __CLASS__, 'run_sync' ) );
        add_action( 'wp_ajax_woo_rs_run_manual_sync', array( __CLASS__, 'ajax_manual_sync_batch' ) );
        add_action( 'wp_ajax_woo_rs_match_sku_conflict', array( __CLASS__, 'ajax_match_sku_conflict' ) );
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
        wp_clear_scheduled_hook( self::HOOK );
        wp_clear_scheduled_hook( self::CONTINUATION_HOOK );
        delete_option( self::CONTINUATION_OPTION );
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
     *
     * Primary strategy: category-by-category — each query is bounded to one
     * category so catalogs of any size are handled without hitting MAX_PAGES.
     * Fallback: full paginated scan used when categories cannot be fetched
     * (e.g. API error) or when the account has no categories configured.
     */
    public static function run_sync() {
        $api_key = WOO_RS_API_Client::get_api_key();
        $api_url = WOO_RS_API_Client::get_api_url();

        if ( empty( $api_key ) || empty( $api_url ) ) {
            return;
        }

        $continuation = get_option( self::CONTINUATION_OPTION, array() );
        $continuation = is_array( $continuation ) ? $continuation : array();
        $stats        = isset( $continuation['stats'] ) && is_array( $continuation['stats'] )
            ? array_merge( array( 'created' => 0, 'updated' => 0, 'skipped' => 0, 'errors' => 0 ), $continuation['stats'] )
            : array( 'created' => 0, 'updated' => 0, 'skipped' => 0, 'errors' => 0 );
        $last_error   = '';

        if ( 'categories' === ( $continuation['mode'] ?? '' ) && ! empty( $continuation['categories'] ) && is_array( $continuation['categories'] ) ) {
            $categories     = $continuation['categories'];
            $category_index = max( 0, (int) ( $continuation['category_index'] ?? 0 ) );
            $start_page     = max( 1, (int) ( $continuation['page'] ?? 1 ) );
        } elseif ( 'fallback' === ( $continuation['mode'] ?? '' ) ) {
            $categories     = array();
            $category_index = 0;
            $start_page     = max( 1, (int) ( $continuation['page'] ?? 1 ) );
        } else {
            $categories     = WOO_RS_API_Client::fetch_all_categories();
            $category_index = 0;
            $start_page     = 1;
            if ( is_wp_error( $categories ) && 'rs_rate_limited' === $categories->get_error_code() ) {
                self::schedule_continuation( array( 'stats' => $stats ), self::retry_after( $categories ) );
                return;
            }
        }

        if ( ! is_wp_error( $categories ) && ! empty( $categories ) ) {
            for ( $index = $category_index, $total = count( $categories ); $index < $total; $index++ ) {
                $category = $categories[ $index ];
                $cat_id   = isset( $category['id'] ) ? (int) $category['id'] : 0;
                if ( $cat_id <= 0 ) {
                    continue;
                }
                $page = ( $index === $category_index ) ? $start_page : 1;
                do {
                    $products = WOO_RS_API_Client::fetch_products_page( $page, 0, $cat_id );
                    if ( is_wp_error( $products ) ) {
                        $last_error = $products->get_error_code() . ': ' . $products->get_error_message();
                        if ( 'rs_rate_limited' === $products->get_error_code() ) {
                            self::schedule_continuation( array(
                                'mode'           => 'categories',
                                'categories'     => $categories,
                                'category_index' => $index,
                                'page'           => $page,
                                'stats'          => $stats,
                            ), self::retry_after( $products ) );
                            return;
                        }
                        break; // non-rate-limit error: skip this category, continue with next
                    }
                    foreach ( $products as $rs_product ) {
                        $result = WOO_RS_Product_Sync::sync_product( $rs_product, 'cron' );
                        if ( isset( $result['action'] ) && isset( $stats[ $result['action'] ] ) ) {
                            $stats[ $result['action'] ]++;
                        }
                    }
                    $page++;
                } while ( ! empty( $products ) && $page <= WOO_RS_API_Client::MAX_PAGES );
            }
        } else {
            // Fallback: full paginated scan when categories are unavailable.
            $page = $start_page;
            do {
                $products = WOO_RS_API_Client::fetch_products_page( $page );
                if ( is_wp_error( $products ) ) {
                    $last_error = $products->get_error_code() . ': ' . $products->get_error_message();
                    if ( 'rs_rate_limited' === $products->get_error_code() ) {
                        self::schedule_continuation( array(
                            'mode'  => 'fallback',
                            'page'  => $page,
                            'stats' => $stats,
                        ), self::retry_after( $products ) );
                        return;
                    }
                    break;
                }
                foreach ( $products as $rs_product ) {
                    $result = WOO_RS_Product_Sync::sync_product( $rs_product, 'cron' );
                    if ( isset( $result['action'] ) && isset( $stats[ $result['action'] ] ) ) {
                        $stats[ $result['action'] ]++;
                    }
                }
                $page++;
            } while ( ! empty( $products ) && $page <= WOO_RS_API_Client::MAX_PAGES );
        }

        update_option( 'woo_rs_product_sync_last_cron_run', array(
            'time'  => current_time( 'mysql', true ),
            'stats' => $stats,
            'error' => $last_error,
        ) );
        delete_option( self::CONTINUATION_OPTION );
    }

    /** Persist a resumable cursor and schedule its dedicated one-shot event. */
    private static function schedule_continuation( $state, $delay ) {
        update_option( self::CONTINUATION_OPTION, $state, false );
        if ( ! wp_next_scheduled( self::CONTINUATION_HOOK ) ) {
            wp_schedule_single_event( time() + max( 1, (int) $delay ), self::CONTINUATION_HOOK );
        }
    }

    /** Extract a conservative retry delay from a rate-limit error. */
    private static function retry_after( $error ) {
        $data = $error->get_error_data();
        return max( 1, (int) ( is_array( $data ) && isset( $data['retry_after'] ) ? $data['retry_after'] : 300 ) );
    }

    /**
     * AJAX handler for manual batch sync with progress tracking.
     */
    public static function ajax_manual_sync_batch() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Permission denied.' );
        }

        check_ajax_referer( 'woo_rs_product_sync_nonce', 'nonce' );

        $page     = isset( $_POST['page'] ) ? max( 1, (int) $_POST['page'] ) : 1;
        $per_page = isset( $_POST['per_page'] ) ? (int) $_POST['per_page'] : 50;
        $skip_product_ids = isset( $_POST['skip_product_ids'] ) && is_array( $_POST['skip_product_ids'] )
            ? array_unique( array_filter( array_map( 'absint', wp_unslash( $_POST['skip_product_ids'] ) ) ) )
            : array();
        // Clamp to a sane window: callers can't request 99,999 in one shot.
        $per_page = max( 1, min( 100, $per_page ) );
        $page     = min( $page, WOO_RS_API_Client::MAX_PAGES );

        $products = WOO_RS_API_Client::fetch_products_page( $page, $per_page );

        if ( is_wp_error( $products ) ) {
            wp_send_json_error( array(
                'code'    => $products->get_error_code(),
                'message' => $products->get_error_message(),
                'data'    => $products->get_error_data(),
            ) );
        }

        $stats = array( 'created' => 0, 'updated' => 0, 'skipped' => 0 );

        foreach ( $products as $rs_product ) {
            $rs_product_id = isset( $rs_product['id'] ) ? (int) $rs_product['id'] : 0;
            if ( $rs_product_id && in_array( $rs_product_id, $skip_product_ids, true ) ) {
                $stats['skipped']++;
                continue;
            }
            try {
                $result = WOO_RS_Product_Sync::sync_product( $rs_product, 'manual' );
            } catch ( \Throwable $e ) {
                WOO_RS_Product_Sync::log_sync( $rs_product_id, 0, 'error', 'manual', array(), 'sync_exception', $e->getMessage() );
                wp_send_json_error( array(
                    'code'       => 'sync_exception',
                    'message'    => $e->getMessage(),
                    'product_id' => $rs_product_id,
                ) );
            }
            if ( isset( $result['action'] ) && 'error' === $result['action'] ) {
                $error = isset( $result['error'] ) && is_wp_error( $result['error'] ) ? $result['error'] : null;
                wp_send_json_error( array(
                    'code'          => $error ? $error->get_error_code() : 'sync_failed',
                    'message'       => $error ? $error->get_error_message() : __( 'This product could not be synced.', 'woo-rs-product-sync' ),
                    'data'          => $error ? $error->get_error_data() : array(),
                    'rs_product_id' => isset( $rs_product['id'] ) ? (int) $rs_product['id'] : 0,
                    'wc_product_id' => isset( $result['wc_product_id'] ) ? (int) $result['wc_product_id'] : 0,
                ) );
            }
            if ( isset( $result['action'] ) && isset( $stats[ $result['action'] ] ) ) {
                $stats[ $result['action'] ]++;
            }
        }

        // RS ignores per_page; loop while the page was non-empty and we haven't
        // hit the safety cap (prevents infinite loop if RS pages wrap around).
        $more = ! empty( $products ) && $page < WOO_RS_API_Client::MAX_PAGES;

        wp_send_json_success( array(
            'processed' => count( $products ),
            'stats'     => $stats,
            'more'      => $more,
            'next_page' => $more ? $page + 1 : null,
        ) );
    }

    /** Link a confirmed WooCommerce/RepairShopr SKU match from the sync screen. */
    public static function ajax_match_sku_conflict() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => __( 'Permission denied.', 'woo-rs-product-sync' ) ), 403 );
        }

        check_ajax_referer( 'woo_rs_product_sync_nonce', 'nonce' );

        $rs_product_id = isset( $_POST['rs_product_id'] ) ? absint( $_POST['rs_product_id'] ) : 0;
        $wc_product_id = isset( $_POST['wc_product_id'] ) ? absint( $_POST['wc_product_id'] ) : 0;
        $result        = WOO_RS_Product_Sync::link_wc_product_to_rs( $wc_product_id, $rs_product_id );

        if ( is_wp_error( $result ) ) {
            wp_send_json_error( array(
                'code'    => $result->get_error_code(),
                'message' => $result->get_error_message(),
                'data'    => $result->get_error_data(),
            ) );
        }

        wp_send_json_success( array(
            'message'       => __( 'Products matched. Restarting the sync.', 'woo-rs-product-sync' ),
            'rs_product_id' => $rs_product_id,
            'wc_product_id' => $wc_product_id,
        ) );
    }

    /**
     * Get the next scheduled cron run time.
     */
    public static function get_next_run() {
        return wp_next_scheduled( self::HOOK );
    }
}
