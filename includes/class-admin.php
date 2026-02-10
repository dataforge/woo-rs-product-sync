<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class WOO_RS_Admin {

    public static function init() {
        add_action( 'admin_menu', array( __CLASS__, 'add_menu' ) );
        add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ) );
        add_action( 'admin_post_woo_rs_product_sync_clear_logs', array( __CLASS__, 'handle_clear_logs' ) );
        add_action( 'admin_post_woo_rs_product_sync_clear_sync_logs', array( __CLASS__, 'handle_clear_sync_logs' ) );
        add_action( 'admin_post_woo_rs_product_sync_regenerate_key', array( __CLASS__, 'handle_regenerate_key' ) );
        add_action( 'admin_post_woo_rs_product_sync_save_settings', array( __CLASS__, 'handle_save_settings' ) );
        add_action( 'wp_ajax_woo_rs_test_openai', array( __CLASS__, 'ajax_test_openai' ) );
    }

    /**
     * Register admin menu page — under WooCommerce if available, otherwise under Tools.
     */
    public static function add_menu() {
        if ( class_exists( 'WooCommerce' ) ) {
            add_submenu_page(
                'woocommerce',
                'Woo RS Product Sync',
                'Woo RS Product Sync',
                'manage_options',
                'woo-rs-product-sync',
                array( __CLASS__, 'render_page' )
            );
        } else {
            add_management_page(
                'Woo RS Product Sync',
                'Woo RS Product Sync',
                'manage_options',
                'woo-rs-product-sync',
                array( __CLASS__, 'render_page' )
            );
        }
    }

    public static function enqueue_assets( $hook ) {
        if ( false === strpos( $hook, 'woo-rs-product-sync' ) ) {
            return;
        }

        wp_enqueue_style(
            'woo-rs-product-sync-admin',
            WOO_RS_PRODUCT_SYNC_URL . 'assets/admin.css',
            array(),
            WOO_RS_PRODUCT_SYNC_VERSION
        );

        wp_enqueue_script(
            'woo-rs-product-sync-admin',
            WOO_RS_PRODUCT_SYNC_URL . 'assets/admin.js',
            array( 'jquery' ),
            WOO_RS_PRODUCT_SYNC_VERSION,
            true
        );

        wp_localize_script( 'woo-rs-product-sync-admin', 'woo_rs_sync', array(
            'ajax_url' => admin_url( 'admin-ajax.php' ),
            'nonce'    => wp_create_nonce( 'woo_rs_product_sync_nonce' ),
        ) );
    }

    /* ───────────────────────────────────────────────────
     * Admin post handlers
     * ─────────────────────────────────────────────────── */

    public static function handle_clear_logs() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( 'Unauthorized' );
        }
        check_admin_referer( 'woo_rs_product_sync_clear_logs' );

        WOO_RS_Logger::clear();

        wp_safe_redirect( add_query_arg( array( 'tab' => 'logs', 'cleared' => '1' ), admin_url( 'admin.php?page=woo-rs-product-sync' ) ) );
        exit;
    }

    public static function handle_clear_sync_logs() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( 'Unauthorized' );
        }
        check_admin_referer( 'woo_rs_product_sync_clear_sync_logs' );

        WOO_RS_Product_Sync::clear_sync_logs();

        wp_safe_redirect( add_query_arg( array( 'tab' => 'logs', 'sync_cleared' => '1' ), admin_url( 'admin.php?page=woo-rs-product-sync' ) ) );
        exit;
    }

    public static function handle_regenerate_key() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( 'Unauthorized' );
        }
        check_admin_referer( 'woo_rs_product_sync_regenerate_key' );

        update_option( 'woo_rs_product_sync_api_key', wp_generate_password( 32, false ) );

        wp_safe_redirect( add_query_arg( array( 'tab' => 'settings', 'key_regenerated' => '1' ), admin_url( 'admin.php?page=woo-rs-product-sync' ) ) );
        exit;
    }

    public static function handle_save_settings() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( 'Unauthorized' );
        }
        check_admin_referer( 'woo_rs_product_sync_save_settings' );

        // RS API Key — encrypted storage
        if ( isset( $_POST['rs_api_key'] ) ) {
            $submitted_key = sanitize_text_field( wp_unslash( $_POST['rs_api_key'] ) );
            $stored_encrypted = get_option( 'woo_rs_product_sync_rs_api_key', '' );
            $stored_decrypted = ! empty( $stored_encrypted ) ? WOO_RS_Encryption::decrypt( $stored_encrypted ) : '';

            // Only update if the submitted value is not the masked version
            $masked = self::mask_key( $stored_decrypted );
            if ( $submitted_key !== $masked && ! empty( $submitted_key ) ) {
                $encrypted = WOO_RS_Encryption::encrypt( $submitted_key );
                update_option( 'woo_rs_product_sync_rs_api_key', $encrypted );
            }
        }

        // RS API URL
        if ( isset( $_POST['rs_api_url'] ) ) {
            $url = trim( esc_url_raw( wp_unslash( $_POST['rs_api_url'] ) ) );
            update_option( 'woo_rs_product_sync_rs_api_url', $url );
        }

        // Auto-sync toggle
        $auto_sync = isset( $_POST['auto_sync'] ) ? 1 : 0;
        update_option( 'woo_rs_product_sync_auto_sync', $auto_sync );

        // Sync interval
        if ( isset( $_POST['sync_interval'] ) ) {
            $interval = max( 1, (int) $_POST['sync_interval'] );
            update_option( 'woo_rs_product_sync_sync_interval', $interval );
        }

        // Logging level
        if ( isset( $_POST['logging_level'] ) ) {
            $level = sanitize_text_field( $_POST['logging_level'] );
            if ( ! in_array( $level, array( 'none', 'changes_only', 'all' ), true ) ) {
                $level = 'changes_only';
            }
            update_option( 'woo_rs_product_sync_logging_level', $level );
        }

        // New product status
        if ( isset( $_POST['new_product_status'] ) ) {
            $status = sanitize_text_field( $_POST['new_product_status'] );
            if ( ! in_array( $status, array( 'publish', 'pending', 'draft' ), true ) ) {
                $status = 'publish';
            }
            update_option( 'woo_rs_product_sync_new_product_status', $status );
        }

        // OpenAI settings
        $openai_enabled = isset( $_POST['openai_enabled'] ) ? 1 : 0;
        update_option( 'woo_rs_product_sync_openai_enabled', $openai_enabled );

        if ( isset( $_POST['openai_api_key'] ) ) {
            $submitted_openai_key = sanitize_text_field( wp_unslash( $_POST['openai_api_key'] ) );
            $stored_openai_encrypted = get_option( 'woo_rs_product_sync_openai_api_key', '' );
            $stored_openai_decrypted = ! empty( $stored_openai_encrypted ) ? WOO_RS_Encryption::decrypt( $stored_openai_encrypted ) : '';

            $masked_openai = self::mask_key( $stored_openai_decrypted );
            if ( $submitted_openai_key !== $masked_openai && ! empty( $submitted_openai_key ) ) {
                $encrypted_openai = WOO_RS_Encryption::encrypt( $submitted_openai_key );
                update_option( 'woo_rs_product_sync_openai_api_key', $encrypted_openai );
            }
        }

        if ( isset( $_POST['openai_model'] ) ) {
            $model = sanitize_text_field( wp_unslash( $_POST['openai_model'] ) );
            update_option( 'woo_rs_product_sync_openai_model', $model );
        }

        $openai_logging = isset( $_POST['openai_logging'] ) ? 1 : 0;
        update_option( 'woo_rs_product_sync_openai_logging', $openai_logging );

        if ( isset( $_POST['openai_prompt'] ) ) {
            $prompt = sanitize_textarea_field( wp_unslash( $_POST['openai_prompt'] ) );
            update_option( 'woo_rs_product_sync_openai_prompt', $prompt );
        }

        // Reschedule cron in case interval or toggle changed
        WOO_RS_Cron::reschedule();

        wp_safe_redirect( add_query_arg( array( 'tab' => 'settings', 'saved' => '1' ), admin_url( 'admin.php?page=woo-rs-product-sync' ) ) );
        exit;
    }

    /**
     * AJAX handler: test OpenAI API key with a sample call.
     */
    public static function ajax_test_openai() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Permission denied.' );
        }

        check_ajax_referer( 'woo_rs_product_sync_nonce', 'nonce' );

        $api_key = WOO_RS_OpenAI::get_api_key();
        if ( empty( $api_key ) ) {
            wp_send_json_error( 'No OpenAI API key saved. Save your key first, then test.' );
        }

        $product_name = isset( $_POST['product_name'] ) ? sanitize_text_field( wp_unslash( $_POST['product_name'] ) ) : '';
        $description  = isset( $_POST['description'] ) ? sanitize_textarea_field( wp_unslash( $_POST['description'] ) ) : '';

        if ( empty( trim( $description ) ) ) {
            $product_name = 'Widget Pro 3000';
            $description  = 'This is a great widget. It does many things. Very useful for fixing stuff. Comes in blue.';
        }

        $user_message = $description;
        if ( ! empty( $product_name ) ) {
            $user_message = "Product: {$product_name}\n\nDescription:\n{$description}";
        }

        $prompt = WOO_RS_OpenAI::get_prompt();
        $model  = WOO_RS_OpenAI::get_model();

        $request_body = WOO_RS_OpenAI::build_request_body( $model, $prompt, $user_message );
        $config       = WOO_RS_OpenAI::get_model_config( $model );

        $response = wp_remote_post( 'https://api.openai.com/v1/chat/completions', array(
            'timeout' => $config['timeout'],
            'headers' => array(
                'Content-Type'  => 'application/json',
                'Authorization' => 'Bearer ' . $api_key,
            ),
            'body' => wp_json_encode( $request_body ),
        ) );

        if ( is_wp_error( $response ) ) {
            wp_send_json_error( 'Connection error: ' . $response->get_error_message() );
        }

        $status_code   = wp_remote_retrieve_response_code( $response );
        $response_body = json_decode( wp_remote_retrieve_body( $response ), true );

        if ( $status_code !== 200 ) {
            $error_msg = isset( $response_body['error']['message'] ) ? $response_body['error']['message'] : 'HTTP ' . $status_code;
            wp_send_json_error( 'API error: ' . $error_msg );
        }

        // Extract output — try chat completions format, then responses API format
        $output_text = '';
        if ( ! empty( $response_body['choices'][0]['message']['content'] ) ) {
            $output_text = trim( $response_body['choices'][0]['message']['content'] );
        } elseif ( ! empty( $response_body['output'] ) ) {
            // Responses API format: output[].content[].text
            foreach ( $response_body['output'] as $output_item ) {
                if ( isset( $output_item['content'] ) ) {
                    foreach ( $output_item['content'] as $content_block ) {
                        if ( isset( $content_block['text'] ) ) {
                            $output_text .= $content_block['text'];
                        }
                    }
                }
            }
            $output_text = trim( $output_text );
        }

        if ( empty( $output_text ) ) {
            $finish = isset( $response_body['choices'][0]['finish_reason'] ) ? $response_body['choices'][0]['finish_reason'] : 'unknown';
            if ( 'length' === $finish ) {
                wp_send_json_error( 'OpenAI used all tokens for reasoning with none left for output. Try a non-reasoning model like gpt-4.1.' );
            }
            wp_send_json_error( 'OpenAI returned an empty response.' );
        }
        $usage       = isset( $response_body['usage'] ) ? $response_body['usage'] : null;
        $used_model  = isset( $response_body['model'] ) ? $response_body['model'] : $model;

        wp_send_json_success( array(
            'model'  => $used_model,
            'input'  => $user_message,
            'output' => $output_text,
            'usage'  => $usage,
        ) );
    }

    /* ───────────────────────────────────────────────────
     * Page rendering — tab dispatcher
     * ─────────────────────────────────────────────────── */

    public static function render_page() {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        $current_tab = isset( $_GET['tab'] ) ? sanitize_text_field( $_GET['tab'] ) : 'dashboard';

        ?>
        <div class="wrap woo-rs-product-sync-wrap">
            <h1>RepairShopr &rarr; WooCommerce Product Sync</h1>

            <?php self::render_notices(); ?>

            <nav class="nav-tab-wrapper woo-rs-tabs">
                <a href="?page=woo-rs-product-sync&tab=dashboard" class="nav-tab <?php echo 'dashboard' === $current_tab ? 'nav-tab-active' : ''; ?>">Dashboard</a>
                <a href="?page=woo-rs-product-sync&tab=settings" class="nav-tab <?php echo 'settings' === $current_tab ? 'nav-tab-active' : ''; ?>">Settings</a>
                <a href="?page=woo-rs-product-sync&tab=categories" class="nav-tab <?php echo 'categories' === $current_tab ? 'nav-tab-active' : ''; ?>">Categories</a>
                <a href="?page=woo-rs-product-sync&tab=logs" class="nav-tab <?php echo 'logs' === $current_tab ? 'nav-tab-active' : ''; ?>">Logs</a>
            </nav>

            <div class="woo-rs-tab-content">
                <?php
                switch ( $current_tab ) {
                    case 'settings':
                        self::render_settings_tab();
                        break;
                    case 'categories':
                        WOO_RS_Category_Map::render_tab();
                        break;
                    case 'logs':
                        self::render_logs_tab();
                        break;
                    default:
                        self::render_dashboard_tab();
                        break;
                }
                ?>
            </div>
        </div>
        <?php
    }

    private static function render_notices() {
        if ( ! empty( $_GET['cleared'] ) ) {
            echo '<div class="notice notice-success is-dismissible"><p>Webhook logs cleared.</p></div>';
        }
        if ( ! empty( $_GET['sync_cleared'] ) ) {
            echo '<div class="notice notice-success is-dismissible"><p>Sync logs cleared.</p></div>';
        }
        if ( ! empty( $_GET['key_regenerated'] ) ) {
            echo '<div class="notice notice-success is-dismissible"><p>API key regenerated.</p></div>';
        }
        if ( ! empty( $_GET['saved'] ) ) {
            echo '<div class="notice notice-success is-dismissible"><p>Settings saved.</p></div>';
        }
    }

    /* ── Dashboard Tab ── */

    private static function render_dashboard_tab() {
        $sync_stats    = WOO_RS_Product_Sync::get_sync_stats();
        $last_cron_run = get_option( 'woo_rs_product_sync_last_cron_run', array() );
        $next_cron     = WOO_RS_Cron::get_next_run();
        $auto_sync     = get_option( 'woo_rs_product_sync_auto_sync', 0 );
        $rs_api_key    = WOO_RS_API_Client::get_api_key();

        ?>
        <!-- Status Overview -->
        <div class="card woo-rs-card">
            <h2>Sync Status</h2>
            <table class="woo-rs-status-table">
                <tr>
                    <td><strong>RS API Key:</strong></td>
                    <td>
                        <?php if ( ! empty( $rs_api_key ) ) : ?>
                            <span class="woo-rs-status woo-rs-status-mapped">Configured</span>
                        <?php else : ?>
                            <span class="woo-rs-status woo-rs-status-unmapped">Not configured</span>
                        <?php endif; ?>
                    </td>
                </tr>
                <tr>
                    <td><strong>Auto-Sync (Cron):</strong></td>
                    <td>
                        <?php if ( $auto_sync ) : ?>
                            <span class="woo-rs-status woo-rs-status-mapped">Enabled</span>
                        <?php else : ?>
                            <span class="woo-rs-status woo-rs-status-unmapped">Disabled</span>
                        <?php endif; ?>
                    </td>
                </tr>
                <tr>
                    <td><strong>Next Cron Run:</strong></td>
                    <td>
                        <?php if ( $next_cron ) : ?>
                            <?php echo esc_html( gmdate( 'Y-m-d H:i:s', $next_cron ) ); ?> UTC
                        <?php else : ?>
                            <em>Not scheduled</em>
                        <?php endif; ?>
                    </td>
                </tr>
                <tr>
                    <td><strong>Last Cron Run:</strong></td>
                    <td>
                        <?php if ( ! empty( $last_cron_run['time'] ) ) : ?>
                            <?php echo esc_html( $last_cron_run['time'] ); ?> UTC
                            <?php if ( ! empty( $last_cron_run['stats'] ) ) : ?>
                                (<?php echo esc_html( $last_cron_run['stats']['created'] ); ?> created,
                                 <?php echo esc_html( $last_cron_run['stats']['updated'] ); ?> updated,
                                 <?php echo esc_html( $last_cron_run['stats']['skipped'] ); ?> skipped)
                            <?php endif; ?>
                        <?php else : ?>
                            <em>Never</em>
                        <?php endif; ?>
                    </td>
                </tr>
                <tr>
                    <td><strong>Last Sync Activity:</strong></td>
                    <td>
                        <?php if ( ! empty( $sync_stats['last_sync'] ) ) : ?>
                            <?php echo esc_html( $sync_stats['last_sync'] ); ?> UTC
                        <?php else : ?>
                            <em>None</em>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php if ( $sync_stats['today'] ) : ?>
                <tr>
                    <td><strong>Today:</strong></td>
                    <td>
                        <?php echo esc_html( $sync_stats['today']->total ); ?> synced
                        (<?php echo esc_html( $sync_stats['today']->created ); ?> created,
                         <?php echo esc_html( $sync_stats['today']->updated ); ?> updated,
                         <?php echo esc_html( $sync_stats['today']->skipped ); ?> skipped)
                    </td>
                </tr>
                <?php endif; ?>
            </table>
        </div>

        <!-- Manual Sync -->
        <div class="card woo-rs-card">
            <h2>Run Full Sync Now</h2>
            <p>Fetch all products from RepairShopr and sync to WooCommerce. Requires RS API key and URL to be configured.</p>

            <?php if ( empty( $rs_api_key ) ) : ?>
                <p><em>Configure your RepairShopr API key in the Settings tab first.</em></p>
            <?php else : ?>
                <button type="button" class="button button-primary" id="woo-rs-start-sync">Start Full Sync</button>

                <div class="woo-rs-progress-container" style="display:none;">
                    <div class="woo-rs-progress-bar" id="woo-rs-sync-progress">0%</div>
                </div>
                <div id="woo-rs-sync-status" class="woo-rs-sync-status" style="display:none;"></div>
            <?php endif; ?>
        </div>
        <?php
    }

    /* ── Settings Tab ── */

    private static function render_settings_tab() {
        $webhook_api_key = get_option( 'woo_rs_product_sync_api_key', '' );
        $webhook_url     = rest_url( 'woo-rs-product-sync/v1/webhook' );
        if ( $webhook_api_key ) {
            $webhook_url = add_query_arg( 'key', $webhook_api_key, $webhook_url );
        }

        $rs_api_key_encrypted = get_option( 'woo_rs_product_sync_rs_api_key', '' );
        $rs_api_key_decrypted = ! empty( $rs_api_key_encrypted ) ? WOO_RS_Encryption::decrypt( $rs_api_key_encrypted ) : '';
        $masked_key           = self::mask_key( $rs_api_key_decrypted );

        $rs_api_url      = get_option( 'woo_rs_product_sync_rs_api_url', '' );
        $auto_sync       = get_option( 'woo_rs_product_sync_auto_sync', 0 );
        $sync_interval   = get_option( 'woo_rs_product_sync_sync_interval', 60 );
        $logging_level   = get_option( 'woo_rs_product_sync_logging_level', 'changes_only' );
        $new_product_status = get_option( 'woo_rs_product_sync_new_product_status', 'publish' );

        $openai_enabled          = get_option( 'woo_rs_product_sync_openai_enabled', 0 );
        $openai_key_encrypted    = get_option( 'woo_rs_product_sync_openai_api_key', '' );
        $openai_key_decrypted    = ! empty( $openai_key_encrypted ) ? WOO_RS_Encryption::decrypt( $openai_key_encrypted ) : '';
        $openai_masked_key       = self::mask_key( $openai_key_decrypted );
        $openai_model            = get_option( 'woo_rs_product_sync_openai_model', WOO_RS_OpenAI::DEFAULT_MODEL );
        $openai_prompt           = get_option( 'woo_rs_product_sync_openai_prompt', '' );
        $openai_prompt_display   = ! empty( $openai_prompt ) ? $openai_prompt : WOO_RS_OpenAI::DEFAULT_PROMPT;
        $openai_logging          = get_option( 'woo_rs_product_sync_openai_logging', 0 );

        ?>
        <!-- Webhook URL -->
        <div class="card woo-rs-card">
            <h2>Webhook URL</h2>
            <p>Paste this URL into RepairShopr's webhook settings:</p>
            <input type="text" readonly value="<?php echo esc_url( $webhook_url ); ?>" class="woo-rs-url-field" onclick="this.select();" />
            <p class="description">This URL includes your API key for authentication.</p>
        </div>

        <!-- Webhook API Key -->
        <div class="card woo-rs-card">
            <h2>Webhook API Key</h2>
            <code class="woo-rs-api-key"><?php echo esc_html( $webhook_api_key ); ?></code>
            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline;">
                <input type="hidden" name="action" value="woo_rs_product_sync_regenerate_key" />
                <?php wp_nonce_field( 'woo_rs_product_sync_regenerate_key' ); ?>
                <button type="submit" class="button" onclick="return confirm('Regenerate API key? The old key will stop working immediately.');">Regenerate Key</button>
            </form>
        </div>

        <!-- RS API Settings -->
        <div class="card woo-rs-card">
            <h2>RepairShopr API Settings</h2>

            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                <input type="hidden" name="action" value="woo_rs_product_sync_save_settings" />
                <?php wp_nonce_field( 'woo_rs_product_sync_save_settings' ); ?>

                <table class="form-table">
                    <tr>
                        <th scope="row"><label for="rs_api_key">RS API Key</label></th>
                        <td>
                            <input type="text" id="rs_api_key" name="rs_api_key"
                                   value="<?php echo esc_attr( $masked_key ); ?>" class="regular-text" autocomplete="off" />
                            <p class="description">
                                Your RepairShopr API key. Stored encrypted.
                                <?php if ( ! empty( $rs_api_key_decrypted ) ) : ?>
                                    <br>Only the last 4 characters are shown. Enter a new key to update.
                                <?php endif; ?>
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="rs_api_url">RS API URL</label></th>
                        <td>
                            <input type="text" id="rs_api_url" name="rs_api_url"
                                   value="<?php echo esc_attr( $rs_api_url ); ?>" class="regular-text" autocomplete="off"
                                   placeholder="https://your-subdomain.repairshopr.com/api/v1" />
                            <p class="description">
                                Your RepairShopr API URL, e.g. <code>https://your-subdomain.repairshopr.com/api/v1</code>
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">Auto-Sync</th>
                        <td>
                            <label>
                                <input type="checkbox" id="woo_rs_auto_sync" name="auto_sync" value="1" <?php checked( $auto_sync, 1 ); ?> />
                                Enable automatic cron-based sync
                            </label>
                            <p class="description">When enabled, the plugin will periodically fetch all products from RepairShopr and sync them.</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="woo_rs_sync_interval">Sync Interval (minutes)</label></th>
                        <td>
                            <input type="number" id="woo_rs_sync_interval" name="sync_interval" min="1"
                                   value="<?php echo esc_attr( $sync_interval ); ?>"
                                   <?php echo $auto_sync ? '' : 'disabled'; ?> />
                            <p class="description">How often to run the automatic sync (minimum 1 minute).</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="logging_level">Logging Level</label></th>
                        <td>
                            <select id="logging_level" name="logging_level">
                                <option value="none" <?php selected( $logging_level, 'none' ); ?>>No Logging</option>
                                <option value="changes_only" <?php selected( $logging_level, 'changes_only' ); ?>>Log Changes Only</option>
                                <option value="all" <?php selected( $logging_level, 'all' ); ?>>Log All Sync Activity</option>
                            </select>
                            <p class="description">Controls how much sync activity is logged to the sync log table.</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="new_product_status">New Product Status</label></th>
                        <td>
                            <select id="new_product_status" name="new_product_status">
                                <option value="publish" <?php selected( $new_product_status, 'publish' ); ?>>Published</option>
                                <option value="pending" <?php selected( $new_product_status, 'pending' ); ?>>Pending Review</option>
                                <option value="draft" <?php selected( $new_product_status, 'draft' ); ?>>Draft</option>
                            </select>
                            <p class="description">The default status for new WooCommerce products created by the sync. Products marked as disabled in RepairShopr will always be set to Draft regardless of this setting.</p>
                        </td>
                    </tr>
                </table>

                <h2 class="title">OpenAI Description Rewriting</h2>
                <p>When enabled, product descriptions synced from RepairShopr will be rewritten by OpenAI before being saved to WooCommerce.</p>

                <table class="form-table">
                    <tr>
                        <th scope="row">Enable OpenAI</th>
                        <td>
                            <label>
                                <input type="checkbox" id="woo_rs_openai_enabled" name="openai_enabled" value="1" <?php checked( $openai_enabled, 1 ); ?> />
                                Rewrite product descriptions using OpenAI
                            </label>
                            <p class="description">Requires an OpenAI API key. Descriptions are rewritten when a product is created or when its description changes.</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="openai_api_key">OpenAI API Key</label></th>
                        <td>
                            <input type="text" id="openai_api_key" name="openai_api_key"
                                   value="<?php echo esc_attr( $openai_masked_key ); ?>" class="regular-text" autocomplete="off" />
                            <p class="description">
                                Your OpenAI API key. Stored encrypted.
                                <?php if ( ! empty( $openai_key_decrypted ) ) : ?>
                                    <br>Only the last 4 characters are shown. Enter a new key to update.
                                <?php endif; ?>
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="openai_model">Model</label></th>
                        <td>
                            <select id="openai_model" name="openai_model">
                                <option value="gpt-5-nano" <?php selected( $openai_model, 'gpt-5-nano' ); ?>>GPT-5 Nano (fastest, cheapest)</option>
                                <option value="gpt-5-mini" <?php selected( $openai_model, 'gpt-5-mini' ); ?>>GPT-5 Mini (fast, cost-efficient)</option>
                                <option value="gpt-5" <?php selected( $openai_model, 'gpt-5' ); ?>>GPT-5 (reasoning)</option>
                                <option value="gpt-5.2" <?php selected( $openai_model, 'gpt-5.2' ); ?>>GPT-5.2 (best, coding/agentic)</option>
                                <option value="gpt-4.1" <?php selected( $openai_model, 'gpt-4.1' ); ?>>GPT-4.1 (smartest non-reasoning)</option>
                            </select>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="openai_prompt">Prompt</label></th>
                        <td>
                            <textarea id="openai_prompt" name="openai_prompt" rows="6" class="large-text"><?php echo esc_textarea( $openai_prompt_display ); ?></textarea>
                            <p class="description">
                                The system prompt sent to OpenAI. The product name and RS description are sent as the user message.
                                <br>Leave blank to use the default prompt, or customize it to match your store's voice and style.
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">Log OpenAI Requests</th>
                        <td>
                            <label>
                                <input type="checkbox" name="openai_logging" value="1" <?php checked( $openai_logging, 1 ); ?> />
                                Log full OpenAI request and response payloads
                            </label>
                            <p class="description">When enabled, the prompt, model, input, output, and token usage are saved in the sync log for each rewrite. Visible under Logs &rarr; Sync Activity Log &rarr; View changes. Disable after troubleshooting to reduce log size.</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">Test OpenAI</th>
                        <td>
                            <input type="text" id="woo-rs-test-openai-name" class="regular-text" placeholder="Product name (optional)" />
                            <br style="margin-bottom:6px;" />
                            <textarea id="woo-rs-test-openai-input" rows="3" class="large-text" placeholder="Enter a product description to test with..."></textarea>
                            <br />
                            <button type="button" class="button" id="woo-rs-test-openai">Send Test Request</button>
                            <span id="woo-rs-openai-test-spinner" class="spinner" style="float:none;"></span>
                            <div id="woo-rs-openai-test-result" style="display:none; margin-top:10px;"></div>
                            <p class="description">Test your OpenAI settings with a custom product description. Uses your saved key, model, and prompt. Save settings first if you've made changes.</p>
                        </td>
                    </tr>
                </table>

                <p class="submit">
                    <button type="submit" class="button button-primary">Save Settings</button>
                </p>
            </form>
        </div>
        <?php
    }

    /* ── Logs Tab ── */

    private static function render_logs_tab() {
        // Sync Activity Log
        $sync_logs       = WOO_RS_Product_Sync::get_sync_logs( 50 );
        $sync_log_count  = WOO_RS_Product_Sync::count_sync_logs();

        ?>
        <div class="card woo-rs-card">
            <h2>
                Sync Activity Log
                <span class="woo-rs-log-count">(<?php echo esc_html( $sync_log_count ); ?> entries)</span>
            </h2>

            <?php if ( $sync_log_count > 0 ) : ?>
                <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin-bottom:12px;">
                    <input type="hidden" name="action" value="woo_rs_product_sync_clear_sync_logs" />
                    <?php wp_nonce_field( 'woo_rs_product_sync_clear_sync_logs' ); ?>
                    <button type="submit" class="button" onclick="return confirm('Delete all sync log entries?');">Clear Sync Logs</button>
                </form>
            <?php endif; ?>

            <?php if ( empty( $sync_logs ) ) : ?>
                <p>No sync activity logged yet.</p>
            <?php else : ?>
                <table class="widefat striped woo-rs-log-table">
                    <thead>
                        <tr>
                            <th>Time (UTC)</th>
                            <th>RS Product</th>
                            <th>WC Product</th>
                            <th>Action</th>
                            <th>Source</th>
                            <th>Changes</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ( $sync_logs as $log ) : ?>
                            <tr>
                                <td><?php echo esc_html( $log->synced_at ); ?></td>
                                <td><?php echo esc_html( $log->rs_product_id ); ?></td>
                                <td>
                                    <?php if ( $log->wc_product_id ) : ?>
                                        <a href="<?php echo esc_url( get_edit_post_link( $log->wc_product_id ) ); ?>">
                                            #<?php echo esc_html( $log->wc_product_id ); ?>
                                        </a>
                                    <?php else : ?>
                                        &mdash;
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <span class="woo-rs-action woo-rs-action-<?php echo esc_attr( $log->action ); ?>">
                                        <?php echo esc_html( ucfirst( $log->action ) ); ?>
                                    </span>
                                </td>
                                <td><?php echo esc_html( ucfirst( $log->source ) ); ?></td>
                                <td>
                                    <?php if ( ! empty( $log->changes ) ) : ?>
                                        <details>
                                            <summary>View</summary>
                                            <pre class="woo-rs-payload"><?php echo esc_html( self::pretty_json( $log->changes ) ); ?></pre>
                                        </details>
                                    <?php else : ?>
                                        &mdash;
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>

        <?php
        // Webhook Log
        $logs       = WOO_RS_Logger::get_logs( 50 );
        $total_logs = WOO_RS_Logger::count();
        ?>

        <div class="card woo-rs-card">
            <h2>
                Webhook Log
                <span class="woo-rs-log-count">(<?php echo esc_html( $total_logs ); ?> entries)</span>
            </h2>

            <?php if ( $total_logs > 0 ) : ?>
                <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin-bottom:12px;">
                    <input type="hidden" name="action" value="woo_rs_product_sync_clear_logs" />
                    <?php wp_nonce_field( 'woo_rs_product_sync_clear_logs' ); ?>
                    <button type="submit" class="button" onclick="return confirm('Delete all webhook log entries?');">Clear Webhook Logs</button>
                </form>
            <?php endif; ?>

            <?php if ( empty( $logs ) ) : ?>
                <p>No webhook requests received yet.</p>
            <?php else : ?>
                <table class="widefat striped woo-rs-log-table">
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Received</th>
                            <th>Method</th>
                            <th>Source IP</th>
                            <th>Payload</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ( $logs as $log ) : ?>
                            <tr>
                                <td><?php echo esc_html( $log->id ); ?></td>
                                <td><?php echo esc_html( $log->received_at ); ?></td>
                                <td><?php echo esc_html( $log->http_method ); ?></td>
                                <td><?php echo esc_html( $log->source_ip ); ?></td>
                                <td>
                                    <details>
                                        <summary>View payload</summary>
                                        <pre class="woo-rs-payload"><?php echo esc_html( self::pretty_json( $log->payload ) ); ?></pre>
                                        <details>
                                            <summary>Headers</summary>
                                            <pre class="woo-rs-payload"><?php echo esc_html( self::pretty_json( $log->headers ) ); ?></pre>
                                        </details>
                                    </details>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
        <?php
    }

    /* ───────────────────────────────────────────────────
     * Helpers
     * ─────────────────────────────────────────────────── */

    private static function mask_key( $key ) {
        if ( empty( $key ) ) {
            return '';
        }
        return str_repeat( '*', max( 0, strlen( $key ) - 4 ) ) . substr( $key, -4 );
    }

    private static function pretty_json( $raw ) {
        $decoded = json_decode( $raw );

        if ( json_last_error() === JSON_ERROR_NONE ) {
            return wp_json_encode( $decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
        }

        return $raw;
    }
}
