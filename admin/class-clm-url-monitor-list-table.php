<?php
/**
 * CLM_URL_Monitor_List_Table Class
 *
 * Extends WP_List_Table to display monitored URLs.
 *
 * @package CustomLinkManager
 * @since 1.0.0
 */

// If this file is called directly, abort.
if ( ! defined( 'WPINC' ) ) {
    die;
}

// WP_List_Table is not loaded automatically
if ( ! class_exists( 'WP_List_Table' ) ) {
    require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

class CLM_URL_Monitor_List_Table extends WP_List_Table {

    public function __construct() {
        parent::__construct( array(
            'singular' => __( 'Monitored URL', 'custom-link-manager' ),
            'plural'   => __( 'Monitored URLs', 'custom-link-manager' ),
            'ajax'     => true // Enable AJAX for actions
        ) );
    }

    /**
     * Retrieve URL data from the database.
     *
     * @param int $per_page
     * @param int $page_number
     * @return array
     */
    public static function get_monitored_urls( $per_page = 20, $page_number = 1, $orderby = 'last_checked_at', $order = 'desc', $filter_status = '' ) {
        global $wpdb;
        $sql = "SELECT * FROM " . CLM_URL_MONITOR_TABLE;

        // Filtering
        $where_clauses = array();
        if ( ! empty( $filter_status ) ) {
            if ($filter_status === 'valid') { // special case for "valid" (200-299)
                 $where_clauses[] = $wpdb->prepare( " (http_status >= %d AND http_status < %d) ", 200, 300 );
            } elseif ($filter_status === 'error') { // special case for "error" (400-599 and custom error codes < 0)
                 $where_clauses[] = $wpdb->prepare( " ((http_status >= %d AND http_status < %d) OR http_status < %d) ", 400, 600, 0 );
            } elseif ($filter_status === 'redirect') { // special case for "redirect" (300-399)
                 $where_clauses[] = $wpdb->prepare( " (http_status >= %d AND http_status < %d) ", 300, 400 );
            } else {
                $where_clauses[] = $wpdb->prepare( "http_status = %s", $filter_status );
            }
        }
        // Search
        if ( ! empty( $_REQUEST['s'] ) ) {
            $search_term = '%' . $wpdb->esc_like( $_REQUEST['s'] ) . '%';
            $where_clauses[] = $wpdb->prepare( " (url LIKE %s OR anchor_text LIKE %s OR post_title LIKE %s) ", $search_term, $search_term, $search_term );
        }

        if (count($where_clauses) > 0) {
            $sql .= " WHERE " . implode(' AND ', $where_clauses);
        }


        // Ordering
        $valid_orderby = array('url', 'anchor_text', 'post_title', 'http_status', 'last_checked_at');
        if ( in_array($orderby, $valid_orderby)) {
            $sql .= ' ORDER BY ' . esc_sql( $orderby );
            $sql .= ( strtolower($order) === 'asc' ) ? ' ASC' : ' DESC';
        } else {
             $sql .= ' ORDER BY last_checked_at DESC';
        }


        $sql .= $wpdb->prepare( " LIMIT %d", $per_page );
        $sql .= $wpdb->prepare( " OFFSET %d", ( $page_number - 1 ) * $per_page );

        return $wpdb->get_results( $sql, 'ARRAY_A' );
    }

    /**
     * Delete a monitored URL record.
     * @param int $id URL monitor ID
     */
    public static function delete_url_entry( $id ) {
        global $wpdb;
        $wpdb->delete( CLM_URL_MONITOR_TABLE, array( 'id' => $id ), array( '%d' ) );
    }

    /**
     * Returns the count of records in the database.
     * @return null|string
     */
    public static function record_count( $filter_status = '' ) {
        global $wpdb;
        $sql = "SELECT COUNT(*) FROM " . CLM_URL_MONITOR_TABLE;

        $where_clauses = array();
        if ( ! empty( $filter_status ) ) {
             if ($filter_status === 'valid') {
                 $where_clauses[] = $wpdb->prepare( " (http_status >= %d AND http_status < %d) ", 200, 300 );
            } elseif ($filter_status === 'error') {
                 $where_clauses[] = $wpdb->prepare( " ((http_status >= %d AND http_status < %d) OR http_status < %d) ", 400, 600, 0 );
            } elseif ($filter_status === 'redirect') {
                 $where_clauses[] = $wpdb->prepare( " (http_status >= %d AND http_status < %d) ", 300, 400 );
            } else {
                $where_clauses[] = $wpdb->prepare( "http_status = %s", $filter_status );
            }
        }
        // Search - Note: record_count for search is more complex if search needs to be exact.
        // For simplicity, this count might not exactly match filtered list if search is active.
        if ( ! empty( $_REQUEST['s'] ) ) {
            $search_term = '%' . $wpdb->esc_like( $_REQUEST['s'] ) . '%';
            $where_clauses[] = $wpdb->prepare( " (url LIKE %s OR anchor_text LIKE %s OR post_title LIKE %s) ", $search_term, $search_term, $search_term );
        }

        if (count($where_clauses) > 0) {
            $sql .= " WHERE " . implode(' AND ', $where_clauses);
        }
        return $wpdb->get_var( $sql );
    }

    public function no_items() {
        _e( 'No URLs found in the monitor.', 'custom-link-manager' );
    }

    public static function get_http_status_text( $code ) {
        $code = intval($code);
        // Custom codes
        if ($code === 0) return __('Connection Error/Unknown', 'custom-link-manager');
        if ($code === -1) return __('Invalid URL Format', 'custom-link-manager');
        if ($code === -2) return __('DNS Resolution Error', 'custom-link-manager');
        if ($code === -3) return __('Timeout', 'custom-link-manager');

        $status_text = get_status_header_desc( $code );
        return $status_text ? $status_text . " ($code)" : "Unknown Status ($code)";
    }

    public function column_default( $item, $column_name ) {
        switch ( $column_name ) {
            case 'anchor_text':
                return esc_html( $item[ $column_name ] );
            case 'post_title': // Link to post
                $post_link = get_edit_post_link( $item['post_id'] );
                if ($post_link) {
                    return sprintf( '<a href="%s">%s</a>', esc_url( $post_link ), esc_html( $item['post_title'] ) );
                }
                return esc_html( $item['post_title'] );
            case 'http_status':
                return esc_html( $this->get_http_status_text($item[ $column_name ]) );
            case 'last_checked_at':
                return esc_html( $item[ $column_name ] );
            default:
                return print_r( $item, true );
        }
    }

    function column_cb( $item ) {
        return sprintf(
            '<input type="checkbox" name="bulk-ids[]" value="%s" />', $item['id']
        );
    }

    function column_url( $item ) {
        $title = sprintf(
            '<a href="%s" target="_blank" title="%s">%s</a>',
            esc_url( $item['url'] ),
            esc_attr( $item['url'] ),
            esc_html( urldecode( $item['url'] ) )
        );

        $actions = array(
            'recheck' => sprintf(
                '<a href="#" class="clm-action-recheck" data-id="%d" data-nonce="%s">' . __( 'Recheck', 'custom-link-manager' ) . '</a>',
                absint( $item['id'] ), wp_create_nonce( 'clm_recheck_url_' . $item['id'] )
            ),
            'remove'  => sprintf(
                '<a href="#" class="clm-action-remove" data-id="%d" data-nonce="%s" onclick="return confirm(\'' . __( 'Are you sure you want to remove this entry from the monitor?', 'custom-link-manager' ) . '\')">' . __( 'Remove', 'custom-link-manager' ) . '</a>',
                absint( $item['id'] ), wp_create_nonce( 'clm_remove_url_' . $item['id'] )
            ),
        );

        $status_code = intval($item['http_status']);

        if ( $status_code === 404 ) {
            $actions['mark_not_broken'] = sprintf(
                '<a href="#" class="clm-action-mark-not-broken" data-id="%d" data-nonce="%s">' . __( 'Mark as Not Broken', 'custom-link-manager' ) . '</a>',
                absint( $item['id'] ), wp_create_nonce( 'clm_mark_not_broken_' . $item['id'] )
            );
        }
        if ( $status_code >= 300 && $status_code < 400 ) {
             $actions['replace_redirect'] = sprintf(
                '<a href="#" class="clm-action-replace-redirect" data-id="%d" data-nonce="%s">' . __( 'Use Final URL', 'custom-link-manager' ) . '</a>',
                absint( $item['id'] ), wp_create_nonce( 'clm_replace_redirect_' . $item['id'] )
            );
        }


        return $title . $this->row_actions( $actions );
    }

    function get_columns() {
        return array(
            'cb'              => '<input type="checkbox" />',
            'url'             => __( 'URL', 'custom-link-manager' ),
            'anchor_text'     => __( 'Anchor Text', 'custom-link-manager' ),
            'http_status'     => __( 'Status', 'custom-link-manager' ),
            'post_title'      => __( 'In Post', 'custom-link-manager' ),
            'last_checked_at' => __( 'Last Checked', 'custom-link-manager' )
        );
    }

    public function get_sortable_columns() {
        return array(
            'url'             => array( 'url', false ),
            'anchor_text'     => array( 'anchor_text', false ),
            'http_status'     => array( 'http_status', false ),
            'post_title'      => array( 'post_title', false ),
            'last_checked_at' => array( 'last_checked_at', true ) // True for default sort
        );
    }

    public function get_bulk_actions() {
        return array(
            'bulk-recheck' => __( 'Recheck Selected', 'custom-link-manager' ),
            'bulk-remove'  => __( 'Remove Selected', 'custom-link-manager' )
        );
    }

    protected function extra_tablenav( $which ) {
        if ( $which == "top" ) {
            $current_filter = isset( $_GET['status_filter'] ) ? $_GET['status_filter'] : '';
            ?>
            <div class="alignleft actions">
                <select name="status_filter" id="status_filter">
                    <option value=""><?php _e( 'All Statuses', 'custom-link-manager' ); ?></option>
                    <option value="valid" <?php selected( $current_filter, 'valid' ); ?>><?php _e( 'Valid (2xx)', 'custom-link-manager' ); ?></option>
                    <option value="error" <?php selected( $current_filter, 'error' ); ?>><?php _e( 'Errors (4xx, 5xx, Other)', 'custom-link-manager' ); ?></option>
                    <option value="redirect" <?php selected( $current_filter, 'redirect' ); ?>><?php _e( 'Redirects (3xx)', 'custom-link-manager' ); ?></option>
                    <option value="404" <?php selected( $current_filter, '404' ); ?>><?php _e( '404 Not Found', 'custom-link-manager' ); ?></option>
                    <option value="0" <?php selected( $current_filter, '0' ); ?>><?php _e( 'Connection Error/Unknown', 'custom-link-manager' ); ?></option>
                     <option value="-1" <?php selected( $current_filter, '-1' ); ?>><?php _e( 'Invalid URL Format', 'custom-link-manager' ); ?></option>
                    <option value="-2" <?php selected( $current_filter, '-2' ); ?>><?php _e( 'DNS Resolution Error', 'custom-link-manager' ); ?></option>
                    <option value="-3" <?php selected( $current_filter, '-3' ); ?>><?php _e( 'Timeout', 'custom-link-manager' ); ?></option>
                </select>
                <?php submit_button( __( 'Filter' ), 'button', 'filter_action', false, array( 'id' => 'post-query-submit' ) ); ?>
            </div>
            <?php
        }
    }


    public function prepare_items() {
        $this->_column_headers = $this->get_column_info();

        // Process bulk actions (AJAX handled separately, this is for non-JS or page load actions)
        $this->process_bulk_action_handler();

        $per_page     = $this->get_items_per_page( 'urls_per_page', 20 );
        $current_page = $this->get_pagenum();
        $orderby      = ( ! empty( $_REQUEST['orderby'] ) ) ? $_REQUEST['orderby'] : 'last_checked_at';
        $order        = ( ! empty( $_REQUEST['order'] ) ) ? $_REQUEST['order'] : 'desc';
        $filter_status = ( ! empty( $_REQUEST['status_filter'] ) ) ? $_REQUEST['status_filter'] : '';


        $total_items  = self::record_count( $filter_status );

        $this->set_pagination_args( array(
            'total_items' => $total_items,
            'per_page'    => $per_page
        ) );

        $this->items = self::get_monitored_urls( $per_page, $current_page, $orderby, $order, $filter_status );
    }

    /**
     * Handles non-AJAX bulk actions.
     * AJAX actions are handled by separate WordPress action hooks.
     */
    public function process_bulk_action_handler() {
        // Check if a bulk action is being performed
        $current_action = $this->current_action();

        if ( 'bulk-remove' === $current_action ) {
            check_admin_referer( 'bulk-' . $this->_args['plural'] ); // Nonce check for bulk actions
            $ids = isset( $_REQUEST['bulk-ids'] ) ? array_map( 'absint', $_REQUEST['bulk-ids'] ) : array();
            if ( ! empty( $ids ) ) {
                foreach ( $ids as $id ) {
                    self::delete_url_entry( $id );
                }
                // Add admin notice for success
                add_settings_error('clm_url_monitor_notices', 'clm_bulk_delete_success', __('Selected URL entries removed.', 'custom-link-manager'), 'updated');
            }
             wp_redirect( remove_query_arg( array( '_wpnonce', 'action', 'action2', 'bulk-ids' ) ) );
             exit;
        }

        // Bulk recheck would ideally be AJAX due to time it can take, but a simple non-AJAX one:
        if ( 'bulk-recheck' === $current_action ) {
            check_admin_referer( 'bulk-' . $this->_args['plural'] );
            $ids = isset( $_REQUEST['bulk-ids'] ) ? array_map( 'absint', $_REQUEST['bulk-ids'] ) : array();
            if ( ! empty( $ids ) ) {
                global $wpdb;
                foreach ( $ids as $id ) {
                    $item = $wpdb->get_row( $wpdb->prepare( "SELECT url FROM " . CLM_URL_MONITOR_TABLE . " WHERE id = %d", $id ), ARRAY_A );
                    if ($item) {
                        $status_info = CLM_Url_Checker::get_url_status( $item['url'], false );
                        $wpdb->update(
                            CLM_URL_MONITOR_TABLE,
                            array(
                                'http_status'     => $status_info['status_code'],
                                'last_checked_at' => current_time( 'mysql' )
                            ),
                            array( 'id' => $id ),
                            array( '%s', '%s' ),
                            array( '%d' )
                        );
                        sleep(1); // Be nice
                    }
                }
                 add_settings_error('clm_url_monitor_notices', 'clm_bulk_recheck_success', __('Selected URL entries rechecked.', 'custom-link-manager'), 'updated');
            }
            wp_redirect( remove_query_arg( array( '_wpnonce', 'action', 'action2', 'bulk-ids' ) ) );
            exit;
        }
    }
}
?>
