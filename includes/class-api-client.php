<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class WOO_RS_API_Client {

    const MAX_CALLS_PER_INTERVAL = 160;
    const INTERVAL_SECONDS       = 60;

    const RATE_STATE_OPTION = 'woo_rs_product_sync_rate_state';
    const RATE_LOCK_KEY     = 'repairshopr_api_rate_limit';

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
        $token = WOO_RS_Locks::acquire_blocking( self::RATE_LOCK_KEY, 10 );
        if ( false === $token ) {
            return new WP_Error(
                'rs_rate_limited',
                'Rate limiter is busy; retry shortly.',
                array( 'retry_after' => 1 )
            );
        }

        try {
            $now   = time();
            $state = get_option( self::RATE_STATE_OPTION, array() );
            $state = is_array( $state ) ? $state : array();
            $start = isset( $state['start'] ) ? (int) $state['start'] : 0;
            $count = isset( $state['count'] ) ? (int) $state['count'] : 0;

            if ( $start <= 0 || ( $now - $start ) >= self::INTERVAL_SECONDS ) {
                $start = $now;
                $count = 0;
            }

            if ( $count >= self::MAX_CALLS_PER_INTERVAL ) {
                return new WP_Error(
                    'rs_rate_limited',
                    'Local rate limit reached.',
                    array( 'retry_after' => max( 1, self::INTERVAL_SECONDS - ( $now - $start ) ) )
                );
            }

            update_option( self::RATE_STATE_OPTION, array(
                'start' => $start,
                'count' => $count + 1,
            ), false );
            return true;
        } finally {
            WOO_RS_Locks::release( self::RATE_LOCK_KEY, $token );
        }
    }

    /** Prevent additional workers from retrying immediately after an API 429. */
    private static function exhaust_rate_limit() {
        $token = WOO_RS_Locks::acquire_blocking( self::RATE_LOCK_KEY, 10 );
        if ( false === $token ) {
            return;
        }
        try {
            update_option( self::RATE_STATE_OPTION, array(
                'start' => time(),
                'count' => self::MAX_CALLS_PER_INTERVAL,
            ), false );
        } finally {
            WOO_RS_Locks::release( self::RATE_LOCK_KEY, $token );
        }
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
            self::exhaust_rate_limit();
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
