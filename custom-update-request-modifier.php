<?php

/**
   * The plugin bootstrap file
   *
   * @link              https://robertdevore.com
   * @since             1.0.0
   * @package           Custom_Update_Request_Modifier
   *
   * @wordpress-plugin
   *
   * Plugin Name: Custom Update Request Modifier
   * Description: Modifies the user-agent string in HTTP requests for theme, plugin, core, and other WordPress API requests. Allows custom API URL configuration, logs request details including headers and body, and excludes non-WordPress.org plugins from update checks.
   * Plugin URI:  https://github.com/robertdevore/custom-update-request-modifier/
   * Version:     1.0.0
   * Author:      Robert DeVore
   * Author URI:  https://robertdevore.com/
   * License:     GPL-2.0+
   * License URI: http://www.gnu.org/licenses/gpl-2.0.txt
   * Text Domain: custom-urm
   * Domain Path: /languages
   * Update URI:  https://github.com/robertdevore/custom-update-request-modifier/
   */
 
// If this file is called directly, abort.
if ( ! defined( 'WPINC' ) ) {
    die;
}

require 'vendor/plugin-update-checker/plugin-update-checker.php';
use YahnisElsts\PluginUpdateChecker\v5\PucFactory;

$myUpdateChecker = PucFactory::buildUpdateChecker(
    'https://github.com/robertdevore/custom-update-request-modifier/',
    __FILE__,
    'custom-update-request-modifier'
);

// Set the branch that contains the stable release.
$myUpdateChecker->setBranch( 'main' );

// Check if Composer's autoloader is already registered globally.
if ( ! class_exists( 'RobertDevore\WPComCheck\WPComPluginHandler' ) ) {
    require_once __DIR__ . '/vendor/autoload.php';
}

use RobertDevore\WPComCheck\WPComPluginHandler;

new WPComPluginHandler( plugin_basename( __FILE__ ), 'https://robertdevore.com/why-this-plugin-doesnt-support-wordpress-com-hosting/' );

/**
 * Current plugin version.
 */
define( 'CUSTOM_URM_VERSION', '1.0.0' );

/**
 * Initialize the plugin.
 * 
 * @since 1.0.0
 */
function custom_urm_init() {
    add_filter( 'http_request_args', 'custom_modify_user_agent', 10, 2 );
    add_action( 'http_api_debug', 'custom_urm_log_response', 10, 5 );
}
add_action( 'plugins_loaded', 'custom_urm_init' );

// Include the custom gift cards list table class.
require_once plugin_dir_path( __FILE__ ) . 'classes/class-custom-urm-logs-table.php';

/**
 * Activation Hook: Create Log Table and Schedule Daily Log Clearing
 * 
 * @since 1.0.0
 */
register_activation_hook( __FILE__, 'custom_urm_activate_plugin' );

/**
 * Plugin activation
 * 
 * @since  1.0.0
 * @return void
 */
function custom_urm_activate_plugin() {
    custom_urm_create_log_table();
    custom_urm_schedule_daily_log_clear();
}

/**
 * Deactivation Hook: Unschedule Daily Log Clearing
 * 
 * @since 1.0.0
 */
register_deactivation_hook( __FILE__, 'custom_urm_deactivate_plugin' );

/**
 * Plugin deactivation
 * 
 * @since  1.0.0
 * @return void
 */
function custom_urm_deactivate_plugin() {
    custom_urm_unschedule_daily_log_clear();
}

/**
 * Uninstall Hook: Clean up database and options
 * 
 * @since  1.0.0
 */
register_uninstall_hook( __FILE__, 'custom_urm_uninstall_plugin' );

/**
 * Uninstall plugin
 * 
 * @since  1.0.0
 * @return void
 */
function custom_urm_uninstall_plugin() {
    global $wpdb;

    // Define the table name.
    $table_name = $wpdb->prefix . 'custom_urm_logs';

    // Drop the custom table.
    $wpdb->query( "DROP TABLE IF EXISTS $table_name" );

    // Delete plugin options.
    delete_option( 'custom_urm_api_urls' );
}

/**
 * Create custom table for logging if it doesn't exist.
 * 
 * @since  1.0.0
 * @return void
 */
