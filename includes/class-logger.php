<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class WOO_RS_Logger {

    /**
     * Header names whose values must never be persisted.
     * Compared case-insensitively against the keys passed to log().
     */
    private static $sensitive_headers = array(
        'authorization',
        'x-api-key',
        'x-auth-token',
        'cookie',
        'set-cookie',
        'proxy-authorization',
    );

    /**
     * Strip sensitive entries from a header array before persisting/logging.
     * Returns a new array; does not mutate the input.
     *
     * @param array $headers
     * @return array
     */
    public static function scrub_headers( $headers ) {
        if ( ! is_array( $headers ) ) {
            return array();
        }
        $clean = array();
        foreach ( $headers as $name => $value ) {
            $lc = strtolower( (string) $name );
            if ( in_array( $lc, self::$sensitive_headers, true ) ) {
                $clean[ $name ] = '[REDACTED]';
                continue;
            }
            $clean[ $name ] = is_array( $value ) ? array_map( 'strval', $value ) : (string) $value;
        }
        return $clean;
    }

    /**
     * Insert a webhook log entry. Headers are scrubbed of credential fields
     * before storage; callers should not pre-scrub.
     */
    public static function log( $method, $headers, $payload, $ip ) {
        global $wpdb;

        $table = WOO_RS_DB::table( 'webhook_log' );

        $wpdb->insert(
            $table,
            array(
                'received_at' => current_time( 'mysql', true ),
                'http_method' => sanitize_text_field( $method ),
                'headers'     => wp_json_encode( self::scrub_headers( $headers ) ),
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

        $table = WOO_RS_DB::table( 'webhook_log' );

        return $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM `{$table}` ORDER BY received_at DESC LIMIT %d OFFSET %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                $limit,
                $offset
            )
        );
    }

    /**
     * Count total log entries.
     */
    public static function count() {
        return WOO_RS_DB::count_rows( 'webhook_log' );
    }

    /**
     * Delete all log entries.
     */
    public static function clear() {
        WOO_RS_DB::truncate( 'webhook_log' );
    }
}
