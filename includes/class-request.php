<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Tiny accessor for superglobals. Centralizes wp_unslash + sanitize so callers
 * can't accidentally skip a step.
 */
class WOO_RS_Request {

    public static function post_text( $key, $default = '' ) {
        if ( ! isset( $_POST[ $key ] ) ) {
            return $default;
        }
        return sanitize_text_field( wp_unslash( $_POST[ $key ] ) );
    }

    public static function post_textarea( $key, $default = '' ) {
        if ( ! isset( $_POST[ $key ] ) ) {
            return $default;
        }
        return sanitize_textarea_field( wp_unslash( $_POST[ $key ] ) );
    }

    public static function post_int( $key, $default = 0 ) {
        return isset( $_POST[ $key ] ) ? (int) $_POST[ $key ] : $default;
    }

    public static function post_url( $key, $default = '' ) {
        if ( ! isset( $_POST[ $key ] ) ) {
            return $default;
        }
        $raw = trim( esc_url_raw( wp_unslash( $_POST[ $key ] ) ) );
        return $raw;
    }

    /**
     * Validate a URL: must parse, must use http(s), must have a host.
     * Returns the URL on success, '' on failure.
     */
    public static function valid_http_url( $url, $require_https = false ) {
        if ( '' === $url ) {
            return '';
        }
        if ( ! wp_http_validate_url( $url ) ) {
            return '';
        }
        $parts = wp_parse_url( $url );
        if ( empty( $parts['host'] ) || empty( $parts['scheme'] ) ) {
            return '';
        }
        $allowed = $require_https ? array( 'https' ) : array( 'http', 'https' );
        if ( ! in_array( strtolower( $parts['scheme'] ), $allowed, true ) ) {
            return '';
        }
        return $url;
    }
}
