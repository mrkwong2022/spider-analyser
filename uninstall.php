<?php
/**
 * Uninstall script for Spider Analyser
 *
 * This script runs when the user deletes the plugin from the WordPress admin.
 * It should remove all plugin data, such as options, custom tables, and cron jobs.
 */

// Exit if accessed directly.
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

global $wpdb;

// Define plugin-specific option names and table names (without prefix)
$option_names = array(
	'wp_spider_analyser_option',
	// 'sync_wb_spider', // This option does not seem to be used. 'wb_spider_info' is used instead.
	'wb_spider_info',                 // Stores fetched spider information from API.
	'wb_spider_analyser_db_ver',
	'sp_an_max_id',
	'wb_spider_analyser_promote',
	'wb_spider_analyser_install_time', // Added: Stores plugin install time.
	// Options related to pro version activation
	'wb_spider_analyser_ver',
);

// Retrieve the activated version to delete version-specific config
$activated_ver = get_option( 'wb_spider_analyser_ver' );
if ( $activated_ver ) {
	// Sanitize the version string before using it in an option name.
	$option_names[] = 'wb_spider_analyser_cnf_' . preg_replace( '/[^a-zA-Z0-9_\.-]/', '', $activated_ver );
}

$table_suffixes = array(
	'wb_spider',
	'wb_spider_ip',
	'wb_spider_log',
	'wb_spider_post',
	'wb_spider_post_link',
	'wb_spider_sum',
	'wb_spider_visit',
);

$cron_hooks = array(
	'wp_wb_spider_analyser_cron',
	// 'wb_wp_spider_trace_cron', // This cron hook is not found in the current codebase. Keeping it is harmless.
);

// 1. Delete Options
foreach ( $option_names as $option_name ) {
	delete_option( $option_name );
	// Consider delete_site_option for multisite if options are network-wide
}

// 2. Delete Transients
// Delete specific known transients
delete_transient('wb_spider_30_day_stats');

// Delete transients by pattern (if any are known to follow a pattern)
// The pattern 'wb_cache_spider_analyser%' might be too broad or not used.
// For 'wb_localize_%' transients, it's better to clear them by specific names if possible,
// or use a more precise pattern if the WB_Localize_Plugin_Helper class sets them predictably.
// Since WP_SPIDER_ANALYSER_VERSION might not be available, pattern is safer if versions change.
$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
	$wpdb->esc_like( '_transient_wb_localize_' . 'wb-spider-analyser' ) . '%', // Text domain based
	$wpdb->esc_like( '_transient_timeout_wb_localize_' . 'wb-spider-analyser' ) . '%'
) );
// Add other specific transient patterns if known, e.g., for cache files if they use transients.


// 3. Drop Custom Tables
foreach ( $table_suffixes as $suffix ) {
	$table_name = $wpdb->prefix . $suffix;
	// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	$wpdb->query( "DROP TABLE IF EXISTS `{$table_name}`" );
}

// 4. Clear Scheduled Hooks
foreach ( $cron_hooks as $hook ) {
	wp_clear_scheduled_hook( $hook );
}

// 5. Remove Cached Files and Directories (if they exist and are safe to remove)
// Define path constants if not already defined in this uninstall context.
if ( ! defined( 'WP_SPIDER_ANALYSER_PATH' ) ) {
    // This path might be tricky to get reliably in uninstall.php if the main plugin file is already gone.
    // WP_UNINSTALL_PLUGIN is defined in wp-settings.php, which is loaded after the plugin itself is dealt with.
    // A common way is to assume the path relative to this uninstall.php file.
    define( 'WP_SPIDER_ANALYSER_PATH', __DIR__ );
}

$log_dir = WP_SPIDER_ANALYSER_PATH . '/#log/';
if ( is_dir( $log_dir ) ) {
	$log_files = glob( $log_dir . '*.php' ); // Cache files are .php
	if ( $log_files && is_array( $log_files ) ) {
		foreach ( $log_files as $file_path ) {
			if ( is_file( $file_path ) ) {
				@unlink( $file_path );
			}
		}
	}
	// Attempt to remove the directory if empty. Suppress errors if it fails.
	@rmdir( $log_dir );
}

$info_dir = WP_SPIDER_ANALYSER_PATH . '/#info/';
if ( is_dir( $info_dir ) ) {
	$info_files = glob( $info_dir . '*.php' );
	if ( $info_files && is_array( $info_files ) ) {
		foreach ( $info_files as $file_path ) {
			if ( is_file( $file_path ) ) {
				@unlink( $file_path );
			}
		}
	}
	@rmdir( $info_dir );
}

// Note: WP_SPIDER_ANALYSER_VERSION might not be defined here if index.php isn't loaded.
// For transient keys that include version, it's more robust to delete them by pattern if possible,
// or ensure the version constant is defined here if needed for specific transient names.
// For simplicity, specific versioned transient deletion is omitted here but should be considered
// if many such transients exist and pattern deletion is not feasible.

?>
