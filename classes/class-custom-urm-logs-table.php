<?php
 
// If this file is called directly, abort.
if ( ! defined( 'WPINC' ) ) {
    die;
}

/**
 * Custom List Table Class for Displaying Logs
 * 
 * @since  1.0.0
 * @return void
 */
if ( ! class_exists( 'WP_List_Table' ) ) {
    require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

class Custom_URM_Logs_Table extends WP_List_Table {

    /**
     * Constructor.
     * 
     * @since  1.0.0
     * @return void
     */
    public function __construct() {
        parent::__construct( [
            'singular' => esc_html__( 'Log', 'custom-urm' ),
            'plural'   => esc_html__( 'Logs', 'custom-urm' ),
            'ajax'     => false
        ] );
    }

    /**
     * Retrieve logs data from the database.
     * 
     * @since  1.0.0
     * @return array|mixed|object|null
     */
    public function get_logs() {
        global $wpdb;
        $table_name = $wpdb->prefix . 'custom_urm_logs';

        // Handle search query.
        $search = isset( $_REQUEST['s'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['s'] ) ) : '';

        // Handle pagination.
        $per_page     = $this->get_items_per_page( 'logs_per_page', apply_filters( 'custom_urm_logs_per_page', 10 ) );
        $current_page = $this->get_pagenum();
        $offset       = ( $current_page - 1 ) * $per_page;

        // Prepare SQL query.
        if ( ! empty( $search ) ) {
            $like_search = '%' . $wpdb->esc_like( $search ) . '%';
            $sql         = $wpdb->prepare(
                "SELECT * FROM $table_name WHERE url LIKE %s OR user_agent LIKE %s ORDER BY time DESC LIMIT %d OFFSET %d",
                $like_search,
                $like_search,
                $per_page,
                $offset
            );

            $total_logs = $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT COUNT(*) FROM $table_name WHERE url LIKE %s OR user_agent LIKE %s",
                    $like_search,
                    $like_search
                )
            );
        } else {
            $sql = $wpdb->prepare(
                "SELECT * FROM $table_name ORDER BY time DESC LIMIT %d OFFSET %d",
                $per_page,
                $offset
            );

            $total_logs = $wpdb->get_var( "SELECT COUNT(*) FROM $table_name" );
        }

        $logs = $wpdb->get_results( $sql );

        // Set pagination.
        $this->set_pagination_args( [
            'total_items' => $total_logs,
            'per_page'    => $per_page,
            'total_pages' => ceil( $total_logs / $per_page )
        ] );

        return $logs;
    }

    /**
     * Define the columns for the table.
     * 
     * @since  1.0.0
     * @return array
     */
    public function get_columns() {
        $columns = [
            'time'            => esc_html__( 'Time', 'custom-urm' ),
            'url'             => esc_html__( 'URL', 'custom-urm' ),
            'user_agent'      => esc_html__( 'User Agent', 'custom-urm' ),
            'request_headers' => esc_html__( 'Request Headers', 'custom-urm' ),
            'request_body'    => esc_html__( 'Request Body', 'custom-urm' ),
            'response_code'   => esc_html__( 'Response Code', 'custom-urm' )
        ];

        return $columns;
    }

    /**
     * Define sortable columns.
     * 
     * @since  1.0.0
     * @return array
     */
    public function get_sortable_columns() {
        $sortable_columns = [
            'time'          => [ 'time', true ],
            'url'           => [ 'url', false ],
            'user_agent'    => [ 'user_agent', false ],
            'response_code' => [ 'response_code', false ]
        ];

        return $sortable_columns;
    }

    /**
     * Define bulk actions (none in this case).
     * 
     * @since  1.0.0
     * @return array
     */
    public function get_bulk_actions() {
        return [];
    }

    /**
     * Prepare the items for display.
     * 
     * @since  1.0.0
     * @return void
     */
    public function prepare_items() {
        $columns  = $this->get_columns();
        $hidden   = [];
        $sortable = $this->get_sortable_columns();

        $this->_column_headers = [ $columns, $hidden, $sortable ];

        $this->items = $this->get_logs();
    }

    /**
     * Default column display.
     *
     * @param object $item Log item.
     * @param string $column_name Column name.
     * 
     * @since  1.0.0
     * @return mixed
     */
    public function column_default( $item, $column_name ) {
        switch ( $column_name ) {
            case 'time':
            case 'url':
            case 'user_agent':
            case 'response_code':
                return esc_html( $item->$column_name );

            case 'request_headers':
                return '<button type="button" class="button show-modal" data-modal="headers-modal-' . esc_attr( $item->id ) . '">' . esc_html__( 'Show', 'custom-urm' ) . '</button>';

            case 'request_body':
                return '<button type="button" class="button show-modal" data-modal="body-modal-' . esc_attr( $item->id ) . '">' . esc_html__( 'Show', 'custom-urm' ) . '</button>';

            default:
                return print_r( $item, true );
        }
    }

    /**
     * Add row actions.
     *
     * @param object $item Log item.
     * 
     * @since  1.0.0
     * @return string
     */
    public function column_time( $item ) {
        return esc_html( $item->time );
    }

    /**
     * No default row actions.
     * 
     * @since  1.0.0
     * @return void
     */
    public function no_items() {
        esc_html_e( 'No logs found.', 'custom-urm' );
    }

    /**
     * Render the table.
     * 
     * @since  1.0.0
     * @return void
     */
    public function display() {
        parent::display();

        // After the table, render modals for each log item.
        if ( ! empty( $this->items ) ) {
            foreach ( $this->items as $item ) {
                // Decode JSON headers and body.
                $decoded_headers = ! empty( $item->request_headers ) ? json_decode( $item->request_headers, true ) : [];
                $decoded_body    = ! empty( $item->request_body ) ? json_decode( $item->request_body, true ) : [];

                // Prepare JSON strings for display in modal (encode back to JSON with pretty print)
                $json_headers = ! empty( $decoded_headers ) ? json_encode( $decoded_headers, JSON_PRETTY_PRINT ) : '{}';
                $json_body    = ! empty( $decoded_body ) ? json_encode( $decoded_body, JSON_PRETTY_PRINT ) : '{}';

                // Generate unique IDs for modal content.
                $headers_modal_id = 'headers-modal-' . esc_attr( $item->id );
                $body_modal_id    = 'body-modal-' . esc_attr( $item->id );

                // Modal for Request Headers.
                echo '
                <div id="' . esc_attr( $headers_modal_id ) . '" class="custom-urm-modal">
                    <div class="custom-urm-modal-content">
                        <span class="custom-urm-close" data-modal="' . esc_attr( $headers_modal_id ) . '">&times;</span>
                        <h2>' . esc_html__( 'Request Headers', 'custom-urm' ) . '</h2>
                        <pre>' . esc_html( $json_headers ) . '</pre>
                    </div>
                </div>';

                // Modal for Request Body.
                echo '
                <div id="' . esc_attr( $body_modal_id ) . '" class="custom-urm-modal">
                    <div class="custom-urm-modal-content">
                        <span class="custom-urm-close" data-modal="' . esc_attr( $body_modal_id ) . '">&times;</span>
                        <h2>' . esc_html__( 'Request Body', 'custom-urm' ) . '</h2>
                        <pre>' . esc_html( $json_body ) . '</pre>
                    </div>
                </div>';
            }
        }

    }
}