function custom_urm_create_log_table() {
    global $wpdb;

    $table_name      = $wpdb->prefix . 'custom_urm_logs';
    $charset_collate = $wpdb->get_charset_collate();

    // SQL to create the custom logs table.
    $sql = "CREATE TABLE IF NOT EXISTS $table_name (
        id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
        time DATETIME DEFAULT CURRENT_TIMESTAMP NOT NULL,
        url TEXT NOT NULL,
        user_agent TEXT NOT NULL,
        request_headers TEXT,
        request_body LONGTEXT,
        response_code VARCHAR(10) NOT NULL,
        PRIMARY KEY (id)
    ) $charset_collate;";

    require_once( ABSPATH . 'wp-admin/includes/upgrade.php' );
    dbDelta( $sql );
}

/**
 * Register settings page and fields for the plugin.
 * 
 * @since  1.0.0
 * @return void
 */
function custom_urm_register_settings() {
    add_options_page(
        esc_html__( 'Custom URM Settings', 'custom-urm' ),
        esc_html__( 'Custom URM', 'custom-urm' ),
        'manage_options',
        'custom-urm-settings',
        'custom_urm_settings_page'
    );

    register_setting( 'custom_urm_settings_group', 'custom_urm_api_urls', [
        'type'              => 'array',
        'sanitize_callback' => 'custom_urm_sanitize_urls'
    ]);
}
add_action( 'admin_menu', 'custom_urm_register_settings' );

/**
 * Display settings page for the plugin with two tabs: API URLs and Logs.
 * 
 * @since  1.0.0
 * @return void
 */
