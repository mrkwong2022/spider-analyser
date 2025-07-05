<?php
/**
 * CLM_Admin_Menu Class
 *
 * Handles the creation of admin menus for the Custom Link Manager plugin.
 *
 * @package CustomLinkManager
 * @since 1.0.0
 */

// If this file is called directly, abort.
if ( ! defined( 'WPINC' ) ) {
    die;
}

class CLM_Admin_Menu {

    /**
     * Constructor. Adds the action to create the admin menu.
     */
    public function __construct() {
        add_action( 'admin_menu', array( $this, 'add_plugin_menu' ) );
    }

    /**
     * Adds the main plugin menu and submenus.
     */
    public function add_plugin_menu() {
        // Add top-level menu
        add_menu_page(
            __( 'Link Tools', 'custom-link-manager' ), // Page title
            __( 'Link Tools', 'custom-link-manager' ), // Menu title
            'manage_options',                         // Capability
            'clm_main_menu',                          // Menu slug
            array( $this, 'render_dashboard_page' ),  // Function to display page content
            'dashicons-admin-links',                  // Icon URL
            75                                        // Position
        );

        // Add submenu for Domain Management
        add_submenu_page(
            'clm_main_menu',                                  // Parent slug
            __( 'Domain Management', 'custom-link-manager' ), // Page title
            __( 'Domain Management', 'custom-link-manager' ), // Menu title
            'manage_options',                                 // Capability
            'clm_domain_management',                          // Menu slug
            array( $this, 'render_domain_management_page' )   // Function
        );

        // Add submenu for URL Monitor
        add_submenu_page(
            'clm_main_menu',                              // Parent slug
            __( 'URL Monitor', 'custom-link-manager' ),   // Page title
            __( 'URL Monitor', 'custom-link-manager' ),   // Menu title
            'manage_options',                             // Capability
            'clm_url_monitor',                            // Menu slug
            array( $this, 'render_url_monitor_page' )     // Function
        );
    }

    /**
     * Renders the main dashboard/overview page for the plugin.
     * Placeholder for now.
     */
    public function render_dashboard_page() {
        echo '<div class="wrap">';
        echo '<h1>' . esc_html__( 'Link Tools Dashboard', 'custom-link-manager' ) . '</h1>';
        echo '<p>' . esc_html__( 'Welcome to the Custom Link Manager. Please use the submenus to manage domains and monitor URLs.', 'custom-link-manager' ) . '</p>';
        // Future: Add some overview stats or quick links here.
        echo '</div>';
    }

    /**
     * Renders the Domain Management page.
     * This will later display the WP_List_Table for domains.
     */
    public function render_domain_management_page() {
        echo '<div class="wrap">';
        echo '<h1>' . esc_html__( 'Domain Management', 'custom-link-manager' ) . '</h1>';

        require_once CLM_PLUGIN_DIR . 'admin/class-clm-domain-list-table.php';

        // Check if an "edit" action is requested
        if ( isset( $_GET['action'] ) && $_GET['action'] == 'edit_domain_item' && isset( $_GET['domain_id'] ) ) {
            // Verify nonce if you have one for the edit link itself, or rely on the form nonce
            $domain_id = absint( $_GET['domain_id'] );
            // Load and display the edit form
            $this->render_edit_domain_form( $domain_id );
        } else {
            // Display the list table
            $domain_list_table = new CLM_Domain_List_Table();
            $domain_list_table->prepare_items();

            echo '<form method="post">';
            // For plugins, we also need to ensure that the form posts to our page.
            echo '<input type="hidden" name="page" value="' . esc_attr( $_REQUEST['page'] ) . '" />';
            $domain_list_table->search_box( __( 'Search Domains', 'custom-link-manager' ), 'clm_search_domains' );
            $domain_list_table->display();
            echo '</form>';
        }
        echo '</div>';
    }

