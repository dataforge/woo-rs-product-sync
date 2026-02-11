<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class WOO_RS_Encryption {

    private static function get_secret() {
        if ( defined( 'REPAIRSHOPR_SYNC_SECRET' ) && REPAIRSHOPR_SYNC_SECRET ) {
            return REPAIRSHOPR_SYNC_SECRET;
        }
        if ( defined( 'AUTH_KEY' ) && AUTH_KEY ) {
            return AUTH_KEY;
        }
        return '';
    }

    public static function encrypt( $plaintext ) {
        $secret = self::get_secret();
        if ( empty( $secret ) || empty( $plaintext ) ) {
            return $plaintext;
        }
        $key        = hash( 'sha256', $secret, true );
        $iv         = openssl_random_pseudo_bytes( 16 );
        $ciphertext = openssl_encrypt( $plaintext, 'AES-256-CBC', $key, 0, $iv );
        // Prepend hex-encoded IV so decrypt can extract it.
        return bin2hex( $iv ) . ':' . $ciphertext;
    }

    public static function decrypt( $ciphertext ) {
        $secret = self::get_secret();
        if ( empty( $secret ) || empty( $ciphertext ) ) {
            return $ciphertext;
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
