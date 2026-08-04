<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Centralizes raw $wpdb access for tables this plugin owns.
 *
 * Every method that interpolates a table name resolves it through table()
 * — which only accepts names from a hard-coded whitelist — so a future
 * caller cannot accidentally pass user input into a query.
 */
class WOO_RS_DB {

    /** Allowed plugin tables. Keys are short labels; values resolve through $wpdb->prefix. */
    private static function whitelist() {
        return array(
            'webhook_log' => WOO_RS_PRODUCT_SYNC_TABLE,
            'sync_log'    => WOO_RS_SYNC_LOG_TABLE,
        );
    }

    /**
     * Resolve a whitelisted table name to its prefixed form.
     *
     * @param string $key
     * @return string
     */
    public static function table( $key ) {
        global $wpdb;
        $whitelist = self::whitelist();
        if ( ! isset( $whitelist[ $key ] ) ) {
            // Hard fail in dev; in production WP will surface as a query error.
            wp_die( esc_html( sprintf( 'WOO_RS_DB: unknown table key "%s"', $key ) ) );
        }
        return $wpdb->prefix . $whitelist[ $key ];
    }

    /**
     * COUNT(*) for a whitelisted table.
     *
     * @param string $key
     * @return int
     */
    public static function count_rows( $key ) {
        global $wpdb;
        $table = self::table( $key );
        // Table name is from a hard-coded whitelist; no user input is interpolated.
        return (int) $wpdb->get_var( "SELECT COUNT(*) FROM `{$table}`" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
    }

    /**
     * TRUNCATE a whitelisted table.
     *
     * @param string $key
     */
    public static function truncate( $key ) {
        global $wpdb;
        $table = self::table( $key );
        $wpdb->query( "TRUNCATE TABLE `{$table}`" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
    }

    /**
     * Return the latest value of $column from a whitelisted table.
     * $column must match /^[a-z_][a-z0-9_]*$/i — caller-supplied identifiers
     * are validated, never interpolated as user input.
     *
     * @param string $key
     * @param string $column
     * @return string|null
     */
    public static function latest_value( $key, $column ) {
        global $wpdb;
        if ( ! preg_match( '/^[a-zA-Z_][a-zA-Z0-9_]*$/', $column ) ) {
            return null;
        }
        $table = self::table( $key );
        return $wpdb->get_var( "SELECT `{$column}` FROM `{$table}` ORDER BY `{$column}` DESC LIMIT 1" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
    }

    /**
     * Return up to $limit recent payloads from the webhook log table, newest first.
     *
     * @param int $limit
     * @return string[]
     */
    public static function recent_webhook_payloads( $limit = 200 ) {
        global $wpdb;
        $table = self::table( 'webhook_log' );
        $limit = max( 1, min( 500, (int) $limit ) );
        return (array) $wpdb->get_col( $wpdb->prepare(
            "SELECT payload FROM `{$table}` ORDER BY received_at DESC LIMIT %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $limit
        ) );
    }

    /**
     * Delete rows older than $days from a whitelisted table, keyed by $column.
     * Both tables index their timestamp columns (received_at/synced_at), so
     * this runs as an index scan. $column is validated like latest_value().
     *
     * @param string $key    Table key ('webhook_log' or 'sync_log').
     * @param string $column Timestamp column to compare against.
     * @param int    $days   Retention window in days.
     * @return int Number of rows deleted.
     */
    public static function prune( $key, $column, $days ) {
        global $wpdb;
        if ( ! preg_match( '/^[a-zA-Z_][a-zA-Z0-9_]*$/', $column ) ) {
            return 0;
        }
        $table  = self::table( $key );
        $cutoff = gmdate( 'Y-m-d H:i:s', time() - max( 1, (int) $days ) * DAY_IN_SECONDS );
        return (int) $wpdb->query( $wpdb->prepare(
            "DELETE FROM `{$table}` WHERE `{$column}` < %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $cutoff
        ) );
    }
}