    /**
     * Renders the edit form for a domain.
     * @param int $domain_id
     */
    public function render_edit_domain_form( $domain_id ) {
        global $wpdb;
        $domain_item = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM " . CLM_DOMAIN_TABLE . " WHERE id = %d", $domain_id ), ARRAY_A );

        if ( ! $domain_item ) {
            echo '<div class="notice notice-error"><p>' . __( 'Domain not found.', 'custom-link-manager' ) . '</p></div>';
            return;
        }

        // Handle form submission
        if ( isset( $_POST['clm_save_domain_nonce'] ) && wp_verify_nonce( $_POST['clm_save_domain_nonce'], 'clm_save_domain_action_' . $domain_id ) ) {
            $updated_data = array(
                'domain_type'       => sanitize_text_field( $_POST['domain_type'] ),
                'domain_attribute'  => sanitize_text_field( $_POST['domain_attribute'] ),
                'rebate_identifier' => sanitize_text_field( $_POST['rebate_identifier'] ),
                'updated_at'        => current_time( 'mysql' ),
            );
            // Ensure domain itself is not being changed here, only its attributes
            // $original_domain = sanitize_text_field( $_POST['original_domain'] );
            // if ($original_domain !== $domain_item['domain']) { /* error or handle carefully */ }


            $where = array( 'id' => $domain_id );
            $wpdb->update( CLM_DOMAIN_TABLE, $updated_data, $where );

            // Refresh domain_item for display
            $domain_item = array_merge($domain_item, $updated_data);

            echo '<div class="notice notice-success is-dismissible"><p>' . __( 'Domain updated successfully!', 'custom-link-manager' ) . '</p></div>';
        }
        ?>
        <h2><?php _e( 'Edit Domain', 'custom-link-manager' ); ?>: <?php echo esc_html( $domain_item['domain'] ); ?></h2>
        <form method="post">
            <input type="hidden" name="page" value="<?php echo esc_attr( $_REQUEST['page'] ); ?>">
            <input type="hidden" name="action" value="edit_domain_item">
            <input type="hidden" name="domain_id" value="<?php echo esc_attr( $domain_id ); ?>">
            <?php wp_nonce_field( 'clm_save_domain_action_' . $domain_id, 'clm_save_domain_nonce' ); ?>

            <table class="form-table">
                <tbody>
                    <tr>
                        <th scope="row"><label for="domain_name_display"><?php _e( 'Domain', 'custom-link-manager' ); ?></label></th>
                        <td><input type="text" id="domain_name_display" value="<?php echo esc_attr( $domain_item['domain'] ); ?>" class="regular-text" readonly>
                        <p class="description"><?php _e('Domain name cannot be changed here to maintain integrity. Delete and re-scan if necessary.', 'custom-link-manager'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="domain_type"><?php _e( 'Domain Type', 'custom-link-manager' ); ?></label></th>
                        <td>
                            <select name="domain_type" id="domain_type">
                                <?php
                                $types = array( 'general' => __('General', 'custom-link-manager'), 'ads' => __('Ad/Sponsor', 'custom-link-manager'), 'known' => __('Known Site', 'custom-link-manager'), 'affiliate' => __('Affiliate', 'custom-link-manager') );
                                foreach ( $types as $key => $label ) {
                                    echo '<option value="' . esc_attr( $key ) . '" ' . selected( $domain_item['domain_type'], $key, false ) . '>' . esc_html( $label ) . '</option>';
                                }
                                ?>
                            </select>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="domain_attribute"><?php _e( 'Domain Attribute', 'custom-link-manager' ); ?></label></th>
                        <td>
                            <select name="domain_attribute" id="domain_attribute">
                                <?php
                                $attributes = array( 'dofollow' => __('dofollow', 'custom-link-manager'), 'nofollow' => __('nofollow', 'custom-link-manager') );
                                foreach ( $attributes as $key => $label ) {
                                    echo '<option value="' . esc_attr( $key ) . '" ' . selected( $domain_item['domain_attribute'], $key, false ) . '>' . esc_html( $label ) . '</option>';
                                }
                                ?>
                            </select>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="rebate_identifier"><?php _e( 'Rebate Identifier', 'custom-link-manager' ); ?></label></th>
                        <td>
                             <select name="rebate_identifier" id="rebate_identifier">
                                <?php
                                $rebates = array( 'none' => __('None', 'custom-link-manager'), 'identifier' => __('Identifier', 'custom-link-manager') );
                                foreach ( $rebates as $key => $label ) {
                                    echo '<option value="' . esc_attr( $key ) . '" ' . selected( $domain_item['rebate_identifier'], $key, false ) . '>' . esc_html( $label ) . '</option>';
                                }
                                ?>
                            </select>
                        </td>
                    </tr>
                </tbody>
            </table>
            <?php submit_button( __( 'Save Changes', 'custom-link-manager' ) ); ?>
        </form>
        <p><a href="?page=clm_domain_management"><?php _e( '&laquo; Back to Domain List', 'custom-link-manager' ); ?></a></p>
        <?php
    }

    /**
     * Renders the URL Monitor page.
     * This will later display the WP_List_Table for URLs.
     */
    public function render_url_monitor_page() {
        echo '<div class="wrap">';
        echo '<h1>' . esc_html__( 'URL Monitor', 'custom-link-manager' ) . '</h1>';

        settings_errors('clm_url_monitor_notices'); // Display any notices like "URL rechecked"

        require_once CLM_PLUGIN_DIR . 'admin/class-clm-url-monitor-list-table.php';
        $url_monitor_list_table = new CLM_URL_Monitor_List_Table();
        $url_monitor_list_table->prepare_items();

        echo '<form method="get">'; // Use GET for filtering, search, pagination
        echo '<input type="hidden" name="page" value="' . esc_attr( $_REQUEST['page'] ) . '" />';
        $url_monitor_list_table->search_box( __( 'Search URLs', 'custom-link-manager' ), 'clm_search_urls' );
        $url_monitor_list_table->display();
        echo '</form>';

        echo '</div>';
    }

    public function __construct() {
        add_action( 'admin_menu', array( $this, 'add_plugin_menu' ) );
        add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_scripts' ) );
    }

    /**
     * Enqueue admin scripts and styles.
     */
    public function enqueue_admin_scripts( $hook_suffix ) {
        // Only load on our plugin's pages
        // $hook_suffix for top level menu: toplevel_page_clm_main_menu
        // $hook_suffix for submenus: link-tools_page_clm_domain_management, link-tools_page_clm_url_monitor
        if ( strpos( $hook_suffix, 'clm_url_monitor' ) !== false ) {
            wp_enqueue_script(
                'clm-url-monitor-js',
                CLM_PLUGIN_URL . 'admin/js/clm-url-monitor.js',
                array( 'jquery' ),
                CLM_VERSION,
                true
            );
            wp_localize_script( 'clm-url-monitor-js', 'clm_object', array(
                'ajax_url' => admin_url( 'admin-ajax.php' ),
                'i18n' => array(
                    'recheck' => __( 'Recheck', 'custom-link-manager' ),
                    'rechecking' => __( 'Rechecking...', 'custom-link-manager' ),
                    'remove' => __( 'Remove', 'custom-link-manager' ),
                    'removing' => __( 'Removing...', 'custom-link-manager' ),
                    'error' => __( 'An error occurred.', 'custom-link-manager' ),
                    'confirmMarkNotBroken' => __( 'Are you sure you want to mark this URL as not broken? It will be removed from the monitor.', 'custom-link-manager'),
                    'confirmReplaceRedirect' => __( 'Are you sure you want to replace this URL with its final destination in the monitor? This does not change the post content yet.', 'custom-link-manager'),
                    'useFinalUrl' => __( 'Use Final URL', 'custom-link-manager' ),
                    'replacing' => __( 'Replacing...', 'custom-link-manager' ),
                )
            ) );
        }
    }

    /**
     * Static method to register AJAX handlers.
     * Called from the main plugin file.
     */
    public static function register_ajax_handlers() {
        // Need to use a valid callback for non-static methods, or make these static.
        // For simplicity, making them static as they don't rely on $this.
        add_action( 'wp_ajax_clm_recheck_url', array( 'CLM_Admin_Menu', 'ajax_recheck_url' ) );
        add_action( 'wp_ajax_clm_remove_url', array( 'CLM_Admin_Menu', 'ajax_remove_url' ) );
        add_action( 'wp_ajax_clm_mark_not_broken', array( __CLASS__, 'ajax_mark_not_broken' ) );
        add_action( 'wp_ajax_clm_replace_redirect_url', array( __CLASS__, 'ajax_replace_redirect_url' ) );
    }

    public static function ajax_recheck_url() {
        check_ajax_referer( 'clm_recheck_url_' . $_POST['id'], 'nonce' );
        $id = absint( $_POST['id'] );
        global $wpdb;
        $item = $wpdb->get_row( $wpdb->prepare( "SELECT url FROM " . CLM_URL_MONITOR_TABLE . " WHERE id = %d", $id ), ARRAY_A );
        if ($item) {
            $status_info = CLM_Url_Checker::get_url_status( $item['url'], false ); // Check without following redirect initially
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
            wp_send_json_success( array( 'status_code' => $status_info['status_code'], 'status_text' => CLM_URL_Monitor_List_Table::get_http_status_text($status_info['status_code']) ) );
        }
        wp_send_json_error( array( 'message' => __('URL not found or error.', 'custom-link-manager') ) );
    }

    public static function ajax_remove_url() {
        check_ajax_referer( 'clm_remove_url_' . $_POST['id'], 'nonce' );
        $id = absint( $_POST['id'] );
        CLM_URL_Monitor_List_Table::delete_url_entry( $id );
        wp_send_json_success( array( 'message' => __('URL entry removed.', 'custom-link-manager') ) );
    }

    public static function ajax_mark_not_broken() {
        check_ajax_referer( 'clm_mark_not_broken_' . $_POST['id'], 'nonce' );
        $id = absint( $_POST['id'] );
        // For now, "mark as not broken" means remove it from the monitor.
        // Later, this could be a flag to ignore it in future scans or set a special status.
        CLM_URL_Monitor_List_Table::delete_url_entry( $id );
        wp_send_json_success( array( 'message' => __('URL marked as not broken and removed from monitor.', 'custom-link-manager') ) );
    }

    public static function ajax_replace_redirect_url() {
        check_ajax_referer( 'clm_replace_redirect_url_' . $_POST['id'], 'nonce' );
        $id = absint( $_POST['id'] );
        global $wpdb;
        $item = $wpdb->get_row( $wpdb->prepare( "SELECT url, post_id FROM " . CLM_URL_MONITOR_TABLE . " WHERE id = %d", $id ), ARRAY_A );
        if ($item) {
            $status_info = CLM_Url_Checker::get_url_status( $item['url'], true ); // Follow redirects

            if ( $status_info['final_url'] !== $item['url'] && !empty($status_info['final_url']) ) {
                // Update the URL in the monitor table
                $wpdb->update(
                    CLM_URL_MONITOR_TABLE,
                    array(
                        'url'             => $status_info['final_url'],
                        'http_status'     => $status_info['status_code'], // This will be the status of the final URL
                        'last_checked_at' => current_time( 'mysql' )
                    ),
                    array( 'id' => $id ),
                    array( '%s', '%s', '%s' ),
                    array( '%d' )
                );

                // IMPORTANT: Replacing in post content is a destructive action and complex.
                // This part is deferred as per earlier discussion.
                // For now, we only update the monitor table.
                // If we were to update post content:
                // $post_content = get_post_field('post_content', $item['post_id']);
                // $new_content = str_replace('href="' . $item['url'] . '"', 'href="' . $status_info['final_url'] . '"', $post_content);
                // $new_content = str_replace("href='" . $item['url'] . "'", "href='" . $status_info['final_url'] . "'", $new_content);
                // if ($new_content !== $post_content) {
                //    wp_update_post(array('ID' => $item['post_id'], 'post_content' => $new_content));
                // }
                wp_send_json_success( array(
                    'message' => __('URL updated to final destination in monitor.', 'custom-link-manager'),
                    'new_url' => $status_info['final_url'],
                    'status_code' => $status_info['status_code'],
                    'status_text' => CLM_URL_Monitor_List_Table::get_http_status_text($status_info['status_code'])
                ) );

            } else if ($status_info['final_url'] === $item['url']) {
                 wp_send_json_error( array( 'message' => __('URL is not a redirect or final URL is the same.', 'custom-link-manager') ) );
            } else {
                 wp_send_json_error( array( 'message' => __('Could not determine final URL.', 'custom-link-manager') ) );
            }
        }
        wp_send_json_error( array( 'message' => __('URL not found or error.', 'custom-link-manager') ) );
    }
}
?>
