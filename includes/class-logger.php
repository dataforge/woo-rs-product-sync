<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class WOO_RS_Logger {

    /**
     * Insert a webhook log entry.
     */
    public static function log( $method, $headers, $payload, $ip ) {
        global $wpdb;

        $table = $wpdb->prefix . WOO_RS_PRODUCT_SYNC_TABLE;

        $wpdb->insert(
            $table,
            array(
                'received_at' => current_time( 'mysql', true ),
                'http_method' => sanitize_text_field( $method ),
                'headers'     => wp_json_encode( $headers ),
                'payload'     => $payload,
                'source_ip'   => sanitize_text_field( $ip ),
            ),
            array( '%s', '%s', '%s', '%s', '%s' )
        );
    }

    /**
     * Get recent log entries.
     */
    public static function get_logs( $limit = 50, $offset = 0 ) {
        global $wpdb;

        $table = $wpdb->prefix . WOO_RS_PRODUCT_SYNC_TABLE;

        return $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$table} ORDER BY received_at DESC LIMIT %d OFFSET %d",
                $limit,
                $offset
            )
        );
    }

    /**
     * Count total log entries.
     */
    public static function count() {
        global $wpdb;

        $table = $wpdb->prefix . WOO_RS_PRODUCT_SYNC_TABLE;

        return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );
    }

    /**
     * Delete all log entries.
     */
    public static function clear() {
        global $wpdb;

        $table = $wpdb->prefix . WOO_RS_PRODUCT_SYNC_TABLE;

        $wpdb->query( "TRUNCATE TABLE {$table}" );
    }
}