function custom_urm_settings_page() {
    if ( ! current_user_can( 'manage_options' ) ) {
        return;
    }

    // Handle "Clear Logs" action.
    if ( isset( $_POST['custom_urm_clear_logs'] ) ) {
        // Verify nonce
        if ( ! isset( $_POST['custom_urm_clear_logs_nonce'] ) || ! wp_verify_nonce( $_POST['custom_urm_clear_logs_nonce'], 'custom_urm_clear_logs' ) ) {
            wp_die( __( 'Nonce verification failed.', 'custom-urm' ) );
        }

        // Clear the logs
        custom_urm_clear_logs();

        // Redirect to avoid resubmission
        wp_redirect( add_query_arg( 'cleared', '1', remove_query_arg( [ 'custom_urm_clear_logs', '_wpnonce' ], $_SERVER['REQUEST_URI'] ) ) );
        exit;
    }

    // Determine the active tab
    $active_tab = isset( $_GET['tab'] ) ? sanitize_text_field( $_GET['tab'] ) : 'api_urls';

    // Display success message if logs were cleared
    if ( isset( $_GET['cleared'] ) && '1' === $_GET['cleared'] ) {
        echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'All logs have been cleared successfully.', 'custom-urm' ) . '</p></div>';
    }

    ?>
    <div class="wrap">
        <h1 style="display: flex; justify-content: space-between; align-items: center;">
            <?php esc_html_e( 'Custom Update Request Modifier Settings', 'custom-urm' ); ?>

            <?php if ( 'logs' === $active_tab ) : ?>
                <form method="post" action="" style="margin: 0;">
                    <?php wp_nonce_field( 'custom_urm_clear_logs', 'custom_urm_clear_logs_nonce' ); ?>
                    <input type="hidden" name="custom_urm_clear_logs" value="1" />
                    <button type="submit" class="button button-secondary" onclick="return confirm('<?php echo esc_js( __( 'Are you sure you want to clear all logs?', 'custom-urm' ) ); ?>');">
                        <?php esc_html_e( 'Clear Logs', 'custom-urm' ); ?>
                    </button>
                </form>
            <?php endif; ?>
        </h1>

        <!-- Tab Navigation -->
        <h2 class="nav-tab-wrapper">
            <a href="?page=custom-urm-settings&tab=api_urls" class="nav-tab <?php echo $active_tab === 'api_urls' ? 'nav-tab-active' : ''; ?>">
                <?php esc_html_e( 'API URLs', 'custom-urm' ); ?>
            </a>
            <a href="?page=custom-urm-settings&tab=logs" class="nav-tab <?php echo $active_tab === 'logs' ? 'nav-tab-active' : ''; ?>">
                <?php esc_html_e( 'Logs', 'custom-urm' ); ?>
            </a>
        </h2>

        <?php if ( 'api_urls' === $active_tab ) : ?>
            <!-- API URLs Tab Content -->
            <form method="post" action="options.php">
                <?php 
                settings_fields( 'custom_urm_settings_group' );
                do_settings_sections( 'custom-urm-settings' ); 
                ?>

                <h2><?php esc_html_e( 'API URLs', 'custom-urm' ); ?></h2>
                <p><?php esc_html_e( 'Add or remove API URLs whose HTTP requests will be modified and logged for monitoring purposes.', 'custom-urm' ); ?></p>
                <table id="api-urls-table" class="form-table">
                    <tbody>
                        <?php
                        $api_urls = get_option( 'custom_urm_api_urls', [] );
                        if ( ! empty( $api_urls ) ) {
                            foreach ( $api_urls as $url ) {
                                echo '<tr><td><input type="text" name="custom_urm_api_urls[]" value="' . esc_url( $url ) . '" size="50" /></td><td><button type="button" class="button remove-url">' . esc_html__( 'Remove', 'custom-urm' ) . '</button></td></tr>';
                            }
                        }
                        ?>
                    </tbody>
                </table>
                <button type="button" id="add-api-url" class="button"><?php esc_html_e( 'Add URL', 'custom-urm' ); ?></button>
                <?php submit_button( __( 'Save Changes', 'custom-urm' ) ); ?>
            </form>
        <?php elseif ( 'logs' === $active_tab ) : ?>
            <?php
                // Instantiate and prepare the logs table.
                $logs_table = new Custom_URM_Logs_Table();
                $logs_table->prepare_items();
            ?>
            <form method="get">
                <input type="hidden" name="page" value="custom-urm-settings" />
                <input type="hidden" name="tab" value="logs" />
                <?php $logs_table->search_box( esc_html__( 'Search', 'custom-urm' ), 'log_search' ); ?>
                <?php $logs_table->display(); ?>
            </form>
        <?php endif; ?>
    </div>

    <!-- JavaScript for Adding/Removing API URL Rows -->
    <script type="text/javascript">
        document.addEventListener( 'DOMContentLoaded', function () {
            document.getElementById( 'add-api-url' ).addEventListener( 'click', function () {
                const table = document.getElementById( 'api-urls-table' ).querySelector( 'tbody' );
                const row = document.createElement( 'tr' );
                row.innerHTML = '<td><input type="text" name="custom_urm_api_urls[]" size="50" /></td><td><button type="button" class="button remove-url"><?php echo esc_js( __( 'Remove', 'custom-urm' ) ); ?></button></td>';
                table.appendChild( row );
            });

            document.addEventListener( 'click', function ( e ) {
                if ( e.target.classList.contains( 'remove-url' ) ) {
                    e.preventDefault();
                    e.target.closest( 'tr' ).remove();
                }
            });
        });
    </script>
    <?php
}

/**
 * Sanitize URLs in API URLs field.
 *
 * @param array $urls List of URLs.
 * 
 * @since  1.0.0
 * @return array Sanitized list of URLs.
 */
function custom_urm_sanitize_urls( $urls ) {
    if ( ! is_array( $urls ) ) {
        return [];
    }
    // Remove empty entries and sanitize each URL.
    return array_filter( array_map( 'esc_url_raw', $urls ) );
}

/**
 * Normalize URL by retaining only essential parameters for specific API requests.
 *
 * @param string $url      The original URL to normalize.
 * @param string $data_type Type of data being requested (core, plugins, themes).
 * 
 * @return string The normalized URL containing only essential parameters.
 */
