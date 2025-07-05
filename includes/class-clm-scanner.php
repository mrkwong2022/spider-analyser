<?php
/**
 * CLM_Scanner Class
 *
 * Handles scanning posts and pages for links and domains.
 *
 * @package CustomLinkManager
 * @since 1.0.0
 */

// If this file is called directly, abort.
if ( ! defined( 'WPINC' ) ) {
    die;
}

class CLM_Scanner {

    /**
     * Get all published posts and pages.
     *
     * @return WP_Post[] Array of post objects.
     */
    private static function get_all_published_posts_and_pages() {
        $args = array(
            'post_type'      => array( 'post', 'page' ),
            'post_status'    => 'publish',
            'posts_per_page' => -1, // Get all posts
        );
        return get_posts( $args );
    }

    /**
     * Extracts all external domains from a given HTML content.
     *
     * @param string $content HTML content.
     * @return array Unique list of external domains.
     */
    public static function extract_external_domains_from_content( $content ) {
        $external_domains = array();
        if ( empty( $content ) ) {
            return $external_domains;
        }

        // Regex to find all href attributes in anchor tags
        preg_match_all( '/<a\s[^>]*href=(\"??)([^\" >]*?)\\1[^>]*>/siU', $content, $matches );

        if ( ! empty( $matches[2] ) ) {
            $home_url_parts = parse_url( home_url() );
            $site_domain = $home_url_parts['host'];

            foreach ( $matches[2] as $link ) {
                $url_parts = parse_url( $link );
                if ( isset( $url_parts['host'] ) && ! empty( $url_parts['host'] ) ) {
                    $domain = strtolower( $url_parts['host'] );
                    // Remove www. for consistency, if present
                    if (strpos($domain, 'www.') === 0) {
                        $domain = substr($domain, 4);
                    }
                    $site_domain_no_www = (strpos($site_domain, 'www.') === 0) ? substr($site_domain, 4) : $site_domain;

                    if ( $domain !== $site_domain_no_www && !in_array($domain, $external_domains) ) {
                        // Basic validation to avoid mailto, javascript, etc.
                        if (isset($url_parts['scheme']) && in_array($url_parts['scheme'], array('http', 'https'))) {
                            $external_domains[] = $domain;
                        }
                    }
                }
            }
        }
        return array_unique( $external_domains );
    }

    /**
     * Scans all published posts and pages, extracts external domains, and stores them.
     */
    public static function scan_and_store_external_domains() {
        global $wpdb;
        $posts = self::get_all_published_posts_and_pages();
        $all_found_domains = array();

        foreach ( $posts as $post ) {
            $domains_in_post = self::extract_external_domains_from_content( $post->post_content );
            $all_found_domains = array_merge( $all_found_domains, $domains_in_post );
        }
        $all_found_domains = array_unique( $all_found_domains );

        if ( ! empty( $all_found_domains ) ) {
            $current_time = current_time( 'mysql' );
            $existing_domains_query = "SELECT domain FROM " . CLM_DOMAIN_TABLE;
            $existing_domains = $wpdb->get_col( $existing_domains_query );

            $values_to_insert = array();
            foreach ( $all_found_domains as $domain ) {
                if ( ! in_array( $domain, $existing_domains ) ) {
                    $values_to_insert[] = $wpdb->prepare(
                        "(%s, %s, %s, %s, %s, %s)",
                        $domain,
                        'general', // default domain_type
                        'dofollow', // default domain_attribute
                        'none', // default rebate_identifier
                        $current_time,
                        $current_time
                    );
                }
            }

            if ( ! empty( $values_to_insert ) ) {
                $query = "INSERT INTO " . CLM_DOMAIN_TABLE . " (domain, domain_type, domain_attribute, rebate_identifier, created_at, updated_at) VALUES ";
                $query .= implode( ",\n", $values_to_insert );
                $wpdb->query( $query );
            }
        }
        // Optionally, log how many domains were found/added.
        // update_option('clm_last_domain_scan_time', $current_time);
    }


    /**
     * Extracts all links from a given HTML content.
     *
     * @param string $content HTML content.
     * @param int $post_id The ID of the post being scanned.
     * @param string $post_title The title of the post being scanned.
     * @return array List of link details (anchor_text, url, post_id, post_title).
     */
    public static function extract_links_from_content( $content, $post_id, $post_title ) {
        $links = array();
        if ( empty( $content ) ) {
            return $links;
        }

        // Regex to find all href attributes and anchor text in anchor tags
        preg_match_all( '/<a\s[^>]*href=(\"??)([^\" >]*?)\\1[^>]*>(.*?)<\/a>/siU', $content, $matches, PREG_SET_ORDER );

        if ( ! empty( $matches ) ) {
            foreach ( $matches as $match ) {
                $url = trim( $match[2] );
                $anchor_text = trim( strip_tags( $match[3] ) ); // Basic sanitization for anchor text

                // Basic validation for URL
                $url_parts = parse_url( $url );
                if ( isset( $url_parts['scheme'] ) && in_array( strtolower( $url_parts['scheme'] ), array( 'http', 'https' ) ) && isset( $url_parts['host'] ) ) {
                    $links[] = array(
                        'anchor_text' => $anchor_text,
                        'url'         => $url,
                        'post_id'     => $post_id,
                        'post_title'  => $post_title, // Store for easier display
                    );
                }
            }
        }
        return $links;
    }
}
?>
