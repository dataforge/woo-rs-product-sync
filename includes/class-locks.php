<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Atomic mutual-exclusion locks backed by a dedicated MySQL table.
 *
 * Why not transients? get_transient + set_transient is a check-then-act
 * pattern; two concurrent webhook handlers can both observe "no lock"
 * and both write, defeating the purpose. INSERT ... ON DUPLICATE KEY UPDATE
 * is performed in a single statement and is therefore safe even when
 * multiple PHP workers race for the same product.
 *
 * Lock semantics:
 *   - acquire($key, $ttl) returns a token (string) on success, false on busy.
 *   - release($key, $token) deletes the row only if $token still matches —
 *     a slow worker whose lock has already expired and been re-acquired by
 *     someone else will not erase the new owner's lock.
 *   - Expired rows are claimed transparently on the next acquire().
 */
class WOO_RS_Locks {

    /**
     * Per-product lock TTL (seconds). This must exceed the longest optional
     * OpenAI request, which runs while the product write lock is held.
     */
    const DEFAULT_TTL = 300;

    /** Max attempts a caller may use when waiting for a busy lock. */
    const MAX_ATTEMPTS = 20;

    /** Microseconds to sleep between attempts. */
    const BACKOFF_USEC = 250000;

    /** Internal table key (resolved through WOO_RS_DB::table()). */
    const TABLE = 'woo_rs_locks';

    /** Bumped whenever the schema changes; triggers a dbDelta on plugins_loaded. */
    const DB_VERSION = 1;

    /**
     * Create or upgrade the locks table. Safe to call repeatedly.
     */
    public static function ensure_table() {
        global $wpdb;

        $installed = (int) get_option( 'woo_rs_locks_db_version', 0 );
        $table     = $wpdb->prefix . self::TABLE;
        $exists    = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) );
        if ( $installed >= self::DB_VERSION && $exists === $table ) {
            return;
        }

        $charset = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE {$table} (
            lock_key VARCHAR(191) NOT NULL,
            token VARCHAR(64) NOT NULL,
            locked_until BIGINT UNSIGNED NOT NULL,
            PRIMARY KEY (lock_key),
            KEY idx_locked_until (locked_until)
        ) {$charset};";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta( $sql );

        // Do not mark the migration complete if dbDelta failed to create the
        // table (for example, due to a database-permission problem).
        $exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) );
        if ( $exists === $table ) {
            update_option( 'woo_rs_locks_db_version', self::DB_VERSION );
        }
    }

    /**
     * Try to take a lock. Returns a token (string) on success, false on busy.
     * Atomic across PHP workers via INSERT ... ON DUPLICATE KEY UPDATE.
     *
     * @param string $key Logical lock name.
     * @param int    $ttl Seconds until the lock auto-expires.
     * @return string|false
     */
    public static function acquire( $key, $ttl = self::DEFAULT_TTL ) {
        global $wpdb;

        $table = $wpdb->prefix . self::TABLE;
        $now   = time();
        $until = $now + max( 1, (int) $ttl );
        $token = wp_generate_password( 32, false, false );

        // Insert if no row, otherwise overwrite only when the existing row has expired.
        // affected_rows: 1 = inserted, 2 = updated (we won), 0 = untouched (someone else holds it).
        $sql = $wpdb->prepare(
            "INSERT INTO `{$table}` (lock_key, token, locked_until)
             VALUES (%s, %s, %d)
             ON DUPLICATE KEY UPDATE
                token = IF(locked_until < %d, VALUES(token), token),
                locked_until = IF(locked_until < %d, VALUES(locked_until), locked_until)",
            $key, $token, $until, $now, $now
        );
        $wpdb->query( $sql );

        if ( $wpdb->rows_affected < 1 ) {
            return false;
        }

        // Confirm the row actually carries our token (on insert it definitely does;
        // on update we only won if locked_until < $now). A second SELECT is the
        // simplest way to know without parsing connection state.
        $current = $wpdb->get_var( $wpdb->prepare(
            "SELECT token FROM `{$table}` WHERE lock_key = %s",
            $key
        ) );

        return ( $current === $token ) ? $token : false;
    }

    /**
     * Wait up to (MAX_ATTEMPTS * BACKOFF_USEC) for a lock, then return token or false.
     *
     * @param string $key
     * @param int    $ttl
     * @return string|false
     */
    public static function acquire_blocking( $key, $ttl = self::DEFAULT_TTL ) {
        for ( $i = 0; $i < self::MAX_ATTEMPTS; $i++ ) {
            $token = self::acquire( $key, $ttl );
            if ( false !== $token ) {
                return $token;
            }
            usleep( self::BACKOFF_USEC );
        }
        return false;
    }

    /**
     * Release a lock if and only if the supplied token still matches.
     */
    public static function release( $key, $token ) {
        global $wpdb;
        $table = $wpdb->prefix . self::TABLE;
        $wpdb->query( $wpdb->prepare(
            "DELETE FROM `{$table}` WHERE lock_key = %s AND token = %s",
            $key, $token
        ) );
    }
}
