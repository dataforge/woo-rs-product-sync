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
        $iv = substr( hash( 'sha256', $secret ), 0, 16 );
        return openssl_encrypt( $plaintext, 'AES-256-CBC', $secret, 0, $iv );
    }

    public static function decrypt( $ciphertext ) {
        $secret = self::get_secret();
        if ( empty( $secret ) || empty( $ciphertext ) ) {
            return $ciphertext;
        }
        $iv = substr( hash( 'sha256', $secret ), 0, 16 );
        $decrypted = openssl_decrypt( $ciphertext, 'AES-256-CBC', $secret, 0, $iv );
        return ( false === $decrypted ) ? '' : $decrypted;
    }
}
