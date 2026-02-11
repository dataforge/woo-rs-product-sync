<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class WOO_RS_Product_Sync {

    /**
     * RS fields that map directly to WC product setters.
     */
    private static $field_map = array(
        'name'             => 'set_name',
        'description'      => 'set_description',
        'long_description' => 'set_short_description',
        'price_retail'     => 'set_regular_price',
        'quantity'         => 'set_stock_quantity',
        'sort_order'       => 'set_menu_order',
    );

    /**
     * RS fields stored as post meta.
     */
    private static $meta_fields = array(
        'price_cost'           => '_rs_price_cost',
        'price_wholesale'      => '_rs_price_wholesale',
        'product_category'     => '_rs_category',
        'category_path'        => '_rs_category_path',
        'upc_code'             => '_rs_upc_code',
        'condition'            => '_rs_condition',
        'physical_location'    => '_rs_physical_location',
        'serialized'           => '_rs_serialized',
        'notes'                => '_rs_notes',
        'reorder_at'           => '_rs_reorder_at',
        'desired_stock_level'  => '_rs_desired_stock_level',
        'discount_percent'     => '_rs_discount_percent',
        'warranty'             => '_rs_warranty',
        'warranty_template_id' => '_rs_warranty_template_id',
        'qb_item_id'          => '_rs_qb_item_id',
        'tax_rate_id'         => '_rs_tax_rate_id',
        'vendor_ids'          => '_rs_vendor_ids',
        'location_quantities'  => '_rs_location_quantities',
        'since_updated_at'     => '_rs_last_updated',
    );

    /**
     * Sync a single RS product to WooCommerce.
     *
     * @param array  $rs_product RS product data array.
     * @param string $source     'webhook', 'cron', or 'manual'.
     * @return array Sync result with action and changes.
     */
    public static function sync_product( $rs_product, $source = 'webhook' ) {
        if ( empty( $rs_product['id'] ) ) {
            return array( 'action' => 'skipped', 'reason' => 'no_id' );
        }

        $rs_category = isset( $rs_product['product_category'] ) ? $rs_product['product_category'] : '';
        if ( ! self::is_category_allowed( $rs_category ) ) {
            self::log_sync( $rs_product['id'], 0, 'skipped', $source, array( 'reason' => 'unmapped_category', 'category' => $rs_category ) );
            return array( 'action' => 'skipped', 'reason' => 'unmapped_category' );
        }

        $wc_product_id = self::find_wc_product( $rs_product['id'] );

        if ( $wc_product_id ) {
            $changes = self::update_product( $wc_product_id, $rs_product );

            // OpenAI description rewrite if description changed
            if ( isset( $changes['description'] ) ) {
                self::maybe_openai_rewrite( $wc_product_id, $rs_product );
            }

            $action  = ! empty( $changes ) ? 'updated' : 'skipped';
            self::log_sync( $rs_product['id'], $wc_product_id, $action, $source, $changes );
            return array( 'action' => $action, 'wc_product_id' => $wc_product_id, 'changes' => $changes );
        }

        $new_id  = self::create_product( $rs_product );
        $changes = array( 'created' => true );

        // OpenAI description rewrite for new products
        self::maybe_openai_rewrite( $new_id, $rs_product );

        self::log_sync( $rs_product['id'], $new_id, 'created', $source, $changes );
        return array( 'action' => 'created', 'wc_product_id' => $new_id, 'changes' => $changes );
    }

    /**
     * Check whether a RS category is mapped (allowed for sync).
     */
    public static function is_category_allowed( $rs_category ) {
        if ( empty( $rs_category ) ) {
            return false;
        }
        $map = get_option( 'woo_rs_product_sync_category_map', array() );
        return isset( $map[ $rs_category ] ) && ! empty( $map[ $rs_category ] );
    }

    /**
     * Find a WC product by RS product ID.
     * Checks SKU first (covers simple products and variations), then meta fallback.
     */
    public static function find_wc_product( $rs_product_id ) {
        $sku_string = (string) $rs_product_id;

        $product_id = wc_get_product_id_by_sku( $sku_string );
        if ( $product_id ) {
            return $product_id;
        }

        // Fallback: meta query
        global $wpdb;
        $product_id = $wpdb->get_var( $wpdb->prepare(
            "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_rs_product_id' AND meta_value = %s LIMIT 1",
            $sku_string
        ) );

        return $product_id ? (int) $product_id : 0;
    }

    /**
     * Create a new WC simple product from RS data.
     */
    private static function create_product( $rs_product ) {
        $product = new WC_Product_Simple();

        $product->set_sku( (string) $rs_product['id'] );
        self::apply_fields( $product, $rs_product, true );

        // Set manage_stock based on RS maintain_stock flag
        if ( isset( $rs_product['maintain_stock'] ) ) {
            $product->set_manage_stock( (bool) $rs_product['maintain_stock'] );
        } else {
            $product->set_manage_stock( true );
        }

        // Status based on disabled flag, or use the configured default
        if ( isset( $rs_product['disabled'] ) && $rs_product['disabled'] ) {
            $product->set_status( 'draft' );
        } else {
            $product->set_status( get_option( 'woo_rs_product_sync_new_product_status', 'publish' ) );
        }

        // Tax status
        if ( isset( $rs_product['taxable'] ) ) {
            $product->set_tax_status( $rs_product['taxable'] ? 'taxable' : 'none' );
        }

        $product_id = $product->save();

        // Store RS product ID as meta for fallback lookups
        update_post_meta( $product_id, '_rs_product_id', (string) $rs_product['id'] );

        // Store all meta fields
        self::apply_meta( $product_id, $rs_product );

        // Assign WC categories from mapping
        self::assign_wc_categories( $product_id, $rs_product );

        return $product_id;
    }

    /**
     * Update an existing WC product (any type: simple, variation, etc.).
     */
    private static function update_product( $wc_product_id, $rs_product ) {
        $product = wc_get_product( $wc_product_id );
        if ( ! $product ) {
            return array();
        }

        $is_variation = $product->is_type( 'variation' );
        $changes      = array();

        // Apply mapped fields and track changes
        $changes = self::apply_fields( $product, $rs_product, false );

        // manage_stock
        if ( isset( $rs_product['maintain_stock'] ) ) {
            $old_manage = $product->get_manage_stock();
            $new_manage = (bool) $rs_product['maintain_stock'];
            if ( $old_manage !== $new_manage ) {
                $product->set_manage_stock( $new_manage );
                $changes['manage_stock'] = array( 'old' => $old_manage, 'new' => $new_manage );
            }
        }

        // Status: only force draft when RS product is disabled (simple products only)
        if ( ! $is_variation && ! empty( $rs_product['disabled'] ) ) {
            $old_status = $product->get_status();
            if ( 'draft' !== $old_status ) {
                $product->set_status( 'draft' );
                $changes['status'] = array( 'old' => $old_status, 'new' => 'draft' );
            }
        }

        // Tax status
        if ( isset( $rs_product['taxable'] ) ) {
            $old_tax = $product->get_tax_status();
            $new_tax = $rs_product['taxable'] ? 'taxable' : 'none';
            if ( $old_tax !== $new_tax ) {
                $product->set_tax_status( $new_tax );
                $changes['tax_status'] = array( 'old' => $old_tax, 'new' => $new_tax );
            }
        }

        if ( ! empty( $changes ) ) {
            $product->save();

            // Reload to verify persistence
            $reloaded = wc_get_product( $wc_product_id );
            if ( $reloaded && isset( $changes['quantity'] ) ) {
                $verified_qty = $reloaded->get_stock_quantity();
                $changes['quantity']['verified'] = $verified_qty;
            }
        }

        // Update WC categories only when the RS category changed (simple products only).
        // This preserves any extra WooCommerce categories added manually.
        // Must run BEFORE apply_meta() which overwrites the stored _rs_category.
        if ( ! $is_variation && isset( $rs_product['product_category'] ) ) {
            $old_rs_cat = get_post_meta( $wc_product_id, '_rs_category', true );
            if ( (string) $old_rs_cat !== (string) $rs_product['product_category'] ) {
                self::assign_wc_categories( $wc_product_id, $rs_product );
            }
        }

        // Update meta fields
        $meta_changes = self::apply_meta( $wc_product_id, $rs_product );
        if ( ! empty( $meta_changes ) ) {
            $changes['meta'] = $meta_changes;
        }

        // Update RS product ID meta for fallback lookups
        update_post_meta( $wc_product_id, '_rs_product_id', (string) $rs_product['id'] );

        return $changes;
    }

    /**
     * Apply WC setter fields from RS data.
     *
     * @param WC_Product $product    WC product object.
     * @param array      $rs_product RS product data.
     * @param bool       $is_create  Whether this is a new product.
     * @return array Changes detected (empty array on create).
     */
    private static function apply_fields( $product, $rs_product, $is_create ) {
        $changes      = array();
        $is_variation = $product->is_type( 'variation' );

        foreach ( self::$field_map as $rs_key => $setter ) {
            if ( ! array_key_exists( $rs_key, $rs_product ) ) {
                continue;
            }

            // Name: only set on create, or for simple products (not variations)
            if ( 'name' === $rs_key && ! $is_create && $is_variation ) {
                continue;
            }

            $new_value = $rs_product[ $rs_key ];

            if ( $is_create ) {
                $product->$setter( $new_value );
                continue;
            }

            // Get current value for comparison
            $getter    = str_replace( 'set_', 'get_', $setter );
            $old_value = $product->$getter();

            if ( self::values_differ( $old_value, $new_value, $rs_key ) ) {
                $product->$setter( $new_value );
                $changes[ $rs_key ] = array( 'old' => $old_value, 'new' => $new_value );
            }
        }

        return $changes;
    }

    /**
     * Apply meta fields from RS data.
     */
    private static function apply_meta( $product_id, $rs_product ) {
        $changes = array();

        foreach ( self::$meta_fields as $rs_key => $meta_key ) {
            if ( ! array_key_exists( $rs_key, $rs_product ) ) {
                continue;
            }

            $new_value = $rs_product[ $rs_key ];

            // Serialize arrays
            if ( is_array( $new_value ) ) {
                $new_value = maybe_serialize( $new_value );
            }

            $old_value = get_post_meta( $product_id, $meta_key, true );

            if ( (string) $old_value !== (string) $new_value ) {
                update_post_meta( $product_id, $meta_key, $new_value );
                $changes[ $meta_key ] = array( 'old' => $old_value, 'new' => $new_value );
            }
        }

        return $changes;
    }

    /**
     * Assign WC categories to a product based on the category map.
     */
    private static function assign_wc_categories( $product_id, $rs_product ) {
        $rs_category = isset( $rs_product['product_category'] ) ? $rs_product['product_category'] : '';
        if ( empty( $rs_category ) ) {
            return;
        }

        $map = get_option( 'woo_rs_product_sync_category_map', array() );
        if ( ! isset( $map[ $rs_category ] ) || empty( $map[ $rs_category ] ) ) {
            return;
        }

        $wc_term_ids = array_map( 'intval', (array) $map[ $rs_category ] );
        wp_set_object_terms( $product_id, $wc_term_ids, 'product_cat' );
    }

    /**
     * Type-safe comparison of old and new values.
     */
    private static function values_differ( $old, $new, $rs_key ) {
        // Price fields: float comparison with epsilon
        if ( in_array( $rs_key, array( 'price_retail' ), true ) ) {
            $old_f = is_null( $old ) ? 0.0 : (float) $old;
            $new_f = is_numeric( $new ) ? (float) $new : 0.0;
            return abs( $old_f - $new_f ) > 0.0001;
        }

        // Quantity and sort_order: integer comparison
        if ( in_array( $rs_key, array( 'quantity', 'sort_order' ), true ) ) {
            $old_i = is_null( $old ) ? 0 : (int) $old;
            $new_i = is_numeric( $new ) ? (int) $new : 0;
            return $old_i !== $new_i;
        }

        // Strings: normalize line endings, trim, then compare
        $old_s = str_replace( "\r\n", "\n", trim( (string) $old ) );
        $new_s = str_replace( "\r\n", "\n", trim( (string) $new ) );
        return $old_s !== $new_s;
    }

    /**
     * Optionally rewrite a product's description via OpenAI.
     * Logs its own sync log entry with source 'openai'.
     */
    private static function maybe_openai_rewrite( $wc_product_id, $rs_product ) {
        if ( ! WOO_RS_OpenAI::is_enabled() ) {
            return;
        }

        $rs_description = isset( $rs_product['description'] ) ? $rs_product['description'] : '';
        $product_name   = isset( $rs_product['name'] ) ? $rs_product['name'] : '';
        $rs_product_id  = $rs_product['id'];

        $result = WOO_RS_OpenAI::maybe_rewrite_product_description( $wc_product_id, $rs_description, $product_name );

        $action = ! empty( $result['rewritten'] ) ? 'updated' : 'skipped';
        self::log_sync( $rs_product_id, $wc_product_id, $action, 'openai', $result );
    }

    /**
     * Log a sync action to the sync log table.
     */
    public static function log_sync( $rs_product_id, $wc_product_id, $action, $source, $changes = array() ) {
        $logging_level = get_option( 'woo_rs_product_sync_logging_level', 'changes_only' );

        if ( 'none' === $logging_level ) {
            return;
        }

        if ( 'changes_only' === $logging_level && 'skipped' === $action ) {
            return;
        }

        global $wpdb;
        $table = $wpdb->prefix . WOO_RS_SYNC_LOG_TABLE;

        $wpdb->insert(
            $table,
            array(
                'synced_at'     => current_time( 'mysql', true ),
                'rs_product_id' => (int) $rs_product_id,
                'wc_product_id' => (int) $wc_product_id,
                'action'        => sanitize_text_field( $action ),
                'source'        => sanitize_text_field( $source ),
                'changes'       => wp_json_encode( $changes ),
            ),
            array( '%s', '%d', '%d', '%s', '%s', '%s' )
        );
    }

    /**
     * Get sync log entries.
     */
    public static function get_sync_logs( $limit = 50, $offset = 0 ) {
        global $wpdb;
        $table = $wpdb->prefix . WOO_RS_SYNC_LOG_TABLE;

        return $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM {$table} ORDER BY synced_at DESC LIMIT %d OFFSET %d",
            $limit,
            $offset
        ) );
    }

    /**
     * Count sync log entries.
     */
    public static function count_sync_logs() {
        global $wpdb;
        $table = $wpdb->prefix . WOO_RS_SYNC_LOG_TABLE;

        return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );
    }

    /**
     * Clear sync log entries.
     */
    public static function clear_sync_logs() {
        global $wpdb;
        $table = $wpdb->prefix . WOO_RS_SYNC_LOG_TABLE;

        $wpdb->query( "TRUNCATE TABLE {$table}" );
    }

    /**
     * Get sync stats for the dashboard.
     */
    public static function get_sync_stats() {
        global $wpdb;
        $table = $wpdb->prefix . WOO_RS_SYNC_LOG_TABLE;

        $last_sync = $wpdb->get_var( "SELECT synced_at FROM {$table} ORDER BY synced_at DESC LIMIT 1" );

        $today = gmdate( 'Y-m-d 00:00:00' );
        $today_stats = $wpdb->get_row( $wpdb->prepare(
            "SELECT
                COUNT(*) as total,
                SUM(CASE WHEN action = 'created' THEN 1 ELSE 0 END) as created,
                SUM(CASE WHEN action = 'updated' THEN 1 ELSE 0 END) as updated,
                SUM(CASE WHEN action = 'skipped' THEN 1 ELSE 0 END) as skipped
            FROM {$table}
            WHERE synced_at >= %s",
            $today
        ) );

        return array(
            'last_sync' => $last_sync,
            'today'     => $today_stats,
        );
    }
}
