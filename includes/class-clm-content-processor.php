<?php
/**
 * CLM_Content_Processor Class
 *
 * Handles processing of post content for domain attribute application and monitoring new domains.
 *
 * @package CustomLinkManager
 * @since 1.0.0
 */

// If this file is called directly, abort.
if ( ! defined( 'WPINC' ) ) {
    die;
}

class CLM_Content_Processor {

    /**
     * Initialize hooks for content processing.
     */
    public static function init() {
        add_filter( 'the_content', array( __CLASS__, 'apply_domain_attributes' ), 20 ); // Priority 20 to run after most content filters
        add_action( 'save_post', array( __CLASS__, 'monitor_post_save' ), 10, 2 );
    }

    /**
     * Applies domain attributes (like nofollow) to links in the content.
     *
     * @param string $content The post content.
     * @return string Processed post content.
     */
    public static function apply_domain_attributes( $content ) {
        if ( is_admin() || empty( $content ) ) {
            return $content;
        }

        global $wpdb;
        // Regex to find all href attributes in anchor tags
        // Using a more robust regex that handles various attribute orders and quotes
        $content = preg_replace_callback(
            '/<a\s+([^>]*href\s*=\s*([\"\'])(.*?)\2[^>]*)>/i',
            function( $matches ) use ( $wpdb ) {
                $original_anchor_tag_attributes = $matches[1]; // All attributes within <a> tag except '>'
                $url = $matches[3]; // The URL
                $full_match = $matches[0]; // The full <a> tag

                $url_parts = parse_url( $url );
                if ( ! isset( $url_parts['host'] ) || empty( $url_parts['host'] ) ) {
                    return $full_match; // Not an external link or malformed
                }

                // Check if it's an external link
                $home_url_parts = parse_url( home_url() );
                $site_domain = strtolower( $home_url_parts['host'] );
                if (strpos($site_domain, 'www.') === 0) {
                    $site_domain = substr($site_domain, 4);
                }

                $link_domain = strtolower( $url_parts['host'] );
                if (strpos($link_domain, 'www.') === 0) {
                    $link_domain = substr($link_domain, 4);
                }

                if ( $link_domain === $site_domain ) {
                    return $full_match; // Internal link
                }

                // Query the database for the domain's attribute
                $domain_data = $wpdb->get_row( $wpdb->prepare(
                    "SELECT domain_attribute FROM " . CLM_DOMAIN_TABLE . " WHERE domain = %s",
                    $link_domain
                ), ARRAY_A );

                if ( $domain_data && $domain_data['domain_attribute'] === 'nofollow' ) {
                    // Check if rel="nofollow" already exists
                    if ( preg_match( '/rel\s*=\s*([\"\'])(.*?)\1/i', $original_anchor_tag_attributes, $rel_matches ) ) {
                        $existing_rels = explode( ' ', strtolower( $rel_matches[2] ) );
                        if ( ! in_array( 'nofollow', $existing_rels ) ) {
                            $new_rel_attr = 'rel="' . esc_attr( trim( $rel_matches[2] . ' nofollow' ) ) . '"';
                            $modified_attributes = preg_replace( '/rel\s*=\s*([\"\'])(.*?)\1/i', $new_rel_attr, $original_anchor_tag_attributes, 1 );
                            return '<a ' . $modified_attributes . '>';
                        }
                        // Nofollow already exists
                        return $full_match;
                    } else {
                        // Add new rel="nofollow"
                        return '<a ' . $original_anchor_tag_attributes . ' rel="nofollow">';
                    }
                }
                return $full_match; // No matching domain or attribute is not nofollow
            },
            $content
        );

        return $content;
    }

    /**
     * Monitors posts when they are saved to extract and store new external domains.
     *
     * @param int $post_id The ID of the post being saved.
     * @param WP_Post $post The post object.
     */
    public static function monitor_post_save( $post_id, $post ) {
        // If this is just a revision, don't do anything.
        if ( wp_is_post_revision( $post_id ) ) {
            return;
        }
        // If this is an auto save, don't do anything.
        if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
            return;
        }
        // Check the user's permissions.
        if ( isset( $_POST['post_type'] ) && 'page' == $_POST['post_type'] ) {
            if ( ! current_user_can( 'edit_page', $post_id ) ) {
                return;
            }
        } else {
            if ( ! current_user_can( 'edit_post', $post_id ) ) {
                return;
            }
        }
        // Check post status
        if ( $post->post_status != 'publish' && $post->post_status != 'future' ) { // also consider pending if needed
            return;
        }

