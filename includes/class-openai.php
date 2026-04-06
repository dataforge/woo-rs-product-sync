<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class WOO_RS_OpenAI {

    const DEFAULT_PROMPT = 'You are a product description writer for an e-commerce store. Rewrite the following product description to be clear, professional, and optimized for online sales. Keep it concise and highlight key features and benefits. Return only the rewritten description with no preamble or extra commentary.';

    const DEFAULT_MODEL = 'gpt-5.4-nano';

    /**
     * Per-model settings: reasoning models need higher token limits,
     * longer timeouts, and don't support temperature.
     */
    private static $model_config = array(
        'gpt-5.4-nano' => array( 'reasoning' => true,  'max_tokens' => 16384, 'timeout' => 60  ),
        'gpt-5.4-mini' => array( 'reasoning' => true,  'max_tokens' => 16384, 'timeout' => 90  ),
        'gpt-5.4'      => array( 'reasoning' => true,  'max_tokens' => 16384, 'timeout' => 120 ),
        'gpt-5-pro'    => array( 'reasoning' => true,  'max_tokens' => 16384, 'timeout' => 180 ),
    );

    /**
     * Aliases for retired/renamed models. Mapped to their closest current
     * replacement. To add a future rename, append a row here and bump
     * MIGRATION_VERSION below — migrate_models() will rewrite stored options
     * for existing installs the next time the plugin loads.
     */
    private static $model_aliases = array(
        'gpt-5-nano' => 'gpt-5.4-nano',
        'gpt-5-mini' => 'gpt-5.4-mini',
        'gpt-5'      => 'gpt-5.4',
        'gpt-5.2'    => 'gpt-5.4',
        'gpt-4.1'    => 'gpt-5.4-mini',
    );

    /**
     * Bump whenever $model_aliases changes so migrate_models() runs once
     * per install for the new mapping.
     */
    const MIGRATION_VERSION = 1;

    /**
     * One-shot migration: rewrite the stored model option if it points at a
     * retired model. Safe to call on every request — short-circuits via the
     * woo_rs_product_sync_openai_model_migration option.
     */
    public static function migrate_models() {
        $done = (int) get_option( 'woo_rs_product_sync_openai_model_migration', 0 );
        if ( $done >= self::MIGRATION_VERSION ) {
            return;
        }

        $current = get_option( 'woo_rs_product_sync_openai_model', '' );
        if ( $current && isset( self::$model_aliases[ $current ] ) ) {
            update_option( 'woo_rs_product_sync_openai_model', self::$model_aliases[ $current ] );
        }

        update_option( 'woo_rs_product_sync_openai_model_migration', self::MIGRATION_VERSION, false );
    }

    /**
     * Get config for a model, with sensible defaults for unknown models.
     */
    public static function get_model_config( $model = null ) {
        if ( null === $model ) {
            $model = self::get_model();
        }
        if ( isset( self::$model_config[ $model ] ) ) {
            return self::$model_config[ $model ];
        }
        // Default: assume non-reasoning
        return array( 'reasoning' => false, 'max_tokens' => 4096, 'timeout' => 60 );
    }

    /**
     * Build the request body for a given model, prompt, and user message.
     */
    public static function build_request_body( $model, $prompt, $user_message ) {
        $config = self::get_model_config( $model );

        $body = array(
            'model'    => $model,
            'messages' => array(
                array( 'role' => 'system', 'content' => $prompt ),
                array( 'role' => 'user',   'content' => $user_message ),
            ),
            'max_completion_tokens' => $config['max_tokens'],
        );

        if ( ! $config['reasoning'] ) {
            $body['temperature'] = 0.7;
        }

        return $body;
    }

    /**
     * Check whether OpenAI rewriting is enabled and configured.
     */
    public static function is_enabled() {
        if ( ! get_option( 'woo_rs_product_sync_openai_enabled', 0 ) ) {
            return false;
        }
        return ! empty( self::get_api_key() );
    }

    /**
     * Get the decrypted OpenAI API key.
     */
    public static function get_api_key() {
        $encrypted = get_option( 'woo_rs_product_sync_openai_api_key', '' );
        if ( empty( $encrypted ) ) {
            return '';
        }
        return WOO_RS_Encryption::decrypt( $encrypted );
    }

    /**
     * Get the user-configured prompt, or the default.
     */
    public static function get_prompt() {
        $prompt = get_option( 'woo_rs_product_sync_openai_prompt', '' );
        return ! empty( trim( $prompt ) ) ? $prompt : self::DEFAULT_PROMPT;
    }

    /**
     * Get the configured model.
     */
    public static function get_model() {
        $model = get_option( 'woo_rs_product_sync_openai_model', self::DEFAULT_MODEL );
        if ( empty( $model ) ) {
            return self::DEFAULT_MODEL;
        }
        // Resolve retired-model aliases at read time so calls work even before
        // migrate_models() has run on this install.
        if ( isset( self::$model_aliases[ $model ] ) ) {
            return self::$model_aliases[ $model ];
        }
        return $model;
    }

    /**
     * Check whether OpenAI request/response logging is enabled.
     */
    public static function is_logging_enabled() {
        return (bool) get_option( 'woo_rs_product_sync_openai_logging', 0 );
    }

    /**
     * Rewrite a product description using OpenAI.
     *
     * @param string $description    The original RS product description.
     * @param string $product_name   The product name (for context).
     * @return array Result array with 'text' (rewritten) or 'error', plus 'log' when logging enabled.
     */
    public static function rewrite_description( $description, $product_name = '' ) {
        $logging = self::is_logging_enabled();

        if ( empty( trim( $description ) ) ) {
            return array( 'text' => $description, 'error' => null );
        }

        $api_key = self::get_api_key();
        if ( empty( $api_key ) ) {
            return array( 'text' => null, 'error' => 'OpenAI API key not configured.' );
        }

        $prompt = self::get_prompt();
        $model  = self::get_model();

        $user_message = $description;
        if ( ! empty( $product_name ) ) {
            $user_message = "Product: {$product_name}\n\nDescription:\n{$description}";
        }

        $request_body = self::build_request_body( $model, $prompt, $user_message );
        $config       = self::get_model_config( $model );

        $response = wp_remote_post( 'https://api.openai.com/v1/chat/completions', array(
            'timeout' => $config['timeout'],
            'headers' => array(
                'Content-Type'  => 'application/json',
                'Authorization' => 'Bearer ' . $api_key,
            ),
            'body' => wp_json_encode( $request_body ),
        ) );

        if ( is_wp_error( $response ) ) {
            $result = array( 'text' => null, 'error' => $response->get_error_message() );
            if ( $logging ) {
                $result['log'] = array(
                    'request'  => $request_body,
                    'response' => $response->get_error_message(),
                );
            }
            return $result;
        }

        $status_code   = wp_remote_retrieve_response_code( $response );
        $response_body = json_decode( wp_remote_retrieve_body( $response ), true );

        if ( ! is_array( $response_body ) ) {
            $result = array( 'text' => null, 'error' => 'OpenAI returned an unparseable response (HTTP ' . $status_code . ').' );
            if ( $logging ) {
                $result['log'] = array(
                    'request'     => $request_body,
                    'http_status' => $status_code,
                    'response'    => wp_remote_retrieve_body( $response ),
                );
            }
            return $result;
        }

        if ( $status_code !== 200 ) {
            $error_msg = isset( $response_body['error']['message'] ) ? $response_body['error']['message'] : "HTTP {$status_code}";
            $result = array( 'text' => null, 'error' => $error_msg );
            if ( $logging ) {
                $result['log'] = array(
                    'request'       => $request_body,
                    'http_status'   => $status_code,
                    'response'      => $response_body,
                );
            }
            return $result;
        }

        // Extract output — try Chat Completions format, then Responses API format.
        $output_text = '';
        if ( ! empty( $response_body['choices'][0]['message']['content'] ) ) {
            $output_text = trim( $response_body['choices'][0]['message']['content'] );
        } elseif ( ! empty( $response_body['output'] ) && is_array( $response_body['output'] ) ) {
            foreach ( $response_body['output'] as $output_item ) {
                if ( ! is_array( $output_item ) || ! isset( $output_item['content'] ) || ! is_array( $output_item['content'] ) ) {
                    continue;
                }
                foreach ( $output_item['content'] as $content_block ) {
                    if ( is_array( $content_block ) && isset( $content_block['text'] ) && is_string( $content_block['text'] ) ) {
                        $output_text .= $content_block['text'];
                    }
                }
            }
            $output_text = trim( $output_text );
        }

        if ( empty( $output_text ) ) {
            $finish = isset( $response_body['choices'][0]['finish_reason'] ) ? $response_body['choices'][0]['finish_reason'] : 'unknown';
            $error_msg = 'length' === $finish
                ? 'OpenAI used all tokens for reasoning with none left for output. Try a smaller model like gpt-5.4-nano.'
                : 'OpenAI returned an empty response.';
            $result = array( 'text' => null, 'error' => $error_msg );
            if ( $logging ) {
                $result['log'] = array(
                    'request'  => $request_body,
                    'response' => $response_body,
                );
            }
            return $result;
        }

        $rewritten = $output_text;

        $result = array( 'text' => $rewritten, 'error' => null );
        if ( $logging ) {
            $usage = isset( $response_body['usage'] ) ? $response_body['usage'] : null;
            $result['log'] = array(
                'request'  => $request_body,
                'response' => array(
                    'model'         => isset( $response_body['model'] ) ? $response_body['model'] : $model,
                    'output'        => $rewritten,
                    'usage'         => $usage,
                    'finish_reason' => isset( $response_body['choices'][0]['finish_reason'] ) ? $response_body['choices'][0]['finish_reason'] : null,
                ),
            );
        }
        return $result;
    }

    /**
     * Rewrite a WC product's description via OpenAI and save it.
     *
     * Called after sync sets the RS description on the WC product.
     *
     * @param int    $product_id   WC product ID.
     * @param string $rs_description The original RS description that was just synced.
     * @param string $product_name   Product name for context.
     * @return array Result with status info.
     */
    public static function maybe_rewrite_product_description( $product_id, $rs_description, $product_name = '' ) {
        if ( ! self::is_enabled() ) {
            return array( 'rewritten' => false, 'reason' => 'disabled' );
        }

        if ( empty( trim( $rs_description ) ) ) {
            return array( 'rewritten' => false, 'reason' => 'empty_description' );
        }

        $result = self::rewrite_description( $rs_description, $product_name );

        if ( ! empty( $result['error'] ) ) {
            $output = array(
                'rewritten' => false,
                'reason'    => 'api_error',
                'error'     => $result['error'],
            );
            if ( isset( $result['log'] ) ) {
                $output['log'] = $result['log'];
            }
            return $output;
        }

        // Update the WC product description
        $product = wc_get_product( $product_id );
        if ( $product ) {
            $product->set_description( $result['text'] );
            $product->save();
        }

        $output = array(
            'rewritten'        => true,
            'original'         => $rs_description,
            'openai_rewritten' => $result['text'],
        );
        if ( isset( $result['log'] ) ) {
            $output['log'] = $result['log'];
        }
        return $output;
    }
}
