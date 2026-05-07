<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class WOO_RS_API_Client {

    const MAX_CALLS_PER_INTERVAL = 160;
    const INTERVAL_SECONDS       = 300;

    private static $api_call_count = 0;
    private static $batch_start    = null;

    public static function get_api_key() {
        $encrypted = get_option( 'woo_rs_product_sync_rs_api_key', '' );
        if ( empty( $encrypted ) ) {
            return '';
        }
        return WOO_RS_Encryption::decrypt( $encrypted );
    }

    public static function get_api_url() {
        $url = get_option( 'woo_rs_product_sync_rs_api_url', '' );
        return rtrim( $url, '/' );
    }

    /**
     * Local in-process rate limiter. If the per-interval budget would be
     * exceeded, returns a WP_Error indicating how long to wait — callers
     * (cron, AJAX) reschedule rather than blocking the PHP worker.
     *
     * @return true|WP_Error
     */
    private static function rate_limit() {
        if ( null === self::$batch_start ) {
            self::$batch_start = microtime( true );
        }

        $elapsed = microtime( true ) - self::$batch_start;

        if ( $elapsed > self::INTERVAL_SECONDS ) {
            self::$api_call_count = 0;
            self::$batch_start    = microtime( true );
            return true;
        }

        if ( self::$api_call_count >= self::MAX_CALLS_PER_INTERVAL ) {
            $wait = max( 1, (int) ceil( self::INTERVAL_SECONDS - $elapsed ) );
            return new WP_Error(
                'rs_rate_limited',
                'Local rate limit reached.',
                array( 'retry_after' => $wait )
            );
        }

        return true;
    }

    public static function get( $endpoint, $params = array() ) {
        $api_key = self::get_api_key();
        if ( empty( $api_key ) ) {
            return new WP_Error( 'no_api_key', 'RepairShopr API key not configured.' );
        }

        $base_url = self::get_api_url();
        if ( empty( $base_url ) ) {
            return new WP_Error( 'no_api_url', 'RepairShopr API URL not configured.' );
        }

        $rl = self::rate_limit();
        if ( is_wp_error( $rl ) ) {
            return $rl;
        }

        $url = $base_url . '/' . ltrim( $endpoint, '/' );
        if ( ! empty( $params ) ) {
            $url = add_query_arg( $params, $url );
        }

        $response = wp_remote_get( $url, array(
            'headers' => array(
                'Authorization' => $api_key,
                'Accept'        => 'application/json',
            ),
            'timeout' => 30,
        ) );

        self::$api_call_count++;

        if ( is_wp_error( $response ) ) {
            return $response;
        }

        $code = wp_remote_retrieve_response_code( $response );
        $body = wp_remote_retrieve_body( $response );
        $data = json_decode( $body, true );

        // RS signals rate-limiting either via HTTP 429 or a 200 body containing
        // "high number of requests". Either way: surface as WP_Error with a
        // retry_after hint and let the caller reschedule. Never block the worker.
        $rate_limited = ( 429 === (int) $code )
            || ( isset( $data['error'] ) && false !== strpos( $body, 'high number of requests' ) );
        if ( $rate_limited ) {
            // Drain our local counter so the next attempt doesn't immediately
            // retrip the in-process limiter.
            self::$api_call_count = self::MAX_CALLS_PER_INTERVAL;
            return new WP_Error(
                'rs_rate_limited',
                'RepairShopr API rate limit hit.',
                array( 'retry_after' => self::INTERVAL_SECONDS )
            );
        }

        if ( $code < 200 || $code >= 300 ) {
            return new WP_Error( 'api_error', 'API returned HTTP ' . $code, $data );
        }

        return $data;
    }

    public static function fetch_product( $rs_id ) {
        $result = self::get( 'products', array( 'id' => $rs_id ) );
        if ( is_wp_error( $result ) ) {
            return $result;
        }
        if ( isset( $result['products'][0] ) ) {
            return $result['products'][0];
        }
        return new WP_Error( 'not_found', 'Product not found in RepairShopr.' );
    }

    /** Safety-net cap on pagination per query. Raised to accommodate large catalogs;
     *  category-based sync makes hitting this essentially impossible in practice. */
    const MAX_PAGES = 2000;

    public static function fetch_all_categories() {
        $result = self::get( 'products/categories' );
        if ( is_wp_error( $result ) ) {
            return $result;
        }
        return isset( $result['categories'] ) && is_array( $result['categories'] )
            ? $result['categories']
            : array();
    }

    public static function fetch_all_products( $per_page = 100 ) {
        $all_products = array();
        $page         = 1;

        do {
            $result = self::get( 'products', array(
                'page'     => $page,
                'per_page' => $per_page,
            ) );

            if ( is_wp_error( $result ) ) {
                return $result;
            }

            $products     = isset( $result['products'] ) ? $result['products'] : array();
            $all_products = array_merge( $all_products, $products );
            $page++;
        } while ( ! empty( $products ) && $page <= self::MAX_PAGES );

        return $all_products;
    }

    public static function fetch_products_page( $page = 1, $per_page = 0, $category_id = 0 ) {
        // RS ignores per_page — omit it and let the API use its native page size.
        $params = array( 'page' => $page );
        if ( $category_id > 0 ) {
            $params['category_id'] = $category_id;
        }
        $result = self::get( 'products', $params );

        if ( is_wp_error( $result ) ) {
            return $result;
        }

        return isset( $result['products'] ) ? $result['products'] : array();
    }
}
