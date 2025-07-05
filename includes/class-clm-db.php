<?php
/**
 * CLM_DB Class
 *
 * Handles database operations for the Custom Link Manager plugin.
 *
 * @package CustomLinkManager
 * @since 1.0.0
 */

// If this file is called directly, abort.
if ( ! defined( 'WPINC' ) ) {
    die;
}

class CLM_DB {

    /**
     * Create custom database tables upon plugin activation.
     */
    public static function create_tables() {
        global $wpdb;
        $charset_collate = $wpdb->get_charset_collate();

        // SQL to create the external domains table
        $sql_external_domains = "CREATE TABLE " . CLM_DOMAIN_TABLE . " (
            id mediumint(9) NOT NULL AUTO_INCREMENT,
            domain varchar(255) NOT NULL,
            domain_type varchar(50) DEFAULT 'general' NOT NULL,
            domain_attribute varchar(20) DEFAULT 'dofollow' NOT NULL,
            rebate_identifier varchar(50) DEFAULT 'none' NOT NULL,
            created_at datetime DEFAULT '0000-00-00 00:00:00' NOT NULL,
            updated_at datetime DEFAULT '0000-00-00 00:00:00' NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY domain (domain)
        ) $charset_collate;";

        // SQL to create the URL monitor table
        $sql_url_monitor = "CREATE TABLE " . CLM_URL_MONITOR_TABLE . " (
            id mediumint(9) NOT NULL AUTO_INCREMENT,
            anchor_text text,
            url varchar(2083) NOT NULL, -- Max URL length
            post_id bigint(20) unsigned NOT NULL,
            post_title text,
            http_status varchar(20) DEFAULT '0' NOT NULL,
            last_checked_at datetime DEFAULT '0000-00-00 00:00:00' NOT NULL,
            created_at datetime DEFAULT '0000-00-00 00:00:00' NOT NULL,
            PRIMARY KEY  (id),
            KEY url (url(191)), -- Index for faster URL lookups, 191 for utf8mb4 compatibility
            KEY post_id (post_id),
            KEY http_status (http_status)
        ) $charset_collate;";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta( $sql_external_domains );
        dbDelta( $sql_url_monitor );
    }

    /**
     * Remove custom database tables upon plugin uninstallation.
     * Note: This should be called from an uninstall hook.
     */
    public static function drop_tables() {
        global $wpdb;
        $wpdb->query( "DROP TABLE IF EXISTS " . CLM_DOMAIN_TABLE );
        $wpdb->query( "DROP TABLE IF EXISTS " . CLM_URL_MONITOR_TABLE );
    }
}
?>
