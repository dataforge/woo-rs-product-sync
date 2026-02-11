<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * GitHub auto-updater using native WordPress Update URI mechanism (WP 5.8+).
 *
 * Checks GitHub Releases for new versions and corrects the extracted
 * directory name so the plugin folder stays "woo-rs-product-sync".
 */
class WOO_RS_Updater {

    const GITHUB_REPO = 'dataforge/woo-rs-product-sync';
    const SLUG        = 'woo-rs-product-sync';
    const CACHE_KEY   = 'woo_rs_github_release';
    const CACHE_TTL   = 12 * HOUR_IN_SECONDS;

    public static function init() {
        add_filter( 'update_plugins_github.com', array( __CLASS__, 'check_update' ), 10, 4 );
        add_filter( 'upgrader_install_package_result', array( __CLASS__, 'fix_directory' ), 10, 2 );
        add_filter( 'plugins_api', array( __CLASS__, 'plugin_info' ), 10, 3 );
        add_action( 'admin_post_woo_rs_product_sync_check_updates', array( __CLASS__, 'handle_check_updates' ) );
    }

    /**
     * Admin POST handler: flush the GitHub release cache and force an update check.
     */
    public static function handle_check_updates() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( 'Unauthorized' );
        }
        check_admin_referer( 'woo_rs_product_sync_check_updates' );

        delete_transient( self::CACHE_KEY );
        wp_clean_plugins_cache( true );
        wp_update_plugins();

        $release = self::fetch_latest_release();
        $status  = 'up_to_date';
        if ( $release ) {
            $remote_version = ltrim( $release->tag_name, 'v' );
            if ( version_compare( WOO_RS_PRODUCT_SYNC_VERSION, $remote_version, '<' ) ) {
                $status = 'update_available';
            }
        }

        wp_safe_redirect( add_query_arg( array( 'update_check' => $status ), admin_url( 'admin.php?page=woo-rs-product-sync' ) ) );
        exit;
    }

    /**
     * Query GitHub for the latest release and tell WordPress if an update is available.
     *
     * @param array|false $update   Existing update data (false if none).
     * @param array       $plugin_data  Plugin header data.
     * @param string      $plugin_file  Plugin file relative path.
     * @param string[]    $locales      Installed locales.
     * @return array|false
     */
    public static function check_update( $update, $plugin_data, $plugin_file, $locales ) {
        // Only handle our plugin.
        if ( plugin_basename( WOO_RS_PRODUCT_SYNC_FILE ) !== $plugin_file ) {
            return $update;
        }

        $release = self::fetch_latest_release();
        if ( ! $release ) {
            return $update;
        }

        $remote_version = ltrim( $release->tag_name, 'v' );

        if ( version_compare( WOO_RS_PRODUCT_SYNC_VERSION, $remote_version, '>=' ) ) {
            return $update;
        }

        return array(
            'slug'    => self::SLUG,
            'version' => $remote_version,
            'url'     => $release->html_url,
            'package' => self::get_asset_url( $release ),
        );
    }

    /**
     * After WordPress extracts the zip, rename the directory from
     * "dataforge-woo-rs-product-sync-<hash>" back to "woo-rs-product-sync".
     *
     * @param array|WP_Error $result  Installer result.
     * @param array          $options Installer options.
     * @return array|WP_Error
     */
    public static function fix_directory( $result, $options ) {
        if ( is_wp_error( $result ) ) {
            return $result;
        }

        // Only act on our plugin.
        if ( ! isset( $options['plugin'] ) || plugin_basename( WOO_RS_PRODUCT_SYNC_FILE ) !== $options['plugin'] ) {
            return $result;
        }

        global $wp_filesystem;

        $expected_dir = trailingslashit( WP_PLUGIN_DIR ) . self::SLUG;
        $actual_dir   = isset( $result['destination'] ) ? rtrim( $result['destination'], '/' ) : '';

        // If the directory already matches, nothing to do.
        if ( $actual_dir === $expected_dir ) {
            return $result;
        }

        // Move the extracted directory to the correct name.
        if ( $wp_filesystem->move( $actual_dir, $expected_dir, true ) ) {
            $result['destination']      = $expected_dir;
            $result['destination_name'] = self::SLUG;
            $result['remote_destination'] = $expected_dir;
        }

        return $result;
    }

    /**
     * Supply plugin info for the "View Details" modal in the WordPress admin.
     *
     * @param false|object|array $result
     * @param string             $action
     * @param object             $args
     * @return false|object
     */
    public static function plugin_info( $result, $action, $args ) {
        if ( 'plugin_information' !== $action ) {
            return $result;
        }

        if ( ! isset( $args->slug ) || self::SLUG !== $args->slug ) {
            return $result;
        }

        $release = self::fetch_latest_release();
        if ( ! $release ) {
            return $result;
        }

        $remote_version = ltrim( $release->tag_name, 'v' );

        $info              = new stdClass();
        $info->name        = 'Woo RS Product Sync';
        $info->slug        = self::SLUG;
        $info->version     = $remote_version;
        $info->author      = '<a href="https://dataforge.us">Dataforge</a>';
        $info->homepage    = 'https://github.com/' . self::GITHUB_REPO;
        $info->requires    = '5.8';
        $info->requires_php = '7.2';
        $info->download_link = self::get_asset_url( $release );
        $info->sections    = array(
            'description' => 'Syncs products from RepairShopr to WooCommerce via webhooks and scheduled API polling.',
            'changelog'   => nl2br( esc_html( $release->body ?? '' ) ),
        );

        return $info;
    }

    /**
     * Get the download URL for the attached .zip asset, falling back to zipball.
     *
     * @param object $release  GitHub release object.
     * @return string
     */
    private static function get_asset_url( $release ) {
        if ( ! empty( $release->assets ) ) {
            foreach ( $release->assets as $asset ) {
                if ( '.zip' === substr( $asset->name, -4 ) ) {
                    return $asset->browser_download_url;
                }
            }
        }
        return $release->zipball_url;
    }

    /**
     * Fetch the latest release from GitHub, cached via transient.
     *
     * @return object|false  Release object or false on failure.
     */
    private static function fetch_latest_release() {
        $cached = get_transient( self::CACHE_KEY );
        if ( false !== $cached ) {
            return $cached;
        }

        $url = 'https://api.github.com/repos/' . self::GITHUB_REPO . '/releases/latest';

        $response = wp_remote_get( $url, array(
            'headers' => array(
                'Accept'     => 'application/vnd.github.v3+json',
                'User-Agent' => 'WordPress/' . get_bloginfo( 'version' ) . '; ' . home_url(),
            ),
            'timeout' => 10,
        ) );

        if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) {
            // Cache the failure briefly so we don't hammer GitHub.
            set_transient( self::CACHE_KEY, false, 5 * MINUTE_IN_SECONDS );
            return false;
        }

        $release = json_decode( wp_remote_retrieve_body( $response ) );
        if ( ! $release || empty( $release->tag_name ) ) {
            set_transient( self::CACHE_KEY, false, 5 * MINUTE_IN_SECONDS );
            return false;
        }

        set_transient( self::CACHE_KEY, $release, self::CACHE_TTL );

        return $release;
    }
}
