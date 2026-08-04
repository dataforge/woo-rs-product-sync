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
     * True when the OpenSSL build supports AES-256-GCM (authenticated).
     * GCM also needs an explicit tag, which PHP only exposes for AEAD ciphers.
     */
    private static function supports_gcm() {
        static $supported = null;
        if ( null === $supported ) {
            $supported = in_array( 'aes-256-gcm', openssl_get_cipher_methods(), true );
        }
        return $supported;
    }

    /**
     * Encrypt a plaintext string. Returns WP_Error if no secret is configured.
     * Returns the original (empty) value if $plaintext is empty.
     *
     * Ciphertext is authenticated: AES-256-GCM with a 96-bit random IV and a
     * 128-bit tag, stored as "v2:<hex iv>:<hex tag>:<base64 ciphertext>". Hosts
     * without GCM fall back to HMAC-SHA256-then-CBC ("h1:" prefix). A DB
     * compromise can no longer tamper with stored keys undetected.
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
        $key = hash( 'sha256', $secret, true );

        if ( self::supports_gcm() ) {
            $iv         = openssl_random_pseudo_bytes( 12 );
            $tag        = '';
            $ciphertext = openssl_encrypt( $plaintext, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag );
            if ( false === $ciphertext ) {
                return new WP_Error( 'woo_rs_encryption_failed', __( 'openssl_encrypt failed.', 'woo-rs-product-sync' ) );
            }
            return 'v2:' . bin2hex( $iv ) . ':' . bin2hex( $tag ) . ':' . base64_encode( $ciphertext );
        }

        // Fallback (no GCM support): HMAC-then-CBC so the stored value is
        // still authenticated. "h1:" prefix keeps it distinct from v2 GCM.
        $iv         = openssl_random_pseudo_bytes( 16 );
        $ciphertext = openssl_encrypt( $plaintext, 'AES-256-CBC', $key, 0, $iv );
        if ( false === $ciphertext ) {
            return new WP_Error( 'woo_rs_encryption_failed', __( 'openssl_encrypt failed.', 'woo-rs-product-sync' ) );
        }
        return 'h1:' . bin2hex( $iv ) . ':' . hash_hmac( 'sha256', $ciphertext, $key ) . ':' . $ciphertext;
    }

    /**
     * Decrypt a stored ciphertext. Returns '' on any failure (never exposes
     * a partial/garbage value). Returns '' if no secret is configured.
     * Supports, in order: v2 (GCM), h1 (HMAC-CBC), and the pre-0.6 legacy
     * unauthenticated CBC formats so existing stored keys keep decrypting.
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
        $key = hash( 'sha256', $secret, true );

        // v2: authenticated AES-256-GCM. A tampered ciphertext or tag fails
        // verification here — do not fall back to a legacy unauthenticated
        // format for a v2 payload, that would defeat the integrity check.
        if ( 0 === strpos( $ciphertext, 'v2:' ) ) {
            $parts = explode( ':', substr( $ciphertext, 3 ), 3 );
            if ( 3 === count( $parts ) ) {
                $iv  = hex2bin( $parts[0] );
                $tag = hex2bin( $parts[1] );
                if ( false !== $iv && false !== $tag ) {
                    $decrypted = openssl_decrypt( $parts[2], 'aes-256-gcm', $key, 0, $iv, $tag );
                    if ( false !== $decrypted ) {
                        return $decrypted;
                    }
                }
            }
            return '';
        }

        // h1: HMAC-SHA256-then-CBC fallback used on hosts without GCM.
        if ( 0 === strpos( $ciphertext, 'h1:' ) ) {
            $parts = explode( ':', substr( $ciphertext, 3 ), 3 );
            if ( 3 === count( $parts ) ) {
                $iv = hex2bin( $parts[0] );
                if ( false !== $iv ) {
                    $expected = hash_hmac( 'sha256', $parts[2], $key );
                    if ( hash_equals( $expected, $parts[1] ) ) {
                        $decrypted = openssl_decrypt( $parts[2], 'AES-256-CBC', $key, 0, $iv );
                        if ( false !== $decrypted ) {
                            return $decrypted;
                        }
                    }
                }
            }
            return '';
        }

        // Legacy format: hex IV + ':' + base64 ciphertext (with derived key).
        if ( false !== strpos( $ciphertext, ':' ) ) {
            $parts = explode( ':', $ciphertext, 2 );
            $iv    = hex2bin( $parts[0] );
            if ( false !== $iv ) {
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
