<?php
/**
 * Plugin Name: Custom Link Manager
 * Plugin URI: https://example.com/custom-link-manager
 * Description: Manages external domains and monitors URL status within your WordPress posts and pages.
 * Version: 1.0.0
 * Author: Jules @ AI
 * Author URI: https://example.com
 * License: GPLv2 or later
 * License URI: http://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: custom-link-manager
 * Domain Path: /languages
 */

// If this file is called directly, abort.
if ( ! defined( 'WPINC' ) ) {
    die;
}

// Define plugin constants
define( 'CLM_VERSION', '1.0.0' );
define( 'CLM_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'CLM_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'CLM_DOMAIN_TABLE', $GLOBALS['wpdb']->prefix . 'external_domains' );
define( 'CLM_URL_MONITOR_TABLE', $GLOBALS['wpdb']->prefix . 'url_monitor' );

// Activation and deactivation hooks
register_activation_hook( __FILE__, 'clm_activate_plugin' );
// register_deactivation_hook( __FILE__, 'clm_deactivate_plugin' ); // We'll use an uninstall hook for table deletion

// Include core files
// require_once CLM_PLUGIN_DIR . 'includes/class-clm-core.php';
require_once CLM_PLUGIN_DIR . 'includes/class-clm-db.php';
require_once CLM_PLUGIN_DIR . 'includes/class-clm-scanner.php';
require_once CLM_PLUGIN_DIR . 'includes/class-clm-cron.php';
require_once CLM_PLUGIN_DIR . 'includes/class-clm-content-processor.php';
require_once CLM_PLUGIN_DIR . 'includes/class-clm-url-checker.php';

// Admin specific files
if ( is_admin() ) {
    require_once CLM_PLUGIN_DIR . 'admin/class-clm-admin-menu.php';
    // List table classes are included within CLM_Admin_Menu when their respective pages are rendered.
    new CLM_Admin_Menu();
    CLM_Admin_Menu::register_ajax_handlers(); // Register AJAX handlers
}

// Initialize the plugin
// function clm_init() {
//     // $clm_core = new CLM_Core();
//     // Add actions and filters here
// }
// add_action( 'plugins_loaded', 'clm_init' );

/**
 * Activation function.
 *
 * This function is called when the plugin is activated.
 * It includes the CLM_DB class and calls the method to create database tables.
 */
function clm_activate_plugin() {
    // No need to require again if it's already included above, but good for clarity if this function were standalone.
    // require_once CLM_PLUGIN_DIR . 'includes/class-clm-db.php';
    CLM_DB::create_tables();
}

/**
 * Placeholder for deactivation function
 * For now, we are not doing anything on deactivation,
 * table removal will be handled by an uninstall hook.
 */
// function clm_deactivate_plugin() {
// }

/**
 * Uninstall function.
 *
 * This function is called when the plugin is uninstalled.
 * It includes the CLM_DB class and calls the method to remove database tables.
 * Note: This requires an uninstall.php file or register_uninstall_hook.
 */
function clm_uninstall_plugin() {
    require_once CLM_PLUGIN_DIR . 'includes/class-clm-db.php';
    require_once CLM_PLUGIN_DIR . 'includes/class-clm-cron.php'; // Ensure Cron class is available
    CLM_DB::drop_tables();
    CLM_Cron::deactivate_cron_jobs(); // Deactivate cron jobs on uninstall
    // Potentially also remove plugin options here.
}
register_uninstall_hook( __FILE__, 'clm_uninstall_plugin' );

?>