function custom_urm_normalize_url( $url, $data_type = 'core' ) {
    // Parse the URL to get components and query parameters.
    $parsed_url = wp_parse_url( $url );
    parse_str( $parsed_url['query'] ?? '', $query_params );

    // Set allowed parameters based on the data type.
    $allowed_params = match ( $data_type ) {
        'core'    => apply_filters( 'custom_urm_allowed_core_params', [ 'version', 'php', 'locale', 'mysql', 'local_package', 'multisite_enabled', 'initial_db_version' ] ),
        'plugins' => [ 'plugins', 'active' ],
        'themes'  => [ 'themes' ],
        default   => []
    };

    // Filter query parameters to retain only those allowed.
    $filtered_params = array_intersect_key( $query_params, array_flip( $allowed_params ) );
    $filtered_query = http_build_query( $filtered_params );

    // Rebuild URL with filtered query.
    return $parsed_url['scheme'] . '://' . $parsed_url['host'] . $parsed_url['path'] . '?' . $filtered_query;
}

/**
 * Log each request to the custom database table with a placeholder for the response code.
 *
 * @param string $url             The request URL.
 * @param string $user_agent      The user-agent string.
 * @param string $request_headers JSON-encoded request headers.
 * @param string $request_body    JSON-encoded request body.
 * 
 * @since  1.0.0
 * @return void
 */
function custom_urm_log_request( $url, $user_agent, $request_headers, $request_body ) {
    global $wpdb;
    $table_name = $wpdb->prefix . 'custom_urm_logs';

    $wpdb->insert(
        $table_name,
        [
            'time'            => current_time( 'mysql' ),
            'url'             => esc_url_raw( $url ),
            'user_agent'      => sanitize_text_field( $user_agent ),
            'request_headers' => $request_headers,
            'request_body'    => $request_body,
            'response_code'   => 'Pending'
        ],
        [ '%s', '%s', '%s', '%s', '%s', '%s' ]
    );
}

/**
 * Modify user-agent, exclude items with UpdateURI, filter core params, and log request details.
 *
 * @param array  $args HTTP request arguments.
 * @param string $url  The request URL.
 * 
 * @since  1.0.0
 * @return array Modified HTTP request arguments.
 */
function custom_modify_user_agent( $args, $url ) {
    $api_urls = get_option( 'custom_urm_api_urls', [] );

    foreach ( $api_urls as $endpoint ) {
        $normalized_endpoint = untrailingslashit( $endpoint );

        if ( strpos( $url, $normalized_endpoint ) === 0 ) {
            // Identify data type (core, plugins, or themes) for normalization.
            $data_type = match (true) {
                strpos( $url, 'core/version-check/' ) !== false    => 'core',
                strpos( $url, 'plugins/update-check/' ) !== false  => 'plugins',
                strpos( $url, 'themes/update-check/' ) !== false   => 'themes',
                default                                            => 'core'
            };

            // Normalize URL for logging.
            $url         = custom_urm_normalize_url( $url, $data_type );
            $args['url'] = $url;

            // Modify the user-agent header.
            if ( ! empty( $args['user-agent'] ) ) {
                $original_user_agent = $args['user-agent'];
                $modified_user_agent = str_replace(
                    get_home_url(),
                    apply_filters( 'custom_urm_user_agent_string_replace', 'wordpress.org' ),
                    $args['user-agent']
                );
                $args['user-agent'] = $modified_user_agent;
            }

            // Handle plugins/themes by excluding those with UpdateURI and normalizing request body data.
            if ( $data_type !== 'core' && isset( $args['body'][ $data_type ] ) ) {
                $decodedJson = json_decode( $args['body'][ $data_type ], true );

                if ( $decodedJson && isset( $decodedJson[ $data_type ] ) ) {

                    $toRemove = [];
                    foreach ( $decodedJson[ $data_type ] as $file => $item ) {
                        if ( isset( $item['UpdateURI'] ) && ! empty( $item['UpdateURI'] ) ) {
                            $toRemove[] = $file;
                        }
                    }

                    // Remove items that need to be excluded from the update check.
                    foreach ( $toRemove as $remove ) {
                        unset( $decodedJson[ $data_type ][ $remove ] );
                    }

                    if ( $data_type === 'plugins' && isset( $decodedJson['active'] ) && is_array( $decodedJson['active'] ) ) {
                        $original_active = $decodedJson['active'];
                        $decodedJson['active'] = array_diff( $decodedJson['active'], $toRemove );
                    }

                    // Encode filtered JSON back into request body.
                    $args['body'][ $data_type ] = json_encode( $decodedJson );
                } else {
                    error_log( "[Custom Update Request Modifier] Custom URM: Failed to decode $data_type JSON." );
                }
            }

            // Log request.
            $request_headers = isset( $args['headers'] ) && is_array( $args['headers'] ) ? json_encode( $args['headers'], JSON_PRETTY_PRINT ) : '{}';
            $request_body    = isset( $args['body'] ) ? json_encode( $args['body'], JSON_PRETTY_PRINT ) : '{}';
            custom_urm_log_request( $url, $args['user-agent'], $request_headers, $request_body );

            break;
        }
    }
    return $args;
}

