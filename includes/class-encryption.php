<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class WOO_RS_Encryption {

    /**
     * Returns the encryption secret, or '' if none is configured.
     * Order: REPAIRSHOPR_SYNC_SECRET, then AUTH_KEY (always present in real WP installs).
     * Empty-string fallback has been removed — see is_available().
     */
    private static function get_secret() {
        if ( defined( 'REPAIRSHOPR_SYNC_SECRET' ) && REPAIRSHOPR_SYNC_SECRET ) {
            return REPAIRSHOPR_SYNC_SECRET;
        }
        if ( defined( 'AUTH_KEY' ) && AUTH_KEY ) {
            return AUTH_KEY;
        }
        return '';
    }

    /**
     * True when a usable secret is configured. Callers must check this before
     * encrypting; encrypt() will return WP_Error otherwise rather than silently
     * persisting plaintext.
     */
    public static function is_available() {
        return '' !== self::get_secret();
    }

    /**
     * Encrypt a plaintext string. Returns WP_Error if no secret is configured.
     * Returns the original (empty) value if $plaintext is empty.
     *
     * @param string $plaintext
     * @return string|WP_Error
     */
    public static function encrypt( $plaintext ) {
        if ( '' === (string) $plaintext ) {
            return $plaintext;
        }
        $secret = self::get_secret();
        if ( '' === $secret ) {
            return new WP_Error(
                'woo_rs_encryption_unavailable',
                __( 'Cannot encrypt: no REPAIRSHOPR_SYNC_SECRET or AUTH_KEY defined in wp-config.php.', 'woo-rs-product-sync' )
            );
        }
        $key        = hash( 'sha256', $secret, true );
        $iv         = openssl_random_pseudo_bytes( 16 );
        $ciphertext = openssl_encrypt( $plaintext, 'AES-256-CBC', $key, 0, $iv );
        if ( false === $ciphertext ) {
            return new WP_Error( 'woo_rs_encryption_failed', __( 'openssl_encrypt failed.', 'woo-rs-product-sync' ) );
        }
        // Prepend hex-encoded IV so decrypt can extract it.
        return bin2hex( $iv ) . ':' . $ciphertext;
    }

    /**
     * Decrypt a stored ciphertext. Returns '' on any failure (never exposes
     * a partial/garbage value). Returns '' if no secret is configured.
     *
     * @param string $ciphertext
     * @return string
     */
    public static function decrypt( $ciphertext ) {
        if ( '' === (string) $ciphertext ) {
            return '';
        }
        $secret = self::get_secret();
        if ( '' === $secret ) {
            return '';
        }

        // New format: 32-char hex IV + ':' + base64 ciphertext (with derived key).
        if ( false !== strpos( $ciphertext, ':' ) ) {
            $parts = explode( ':', $ciphertext, 2 );
            $iv    = hex2bin( $parts[0] );
            if ( false !== $iv ) {
                $key       = hash( 'sha256', $secret, true );
                $decrypted = openssl_decrypt( $parts[1], 'AES-256-CBC', $key, 0, $iv );
                if ( false !== $decrypted ) {
                    return $decrypted;
                }
            }
        }

        // Legacy format: static IV derived from secret, raw secret as key.
        $iv        = substr( hash( 'sha256', $secret ), 0, 16 );
        $decrypted = openssl_decrypt( $ciphertext, 'AES-256-CBC', $secret, 0, $iv );
        return ( false === $decrypted ) ? '' : $decrypted;
    }
}
