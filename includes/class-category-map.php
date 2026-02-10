<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class WOO_RS_Category_Map {

    const OPTION_KEY   = 'woo_rs_product_sync_category_map';
    const TRANSIENT_KEY = 'woo_rs_rs_categories_cache';

    public static function init() {
        add_action( 'wp_ajax_woo_rs_refresh_categories', array( __CLASS__, 'ajax_refresh_categories' ) );
        add_action( 'wp_ajax_woo_rs_create_wc_category', array( __CLASS__, 'ajax_create_wc_category' ) );
        add_action( 'wp_ajax_woo_rs_save_category_mapping', array( __CLASS__, 'ajax_save_category_mapping' ) );
        add_action( 'admin_post_woo_rs_product_sync_save_categories', array( __CLASS__, 'handle_save_categories' ) );
    }

    /**
     * Get the current category map.
     *
     * @return array RS category name => array of WC term IDs.
     */
    public static function get_map() {
        return get_option( self::OPTION_KEY, array() );
    }

    /**
     * Save the category map.
     */
    public static function save_map( $map ) {
        update_option( self::OPTION_KEY, $map );
    }

    /**
     * Discover known RS categories from all available sources.
     */
    public static function discover_categories() {
        $categories = array();

        // Source 1: Parse from webhook log payloads
        $categories = array_merge( $categories, self::categories_from_webhook_log() );

        // Source 2: Query from existing synced product meta
        $categories = array_merge( $categories, self::categories_from_product_meta() );

        // Source 3: Fetch from RS API (cached in transient)
        $categories = array_merge( $categories, self::categories_from_api() );

        $categories = array_unique( array_filter( $categories ) );
        sort( $categories );

        return $categories;
    }

    /**
     * Extract RS categories from webhook log payloads.
     */
    private static function categories_from_webhook_log() {
        global $wpdb;
        $table = $wpdb->prefix . WOO_RS_PRODUCT_SYNC_TABLE;

        $payloads = $wpdb->get_col( "SELECT payload FROM {$table} ORDER BY received_at DESC LIMIT 500" );
        $categories = array();

        foreach ( $payloads as $payload ) {
            $data = json_decode( $payload, true );
            if ( ! $data ) {
                continue;
            }

            // Webhook payload wraps data in attributes
            $product = isset( $data['attributes'] ) ? $data['attributes'] : $data;
            if ( isset( $product['product_category'] ) && '' !== $product['product_category'] ) {
                $categories[] = $product['product_category'];
            }
        }

        return $categories;
    }

    /**
     * Extract RS categories from already-synced product meta.
     */
    private static function categories_from_product_meta() {
        global $wpdb;

        $results = $wpdb->get_col(
            "SELECT DISTINCT meta_value FROM {$wpdb->postmeta} WHERE meta_key = '_rs_category' AND meta_value != ''"
        );

        return $results ? $results : array();
    }

    /**
     * Fetch RS categories from the RS API via GET /products/categories.
     * Results cached in a 1-hour transient.
     */
    private static function categories_from_api() {
        $cached = get_transient( self::TRANSIENT_KEY );
        if ( false !== $cached ) {
            return $cached;
        }

        $api_key = WOO_RS_API_Client::get_api_key();
        $api_url = WOO_RS_API_Client::get_api_url();

        if ( empty( $api_key ) || empty( $api_url ) ) {
            return array();
        }

        $result = WOO_RS_API_Client::get( 'products/categories' );
        if ( is_wp_error( $result ) ) {
            return array();
        }

        $categories = array();
        $rs_categories = isset( $result['categories'] ) ? $result['categories'] : array();

        foreach ( $rs_categories as $cat ) {
            if ( ! empty( $cat['name'] ) ) {
                $categories[] = $cat['name'];
            }
        }

        $categories = array_unique( $categories );
        set_transient( self::TRANSIENT_KEY, $categories, HOUR_IN_SECONDS );

        return $categories;
    }

    /**
     * AJAX handler: refresh RS categories from API.
     */
    public static function ajax_refresh_categories() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Permission denied.' );
        }

        check_ajax_referer( 'woo_rs_product_sync_nonce', 'nonce' );

        // Clear cache so API source is re-fetched
        delete_transient( self::TRANSIENT_KEY );

        $categories    = self::discover_categories();
        $current_map   = self::get_map();
        $wc_categories = self::get_wc_categories();

        wp_send_json_success( array(
            'rs_categories' => $categories,
            'current_map'   => $current_map,
            'wc_categories' => $wc_categories,
        ) );
    }

    /**
     * AJAX handler: create a WC category with the same name as the RS category and map it.
     */
    public static function ajax_create_wc_category() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Permission denied.' );
        }

        check_ajax_referer( 'woo_rs_product_sync_nonce', 'nonce' );

        $rs_cat = isset( $_POST['rs_category'] ) ? sanitize_text_field( wp_unslash( $_POST['rs_category'] ) ) : '';
        if ( empty( $rs_cat ) ) {
            wp_send_json_error( 'No category name provided.' );
        }

        // Check if a WC category with this name already exists
        $existing = get_term_by( 'name', $rs_cat, 'product_cat' );
        if ( $existing ) {
            $term_id = $existing->term_id;
        } else {
            $result = wp_insert_term( $rs_cat, 'product_cat' );
            if ( is_wp_error( $result ) ) {
                wp_send_json_error( $result->get_error_message() );
            }
            $term_id = $result['term_id'];
        }

        // Map this RS category to the new/existing WC category
        $map = self::get_map();
        $existing_ids = isset( $map[ $rs_cat ] ) ? $map[ $rs_cat ] : array();
        if ( ! in_array( $term_id, $existing_ids, true ) ) {
            $existing_ids[] = $term_id;
        }
        $map[ $rs_cat ] = $existing_ids;
        self::save_map( $map );

        wp_send_json_success( array(
            'term_id'   => $term_id,
            'term_name' => $rs_cat,
        ) );
    }

    /**
     * AJAX handler: save a single RS → WC category mapping.
     */
    public static function ajax_save_category_mapping() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Permission denied.' );
        }

        check_ajax_referer( 'woo_rs_product_sync_nonce', 'nonce' );

        $rs_cat = isset( $_POST['rs_category'] ) ? sanitize_text_field( wp_unslash( $_POST['rs_category'] ) ) : '';
        if ( empty( $rs_cat ) ) {
            wp_send_json_error( 'No category name provided.' );
        }

        $map = self::get_map();

        if ( isset( $_POST['wc_categories'] ) && is_array( $_POST['wc_categories'] ) ) {
            $map[ $rs_cat ] = array_map( 'intval', $_POST['wc_categories'] );
        } else {
            unset( $map[ $rs_cat ] );
        }

        self::save_map( $map );

        wp_send_json_success( array( 'saved' => $rs_cat ) );
    }

    /**
     * Handle save category mappings form submission.
     */
    public static function handle_save_categories() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( 'Unauthorized' );
        }

        check_admin_referer( 'woo_rs_product_sync_save_categories' );

        $map     = self::get_map();
        $rs_cat  = isset( $_POST['rs_category'] ) ? sanitize_text_field( wp_unslash( $_POST['rs_category'] ) ) : '';

        if ( ! empty( $rs_cat ) ) {
            if ( isset( $_POST['wc_categories'] ) && is_array( $_POST['wc_categories'] ) ) {
                $map[ $rs_cat ] = array_map( 'intval', $_POST['wc_categories'] );
            } else {
                // No selections = unmap this category
                unset( $map[ $rs_cat ] );
            }
        }

        self::save_map( $map );

        wp_safe_redirect( add_query_arg(
            array( 'tab' => 'categories', 'saved' => '1' ),
            admin_url( 'admin.php?page=woo-rs-product-sync' )
        ) );
        exit;
    }

    /**
     * Get all WC product categories for the mapping UI, sorted hierarchically with depth prefixes.
     */
    public static function get_wc_categories() {
        $terms = get_terms( array(
            'taxonomy'   => 'product_cat',
            'hide_empty' => false,
        ) );

        if ( is_wp_error( $terms ) || empty( $terms ) ) {
            return array();
        }

        // Index by term_id for tree building
        $by_id    = array();
        $children = array();
        foreach ( $terms as $term ) {
            $by_id[ $term->term_id ] = $term;
            $children[ $term->parent ][] = $term->term_id;
        }

        // Walk tree depth-first to produce indented flat list
        $categories = array();
        self::walk_category_tree( $children, $by_id, 0, 0, $categories );

        return $categories;
    }

    /**
     * Recursive helper to walk category tree and build indented list.
     */
    private static function walk_category_tree( &$children, &$by_id, $parent_id, $depth, &$output ) {
        if ( empty( $children[ $parent_id ] ) ) {
            return;
        }

        // Sort children alphabetically
        usort( $children[ $parent_id ], function ( $a, $b ) use ( $by_id ) {
            return strcasecmp( $by_id[ $a ]->name, $by_id[ $b ]->name );
        } );

        foreach ( $children[ $parent_id ] as $term_id ) {
            $term   = $by_id[ $term_id ];
            $prefix = $depth > 0 ? str_repeat( "\xE2\x80\x94 ", $depth ) : '';

            $output[] = array(
                'id'   => $term->term_id,
                'name' => $prefix . $term->name,
                'slug' => $term->slug,
            );

            self::walk_category_tree( $children, $by_id, $term_id, $depth + 1, $output );
        }
    }

    /**
     * Render the Categories tab in the admin UI.
     */
    public static function render_tab() {
        $rs_categories = self::discover_categories();
        $current_map   = self::get_map();
        $wc_categories = self::get_wc_categories();

        if ( ! empty( $_GET['saved'] ) ) {
            echo '<div class="notice notice-success is-dismissible"><p>Category mappings saved.</p></div>';
        }

        ?>
        <div class="card woo-rs-card">
            <h2>Category Mapping</h2>
            <p>Map RepairShopr product categories to WooCommerce categories. Only products in mapped categories will be synced.</p>

            <p>
                <button type="button" class="button" id="woo-rs-refresh-categories">Refresh from RepairShopr API</button>
                <span id="woo-rs-refresh-status"></span>
            </p>

            <?php if ( empty( $rs_categories ) ) : ?>
                <p><em>No RepairShopr categories discovered yet. Send a webhook or click "Refresh from RepairShopr API" to discover categories.</em></p>
            <?php else : ?>
                    <table class="widefat striped woo-rs-category-table">
                        <thead>
                            <tr>
                                <th>RepairShopr Category</th>
                                <th>WooCommerce Categories</th>
                                <th>Status</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ( $rs_categories as $rs_cat ) :
                                $mapped_ids = isset( $current_map[ $rs_cat ] ) ? $current_map[ $rs_cat ] : array();
                                $is_mapped  = ! empty( $mapped_ids );
                                $row_id     = 'woo-rs-cat-' . sanitize_title( $rs_cat );
                            ?>
                                <tr>
                                    <td><strong><?php echo esc_html( $rs_cat ); ?></strong></td>
                                    <td>
                                        <?php if ( ! empty( $wc_categories ) ) : ?>
                                            <select id="<?php echo esc_attr( $row_id ); ?>" multiple class="woo-rs-category-select">
                                                <?php foreach ( $wc_categories as $wc_cat ) : ?>
                                                    <option value="<?php echo esc_attr( $wc_cat['id'] ); ?>"
                                                            <?php echo in_array( $wc_cat['id'], $mapped_ids, true ) ? 'selected' : ''; ?>>
                                                        <?php echo esc_html( $wc_cat['name'] ); ?>
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>
                                            <p class="description">Hold Ctrl (or Cmd on Mac) to select multiple.</p>
                                        <?php else : ?>
                                            <em>No WooCommerce categories found.</em>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if ( $is_mapped ) : ?>
                                            <span class="woo-rs-status woo-rs-status-mapped">Mapped</span>
                                        <?php else : ?>
                                            <span class="woo-rs-status woo-rs-status-unmapped">Unmapped</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="woo-rs-category-actions">
                                        <button type="button" class="button button-small woo-rs-save-category"
                                                data-rs-category="<?php echo esc_attr( $rs_cat ); ?>"
                                                data-select-id="<?php echo esc_attr( $row_id ); ?>">Save</button>
                                        <button type="button" class="button button-small woo-rs-create-category"
                                                data-rs-category="<?php echo esc_attr( $rs_cat ); ?>">
                                            Create New Category
                                        </button>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
            <?php endif; ?>
        </div>
        <?php
    }
}
