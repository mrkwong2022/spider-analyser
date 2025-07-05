<?php
/**
 * CLM_Url_Checker Class
 *
 * Handles checking URL status and storing URL information.
 *
 * @package CustomLinkManager
 * @since 1.0.0
 */

// If this file is called directly, abort.
if ( ! defined( 'WPINC' ) ) {
    die;
}

class CLM_Url_Checker {

    /**
     * Checks the HTTP status of a given URL.
     *
     * @param string $url The URL to check.
     * @param bool $follow_redirects Whether to follow 30x redirects to get the final status.
     * @return array Contains 'status_code' (int) and 'final_url' (string).
     */
    public static function get_url_status( $url, $follow_redirects = true ) {
        $return = array(
            'status_code' => 0, // 0 for connection error or other issues
            'final_url'   => $url
        );

        // Validate URL format
        if ( ! filter_var( $url, FILTER_VALIDATE_URL ) ) {
            $return['status_code'] = -1; // Invalid URL format
            return $return;
        }

        $args = array(
            'timeout'     => 15, // seconds
            'redirection' => $follow_redirects ? 5 : 0, // Max number of redirects to follow
            'sslverify'   => apply_filters( 'clm_url_check_sslverify', true ), // Allow filtering for problematic SSL certs
            'user-agent'  => 'WordPress/' . get_bloginfo( 'version' ) . '; ' . get_bloginfo( 'url' ) . ' (CLM Plugin)',
        );

        $response = wp_remote_get( $url, $args );

        if ( is_wp_error( $response ) ) {
            // Could try to get a more specific error code if needed
            // For example, $response->get_error_code() might return 'http_request_failed'
            // which could map to a specific status like 503 or a custom code.
            // For simplicity, we'll use 0 for general errors.
            // Some common errors: 'Host not found', 'Connection timed out'
            $error_code = $response->get_error_code();
            if ($error_code === 'http_request_failed' && strpos(strtolower($response->get_error_message()), 'could not resolve host') !== false) {
                $return['status_code'] = -2; // DNS resolution error
            } elseif ($error_code === 'http_request_failed' && strpos(strtolower($response->get_error_message()), 'timed out') !== false) {
                $return['status_code'] = -3; // Timeout
            }
            // Other errors will remain 0
        } else {
            $return['status_code'] = wp_remote_retrieve_response_code( $response );
            if ( $follow_redirects ) {
                // If redirects were followed, wp_remote_get returns the final URL in 'url' if different
                $response_url = wp_remote_retrieve_url( $response );
                if ($response_url && $response_url !== $url) {
                    $return['final_url'] = $response_url;
                }
            }
        }
        return $return;
    }

    /**
     * Scans all published posts and pages, extracts all links, checks their status, and stores them.
     */
    public static function scan_and_store_all_urls() {
        global $wpdb;
        $args = array(
            'post_type'      => apply_filters('clm_url_monitor_post_types', array( 'post', 'page' )),
            'post_status'    => 'publish',
            'posts_per_page' => -1,
        );
        $posts = get_posts( $args );
        $current_time = current_time( 'mysql' );

        foreach ( $posts as $post ) {
            $links_in_post = CLM_Scanner::extract_links_from_content( $post->post_content, $post->ID, $post->post_title );

            if ( ! empty( $links_in_post ) ) {
                foreach ( $links_in_post as $link_data ) {
                    $url_to_check = $link_data['url'];
                    $anchor_text = $link_data['anchor_text'];
                    $post_id = $link_data['post_id'];
                    $post_title = $link_data['post_title'];

                    // Check if this exact URL from this exact post already exists to avoid duplicate checks if content hasn't changed.
                    // However, for simplicity of initial cron, we might re-check.
                    // A more sophisticated approach would be to only check new or updated links, or links whose status is old.

                    $status_info = self::get_url_status( $url_to_check, false ); // Don't follow redirects for initial scan, get the direct status

                    // Check if record exists for this URL in this post
                    $existing_record = $wpdb->get_row( $wpdb->prepare(
                        "SELECT id, http_status FROM " . CLM_URL_MONITOR_TABLE . " WHERE url = %s AND post_id = %d",
                        $url_to_check,
                        $post_id
                    ));

                    if ( $existing_record ) {
                        // Update existing record
                        if ( $existing_record->http_status != $status_info['status_code'] ) {
                             $wpdb->update(
                                CLM_URL_MONITOR_TABLE,
                                array(
                                    'anchor_text'     => $anchor_text, // Anchor text might change
                                    'http_status'     => $status_info['status_code'],
                                    'last_checked_at' => $current_time,
                                    // 'post_title' might change if post title changes, update it too
                                    'post_title'      => $post_title,
                                ),
                                array( 'id' => $existing_record->id ),
                                array( '%s', '%s', '%s', '%s' ), // Data formats
                                array( '%d' )  // Where format
                            );
                        } else {
                            // Status hasn't changed, just update last_checked_at and potentially anchor/title
                             $wpdb->update(
                                CLM_URL_MONITOR_TABLE,
                                array(
                                    'anchor_text'     => $anchor_text,
                                    'last_checked_at' => $current_time,
                                    'post_title'      => $post_title,
                                ),
                                array( 'id' => $existing_record->id ),
                                array( '%s', '%s', '%s' ),
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
                                'post_title'      => $post_title,
                                'http_status'     => $status_info['status_code'],
                                'last_checked_at' => $current_time,
                                'created_at'      => $current_time,
                            ),
                            array( '%s', '%s', '%d', '%s', '%s', '%s', '%s' )
                        );
                    }
                    // To avoid hammering servers, especially our own or others during scans.
                    sleep( apply_filters( 'clm_url_check_sleep_duration', 1 ) ); // Sleep for 1 second between checks
                }
            }
        }
        update_option('clm_last_url_scan_time', $current_time);
    }
}
?>