        // Check post type (only for posts and pages, or CPTs if configured)
        $allowed_post_types = apply_filters('clm_monitored_post_types', array('post', 'page'));
        if (!in_array($post->post_type, $allowed_post_types)) {
            return;
        }

        global $wpdb;
        $content = $post->post_content;
        $domains_in_post = CLM_Scanner::extract_external_domains_from_content( $content );

        if ( ! empty( $domains_in_post ) ) {
            $current_time = current_time( 'mysql' );
            $existing_domains_query = $wpdb->prepare(
                "SELECT domain FROM " . CLM_DOMAIN_TABLE . " WHERE domain IN (" . implode( ',', array_fill(0, count($domains_in_post), '%s') ) . ")",
                $domains_in_post
            );
            $existing_domains = $wpdb->get_col( $existing_domains_query );

            $values_to_insert = array();
            foreach ( $domains_in_post as $domain ) {
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
                $query .= " ON DUPLICATE KEY UPDATE domain=domain"; // Should not happen due to prior check, but as a safeguard.
                $wpdb->query( $query );
            }
        }

        // === URL Monitor Update on Post Save ===
        $links_in_post = CLM_Scanner::extract_links_from_content( $content, $post_id, $post->post_title );
        if ( ! empty( $links_in_post ) ) {
            $current_time_url_scan = current_time( 'mysql' ); // Potentially same as $current_time above, but good to be explicit

            foreach ( $links_in_post as $link_data ) {
                $url_to_check = $link_data['url'];
                $anchor_text  = $link_data['anchor_text'];
                // post_id and post_title are already available from $link_data or $post object

                // Check if this exact URL from this exact post already exists
                $existing_record = $wpdb->get_row( $wpdb->prepare(
                    "SELECT id, http_status FROM " . CLM_URL_MONITOR_TABLE . " WHERE url = %s AND post_id = %d",
                    $url_to_check,
                    $post_id
                ));

                // Perform a quick status check. Could be conditional (e.g., only for new links or if status is old)
                // For simplicity on save, we might check all links in the saved post.
                // However, to avoid slowing down post saving too much, especially if there are many links,
                // this could be deferred to a background task or only done for new links.
                // For now, let's check all links in the saved post but without following redirects to speed it up.
                $status_info = CLM_Url_Checker::get_url_status( $url_to_check, false );

                if ( $existing_record ) {
                    // Update existing record if status changed or if it's a general update
                    if ( $existing_record->http_status != $status_info['status_code'] ) {
                        $wpdb->update(
                            CLM_URL_MONITOR_TABLE,
                            array(
                                'anchor_text'     => $anchor_text,
                                'http_status'     => $status_info['status_code'],
                                'last_checked_at' => $current_time_url_scan,
                                'post_title'      => $post->post_title,
                            ),
                            array( 'id' => $existing_record->id ),
                            array( '%s', '%s', '%s', '%s' ),
                            array( '%d' )
                        );
                    } else {
                         // Even if status is same, update anchor_text, post_title and last_checked_at
                         $wpdb->update(
                            CLM_URL_MONITOR_TABLE,
                            array(
                                'anchor_text'     => $anchor_text,
                                'last_checked_at' => $current_time_url_scan,
                                'post_title'      => $post->post_title,
                            ),
                            array( 'id' => $existing_record->id ),
                            array( '%s', '%s', '%s'),
                            array( '%d' )
                        );
                    }
                } else {
                    // Insert new record
                    $wpdb->insert(
                        CLM_URL_MONITOR_TABLE,
                        array(
                            'anchor_text'     => $anchor_text,
                            'url'             => $url_to_check,
                            'post_id'         => $post_id,
                            'post_title'      => $post->post_title,
                            'http_status'     => $status_info['status_code'],
                            'last_checked_at' => $current_time_url_scan,
                            'created_at'      => $current_time_url_scan,
                        ),
                        array( '%s', '%s', '%d', '%s', '%s', '%s', '%s' )
                    );
                }
                // Consider a small sleep if this process becomes too heavy on post save,
                // though ideally, save_post actions should be quick.
                // sleep(1); // Probably not ideal here.
            }
        }
    }
}

// Initialize content processing
add_action( 'plugins_loaded', array( 'CLM_Content_Processor', 'init' ) );
?>