/**
 * Capture the response and update the corresponding log entry.
 *
 * @param WP_Error|array $response The HTTP response data or WP_Error.
 * @param string         $context  The context of the request.
 * @param string         $class    The HTTP class.
 * @param array          $args     HTTP request arguments.
 * @param string         $url      The request URL.
 * 
 * @since  1.0.0
 * @return void
 */
function custom_urm_log_response( $response, $context, $class, $args, $url ) {
    global $wpdb;
    $table_name = $wpdb->prefix . 'custom_urm_logs';

    // Determine the data type and normalize the URL accordingly.
    $data_type = match (true) {
        strpos( $url, 'core/version-check/' ) !== false    => 'core',
        strpos( $url, 'plugins/update-check/' ) !== false  => 'plugins',
        strpos( $url, 'themes/update-check/' ) !== false   => 'themes',
        default                                            => 'core'
    };
    $normalized_url = custom_urm_normalize_url( $url, $data_type );

    // Determine the response code.
    $response_code = is_wp_error( $response ) ? $response->get_error_code() : ( $response['response']['code'] ?? 'Unknown' );

    // Find the latest log entry with a matching normalized URL.
    $log_entry = $wpdb->get_row(
        $wpdb->prepare(
            "SELECT * FROM $table_name WHERE url = %s AND response_code = 'Pending' ORDER BY time DESC LIMIT 1",
            $normalized_url
        )
    );

    if ( $log_entry ) {
        $wpdb->update(
            $table_name,
            [ 'response_code' => $response_code ],
            [ 'id' => $log_entry->id ],
            [ '%s' ],
            [ '%d' ]
        );
    } else {
        error_log( "[Custom Update Request Modifier] No matching 'Pending' entry found for $data_type normalized URL: $normalized_url" );
    }
}

/**
 * Clear logs from the custom table.
 * 
 * @since  1.0.0
 * @return void
 */
function custom_urm_clear_logs() {
    global $wpdb;
    $table_name = $wpdb->prefix . 'custom_urm_logs';
    $wpdb->query( "TRUNCATE TABLE $table_name" );
}

/**
 * Schedule daily log clearing on plugin activation.
 * 
 * @since  1.0.0
 * @return void
 */
function custom_urm_schedule_daily_log_clear() {
    if ( ! wp_next_scheduled( 'custom_urm_daily_log_clear' ) ) {
        wp_schedule_event( time(), 'daily', 'custom_urm_daily_log_clear' );
    }
}
add_action( 'custom_urm_daily_log_clear', 'custom_urm_clear_logs' );

/**
 * Unschedule daily log clearing on plugin deactivation.
 * 
 * @since  1.0.0
 * @return void
 */
function custom_urm_unschedule_daily_log_clear() {
    $timestamp = wp_next_scheduled( 'custom_urm_daily_log_clear' );
    if ( $timestamp ) {
        wp_unschedule_event( $timestamp, 'custom_urm_daily_log_clear' );
    }
}

/**
 * Enqueue scripts and styles for the logs page if needed.
 * 
 * @since  1.0.0
 * @return void
 */
function custom_urm_enqueue_assets( $hook ) {
    if ( 'settings_page_custom-urm-settings' !== $hook ) {
        return;
    }

    wp_enqueue_style( 'custom-urm-styles', plugin_dir_url( __FILE__ ) . 'assets/css/custom-urm-styles.css', [], CUSTOM_URM_VERSION );
    wp_enqueue_script( 'custom-urm-scripts', plugin_dir_url( __FILE__ ) . 'assets/js/custom-urm-scripts.js', [], CUSTOM_URM_VERSION, true );
}
add_action( 'admin_enqueue_scripts', 'custom_urm_enqueue_assets' );
