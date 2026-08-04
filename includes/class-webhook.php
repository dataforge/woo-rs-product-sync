<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class WOO_RS_Webhook {

    /** Option storing the (encrypted) HMAC shared secret. Empty = HMAC verification disabled. */
    const OPTION_HMAC_SECRET = 'woo_rs_product_sync_webhook_hmac_secret';

    /** Option storing a CSV of allowed source IPs. Empty = no IP allowlisting. */
    const OPTION_IP_ALLOWLIST = 'woo_rs_product_sync_webhook_ip_allowlist';

    /** Header name carrying the HMAC. Format: "sha256=<hex>". */
    const SIGNATURE_HEADER = 'x_rs_signature';

    public static function init() {
        add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
    }

    public static function register_routes() {
        register_rest_route( 'woo-rs-product-sync/v1', '/webhook', array(
            'methods'             => 'POST',
            'callback'            => array( __CLASS__, 'handle' ),
            'permission_callback' => array( __CLASS__, 'check_auth' ),
        ) );
    }

    /**
     * Authenticate an inbound webhook. Layered checks (all enforced if configured):
     *   1. API key (always required) — query param `key` matched in constant time.
     *   2. IP allowlist (optional)   — REMOTE_ADDR must be in CSV list.
     *   3. HMAC signature (optional) — header X-RS-Signature: sha256=<hex(hmac_sha256(secret, body))>.
     */
    public static function check_auth( WP_REST_Request $request ) {
        // 1. API key.
        $stored_key = get_option( 'woo_rs_product_sync_api_key' );
        if ( empty( $stored_key ) ) {
            return new WP_Error( 'rest_forbidden', 'Webhook API key not configured.', array( 'status' => 403 ) );
        }
        $provided_key = (string) $request->get_param( 'key' );
        if ( '' === $provided_key || ! hash_equals( $stored_key, $provided_key ) ) {
            return new WP_Error( 'rest_forbidden', 'Invalid or missing API key.', array( 'status' => 403 ) );
        }

        // 2. IP allowlist (optional).
        $allowlist_raw = (string) get_option( self::OPTION_IP_ALLOWLIST, '' );
        if ( '' !== trim( $allowlist_raw ) ) {
            $client_ip = self::client_ip();
            if ( ! self::ip_allowed( $client_ip, $allowlist_raw ) ) {
                return new WP_Error( 'rest_forbidden', 'Source IP not allowed.', array( 'status' => 403 ) );
            }
        }

        // 3. HMAC signature (optional).
        $secret_encrypted = (string) get_option( self::OPTION_HMAC_SECRET, '' );
        if ( '' !== $secret_encrypted ) {
            $secret = WOO_RS_Encryption::decrypt( $secret_encrypted );
            if ( '' === $secret ) {
                return new WP_Error( 'rest_forbidden', 'Webhook HMAC misconfigured.', array( 'status' => 500 ) );
            }
            $sig_header = (string) $request->get_header( self::SIGNATURE_HEADER );
            if ( '' === $sig_header || 0 !== strpos( $sig_header, 'sha256=' ) ) {
                return new WP_Error( 'rest_forbidden', 'Missing webhook signature.', array( 'status' => 403 ) );
            }
            $provided = substr( $sig_header, 7 );
            $expected = hash_hmac( 'sha256', $request->get_body(), $secret );
            if ( ! hash_equals( $expected, $provided ) ) {
                return new WP_Error( 'rest_forbidden', 'Invalid webhook signature.', array( 'status' => 403 ) );
            }
        }

        return true;
    }

    /**
     * Handle incoming webhook request: log it, sync the product, and return 200.
     * Malformed deliveries (non-JSON body or missing product id) are logged as
     * error rows and answered with a 4xx so RepairShopr can retry instead of
     * silently dropping the event.
     */
    public static function handle( WP_REST_Request $request ) {
        $headers = $request->get_headers();
        $body    = $request->get_body();
        $method  = isset( $_SERVER['REQUEST_METHOD'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) : 'POST';
        $ip      = self::client_ip();

        // Logger scrubs sensitive headers (Authorization etc.) before persisting.
        WOO_RS_Logger::log( $method, $headers, $body, $ip );

        $data = json_decode( $body, true );

        if ( ! is_array( $data ) ) {
            WOO_RS_Product_Sync::log_sync( 0, 0, 'error', 'webhook', array(), 'malformed_payload', 'Webhook body is not valid JSON.' );
            return new WP_REST_Response( array(
                'success' => false,
                'message' => 'Invalid JSON payload.',
            ), 400 );
        }

        // RS webhooks wrap product data under "attributes".
        $rs_product = isset( $data['attributes'] ) && is_array( $data['attributes'] ) ? $data['attributes'] : $data;

        if ( empty( $rs_product['id'] ) ) {
            WOO_RS_Product_Sync::log_sync( 0, 0, 'error', 'webhook', array(), 'missing_product_id', 'Webhook payload has no product id.' );
            return new WP_REST_Response( array(
                'success' => false,
                'message' => 'Missing product id.',
            ), 400 );
        }

        try {
            $result = WOO_RS_Product_Sync::sync_product( $rs_product, 'webhook' );
        } catch ( \Throwable $e ) {
            WOO_RS_Product_Sync::log_sync( (int) $rs_product['id'], 0, 'error', 'webhook', array( 'exception' => $e->getMessage() ), 'sync_exception', $e->getMessage() );
            return new WP_REST_Response( array(
                'success' => false,
                'message' => 'Webhook processing failed.',
            ), 500 );
        }

        // Transient failures get a 5xx so RepairShopr redelivers the event.
        // Durable business errors (rs_sku_conflict, rs_duplicate_wc_sku) keep
        // the 200: retrying them would loop forever and an admin must resolve
        // them on screen anyway.
        if ( is_array( $result ) && self::is_retryable_failure( $result ) ) {
            return new WP_REST_Response( array(
                'success' => false,
                'message' => 'Webhook sync failed; please retry.',
            ), 503 );
        }

        return new WP_REST_Response( array(
            'success' => true,
            'message' => 'Webhook received.',
        ), 200 );
    }

    /**
     * True when a sync result represents a transient failure worth redelivering.
     * lock_busy is reported as action 'skipped' with reason 'lock_busy' (see
     * WOO_RS_Product_Sync::sync_product()); save failures arrive as WP_Error
     * error codes.
     *
     * @param array $result Sync result from WOO_RS_Product_Sync::sync_product().
     * @return bool
     */
    private static function is_retryable_failure( $result ) {
        if ( isset( $result['reason'] ) && 'lock_busy' === $result['reason'] ) {
            return true;
        }
        if ( isset( $result['action'] ) && 'error' === $result['action'] && isset( $result['error'] ) && is_wp_error( $result['error'] ) ) {
            return in_array( $result['error']->get_error_code(), array( 'wc_save_failed', 'wc_product_not_found', 'lock_busy' ), true );
        }
        return false;
    }

    /**
     * Best-effort client IP from REMOTE_ADDR. We do not honor X-Forwarded-For
     * by default — that header is trivially spoofable unless your stack
     * normalizes it. Admins fronting WP behind a trusted proxy should configure
     * the proxy to set REMOTE_ADDR.
     */
    private static function client_ip() {
        if ( empty( $_SERVER['REMOTE_ADDR'] ) ) {
            return '';
        }
        $ip = sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) );
        return ( false !== filter_var( $ip, FILTER_VALIDATE_IP ) ) ? $ip : '';
    }

    /**
     * @param string $ip       Validated client IP.
     * @param string $csv_list Allowlist CSV (IPs only — CIDR not yet supported).
     */
    private static function ip_allowed( $ip, $csv_list ) {
        if ( '' === $ip ) {
            return false;
        }
        $entries = array_filter( array_map( 'trim', explode( ',', $csv_list ) ) );
        foreach ( $entries as $entry ) {
            if ( hash_equals( $entry, $ip ) ) {
                return true;
            }
        }
        return false;
    }
}
