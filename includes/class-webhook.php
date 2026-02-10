<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class WOO_RS_Webhook {

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
     * Validate the API key if one is configured.
     */
    public static function check_auth( WP_REST_Request $request ) {
        $stored_key = get_option( 'woo_rs_product_sync_api_key' );

        if ( empty( $stored_key ) ) {
            return true;
        }

        $provided_key = $request->get_param( 'key' );

        if ( empty( $provided_key ) ) {
            return new WP_Error(
                'rest_forbidden',
                'Missing API key.',
                array( 'status' => 403 )
            );
        }

        if ( ! hash_equals( $stored_key, $provided_key ) ) {
            return new WP_Error(
                'rest_forbidden',
                'Invalid API key.',
                array( 'status' => 403 )
            );
        }

        return true;
    }

    /**
     * Handle incoming webhook request: log it, sync the product, and return 200.
     */
    public static function handle( WP_REST_Request $request ) {
        $headers = $request->get_headers();
        $body    = $request->get_body();
        $method  = $_SERVER['REQUEST_METHOD'] ?? 'POST';
        $ip      = $_SERVER['REMOTE_ADDR'] ?? '';

        // Always log the raw webhook
        WOO_RS_Logger::log( $method, $headers, $body, $ip );

        // Attempt to sync the product
        $sync_result = null;
        $data = json_decode( $body, true );

        if ( $data ) {
            // RS webhooks wrap product data under "attributes"
            $rs_product = isset( $data['attributes'] ) ? $data['attributes'] : $data;

            if ( ! empty( $rs_product['id'] ) ) {
                try {
                    $sync_result = WOO_RS_Product_Sync::sync_product( $rs_product, 'webhook' );
                } catch ( \Exception $e ) {
                    // Don't let sync errors block the 200 response to RS
                    $sync_result = array(
                        'action' => 'error',
                        'error'  => $e->getMessage(),
                    );
                }
            }
        }

        return new WP_REST_Response( array(
            'success' => true,
            'message' => 'Webhook received.',
            'sync'    => $sync_result,
        ), 200 );
    }
}
