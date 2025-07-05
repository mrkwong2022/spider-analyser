<?php
/**
 * CLM_Domain_List_Table Class
 *
 * Extends WP_List_Table to display external domains.
 *
 * @package CustomLinkManager
 * @since 1.0.0
 */

// If this file is called directly, abort.
if ( ! defined( 'WPINC' ) ) {
    die;
}

// WP_List_Table is not loaded automatically so we need to load it in our application
if ( ! class_exists( 'WP_List_Table' ) ) {
    require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

class CLM_Domain_List_Table extends WP_List_Table {

    /**
     * Constructor.
     */
    public function __construct() {
        parent::__construct( array(
            'singular' => __( 'Domain', 'custom-link-manager' ), // Singular name of the listed records
            'plural'   => __( 'Domains', 'custom-link-manager' ), // Plural name of the listed records
            'ajax'     => false // We'll C_U_R_D without AJAX for now
        ) );
    }

    /**
     * Retrieve domains data from the database.
     *
     * @param int $per_page
     * @param int $page_number
     * @return array
     */
    public static function get_domains( $per_page = 20, $page_number = 1 ) {
        global $wpdb;
        $sql = "SELECT * FROM " . CLM_DOMAIN_TABLE;

        if ( ! empty( $_REQUEST['orderby'] ) ) {
            $sql .= ' ORDER BY ' . esc_sql( $_REQUEST['orderby'] );
            $sql .= ! empty( $_REQUEST['order'] ) ? ' ' . esc_sql( $_REQUEST['order'] ) : ' ASC';
        } else {
            $sql .= ' ORDER BY domain ASC'; // Default order
        }

        $sql .= " LIMIT $per_page";
        $sql .= ' OFFSET ' . ( $page_number - 1 ) * $per_page;

        $result = $wpdb->get_results( $sql, 'ARRAY_A' );
        return $result;
    }

    /**
     * Delete a domain record.
     *
     * @param int $id domain ID
     */
    public static function delete_domain( $id ) {
        global $wpdb;
        $wpdb->delete( CLM_DOMAIN_TABLE, array( 'id' => $id ), array( '%d' ) );
    }

    /**
     * Returns the count of records in the database.
     *
     * @return null|string
     */
    public static function record_count() {
        global $wpdb;
        $sql = "SELECT COUNT(*) FROM " . CLM_DOMAIN_TABLE;
        return $wpdb->get_var( $sql );
    }

    /** Text displayed when no domain data is available */
    public function no_items() {
        _e( 'No domains found in the database.', 'custom-link-manager' );
    }

    /**
     * Render a column when no column specific method exists.
     *
     * @param array $item
     * @param string $column_name
     * @return mixed
     */
    public function column_default( $item, $column_name ) {
        switch ( $column_name ) {
            case 'domain':
            case 'domain_type':
            case 'domain_attribute':
            case 'rebate_identifier':
            case 'updated_at':
                return esc_html( $item[ $column_name ] );
            default:
                return print_r( $item, true ); // Show the whole array for troubleshooting purposes
        }
    }

    /**
     * Render the bulk edit checkbox
     *
     * @param array $item
     * @return string
     */
    function column_cb( $item ) {
        return sprintf(
            '<input type="checkbox" name="bulk-ids[]" value="%s" />', $item['id']
        );
    }

    /**
     * Method for name column
     *
     * @param array $item an array of DB data
     * @return string
     */
    function column_domain( $item ) {
        $edit_nonce = wp_create_nonce( 'clm_edit_domain' );
        $delete_nonce = wp_create_nonce( 'clm_delete_domain' );

        $title = '<strong>' . esc_html( $item['domain'] ) . '</strong>';

        $actions = array(
            'edit'   => sprintf( '<a href="?page=%s&action=%s&domain_id=%s&_wpnonce=%s">' . __( 'Edit', 'custom-link-manager' ) . '</a>', esc_attr( $_REQUEST['page'] ), 'edit_domain_item', absint( $item['id'] ), $edit_nonce ),
            'delete' => sprintf( '<a href="?page=%s&action=%s&domain_id=%s&_wpnonce=%s" onclick="return confirm(\'' . __( 'Are you sure you want to delete this domain?', 'custom-link-manager' ) . '\')">' . __( 'Delete', 'custom-link-manager' ) . '</a>', esc_attr( $_REQUEST['page'] ), 'delete_domain_item', absint( $item['id'] ), $delete_nonce )
        );

        return $title . $this->row_actions( $actions );
    }


    /**
     *  Associative array of columns
     *
     * @return array
     */
    function get_columns() {
        $columns = array(
            'cb'                => '<input type="checkbox" />',
            'domain'            => __( 'Domain', 'custom-link-manager' ),
            'domain_type'       => __( 'Type', 'custom-link-manager' ),
            'domain_attribute'  => __( 'Attribute', 'custom-link-manager' ),
            'rebate_identifier' => __( 'Rebate ID', 'custom-link-manager' ),
            'updated_at'        => __( 'Last Updated', 'custom-link-manager' )
        );
        return $columns;
    }

    /**
     * Columns to make sortable.
     *
     * @return array
     */
    public function get_sortable_columns() {
        $sortable_columns = array(
            'domain'           => array( 'domain', true ),
            'domain_type'      => array( 'domain_type', false ),
            'domain_attribute' => array( 'domain_attribute', false ),
            'updated_at'       => array( 'updated_at', false )
        );
        return $sortable_columns;
    }

    /**
     * Returns an associative array containing the bulk action
     *
     * @return array
     */
    public function get_bulk_actions() {
        $actions = array(
            'bulk-delete'             => __( 'Delete', 'custom-link-manager' ),
            'bulk_set_type_ads'       => __( 'Set Type: Ad/Sponsor', 'custom-link-manager' ),
            'bulk_set_type_known'     => __( 'Set Type: Known Site', 'custom-link-manager' ),
            'bulk_set_type_affiliate' => __( 'Set Type: Affiliate', 'custom-link-manager' ),
            'bulk_set_type_general'   => __( 'Set Type: General', 'custom-link-manager' ),
            'bulk_set_attr_nofollow'  => __( 'Set Attribute: nofollow', 'custom-link-manager' ),
            'bulk_set_attr_dofollow'  => __( 'Set Attribute: dofollow', 'custom-link-manager' ),
        );
        return $actions;
    }

    /**
     * Handles data query and filter, sorting, and pagination.
     */
    public function prepare_items() {
        $this->_column_headers = $this->get_column_info();

        /** Process bulk action */
        $this->process_bulk_action();

        $per_page     = $this->get_items_per_page( 'domains_per_page', 20 );
        $current_page = $this->get_pagenum();
        $total_items  = self::record_count();

        $this->set_pagination_args( array(
            'total_items' => $total_items, // WE have to calculate the total number of items
            'per_page'    => $per_page // WE have to determine how many items to show on a page
        ) );

        $this->items = self::get_domains( $per_page, $current_page );
    }

    /**
     * Process bulk actions.
     */
    public function process_bulk_action() {
        // Detect when a bulk action is being triggered...
        if ( 'delete_domain_item' === $this->current_action() ) {
            // In WordPress, we do not use $_REQUEST directly.
            // Verify the nonce.
            $nonce = esc_attr( $_REQUEST['_wpnonce'] );
            if ( ! wp_verify_nonce( $nonce, 'clm_delete_domain' ) ) {
                die( 'Go get a life script kiddies' );
            } else {
                self::delete_domain( absint( $_GET['domain_id'] ) );
                // Could add a success admin notice here
                // For now, redirect to clean the URL
                wp_redirect( remove_query_arg( array( 'action', 'domain_id', '_wpnonce' ) ) );
                exit;
            }
        }

        // If the delete bulk action is triggered
        if ( ( isset( $_POST['action'] ) && $_POST['action'] == 'bulk-delete' )
             || ( isset( $_POST['action2'] ) && $_POST['action2'] == 'bulk-delete' )
        ) {
            $delete_ids = esc_sql( $_POST['bulk-ids'] );
            // loop over the array of record IDs and delete them
            if ( ! empty( $delete_ids ) && is_array( $delete_ids )) {
                foreach ( $delete_ids as $id ) {
                    self::delete_domain( absint( $id ) );
                }
                // Could add a success admin notice here
                wp_redirect( remove_query_arg( array( 'action', 'action2', 'bulk-ids' ) ) );
                exit;
            }
        }

        // Process other bulk actions
        $action = $this->current_action();
        if ( strpos( $action, 'bulk_set_type_' ) === 0 || strpos( $action, 'bulk_set_attr_' ) === 0 ) {
            $ids = isset( $_POST['bulk-ids'] ) ? array_map( 'absint', $_POST['bulk-ids'] ) : array();

            if ( empty( $ids ) ) {
                return;
            }

            global $wpdb;
            $current_time = current_time( 'mysql' );
            $ids_placeholder = implode( ',', array_fill( 0, count( $ids ), '%d' ) );

            $data_to_update = array( 'updated_at' => $current_time );
            $data_format = array('%s'); // for updated_at

            if ( strpos( $action, 'bulk_set_type_' ) === 0 ) {
                $type = substr( $action, strlen( 'bulk_set_type_' ) );
                 // Validate type against known types
                $allowed_types = array('ads', 'known', 'affiliate', 'general');
                if (in_array($type, $allowed_types)) {
                    $data_to_update['domain_type'] = $type;
                    $data_format[] = '%s'; // for domain_type
                } else {
                    return; // Invalid type
                }
            } elseif ( strpos( $action, 'bulk_set_attr_' ) === 0 ) {
                $attribute = substr( $action, strlen( 'bulk_set_attr_' ) );
                // Validate attribute
                $allowed_attributes = array('nofollow', 'dofollow');
                 if (in_array($attribute, $allowed_attributes)) {
                    $data_to_update['domain_attribute'] = $attribute;
                    $data_format[] = '%s'; // for domain_attribute
                } else {
                    return; // Invalid attribute
                }
            }

            if (count($data_to_update) > 1) { // Ensure there's something to update besides timestamp
                 // Warning: $wpdb->update cannot directly accept an array of IDs in the $where clause in this manner.
                 // We need to construct the query carefully.
                $sql = "UPDATE " . CLM_DOMAIN_TABLE . " SET ";
                $set_clauses = array();
                foreach ($data_to_update as $column => $value) {
                    // Find the format for this column
                    $format_idx = array_search($column, array_keys($data_to_update));
                    $set_clauses[] = $wpdb->prepare( "$column = " . $data_format[$format_idx], $value );
                }
                $sql .= implode( ', ', $set_clauses );
                $sql .= $wpdb->prepare( " WHERE id IN ( $ids_placeholder )", $ids );

                $wpdb->query( $sql );
            }

            wp_redirect( remove_query_arg( array( 'action', 'action2', '_wpnonce', 'bulk-ids' ) ) );
            exit;
        }
    }
}
?>
