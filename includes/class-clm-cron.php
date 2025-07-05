<?php
/**
 * CLM_Cron Class
 *
 * Handles scheduling and execution of cron jobs for Custom Link Manager.
 *
 * @package CustomLinkManager
 * @since 1.0.0
 */

// If this file is called directly, abort.
if ( ! defined( 'WPINC' ) ) {
    die;
}

class CLM_Cron {

    const DOMAIN_SCAN_HOOK = 'clm_daily_domain_scan';
    const URL_MONITOR_HOOK = 'clm_daily_url_monitor';

    /**
     * Initialize cron jobs.
     * Adds actions for our scheduled events.
     */
    public static function init() {
        add_action( self::DOMAIN_SCAN_HOOK, array( 'CLM_Scanner', 'scan_and_store_external_domains' ) );
        add_action( self::URL_MONITOR_HOOK, array( 'CLM_Url_Checker', 'scan_and_store_all_urls' ) );

        // Schedule events if not already scheduled
        if ( ! wp_next_scheduled( self::DOMAIN_SCAN_HOOK ) ) {
            wp_schedule_event( time(), 'daily', self::DOMAIN_SCAN_HOOK );
        }
        if ( ! wp_next_scheduled( self::URL_MONITOR_HOOK ) ) {
            // Stagger the cron jobs slightly if running on the same schedule
            wp_schedule_event( time() + ( HOUR_IN_SECONDS / 2 ), 'daily', self::URL_MONITOR_HOOK );
        }
    }

    /**
     * Clear scheduled cron jobs.
     * This should be called on plugin deactivation or uninstallation.
     */
    public static function deactivate_cron_jobs() {
        wp_clear_scheduled_hook( self::DOMAIN_SCAN_HOOK );
        wp_clear_scheduled_hook( self::URL_MONITOR_HOOK );
    }
}

// Initialize cron jobs when the plugin is loaded
add_action( 'plugins_loaded', array( 'CLM_Cron', 'init' ) );
?>
