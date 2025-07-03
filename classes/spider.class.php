<?php
/**
 * Spider Analyser Core Class.
 *
 * Handles spider detection, logging, request parsing, AJAX actions,
 * and other core functionalities of the plugin.
 *
 * @package Spider_Analyser
 * @since 1.0.0
 */

if (!defined('ABSPATH')) {
    return;
}

/**
 * Main class for Spider Analyser plugin.
 * Extends WP_Spider_Analyser_Base for common utilities.
 */
class WP_Spider_Analyser extends WP_Spider_Analyser_Base
{
    /**
     * Flag to indicate if a log entry has already been made for the current request.
     * @var bool
     */
    public static $in_log = false;
    /**
     * Debug mode flag.
     * @var bool
     */
    public static $debug = false;
    /**
     * Flag to indicate if the current request has been blocked.
     * @var bool
     */
    public static $blocked = false;
    /**
     * Flag to indicate if the 'parse_request' action has occurred.
     * @var bool
     */
    public static $after_request = false;

    /**
     * Initialize the plugin, set up hooks and actions.
     */
    public static function init()
    {
        add_action('plugins_loaded', function () {
            load_plugin_textdomain(WB_SPA_DM, false, plugin_basename(WP_SPIDER_ANALYSER_PATH) . '/languages/');
        });

        add_filter('all_plugins', function ($plugins) {
            if (isset($plugins['spider-analyser/index.php'])) {
                $plugins_info = [
                    'Name' => __('Spider Analyser', WB_SPA_DM),
                    'Title' => __('Spider Analyser', WB_SPA_DM),
                    'Author' => __('闪电博', WB_SPA_DM),
                    'AuthorName' => __('闪电博', WB_SPA_DM),
                    'Description' => __('Spider Analyser是一款用于跟踪WordPress网站各种搜索引擎蜘蛛爬行日志的插件，并进行详细的蜘蛛爬行数据统计、蜘蛛行为分析、蜘蛛爬取分析及伪蜘蛛拦截等。', WB_SPA_DM),
                    'AuthorURI' => __('https://www.wbolt.com/', WB_SPA_DM)
                ];
                $plugins['spider-analyser/index.php'] = array_merge($plugins['spider-analyser/index.php'], $plugins_info);
            }
            return $plugins;
        });

        add_action('parse_request', array(__CLASS__, 'parse_request'), 1);
        add_action('admin_menu', array(__CLASS__, 'admin_menu_handler'));
        add_action('edit_post', array(__CLASS__, 'spider_edit_post'), 500, 2);
        add_filter('plugin_action_links', array(__CLASS__, 'actionLinks'), 10, 2);
        register_shutdown_function(array(__CLASS__, 'handle'));

        add_filter('redirect_canonical', function ($redirect_url, $requested_url) {
            if (!static::$in_log && $redirect_url) {
                static::$in_log = true;
                static::log(302);
            }
            return $redirect_url;
        }, 10, 2);

        add_action('wp_wb_spider_analyser_cron', array(__CLASS__, 'wp_wb_spider_analyser_cron'));
        if (!wp_next_scheduled('wp_wb_spider_analyser_cron')) {
            wp_schedule_event(strtotime(current_time('Y-m-d H:i:00', 1)), 'hourly', 'wp_wb_spider_analyser_cron');
        }

        register_activation_hook(WP_SPIDER_ANALYSER_BASE_FILE, array(__CLASS__, 'plugin_activate'));
        register_deactivation_hook(WP_SPIDER_ANALYSER_BASE_FILE, array(__CLASS__, 'plugin_deactivate'));

        WP_Spider_Analyser_Admin::init();

        add_action('wp_ajax_spider_analyser', array(__CLASS__, 'spider_analyser_ajax_save'));
        add_action('wp_ajax_spider_analyser', array(__CLASS__, 'spider_analyser_ajax'));

        add_action('admin_enqueue_scripts', array(__CLASS__, 'admin_enqueue_scripts'), 1);
        add_action('admin_notices', array(__CLASS__, 'admin_notices'));
        static::upgrade();
    }

    /**
     * Parse the current request to identify and potentially block spiders based on defined rules.
     * Hooked to 'parse_request'.
     */
    public static function parse_request()
    {
        if (!get_option('wb_spider_analyser_ver', 0)) { // Only run if Pro version seems active (or some verification passed)
            static::$after_request = true;
            return;
        }

        $db = static::db();
        $ip = static::getIp();
        $table_spider_ip = $db->prefix . 'wb_spider_ip';

        $spider = static::spider();
        if (!$spider) {
            static::$after_request = true; // Still mark as after_request for shutdown handler
            return;
        }

        $match = false;

        if ($ip) {
            $ips = explode('.', $ip);
            array_pop($ips);
            $ip3 = implode('.', $ips);
            // Select only necessary columns for matching.
            $sql = "SELECT `status`, `name`, `ip` FROM `{$table_spider_ip}` WHERE (status=4 OR status>10) AND (ip = '' OR ip LIKE %s) AND (name='' OR name = %s) GROUP BY CONCAT_WS('',ip,name) ";
            $list = $db->get_results($db->prepare($sql, $ip3 . '.%', $spider));

            if ($list) {
                foreach ($list as $r) {
                    $rule_matches = false;
                    if (isset($r->name) && $r->name === $spider) {
                        if (empty($r->ip) || $r->ip === $ip3 . '.*' || $r->ip === $ip) {
                            $rule_matches = true;
                        }
                    } elseif (empty($r->name) && isset($r->ip)) {
                         if ($r->ip === $ip3 . '.*' || $r->ip === $ip) {
                            $rule_matches = true;
                        }
                    }
                    if ($rule_matches) {
                        $match = true;
                        break;
                    }
                }
            }
        } else {
            $sql = "SELECT `status`, `name` FROM `{$table_spider_ip}` WHERE (status=4 OR status>10) AND name = %s GROUP BY name";
            $list = $db->get_results($db->prepare($sql, $spider));
            if ($list) {
                $match = true;
            }
        }

        static::$after_request = true;
        if ($match) {
            static::$blocked = true;
            wp_die( esc_html__('Blocked Spider Access!', WB_SPA_DM), esc_html__('IP Blocked', WB_SPA_DM), array('response' => 403) );
        }
    }

    /**
     * Display admin notices, particularly for plugin updates.
     */
    public static function admin_notices()
    {
        global $current_screen;
        if ( ! current_user_can( 'update_plugins' ) ) {
            return;
        }
        if ( ! $current_screen || ! preg_match( '#spider_analyser#', $current_screen->parent_base ) ) {
            return;
        }
        $current = get_site_transient( 'update_plugins' );
        if ( ! $current ) {
            return;
        }
        $plugin_file = plugin_basename( WP_SPIDER_ANALYSER_BASE_FILE );
        if ( ! isset( $current->response[ $plugin_file ] ) ) {
            return;
        }
        $all_plugins = get_plugins();
        if ( ! $all_plugins || ! isset( $all_plugins[ $plugin_file ] ) ) {
            return;
        }
        $plugin_data = $all_plugins[ $plugin_file ];
        $update      = $current->response[ $plugin_file ];

        $update_url = wp_nonce_url( admin_url( 'update.php?action=upgrade-plugin&plugin=' . $plugin_file ), 'upgrade-plugin_' . $plugin_file );

        $pd_name = $plugin_data['Name'];
        echo '<div class="update-message notice inline notice-warning notice-alt"><p>' . sprintf(
            esc_html__( '%1$s有新版本可用。 %2$s 或 %3$s。', WB_SPA_DM ),
            esc_html( $pd_name ),
            '<a href="' . esc_url( $update->url ) . '" target="_blank" aria-label="' . esc_attr( sprintf( _x( '查看 %s 版本 %s 详情', 'Plugin update notice link', WB_SPA_DM ), $pd_name, $update->new_version ) ) . '">' . sprintf( esc_html__( '查看版本 %s 详情', WB_SPA_DM ), esc_html( $update->new_version ) ) . '</a>',
            '<a href="' . esc_url( $update_url ) . '" class="update-link" aria-label="' . esc_attr( sprintf( _x( '现在更新%s', 'Plugin update notice link', WB_SPA_DM ), $pd_name ) ) . '">' . esc_html_x( '现在更新', 'Plugin update notice link text', WB_SPA_DM ) . '</a>'
        ) . '</p></div>';
    }

    /**
     * Enqueue Vue assets for the admin interface.
     * (This method seems to be related to a shared asset loading mechanism, possibly from WBP).
     */
    public static function vue_assets() // Potentially part of WBP integration.
    {
        $assets_file = WP_SPIDER_ANALYSER_PATH . '/plugins_assets.php';
        if ( ! file_exists( $assets_file ) ) {
            return;
        }
        $assets = include $assets_file;

        if ( ! $assets || ! is_array( $assets ) ) {
            return;
        }

        $wp_styles = wp_styles();
        if ( isset( $assets['css'] ) && is_array( $assets['css'] ) ) {
            foreach ( $assets['css'] as $r ) {
                if ( isset( $r['handle'], $r['src'] ) ) {
                    $wp_styles->add( $r['handle'], WP_SPIDER_ANALYSER_URL . $r['src'], isset( $r['dep'] ) ? $r['dep'] : array(), WP_SPIDER_ANALYSER_VERSION, isset( $r['args'] ) ? $r['args'] : null );
                    $wp_styles->enqueue( $r['handle'] );
                }
            }
        }
        if ( isset( $assets['js'] ) && is_array( $assets['js'] ) ) {
            foreach ( $assets['js'] as $r ) {
                if ( isset( $r['handle'] ) ) {
                    if ( empty( $r['src'] ) && ! empty( $r['in_line'] ) ) {
                        wp_register_script( $r['handle'], false, isset( $r['dep'] ) ? $r['dep'] : array(), WP_SPIDER_ANALYSER_VERSION, true );
                        wp_enqueue_script( $r['handle'] );
                        wp_add_inline_script( $r['handle'], $r['in_line'], 'after' );
                    } elseif ( ! empty( $r['src'] ) ) {
                        wp_enqueue_script( $r['handle'], WP_SPIDER_ANALYSER_URL . $r['src'], isset( $r['dep'] ) ? $r['dep'] : array(), WP_SPIDER_ANALYSER_VERSION, true );
                    }
                }
            }
        }
    }

    /**
     * Enqueue scripts and styles for the plugin's admin pages.
     *
     * @param string $hook The current admin page hook.
     */
    public static function admin_enqueue_scripts( $hook ) {
        if ( ! preg_match( '#wp_spider_analyser#', $hook ) ) {
            return;
        }
        add_filter( 'script_loader_tag', array( __CLASS__, 'script_tag_handler' ), 10, 3 );

        wp_register_script( 'wbs-inline-js', false, array(), WP_SPIDER_ANALYSER_VERSION, true );
        wp_enqueue_script( 'wbs-inline-js' );

        $wb_ajax_nonce = wp_create_nonce( 'wp_ajax_wb_spider_analyser' );
        $options       = static::cnf(); // Get plugin settings via WP_Spider_Analyser_Admin::cnf()

        $prompt_items_file = __DIR__ . '/json/prompt.json';
        $prompt_items      = class_exists( 'WBP' ) && method_exists( 'WBP', 'wb_get_json_fields' ) ? WBP::wb_get_json_fields( basename( $prompt_items_file ), dirname( $prompt_items_file ) . '/' ) : array();

        $wb_cnf = array(
            'home_url'         => home_url(),
            'base_url'         => admin_url(),
            'ajax_url'         => admin_url( 'admin-ajax.php' ),
            'dir_url'          => WP_SPIDER_ANALYSER_URL,
            'pd_code'          => 'spider-analyser',
            'pd_title'         => _x( 'Spider Analyser-蜘蛛分析插件', '产品名', WB_SPA_DM ),
            'pd_version'       => WP_SPIDER_ANALYSER_VERSION,
            'is_pro'           => (bool) get_option( 'wb_spider_analyser_ver', 0 ),
            'action'           => array(
                'act'   => 'spider_analyser', // Main AJAX action
                'fetch' => 'get_setting',   // Sub-action to fetch settings
                'push'  => 'set_setting',   // Sub-action to save settings (likely handled by spider_analyser_ajax_save)
            ),
            'wbp_security'     => $wb_ajax_nonce,
            'wb_spider_auto'   => ! empty( $options['auto_deny'] ) && '1' === $options['auto_deny'] ? '1' : '0',
            'locale'           => get_locale(),
            'actpanel_visible' => in_array( get_locale(), array( 'zh_CN', 'zh_TW' ), true ),
            'prompt'           => $prompt_items, // Data from prompt.json
        );

        wp_localize_script( 'wbs-inline-js', 'wbp_js_cnf', $wb_cnf );
        // WB_Vite handles actual script output for Vue app
        echo WB_Vite::vite( 'src/main.js', WP_SPIDER_ANALYSER_PATH . '/assets/wbp/', WP_SPIDER_ANALYSER_URL . '/assets/wbp/' );
    }

    /**
     * Modify script tag for specific handles to add type="module".
     *
     * @param string $tag    The <script> tag for the enqueued script.
     * @param string $handle The script's registered handle.
     * @param string $src    The script's source URL.
     * @return string Modified <script> tag.
     */
    public static function script_tag_handler( $tag, $handle, $src ) {
        if ( preg_match( "/wbs-/i", $handle ) ) { // Assumes 'wbs-' prefixed handles are modules
            return '<script type="module" src="' . esc_url( $src ) . '" defer></script>' . "\n";
        }
        return $tag;
    }

    /**
     * Determine the type of a given URL (e.g., index, post, category).
     * Uses custom rules and falls back to WordPress query parsing.
     *
     * @param string $url The URL to analyze.
     * @param WP_Post|null $query Passed by reference, will contain the post object if the URL is a singular post/page.
     * @return string|null The determined URL type, or null if not determined.
     */
    public static function match_type($url, &$query = null)
    {
        global $wp_filter;
        $cnf = static::cnf(); // Get plugin settings

        $type = null;
        $old_page = null;

        $request_uri_original = isset($_SERVER['REQUEST_URI']) ? sanitize_text_field(wp_unslash($_SERVER['REQUEST_URI'])) : '';
        $php_self_original = isset($_SERVER['PHP_SELF']) ? sanitize_text_field(wp_unslash($_SERVER['PHP_SELF'])) : '';
        $reset_url = false;

        do { // Using do-while(0) for easy break
            // Check custom rules from settings
            if (isset($cnf['extral_rule']) && is_array($cnf['extral_rule'])) {
                foreach ($cnf['extral_rule'] as $r_type => $rule) {
                    if (!$rule) continue;
                    $rule_regex = str_replace(array(',', '\\*'), array('|', '.+?'), preg_quote($rule, '#'));
                    if (preg_match('#(' . $rule_regex . ')#i', $url)) {
                        $type = $r_type;
                        break;
                    }
                }
            }
            if ($type) break;

            if (isset($cnf['user_rule']) && is_array($cnf['user_rule'])) {
                foreach ($cnf['user_rule'] as $r) {
                    if (empty($r['rule']) || empty($r['name'])) continue;
                    $rule_regex = str_replace(array(',', '\\*'), array('|', '.+?'), preg_quote($r['rule'], '#'));
                    if (preg_match('#' . $rule_regex . '#i', $url)) {
                        $type = $r['name'];
                        break;
                    }
                }
            }
            if ($type) break;

            // Standard WordPress URL type checks
            if (preg_match('#/wp-admin/admin-ajax\.php#', $url)) { $type = 'api'; break; }
            if (preg_match('#^/sitemap(-[a-z0-9_-]+)?\.xml#i', $url)) { $type = 'sitemap'; break; }

            $parse = wp_parse_url($url);
            if (isset($parse['query']) && $parse['query']) {
                parse_str($parse['query'], $param);
                if (isset($param['s'])) { $type = 'search'; break; }
            }
            if (empty($parse['path']) || $parse['path'] == '/') { $type = 'index'; break; }

            // Simulate WordPress environment to parse the URL
            $wp = new WP();
            $_SERVER['REQUEST_URI'] = $url;
            $_SERVER['PHP_SELF'] = '/index.php';
            $old_page = isset($_GET['page']) ? sanitize_text_field($_GET['page']) : null;
            if ($old_page !== null) unset($_GET['page']);
            $reset_url = true;

            $wp->query_vars = static::url_help($url);
            $wp->build_query_string();

            $old_wp_filter_parse_query = isset($wp_filter['parse_query']) ? $wp_filter['parse_query'] : null;
            remove_all_filters('parse_query'); // Temporarily remove filters that might interfere

            $wp_query = new WP_Query();
            $wp_query->parse_query($wp->query_vars);

            if ($old_wp_filter_parse_query) $wp_filter['parse_query'] = $old_wp_filter_parse_query; // Restore filters

            if ($wp_query->is_author()) { $type = 'author'; break; }
            if ($wp_query->is_tag()) { $type = 'tag'; break; }
            if ($wp_query->is_feed()) { $type = 'feed'; break; }
            if ($wp_query->is_archive()) { $type = 'category'; break; }

            if ($wp_query->is_singular()) {
                $posts_array = $wp_query->get_posts();
                if ($posts_array && $posts_array[0] instanceof WP_Post) {
                    $query = $posts_array[0];
                    if ($posts_array[0]->post_type == 'page') { $type = 'page'; break; }
                }
                $type = 'post';
                break;
            }
            $type = 'other'; // Default fallback
        } while (0);

        if ($reset_url) { // Restore original server/get variables
            if ($old_page !== null) $_GET['page'] = $old_page;
            $_SERVER['PHP_SELF'] = $php_self_original;
            $_SERVER['REQUEST_URI'] = $request_uri_original;
        }
        return $type;
    }

    /**
     * Helper function to parse a URL and determine query variables,
     * simulating part of WordPress's URL parsing mechanism.
     *
     * @global WP_Rewrite $wp_rewrite WordPress Rewrite object.
     * @global WP $wp WordPress environment object.
     * @param string $req_url The request URL to parse.
     * @return array Array of query variables.
     */
    public static function url_help($req_url)
    {
        global $wp_rewrite, $wp;
        $private_query_vars = $wp->private_query_vars;
        $public_query_vars = $wp->public_query_vars;
        $query_vars     = array();
        $post_type_query_vars = array();
        $extra_query_vars = array();

        if ($req_url) parse_str($req_url, $extra_query_vars);

        $rewrite = $wp_rewrite->wp_rewrite_rules();

        if (!empty($rewrite)) {
            $error               = '404';
            $pathinfo         = isset($_SERVER['PATH_INFO']) ? sanitize_text_field(wp_unslash($_SERVER['PATH_INFO'])) : '';
            list($pathinfo) = explode('?', $pathinfo);
            $pathinfo         = str_replace('%', '%25', $pathinfo);
            list($req_uri) = explode('?', sanitize_text_field(wp_unslash($_SERVER['REQUEST_URI'])));
            $self            = sanitize_text_field(wp_unslash($_SERVER['PHP_SELF']));
            $home_path       = trim(wp_parse_url(home_url(), PHP_URL_PATH), '/');
            $home_path_regex = sprintf('|^%s|i', preg_quote($home_path, '|'));

            $req_uri  = str_replace($pathinfo, '', $req_uri);
            $req_uri  = trim($req_uri, '/');
            $req_uri  = preg_replace($home_path_regex, '', $req_uri);
            $req_uri  = trim($req_uri, '/');
            $pathinfo = trim($pathinfo, '/');
            $pathinfo = preg_replace($home_path_regex, '', $pathinfo);
            $pathinfo = trim($pathinfo, '/');
            $self     = trim($self, '/');
            $self     = preg_replace($home_path_regex, '', $self);
            $self     = trim($self, '/');

            if (!empty($pathinfo) && !preg_match('|^.*' . preg_quote($wp_rewrite->index, '|') . '$|', $pathinfo)) {
                $requested_path = $pathinfo;
            } else {
                if ($req_uri == $wp_rewrite->index) $req_uri = '';
                $requested_path = $req_uri;
            }
            $requested_file = $req_uri;
            $request_match = $requested_path;

            if (empty($request_match)) {
                if (isset($rewrite['$'])) {
                    $matched_rule = '$';
                    $query_string_from_rule = $rewrite['$'];
                    $matches            = array('');
                }
            } else {
                foreach ((array) $rewrite as $match_pattern => $query_string_from_rule) {
                    if (!empty($requested_file) && strpos($match_pattern, $requested_file) === 0 && $requested_file != $requested_path) {
                        $request_match = $requested_file . '/' . $requested_path;
                    }
                    if ( preg_match("#^$match_pattern#", $request_match, $matches) || preg_match("#^$match_pattern#", urldecode($request_match), $matches) ) {
                        if ($wp_rewrite->use_verbose_page_rules && preg_match('/pagename=\$matches\[([0-9]+)\]/', $query_string_from_rule, $varmatch)) {
                            $page = get_page_by_path($matches[$varmatch[1]]);
                            if (!$page) { continue; }
                            $post_status_obj = get_post_status_object($page->post_status);
                            if ( !$post_status_obj || (!$post_status_obj->public && !$post_status_obj->protected && !$post_status_obj->private && $post_status_obj->exclude_from_search) ) {
                                continue;
                            }
                        }
                        $matched_rule = $match_pattern;
                        break;
                    }
                }
            }
            if (isset($matched_rule)) {
                $query_string_from_rule = preg_replace('!^.+\?!', '', $query_string_from_rule);
                $query_string_from_rule = addslashes(WP_MatchesMapRegex::apply($query_string_from_rule, $matches));
                parse_str($query_string_from_rule, $perma_query_vars);
                if ('404' == $error) unset($error, $_GET['error']);
            }
            if (empty($requested_path) || $requested_file == $self || strpos(sanitize_text_field(wp_unslash($_SERVER['PHP_SELF'])), 'wp-admin/') !== false) {
                unset($error, $_GET['error']);
                if (isset($perma_query_vars) && strpos(sanitize_text_field(wp_unslash($_SERVER['PHP_SELF'])), 'wp-admin/') !== false) unset($perma_query_vars);
            }
        }

        $public_query_vars = apply_filters('query_vars', $public_query_vars);
        foreach (get_post_types(array(), 'objects') as $post_type => $t) {
            if (is_post_type_viewable($t) && $t->query_var) $post_type_query_vars[$t->query_var] = $post_type;
        }

        foreach ($public_query_vars as $wpvar) {
            if (isset($extra_query_vars[$wpvar])) { $query_vars[$wpvar] = $extra_query_vars[$wpvar];
            } elseif (isset($_POST[$wpvar])) { $query_vars[$wpvar] = $_POST[$wpvar]; // POST overrides GET for public vars
            } elseif (isset($_GET[$wpvar])) { $query_vars[$wpvar] = $_GET[$wpvar];
            } elseif (isset($perma_query_vars[$wpvar])) { $query_vars[$wpvar] = $perma_query_vars[$wpvar];}

            if (!empty($query_vars[$wpvar])) {
                if (!is_array($query_vars[$wpvar])) { $query_vars[$wpvar] = (string) $query_vars[$wpvar];
                } else { foreach ($query_vars[$wpvar] as $vkey => $v) { if (is_scalar($v)) $query_vars[$wpvar][$vkey] = (string) $v;}}
                if (isset($post_type_query_vars[$wpvar])) { $query_vars['post_type'] = $post_type_query_vars[$wpvar]; $query_vars['name'] = $query_vars[$wpvar];}
            }
        }

        foreach (get_taxonomies(array(), 'objects') as $taxonomy => $t) {
            if ($t->query_var && isset($query_vars[$t->query_var])) $query_vars[$t->query_var] = str_replace(' ', '+', $query_vars[$t->query_var]);
        }
        if (!is_admin()) {
            foreach (get_taxonomies(array('publicly_queryable' => false), 'objects') as $taxonomy => $t) {
                if (isset($query_vars['taxonomy']) && $taxonomy === $query_vars['taxonomy']) unset($query_vars['taxonomy'], $query_vars['term']);
            }
        }
        if (isset($query_vars['post_type'])) {
            $queryable_post_types = get_post_types(array('publicly_queryable' => true));
            if (!is_array($query_vars['post_type'])) {
                if (!in_array($query_vars['post_type'], $queryable_post_types, true)) unset($query_vars['post_type']);
            } else { $query_vars['post_type'] = array_intersect($query_vars['post_type'], $queryable_post_types);}
        }
        $query_vars = wp_resolve_numeric_slug_conflicts($query_vars);
        foreach ((array) $private_query_vars as $var) { if (isset($extra_query_vars[$var])) $query_vars[$var] = $extra_query_vars[$var];}
        if (isset($error)) $query_vars['error'] = $error;

        return $query_vars;
    }

    /**
     * Generate chart data for spider visits.
     *
     * @param int    $day Number of days to fetch data for.
     * @param int    $type Type of data to return (1: distinct spider count, 2: total visits, 3: visits per spider).
     * @param int    $compare Comparison flag (currently seems to shift time window).
     * @param string|null $spider Specific spider name to filter by.
     * @return array Array containing x-axis data (dates/times) and y-axis data (counts).
     */
    public static function chart_data( $day, $type, $compare = 0, $spider = null ) {
        $db = static::db();
        $current_timestamp = current_time( 'timestamp', true );
        $time = $current_timestamp - ( DAY_IN_SECONDS * $day );

        if ( $compare ) { // If comparing, shift the time window back further
            $time -= DAY_IN_SECONDS * ( $day > 0 ? $day : 1 ); // Shift by another 'day' period
        }

        $ymd_base = gmdate( 'Y-m-d', $time );
        $table_log = $db->prefix . 'wb_spider_log';
        $xdata     = array();
        $date_condition_sql = '';
        $date_format_sql    = '';

        if ( $day > 2 ) { // Multi-day view (e.g., last 7 days, last 30 days)
            $date_format_sql = '%m/%d';
            for ( $i = 0; $i < $day; $i++ ) {
                $xdata[] = gmdate( 'm/d', strtotime( $ymd_base . " +{$i} days" ) );
            }
            $ymd_start = $ymd_base . ' 00:00:00';
            $ymd_end   = gmdate( 'Y-m-d 23:59:59', strtotime( $ymd_base . " +" . ( $day - 1 ) . " days" ) );
            $date_condition_sql = $db->prepare( "visit_date >= %s AND visit_date <= %s", $ymd_start, $ymd_end );

        } else { // Single day view (today or yesterday, $day = 0 or 1) or 2-day view
            $date_format_sql = '%H:00-%H:59';
            for ( $i = 0; $i < 24; $i++ ) {
                $xdata[] = $i < 10 ? ( '0' . $i . ':00-0' . $i . ':59' ) : ( '' . $i . ':00-' . $i . ':59' );
            }
            $ymd_start   = $ymd_base . ' 00:00:00';
            $ymd_day_end = $ymd_base . ' 23:59:59';
            $date_condition_sql = $db->prepare( "visit_date >= %s AND visit_date <= %s", $ymd_start, $ymd_day_end );
        }

        $select_fields_subquery = "`visit_date`, `spider`";

        $where_spider_sql = '';
        if ( $spider ) {
            $where_spider_sql = $db->prepare( " AND `spider` = %s", $spider );
        }

        $sql = $db->prepare(
            "SELECT COUNT(1) AS num, COUNT(DISTINCT `spider`) AS distinct_spider_count, DATE_FORMAT(`visit_date`, %s) AS ymd
             FROM (
                 SELECT {$select_fields_subquery}
                 FROM `{$table_log}`
                 WHERE {$date_condition_sql} {$where_spider_sql}
             ) AS a
             GROUP BY ymd
             ORDER BY ymd",
            $date_format_sql
        );

        $list = $db->get_results( $sql );
        $tmp = array();
        if ( $list ) {
            foreach ( $list as $r ) {
                if ( 2 === $type ) {
                    $tmp[ $r->ymd ] = (int) $r->num;
                } elseif ( 3 === $type ) {
                    $tmp[ $r->ymd ] = $r->distinct_spider_count > 0 ? ceil( (int)$r->num / (int)$r->distinct_spider_count ) : 0;
                } else {
                    $tmp[ $r->ymd ] = (int) $r->distinct_spider_count;
                }
            }
        }

        $ydata = array();
        foreach ( $xdata as $v_key ) {
            $ydata[] = isset( $tmp[ $v_key ] ) ? $tmp[ $v_key ] : 0;
        }
        return array( $xdata, $ydata );
    }

    /**
     * Get the thumbnail URL for a given spider name.
     * Uses a static cache for bot_info within a single request.
     *
     * @param string $spider_name The name of the spider.
     * @return string The URL of the spider's thumbnail, or a default placeholder.
     */
    protected static function get_spider_thumbnail_url(string $spider_name): string {
        static $bot_info_cache = null;
        if (null === $bot_info_cache) {
            $bot_info_cache = static::read_spider_info();
        }

        $spider_key = strtolower($spider_name);
        if ($bot_info_cache && isset($bot_info_cache[$spider_key]['thumb']) && !empty($bot_info_cache[$spider_key]['thumb'])) {
            return esc_url($bot_info_cache[$spider_key]['thumb']);
        }
        return 'https://static.wbolt.com/wp-content/uploads/2025/02/unknown-bot.svg';
    }

    /**
     * Handle AJAX requests for saving/updating plugin settings and data.
     * Hooked to 'wp_ajax_spider_analyser'. This method name is a bit generic,
     * as 'spider_analyser_ajax' also exists. This one seems to handle more settings-related operations.
     */
    public static function spider_analyser_ajax_save() {
        $op_raw = static::param('op');
        if (!$op_raw) $op_raw = static::param('op', '', 'g');
        if (!$op_raw) wp_die();

        $op = sanitize_key($op_raw);

        $arrow = [
            'list', 'stop', 'clean_log', 'clean_all', 'verify', 'reset',
            'options', 'update_setting', 'get_localize', 'get_comparison'
        ];
        if ( ! in_array( $op, $arrow, true ) ) wp_die();

        if ( ! current_user_can( 'manage_options' ) ) {
            static::ajax_resp( array( 'code' => 1, 'desc' => esc_html__('Permission Denied.', WB_SPA_DM) ) );
        }

        if (!check_ajax_referer('wp_ajax_wb_spider_analyser', '_ajax_nonce', false)) {
            static::ajax_resp(array('code' => 1, 'desc' => esc_html__('Nonce verification failed.', WB_SPA_DM)));
        }

        switch ( $op ) {
            case 'list':
                $ret = array( 'code' => 0, 'desc' => esc_html__('Success', WB_SPA_DM) ); // Default success
                do {
                    $skip_param = static::param( 'skip' );
                    if ( $skip_param ) {
                        if ( is_array( $skip_param ) ) {
                            $skips = static::array_sanitize_text_field( $skip_param );
                            foreach ( $skips as $single_skip ) {
                                static::skip_spider( $single_skip );
                                static::delete_log( array( 'spider' => $single_skip ) );
                            }
                        } else {
                            $spider_to_skip = trim( sanitize_text_field( $skip_param ) );
                            static::skip_spider( $spider_to_skip );
                            static::delete_log( array( 'spider' => $spider_to_skip ) );
                        }
                        static::ajax_resp($ret);
                        return;
                    }

                    $db = static::db();
                    $q_raw  = static::param( 'q' );
                    $q = $q_raw;
                    if ( $q && is_array( $q ) ) {
                        $q_sanitized = [];
                        $q_sanitized['day'] = isset($q['day']) ? intval($q['day']) : -1;
                        if(isset($q['code'])) $q_sanitized['code'] = sanitize_text_field($q['code']);
                        if(isset($q['bot_type'])) $q_sanitized['bot_type'] = sanitize_text_field($q['bot_type']);
                        if(isset($q['spider'])) $q_sanitized['spider'] = sanitize_text_field($q['spider']);
                        if(isset($q['name'])) $q_sanitized['name'] = sanitize_text_field($q['name']);
                        $q = $q_sanitized;
                    } else {
                        $q = ['day' => -1];
                    }

                    $day = $q['day'];
                    $table_spider_log = $db->prefix . 'wb_spider_log';
                    $table_spider     = $db->prefix . 'wb_spider';
                    $where_conditions = array();
                    $total_where_conditions = array();

                    if ( $day > -1 ) {
                        $time_timestamp = current_time( 'timestamp', true ) - ( DAY_IN_SECONDS * $day );
                        $ymd_param_start = gmdate( 'Y-m-d 00:00:00', $time_timestamp );
                        if ( $day >= 1 ) {
                             $current_day_end_gmt = gmdate( 'Y-m-d 23:59:59', current_time('timestamp', true) );
                             $where_conditions[] = $db->prepare( "a.visit_date >= %s AND a.visit_date <= %s", $ymd_param_start, $current_day_end_gmt );
                             $total_where_conditions[] = $db->prepare( "visit_date >= %s AND visit_date <= %s", $ymd_param_start, $current_day_end_gmt );
                        } else {
                             $ymd_param_end   = gmdate( 'Y-m-d 23:59:59', $time_timestamp );
                             $where_conditions[] = $db->prepare( "a.visit_date >= %s AND a.visit_date <= %s", $ymd_param_start, $ymd_param_end );
                             $total_where_conditions[] = $db->prepare( "visit_date >= %s AND visit_date <= %s", $ymd_param_start, $ymd_param_end );
                        }
                    }

                    if ( ! empty( $q['code'] ) ) $where_conditions[] = $db->prepare( "a.code = %s", $q['code'] );
                    if ( ! empty( $q['bot_type'] ) ) $where_conditions[] = $db->prepare( "b.bot_type = %s", $q['bot_type'] );
                    if ( ! empty( $q['spider'] ) ) $where_conditions[] = $db->prepare( "a.spider = %s", $q['spider'] );
                    if ( ! empty( $q['name'] ) ) $where_conditions[] = $db->prepare( "a.spider REGEXP %s", $q['name'] );

                    $num = absint( static::param( 'num', 30 ) );
                    if ( ! $num ) $num = 30;
                    $page = absint( static::param( 'page', 1 ) );
                    if ( ! $page ) $page = 1;
                    $offset = max( 0, ( $page - 1 ) * $num );

                    $where_sql = '1=1';
                    if ( ! empty( $where_conditions ) ) $where_sql = implode( ' AND ', $where_conditions );
                    $total_where_sql = '1=1';
                    if ( ! empty( $total_where_conditions ) ) $total_where_sql = implode( ' AND ', $total_where_conditions );

                    $allowed_sort_columns_list = ['num', 'last_visit', 'spider', 'bot_type'];
                    $order_by_column = 'num';
                    $sort_param = sanitize_key( static::param( 'sort' ) );

                    if ( 'type' === $sort_param ) $order_by_column = 'b.bot_type';
                    elseif (in_array($sort_param, $allowed_sort_columns_list, true)) {
                        $order_by_column = ($sort_param === 'spider') ? 'a.spider' : $sort_param;
                        if ($sort_param === 'last_visit') $order_by_column = 'last_visit';
                    }

                    $sort_order_param = sanitize_key( static::param( 'order' ) );
                    $order_direction = ( 'asc' === strtolower( $sort_order_param ) ) ? 'ASC' : 'DESC';
                    $order_by_prefix = in_array($order_by_column, ['num', 'last_visit']) ? '' : (strpos($order_by_column, '.') === false ? 'a.' : '');
                    if ($order_by_column === 'b.bot_type') $order_by_prefix = '';

                    $order_by_sql = ($order_by_column === 'num' || $order_by_column === 'last_visit')
                                    ? "{$order_by_column} {$order_direction}"
                                    : "{$order_by_prefix}" . esc_sql($order_by_column) . " {$order_direction}";

                    $cache_param = array( 'list', $where_sql, $order_by_sql, $total_where_sql, $offset, $num, $q_raw );
                    $cache_file  = static::cache( $cache_param );
                    if ( $cache_file ) { include $cache_file; wp_die(); }

                    $total_query = "SELECT COUNT(1) FROM `{$table_spider_log}` WHERE {$total_where_sql}";
                    $total = $db->get_var( $total_query );

                    $sql = "SELECT SQL_CALC_FOUND_ROWS a.spider, COUNT(1) AS num, MAX(a.visit_date) AS last_visit, b.bot_type, b.bot_url, b.status AS udg
                            FROM `{$table_spider_log}` a
                            LEFT JOIN `{$table_spider}` b ON a.spider = b.name
                            WHERE {$where_sql}
                            GROUP BY a.spider
                            ORDER BY {$order_by_sql}
                            LIMIT %d, %d";
                    $list = $db->get_results( $db->prepare( $sql, $offset, $num ) );
                    $row_total = $db->get_var( "SELECT FOUND_ROWS()" );

                    if ( $list ) {
                        foreach ( $list as $r ) {
                            $r->thumb = static::get_spider_thumbnail_url( $r->spider );
                            $r->rate = $total > 0 ? round( ( (int)$r->num / (int)$total ) * 100, 2 ) : 0;
                        }
                    }
                    $ret = array(
                        'num'   => $num,
                        'total' => (int)$row_total,
                        'code'  => 0,
                        'data'  => $list ?: [],
                    );
                    static::cache( $cache_param, $ret, 3600 );
                } while ( 0 );
                static::ajax_resp( $ret );
                break;

            case 'stop':
                $ret = array( 'code' => 0, 'desc' => esc_html__('Success', WB_SPA_DM), 'data' => array(), 'total' => 0 );
                do {
                    if ( ! get_option( 'wb_spider_analyser_ver', 0 ) ) {
                        $ret['desc'] = esc_html__('This is a Pro version feature.', WB_SPA_DM);
                        $ret['code'] = 1;
                        break;
                    }

                    $db = static::db();
                    $table_spider_ip = $db->prefix . 'wb_spider_ip';
                    $action_taken = false; // Flag to check if any sub-action was performed

                    $add_params_raw = static::param( 'add', null );
                    if ( $add_params_raw && is_array( $add_params_raw ) ) {
                        $action_taken = true;
                        $name = isset($add_params_raw[0]) ? sanitize_text_field($add_params_raw[0]) : '';
                        $ip_input = isset($add_params_raw[1]) ? $add_params_raw[1] : '';
                        $cid_param_raw = static::param( 'cid' );
                        $cid = 4;
                        if ( $cid_param_raw && in_array( intval( $cid_param_raw ), array( 11, 12, 13, 14, 15, 16, 17 ), true ) ) {
                            $cid = intval( $cid_param_raw );
                        }
                        $db->suppress_errors();
                        if ( is_array( $ip_input ) ) {
                            $ip_addresses = array_map('sanitize_text_field', $ip_input);
                            foreach ( $ip_addresses as $single_ip ) {
                                if (empty($name) && empty($single_ip)) continue;
                                $insert_sql = $db->prepare( "INSERT INTO `{$table_spider_ip}` (`name`, `ip`, `status`) VALUES (%s, %s, %d)", $name, $single_ip, $cid );
                                if ( ! $db->query( $insert_sql ) ) {
                                    $db->query( $db->prepare( "UPDATE `{$table_spider_ip}` SET status = %d WHERE name = %s AND ip = %s", $cid, $name, $single_ip ) );
                                }
                                static::delete_log( array( 'spider' => $name, 'ip' => $single_ip ) );
                            }
                        } elseif ( is_string($ip_input) ) {
                            $ip_address = sanitize_text_field($ip_input);
                            if ( $ip_address || $name ) {
                                $insert_sql = $db->prepare( "INSERT INTO `{$table_spider_ip}` (`name`, `ip`, `status`) VALUES (%s, %s, %d)", $name, $ip_address, $cid );
                                if ( ! $db->query( $insert_sql ) ) {
                                     $db->query( $db->prepare( "UPDATE `{$table_spider_ip}` SET status = %d WHERE name = %s AND ip = %s", $cid, $name, $ip_address ) );
                                }
                                static::delete_log( array( 'spider' => $name, 'ip' => $ip_address ) );
                            }
                        }
                        $db->suppress_errors( false );
                        static::clear_cache();
                    }

                    $removes_params_raw = static::param( 'removes', null );
                    if ( !$action_taken && $removes_params_raw && is_array( $removes_params_raw ) ) {
                        $action_taken = true;
                        foreach ($removes_params_raw as $r_item_raw) {
                            if (is_array($r_item_raw) && count($r_item_raw) >= 2) {
                                $r_name = sanitize_text_field($r_item_raw[0]);
                                $r_ip = sanitize_text_field($r_item_raw[1]);
                                $db->query( $db->prepare( "DELETE FROM `{$table_spider_ip}` WHERE status = 15 AND name = %s AND ip = %s", $r_name, $r_ip ) );
                                $db->query( $db->prepare( "UPDATE `{$table_spider_ip}` SET status = 1 WHERE name = %s AND ip = %s", $r_name, $r_ip ) );
                            }
                        }
                        $db->query( "DELETE FROM `{$table_spider_ip}` WHERE ip = '' AND status = 1" );
                        $db->query( "DELETE FROM `{$table_spider_ip}` WHERE ip LIKE '%.*' AND status = 1" );
                        static::clear_cache();
                    }

                    $remove_params_raw = static::param( 'remove', null );
                    if ( !$action_taken && $remove_params_raw && is_array( $remove_params_raw ) ) {
                        $action_taken = true;
                         if (count($remove_params_raw) >= 2) {
                            $name_to_remove = sanitize_text_field($remove_params_raw[0]);
                            $ip_to_remove = sanitize_text_field($remove_params_raw[1]);
                            if ( $name_to_remove || $ip_to_remove ) {
                                $db->query( $db->prepare( "DELETE FROM `{$table_spider_ip}` WHERE status = 15 AND name = %s AND ip = %s", $name_to_remove, $ip_to_remove ) );
                                $db->query( $db->prepare( "UPDATE `{$table_spider_ip}` SET status = 1 WHERE name = %s AND ip = %s", $name_to_remove, $ip_to_remove ) );
                            }
                        }
                        $db->query( "DELETE FROM `{$table_spider_ip}` WHERE ip = '' AND status = 1" );
                        $db->query( "DELETE FROM `{$table_spider_ip}` WHERE ip LIKE '%.*' AND status = 1" );
                        static::clear_cache();
                    }

                    $new_params_raw = static::param( 'new', null );
                    if ( !$action_taken && $new_params_raw && is_array( $new_params_raw ) ) {
                        $action_taken = true;
                        $name_new = isset($new_params_raw[0]) ? sanitize_text_field($new_params_raw[0]) : '';
                        $ip_input_new = isset($new_params_raw[1]) ? $new_params_raw[1] : '';
                        $cid_param_new_raw = static::param( 'cid', 0 );
                        $cid_new = intval( $cid_param_new_raw );
                        if ( ! $cid_new || ! in_array( $cid_new, array( 11, 12, 13, 14, 15, 16, 17 ), true ) ) $cid_new = 4;

                        $db->suppress_errors();
                        if ( is_array( $ip_input_new ) ) {
                             $ip_addresses_new = array_map('sanitize_text_field', $ip_input_new);
                            foreach ( $ip_addresses_new as $single_ip_new ) {
                                if (empty($name_new) && empty($single_ip_new)) continue;
                                $insert_sql = $db->prepare( "INSERT INTO `{$table_spider_ip}` (`name`, `ip`, `status`) VALUES (%s, %s, %d)", $name_new, $single_ip_new, $cid_new );
                                if ( ! $db->query( $insert_sql ) ) {
                                    $db->query( $db->prepare( "UPDATE `{$table_spider_ip}` SET status = %d WHERE name = %s AND ip = %s", $cid_new, $name_new, $single_ip_new ) );
                                }
                                static::delete_log( array( 'spider' => $name_new, 'ip' => $single_ip_new ) );
                            }
                        } elseif ( is_string($ip_input_new) ) {
                            $ip_address_new = sanitize_text_field($ip_input_new);
                             if ( $ip_address_new || $name_new ) {
                                 $insert_sql = $db->prepare( "INSERT INTO `{$table_spider_ip}` (`name`, `ip`, `status`) VALUES (%s, %s, %d)", $name_new, $ip_address_new, $cid_new );
                                if ( ! $db->query( $insert_sql ) ) {
                                    $db->query( $db->prepare( "UPDATE `{$table_spider_ip}` SET status = %d WHERE name = %s AND ip = %s", $cid_new, $name_new, $ip_address_new ) );
                                }
                                static::delete_log( array( 'spider' => $name_new, 'ip' => $ip_address_new ) );
                            }
                        } elseif ( is_array( $name_new ) ) {
                             $names_new = array_map('sanitize_text_field', $name_new);
                             $ip_address_single_new = sanitize_text_field($ip_input_new);
                            foreach ( $names_new as $single_name_new ) {
                                if (empty($single_name_new) && empty($ip_address_single_new)) continue;
                                $insert_sql = $db->prepare( "INSERT INTO `{$table_spider_ip}` (`name`, `ip`, `status`) VALUES (%s, %s, %d)", $single_name_new, $ip_address_single_new, $cid_new );
                                if ( ! $db->query( $insert_sql ) ) {
                                    $db->query( $db->prepare( "UPDATE `{$table_spider_ip}` SET status = %d WHERE name = %s AND ip = %s", $cid_new, $single_name_new, $ip_address_single_new ) );
                                }
                                static::delete_log( array( 'spider' => $single_name_new, 'ip' => $ip_address_single_new ) );
                            }
                        }
                        $db->suppress_errors( false );
                        static::clear_cache();
                    }

                    if ($action_taken) { // If any modification action was taken, send response and exit.
                        static::ajax_resp($ret);
                        return;
                    }

                    // If no modification action, proceed to list rules.
                    $where_conditions_stop = array();
                    $query_status = intval( static::param( 'status', 0 ) );

                    if ( $query_status ) {
                        if ( 4 === $query_status ) $where_conditions_stop[] = "(status = 4 OR status > 10)";
                        else $where_conditions_stop[] = $db->prepare( "status = %d", $query_status );
                    } else {
                        $where_conditions_stop[] = "(status = 4 OR status > 10)";
                    }

                    $type_filter = intval( static::param( 'type', 0 ) );
                    if ( $type_filter ) {
                        if ( 5 === $type_filter ) $where_conditions_stop[] = $db->prepare( "status = %d", 15 );
                        elseif ( 1 === $type_filter ) $where_conditions_stop[] = "(`name` <> '' AND (`ip` = '' OR `ip` IS NULL))";
                        elseif ( 2 === $type_filter ) $where_conditions_stop[] = "(`ip` <> '' AND `ip` NOT LIKE '%.*' AND (`name` = '' OR `name` IS NULL))";
                        elseif ( 3 === $type_filter ) $where_conditions_stop[] = "(`ip` LIKE '%.*' AND (`name` = '' OR `name` IS NULL))";
                        elseif ( 4 === $type_filter ) $where_conditions_stop[] = "(`ip` <> '' AND `name` <> '')";
                    }

                    $path_filter = intval( static::param( 'path', 0 ) );
                    if ( $path_filter && in_array($path_filter, [11,12,13,14,15,16,17])) {
                        $where_conditions_stop[] = $db->prepare( "status = %d", $path_filter );
                    }

                    $keyword_filter = sanitize_text_field( static::param( 'kw' ) );
                    if ( $keyword_filter ) {
                        $where_conditions_stop[] = $db->prepare( "(`name` LIKE %s OR `ip` LIKE %s)", '%' . $db->esc_like( $keyword_filter ) . '%', '%' . $db->esc_like( $keyword_filter ) . '%' );
                    }

                    $num_per_page = absint( static::param( 'num', 30 ) );
                    if ( ! $num_per_page ) $num_per_page = 30;
                    $current_page = absint( static::param( 'page', 1 ) );
                    if ( ! $current_page ) $current_page = 1;
                    $offset_val = max( 0, ( $current_page - 1 ) * $num_per_page );

                    $where_sql_stop = '1=1';
                    if ($where_conditions_stop) $where_sql_stop = implode( ' AND ', $where_conditions_stop );

                    $cache_param_list = array( 'stop_list', $where_sql_stop, $offset_val, $num_per_page );
                    $cache_file_list  = static::cache( $cache_param_list );
                    if ( $cache_file_list ) { include $cache_file_list; wp_die(); }

                    $sql_query = $db->prepare( "SELECT SQL_CALC_FOUND_ROWS `id`, `name`, `ip`, `status`, `add_time` FROM `{$table_spider_ip}` WHERE {$where_sql_stop} ORDER BY `add_time` DESC LIMIT %d, %d", $offset_val, $num_per_page );
                    $list_items = $db->get_results( $sql_query );
                    $total_items = $db->get_var( "SELECT FOUND_ROWS()" );

                    $ret = array(
                        'num'   => $num_per_page,
                        'total' => (int)$total_items,
                        'code'  => 0,
                        'data'  => $list_items ?: [],
                    );
                    static::cache( $cache_param_list, $ret, 3600 );

                } while ( 0 );
                static::ajax_resp( $ret );
                break;

            case 'clean_log':
                $db = static::db();
                $ret = array( 'code' => 0, 'desc' => esc_html__('All logs and rules cleared.', WB_SPA_DM) );
                $tables_to_truncate = array( 'wb_spider_sum', 'wb_spider_visit', 'wb_spider_log', 'wb_spider_post', 'wb_spider_post_link', 'wb_spider_ip' );
                foreach ( $tables_to_truncate as $table_name_suffix ) {
                    $table_name = $db->prefix . $table_name_suffix;
                    $db->query( "TRUNCATE TABLE `{$table_name}`" );
                }
                static::clear_cache();
                static::ajax_resp( $ret );
                break;

            case 'clean_all':
                $ret = array( 'code' => 0, 'desc' => esc_html__('All custom block/allow rules have been reset.', WB_SPA_DM) );
                $db = static::db();
                $table_spider_ip = $db->prefix . 'wb_spider_ip';
                $db->query( "DELETE FROM `{$table_spider_ip}` WHERE (status = 4 OR status > 10)" );
                static::clear_cache();
                static::ajax_resp( $ret );
                break;

            case 'update_setting':
                $data = WP_Spider_Analyser_Admin::update_cnf();
                $ret  = array( 'code' => 0, 'desc' => esc_html__('Settings updated.', WB_SPA_DM), 'data' => $data );
                static::ajax_resp( $ret );
                break;

            case 'verify':
                $ret = array( 'code' => 1, 'desc' => esc_html__('Verification failed.', WB_SPA_DM) );
                $param_key  = sanitize_text_field( static::param( 'key' ) );
                $param_host = sanitize_text_field( static::param( 'host' ) );
                $api_params = array( 'code' => $param_key, 'host' => $param_host, 'ver'  => 'spider-analyser');
                $err = '';
                do {
                    if ( empty( $api_params['code'] ) || empty( $api_params['host'] ) ) {
                        $err = _x( 'Invalid request, parameters missing.', 'ajax response', WB_SPA_DM ); break;
                    }
                    $http_args = array( 'timeout'   => 30, 'sslverify' => true, 'body' => $api_params, 'headers' => array( 'referer' => home_url() ) );
                    $response = wp_remote_post( 'https://www.wbolt.com/wb-api/v1/verify', $http_args );

                    if ( is_wp_error( $response ) ) {
                        $err = sprintf( _x( 'Verification request failed. Please try again later. (Error: %s)', 'ajax response', WB_SPA_DM ), $response->get_error_message() ); break;
                    }
                    $response_code = wp_remote_retrieve_response_code( $response );
                    if ( 200 !== $response_code ) {
                        $err = sprintf( _x( 'Verification server returned an error. Please try again. (Code: %s)', 'ajax response', WB_SPA_DM ), $response_code ); break;
                    }
                    $body = wp_remote_retrieve_body( $response );
                    if ( empty( $body ) ) { $err = _x( 'Verification response was empty. Please contact support. (Error 010)', 'ajax response', WB_SPA_DM ); break; }
                    $data = json_decode( $body, true );
                    if ( null === $data || !is_array($data) ) { $err = _x( 'Invalid response from verification server. Please contact support. (Error 011)', 'ajax response', WB_SPA_DM ); break; }

                    if ( ! empty( $data['code'] ) && 0 !== (int)$data['code'] ) {
                        $err_msg_from_api = '';
                        switch((int)$data['code']){
                            case 100: case 101: case 102: case 103: $err_msg_from_api = sprintf(_x( 'Plugin configuration parameter error. Code: %s', 'ajax response', WB_SPA_DM ), (int)$data['code']); break;
                            case 200: $err_msg_from_api = _x( 'Invalid key provided. Please enter the correct key. (Code: 200)', 'ajax response', WB_SPA_DM ); break;
                            case 201: $err_msg_from_api = _x( 'Key usage limit exceeded. (Code: 201)', 'ajax response', WB_SPA_DM ); break;
                            case 202: case 203: case 204: $err_msg_from_api = sprintf(_x( 'Verification server error. Please contact support. Code: %s', 'ajax response', WB_SPA_DM ), (int)$data['code']); break;
                            default: $err_msg_from_api = isset($data['desc']) ? sanitize_text_field($data['desc']) : sprintf(_x('An unknown error occurred. Code: %s', 'ajax response', WB_SPA_DM), (int)$data['code']);
                        }
                        $err = $err_msg_from_api; break;
                    } elseif ( empty( $data['v'] ) || empty( $data['data'] ) ) { $err = _x( 'Verification data is incomplete. Please contact support. (Error 004)', 'ajax response', WB_SPA_DM ); break; }

                    $verified_version = sanitize_text_field($data['v']);
                    update_option( 'wb_spider_analyser_ver', $verified_version, 'yes' );
                    update_option( 'wb_spider_analyser_cnf_' . $verified_version, $data['data'], 'yes' );
                    $ret['code'] = 0; $ret['desc'] = esc_html__('Plugin verified successfully.', WB_SPA_DM);
                } while ( false );
                if ( $err ) $ret['desc'] = $err;
                static::ajax_resp( $ret );
                break;

            case 'reset':
                $ver = get_option( 'wb_spider_analyser_ver', 0 );
                if ( ! $ver ) {
                    static::ajax_resp( array( 'code' => 0, 'desc' => esc_html__('Plugin is not currently verified.', WB_SPA_DM) ) );
                } else {
                    delete_option( 'wb_spider_analyser_ver' );
                    delete_option( 'wb_spider_analyser_cnf_' . sanitize_key($ver) );
                    $ret = array( 'code' => 0, 'desc' => esc_html__('Plugin verification has been reset.', WB_SPA_DM) );
                    static::ajax_resp( $ret );
                }
                break;

            case 'options':
                $ver = get_option( 'wb_spider_analyser_ver', 0 );
                $cnf_options_data = '';
                if ( $ver ) $cnf_options_data = get_option( 'wb_spider_analyser_cnf_' . sanitize_key($ver), '' );
                static::ajax_resp( array( 'o' => $cnf_options_data ) );
                break;

            case 'get_localize':
                static::ajax_resp(array( 'code' => 0, 'desc' => 'success', 'data' => static::localize_ajax_handle()));
                break;

            case 'get_comparison':
                $comparison_data = class_exists('WBP') && method_exists('WBP', 'wb_get_json_fields')
                                   ? WBP::wb_get_json_fields( 'comparison.json', __DIR__ . '/json/' ) : array();
                static::ajax_resp(array( 'code' => 0, 'desc' => 'success', 'data' => $comparison_data));
                break;
        }
        wp_die();
    }

    /**
     * Handle general AJAX requests for fetching data for the admin interface.
     * Hooked to 'wp_ajax_spider_analyser'.
     */
    public static function spider_analyser_ajax()
    {
        $op_raw = static::param('op');
        if (!$op_raw) $op_raw = static::param('op', '', 'g');
        if (!$op_raw) wp_die();

        $op = sanitize_key($op_raw);

        $arrow = ['code', 'top_url', 'top_spider', 'summary', 'log', 'log_cnf', 'stop_cnf', 'path_cnf', 'path', 'ip', 'post', 'get_setting', 'promote', 'chk_ver', 'down_log', 'spider_history'];
        if (!in_array($op, $arrow, true)) wp_die();

        if (!current_user_can('manage_options')) {
            static::ajax_resp(['code' => 1, 'desc' => esc_html__('Permission Denied.', WB_SPA_DM)]);
        }

        $nonce_actions = ['promote', 'down_log'];
        if (in_array($op, $nonce_actions, true)) {
            if (!check_ajax_referer('wp_ajax_wb_spider_analyser', '_ajax_nonce', false)) {
                static::ajax_resp(['code' => 1, 'desc' => esc_html__('Nonce verification failed.', WB_SPA_DM)]);
            }
        }

        switch ($op) {
            case 'code':
                $ret = ['code' => 0, 'desc' => esc_html__('Success', WB_SPA_DM)];
                do {
                    $day = (int) static::param('day', 7);
                    $db = static::db(); $table_log = $db->prefix . 'wb_spider_log';
                    $current_timestamp = current_time('timestamp', true);
                    $start_timestamp = $current_timestamp - (DAY_IN_SECONDS * $day);
                    $start_date_gmt = gmdate('Y-m-d H:i:s', $start_timestamp);
                    $end_date_gmt = gmdate('Y-m-d H:i:s', $current_timestamp);
                    $sql = $db->prepare("SELECT `code`, COUNT(1) AS num FROM `{$table_log}` WHERE visit_date >= %s AND visit_date <= %s GROUP BY `code` ORDER BY num DESC LIMIT 10", $start_date_gmt, $end_date_gmt);
                    $ret['data'] = $db->get_results($sql) ?: [];
                } while (0);
                static::ajax_resp($ret);
                break;
            case 'top_url':
                $ret = ['code' => 0, 'desc' => esc_html__('Success', WB_SPA_DM)];
                do {
                    $day = (int) static::param('day', 7);
                    $db = static::db(); $table_log = $db->prefix . 'wb_spider_log';
                    $current_timestamp = current_time('timestamp', true);
                    $start_timestamp = $current_timestamp - (DAY_IN_SECONDS * $day);
                    $start_date_gmt = gmdate('Y-m-d H:i:s', $start_timestamp);
                    $end_date_gmt = gmdate('Y-m-d H:i:s', $current_timestamp);
                    $sql = $db->prepare("SELECT `visit_url`, COUNT(1) AS num FROM `{$table_log}` WHERE visit_date >= %s AND visit_date <= %s GROUP BY `visit_url` ORDER BY num DESC LIMIT 10", $start_date_gmt, $end_date_gmt);
                    $ret['data'] = $db->get_results($sql) ?: [];
                } while (0);
                static::ajax_resp($ret);
                break;
            case 'top_spider':
                $ret = ['code' => 0, 'desc' => esc_html__('Success', WB_SPA_DM)];
                do {
                    $day = (int) static::param('day', 7);
                    $db = static::db(); $table_log = $db->prefix . 'wb_spider_log';
                    $current_timestamp = current_time('timestamp', true);
                    $start_timestamp = $current_timestamp - (DAY_IN_SECONDS * $day);
                    $start_date_gmt = gmdate('Y-m-d H:i:s', $start_timestamp);
                    $end_date_gmt = gmdate('Y-m-d H:i:s', $current_timestamp);
                    $sql = $db->prepare("SELECT `spider`, COUNT(1) AS num FROM `{$table_log}` WHERE visit_date >= %s AND visit_date <= %s GROUP BY `spider` ORDER BY num DESC LIMIT 10", $start_date_gmt, $end_date_gmt);
                    $list = $db->get_results($sql);
                    if ($list) {
                        foreach ($list as $r) $r->thumb = static::get_spider_thumbnail_url( $r->spider );
                    }
                    $ret['data'] = $list ?: [];
                } while (0);
                static::ajax_resp($ret);
                break;
            case 'summary':
                $ret = ['code' => 0, 'desc' => esc_html__('Success', WB_SPA_DM)];
                do {
                    $day = (int) static::param('day', 7);
                    $db = static::db(); $table_log = $db->prefix . 'wb_spider_log';
                    $current_timestamp = current_time('timestamp', true);
                    $start_timestamp = $current_timestamp - (DAY_IN_SECONDS * $day);
                    $start_date_gmt = gmdate('Y-m-d H:i:s', $start_timestamp);
                    $end_date_gmt = gmdate('Y-m-d H:i:s', $current_timestamp);

                    $total = $db->get_var($db->prepare("SELECT COUNT(1) FROM `{$table_log}` WHERE visit_date >= %s AND visit_date <= %s", $start_date_gmt, $end_date_gmt));
                    $spider_count = $db->get_var($db->prepare("SELECT COUNT(DISTINCT spider) FROM `{$table_log}` WHERE visit_date >= %s AND visit_date <= %s", $start_date_gmt, $end_date_gmt));
                    $url_count = $db->get_var($db->prepare("SELECT COUNT(DISTINCT visit_url) FROM `{$table_log}` WHERE visit_date >= %s AND visit_date <= %s", $start_date_gmt, $end_date_gmt));
                    $data = ['total' => (int)$total, 'spider' => (int)$spider_count, 'url' => (int)$url_count];
                    $type_row = $db->get_row($db->prepare("SELECT `code`, COUNT(1) AS num FROM `{$table_log}` WHERE visit_date >= %s AND visit_date <= %s GROUP BY `code` ORDER BY num DESC LIMIT 1", $start_date_gmt, $end_date_gmt));
                    $data['code'] = $type_row ? $type_row->code : '-';
                    $ret['data'] = $data;
                } while (0);
                static::ajax_resp($ret);
                break;
            case 'log':
            case 'path':
            case 'ip':
            case 'post':
                $ret = ['code' => 0, 'desc' => esc_html__('Success', WB_SPA_DM)];
                do {
                    $q_raw = static::param('q'); $q = [];
                    $q['day'] = isset($q_raw['day']) ? intval($q_raw['day']) : -1;
                    if (isset($q_raw['code'])) $q['code'] = sanitize_text_field($q_raw['code']);
                    if (isset($q_raw['name'])) $q['name'] = sanitize_text_field($q_raw['name']);
                    if (isset($q_raw['ip'])) $q['ip'] = sanitize_text_field($q_raw['ip']);
                    if (isset($q_raw['url'])) $q['url'] = esc_url_raw($q_raw['url']);
                    if (isset($q_raw['type'])) $q['type'] = sanitize_text_field($q_raw['type']);
                    if (isset($q_raw['post_id'])) $q['post_id'] = intval($q_raw['post_id']);

                    $db = static::db(); $day = $q['day']; $table_log = $db->prefix . 'wb_spider_log';
                    $where_conditions = [];

                    if ($day > -1) {
                        $time_timestamp = current_time('timestamp', true) - (DAY_IN_SECONDS * $day);
                        $ymd_param_start = gmdate('Y-m-d 00:00:00', $time_timestamp);
                        $ymd_param_end = ($day >= 1) ? gmdate('Y-m-d 23:59:59', current_time('timestamp', true)) : gmdate('Y-m-d 23:59:59', $time_timestamp);
                        $where_conditions[] = $db->prepare("visit_date >= %s AND visit_date <= %s", $ymd_param_start, $ymd_param_end );
                    }

                    if (!empty($q['code'])) $where_conditions[] = $db->prepare("code = %s", $q['code']);
                    if (!empty($q['name'])) $where_conditions[] = $db->prepare("spider = %s", $q['name']);
                    if ('ip' === $op && !empty($q['ip'])) $where_conditions[] = $db->prepare("ip = %s", $q['ip']);
                    if (!empty($q['url'])) $where_conditions[] = $db->prepare("visit_url = %s", $q['url']);
                    if ('path' === $op && !empty($q['type'])) $where_conditions[] = $db->prepare("visit_type = %s", $q['type']);
                    if ('post' === $op && !empty($q['post_id']) && $q['post_id'] > 0) $where_conditions[] = $db->prepare("post_id = %d", $q['post_id']);

                    $num = absint(static::param('num', 30)); $num = $num ?: 30;
                    $page = absint(static::param('page', 1)); $page = $page ?: 1;
                    $offset = max(0, ($page - 1) * $num);
                    $where_sql = $where_conditions ? implode(' AND ', $where_conditions) : '1=1';

                    $cache_param = [$op, $where_sql, $offset, $num, static::param('sort'), static::param('order'), $q_raw];
                    if ($cache_file = static::cache($cache_param)) { include $cache_file; wp_die(); }

                    $allowed_sort_columns = ['id', 'spider', 'visit_url', 'ip', 'code', 'visit_date', 'visit_type', 'post_id'];
                    $order_by_column = 'id'; $sort_param = sanitize_key(static::param('sort'));
                    if (in_array($sort_param, $allowed_sort_columns, true)) $order_by_column = $sort_param;
                    elseif ('name' === $sort_param) $order_by_column = 'spider';
                    elseif ('type' === $sort_param) {
                        if ('path' === $op) $order_by_column = 'visit_type';
                        elseif ('log' === $op) $order_by_column = 'code';
                        else $order_by_column = 'spider'; // Default for ip/post if sorted by type
                    }
                    $order_direction = ('asc' === strtolower(sanitize_key(static::param('order')))) ? 'ASC' : 'DESC';
                    $order_by_sql = "`" . esc_sql($order_by_column) . "` {$order_direction}";

                    $select_columns = "`id`, `spider`, `visit_url`, `ip`, `code`, `visit_date`, `visit_type`, `post_id`";
                    $sql = $db->prepare("SELECT SQL_CALC_FOUND_ROWS {$select_columns} FROM `{$table_log}` WHERE {$where_sql} ORDER BY {$order_by_sql} LIMIT %d, %d", $offset, $num);
                    $list = $db->get_results($sql);
                    $total = $db->get_var("SELECT FOUND_ROWS()");
                    if ($list) { foreach ($list as $r) $r->thumb = static::get_spider_thumbnail_url( $r->spider ); }

                    $ret = ['num' => $num, 'total' => (int)$total, 'code' => 0, 'data' => $list ?: []];
                    static::cache($cache_param, $ret, 3600);
                } while (0);
                static::ajax_resp($ret);
                break;
            case 'log_cnf':
                static::ajax_resp(['code' => 0, 'desc' => esc_html__('Success', WB_SPA_DM), 'data' => static::spider_log()]);
                break;
            case 'stop_cnf':
                static::ajax_resp(['code' => 0, 'desc' => esc_html__('Success', WB_SPA_DM), 'data' => static::spider_list_stop()]);
                break;
            case 'path_cnf':
                static::ajax_resp(['code' => 0, 'desc' => esc_html__('Success', WB_SPA_DM), 'data' => static::spider_path()]);
                break;
            case 'get_setting':
                static::ajax_resp(['code' => 0, 'desc' => esc_html__('Success', WB_SPA_DM), 'data' => WP_Spider_Analyser_Admin::get_cnf()]);
                break;
            case 'promote':
                $ret = ['code' => 1, 'desc' => esc_html__('Promotion check failed.', WB_SPA_DM)];
                $params = [
                    'pd_code' => 'spider-analyser', 'pd_name' => __('Spider Analyser', WB_SPA_DM),
                    'pd_version' => WP_SPIDER_ANALYSER_VERSION, 'site_name' => get_bloginfo('name'),
                    'site_url' => site_url(), 'host' => isset($_SERVER['HTTP_HOST']) ? sanitize_text_field(wp_unslash($_SERVER['HTTP_HOST'])) : '',
                    'type' => sanitize_key(static::param('type', 'promote'))
                ];
                $http_args = ['timeout' => 15, 'sslverify' => true, 'body' => $params, 'headers' => ['referer' => site_url()]];
                $resp = wp_remote_post('https://www.wbolt.com/wb-api/v1/promote', $http_args);
                if (!is_wp_error($resp)) {
                    $body = wp_remote_retrieve_body($resp);
                    if ($body) {
                        $data = json_decode($body, true);
                        if ($data && isset($data['code']) && 0 === (int)$data['code']) {
                            $ret = ['code' => 0, 'desc' => esc_html__('Success', WB_SPA_DM)];
                            if (isset($data['data'])) $ret['data'] = static::array_sanitize_text_field($data['data']);
                        } elseif ($data && isset($data['desc'])) {
                            $ret['desc'] = sanitize_text_field($data['desc']);
                        }
                    }
                } else { $ret['desc'] = $resp->get_error_message(); }
                static::ajax_resp($ret);
                break;
            case 'chk_ver':
                $params = [
                    'pd_code' => 'spider-analyser', 'pd_name' => __('Spider Analyser', WB_SPA_DM),
                    'pd_version' => WP_SPIDER_ANALYSER_VERSION, 'locale' => get_locale(),
                    'host' => isset($_SERVER['HTTP_HOST']) ? sanitize_text_field(wp_unslash($_SERVER['HTTP_HOST'])) : '',
                    'type' => 'check', 'ver' => get_option('wb_spider_analyser_ver', 0)
                ];
                $http_args = ['timeout' => 15, 'sslverify' => true, 'body' => $params, 'headers' => ['referer' => site_url()]];
                $resp = wp_remote_post('https://www.wbolt.com/wb-api/v1/version', $http_args);

                if (is_wp_error($resp)) {
                    error_log(sprintf('Spider Analyser Version Check API Error: %s', $resp->get_error_message()));
                    static::ajax_resp(['code' => 1, 'desc' => sprintf(esc_html__('API Error: %s', WB_SPA_DM), $resp->get_error_message())]);
                }
                $body = wp_remote_retrieve_body($resp);
                if (!$body) {
                    error_log('Spider Analyser Version Check API Error: Empty response.');
                    static::ajax_resp(['code' => 1, 'desc' => esc_html__('API Error: Empty response.', WB_SPA_DM)]);
                }
                $data = json_decode($body, true);
                if (!$data || !isset($data['code'])) {
                    error_log('Spider Analyser Version Check API Error: Invalid JSON.');
                    static::ajax_resp(['code' => 1, 'desc' => esc_html__('API Error: Invalid JSON.', WB_SPA_DM)]);
                }

                if ($data['code'] === 0 && isset($data['data']['version']) && version_compare($data['data']['version'], WP_SPIDER_ANALYSER_VERSION, '>')) {
                    $plugin_file = plugin_basename(WP_SPIDER_ANALYSER_BASE_FILE);
                    $update_transient = get_site_transient('update_plugins');
                    if (!$update_transient) $update_transient = new \stdClass();
                    if (!isset($update_transient->response)) $update_transient->response = [];

                    $update_transient->response[$plugin_file] = (object) [
                        'slug' => 'spider-analyser', 'plugin' => $plugin_file,
                        'new_version' => sanitize_text_field($data['data']['version']),
                        'url' => isset($data['data']['url']) ? esc_url_raw($data['data']['url']) : '',
                        'package' => isset($data['data']['package']) ? esc_url_raw($data['data']['package']) : '',
                        'tested' => isset($data['data']['tested']) ? sanitize_text_field($data['data']['tested']) : '',
                        'requires_php' => isset($data['data']['requires_php']) ? sanitize_text_field($data['data']['requires_php']) : '',
                    ];
                    set_site_transient('update_plugins', $update_transient);
                    static::ajax_resp(['code' => 0, 'desc' => esc_html__('Update transient set.', WB_SPA_DM)]);
                } else {
                    static::ajax_resp(['code' => 0, 'desc' => esc_html__('No new version or API indicated no update.', WB_SPA_DM)]);
                }
                break;
            case 'down_log':
                $ret = ['code' => 1, 'desc' => esc_html__('Log download failed.', WB_SPA_DM)];
                do {
                    $q_raw = static::param('q'); $q = [];
                    $q['day'] = isset($q_raw['day']) ? intval($q_raw['day']) : -1;
                    if (isset($q_raw['code'])) $q['code'] = sanitize_text_field($q_raw['code']);
                    if (isset($q_raw['name'])) $q['name'] = sanitize_text_field($q_raw['name']);
                    if (isset($q_raw['ip'])) $q['ip'] = sanitize_text_field($q_raw['ip']);
                    if (isset($q_raw['url'])) $q['url'] = esc_url_raw($q_raw['url']);

                    $db = static::db(); $day = $q['day']; $table_log = $db->prefix . 'wb_spider_log';
                    $where_conditions = [];

                    if ($day > -1) {
                        $time_timestamp = current_time('timestamp', true) - (DAY_IN_SECONDS * $day);
                        $ymd_param_start = gmdate('Y-m-d 00:00:00', $time_timestamp);
                        $ymd_param_end = ($day >= 1) ? gmdate('Y-m-d 23:59:59', current_time('timestamp', true)) : gmdate('Y-m-d 23:59:59', $time_timestamp);
                        $where_conditions[] = $db->prepare("visit_date >= %s AND visit_date <= %s", $ymd_param_start, $ymd_param_end);
                    }

                    if (!empty($q['code'])) $where_conditions[] = $db->prepare("code = %s", $q['code']);
                    if (!empty($q['name'])) $where_conditions[] = $db->prepare("spider = %s", $q['name']);
                    if (!empty($q['ip'])) $where_conditions[] = $db->prepare("ip = %s", $q['ip']);
                    if (!empty($q['url'])) $where_conditions[] = $db->prepare("visit_url = %s", $q['url']);

                    $where_sql = $where_conditions ? implode(' AND ', $where_conditions) : '1=1';

                    $select_columns = "`id`, `spider`, `visit_url`, `ip`, `code`, `visit_date`, `visit_type`, `post_id`";
                    $sql = "SELECT {$select_columns} FROM `{$table_log}` WHERE {$where_sql} ORDER BY id DESC";
                    $list = $db->get_results($sql);

                    if (!$list) { $ret['desc'] = __('No logs found for the selected criteria.', WB_SPA_DM); break; }

                    $filename = 'spider_analyser_logs_' . gmdate('YmdHis') . '.csv';
                    header('Content-Type: text/csv; charset=utf-8');
                    header('Content-Disposition: attachment; filename=' . sanitize_file_name($filename));
                    header('Pragma: no-cache'); header('Expires: 0');

                    $output = fopen('php://output', 'w');
                    fwrite($output, "\xEF\xBB\xBF");

                    fputcsv($output, [
                        __('ID', WB_SPA_DM), __('Spider Name', WB_SPA_DM), __('Visit URL', WB_SPA_DM),
                        __('IP Address', WB_SPA_DM), __('Response Code', WB_SPA_DM), __('Visit Date (UTC)', WB_SPA_DM),
                        __('Visit Type', WB_SPA_DM), __('Post ID', WB_SPA_DM)
                    ]);
                    foreach ($list as $row) {
                        fputcsv($output, [$row->id, $row->spider, $row->visit_url, $row->ip, $row->code, $row->visit_date, $row->visit_type, $row->post_id]);
                    }
                    fclose($output); exit;
                } while (0);
                static::ajax_resp($ret);
                break;
            case 'spider_history':
                $ret = ['code' => 0, 'desc' => esc_html__('Success', WB_SPA_DM)];
                do {
                    $day = (int) static::param('day', 7);
                    $spider_name = sanitize_text_field(static::param('name'));
                    if (!$spider_name) { $ret = ['code' => 1, 'desc' => esc_html__('Spider name is required.', WB_SPA_DM)]; break; }

                    list($xdata, $ydata) = static::chart_data($day, 2, 0, $spider_name);
                    $ret['data'] = ['x' => $xdata ?: [], 'y' => $ydata ?: []];
                } while (0);
                static::ajax_resp($ret);
                break;
        }
        wp_die();
    }

    /**
     * Send spider data to an external API. Non-blocking.
     *
     * @param array $spider_data Data about the spider to send.
     */
    public static function update_spider( $spider_data ) {
        $api_url = 'https://www.wbolt.com/wb-api/v1/spider/info';
        $http_args = array(
            'timeout'   => 1,
            'blocking'  => false,
            'sslverify' => true,
            'body'      => array( 'spider' => wp_json_encode( $spider_data ) ),
            'headers'   => array( 'referer' => home_url() ),
        );
        wp_remote_post( $api_url, $http_args );
    }

    /**
     * Synchronize spider information from an external API.
     * Typically run via cron.
     */
    public static function sync_wb_spider() {
        $db = static::db();
        $table_spider = $db->prefix . 'wb_spider';
        $existing_spiders = $db->get_col( "SELECT `name` FROM `{$table_spider}`" );

        if ( empty( $existing_spiders ) ) return;

        $api_url = 'https://www.wbolt.com/wb-api/v1/spider/info';
        $api_params = array(
            'timeout'   => 30, 'sslverify' => true,
            'headers'   => array( 'referer' => home_url() ),
            'body'      => array( 'udg' => 1, 'logo' => 1, 'locale' => get_locale()),
        );
        $response = wp_remote_get( $api_url, $api_params );

        if ( is_wp_error( $response ) ) { error_log('Spider Analyser Sync API Error: ' . $response->get_error_message()); return; }
        if ( 200 !== wp_remote_retrieve_response_code( $response ) ) { error_log('Spider Analyser Sync API Non-200 Response: ' . wp_remote_retrieve_response_code( $response )); return; }

        $body = wp_remote_retrieve_body( $response );
        if ( ! $body ) { error_log('Spider Analyser Sync API Error: Empty response body.'); return; }

        $api_data = json_decode( $body, true );
        if ( ! $api_data || ! is_array( $api_data ) || empty( $api_data['data'] ) || ! is_array( $api_data['data'] ) ) {
            error_log('Spider Analyser Sync API Error: Invalid data structure.'); return;
        }

        static::save_spider_info( $api_data['data'] );

        $db->query( "UPDATE `{$table_spider}` SET `status` = 1 WHERE `status` = 2" );

        foreach ( $api_data['data'] as $spider_info_from_api ) {
            if ( ! isset( $spider_info_from_api['name'], $spider_info_from_api['bot_type'], $spider_info_from_api['bot_url'] ) ) continue;

            $s_name = sanitize_text_field($spider_info_from_api['name']);
            $s_bot_type = sanitize_text_field($spider_info_from_api['bot_type']);
            $s_bot_url = esc_url_raw($spider_info_from_api['bot_url']);

            if ( in_array( $s_name, $existing_spiders, true ) ) {
                $db->update( $table_spider,
                    array( 'status' => 2, 'bot_type' => $s_bot_type, 'bot_url'  => $s_bot_url),
                    array( 'name' => $s_name ),
                    array( '%d', '%s', '%s' ), array( '%s' ) );
            }
        }
    }

    /**
     * Manage cached data.
     *
     * @param string|array $param Parameters to create the cache key.
     * @param mixed|null $data_to_cache Data to cache. If null, attempts to read from cache.
     * @param int $cache_duration Duration in seconds for how long to cache the data.
     * @param string $response_type Expected response type (e.g., 'json'). Currently not strictly used for header setting in this version.
     * @return string|bool Path to cache file if reading and cache exists, true on successful write, false on failure.
     */
    public static function cache( $param, $data_to_cache = null, $cache_duration = 0, $response_type = 'json' ) {
        if (!is_array($param)) $param = array($param);

        $cache_key = md5( wp_json_encode( $param ) );
        $log_dir = WP_SPIDER_ANALYSER_PATH . '/#log/';

        if ( ! is_dir( $log_dir ) ) {
            if ( ! wp_mkdir_p( $log_dir ) ) { error_log("Spider Analyser Cache: Failed to create directory " . $log_dir); return false; }
        }
        $cache_file_path = $log_dir . $cache_key . '.php';

        if ( null === $data_to_cache ) {
            if ( file_exists( $cache_file_path ) ) return $cache_file_path;
            return false;
        }

        $json_data_to_cache = (is_array( $data_to_cache ) || is_object( $data_to_cache )) ? wp_json_encode( $data_to_cache ) : $data_to_cache;
        if (false === $json_data_to_cache && (is_array( $data_to_cache ) || is_object( $data_to_cache ))) { // Check if json_encode failed
            error_log("Spider Analyser Cache: Failed to encode data to JSON. Key: " . $cache_key); return false;
        }

        $expiration_timestamp = time() + absint( $cache_duration );

        $cache_file_content  = "<" . "?php \n";
        $cache_file_content .= "// Cache generated by Spider Analyser at: " . current_time( 'mysql' ) . " (UTC)\n";
        $cache_file_content .= "// Expires at timestamp: " . $expiration_timestamp . "\n";
        $cache_file_content .= "if ( time() > " . $expiration_timestamp . " ) { @unlink(__FILE__); return; } \n";
        $cache_file_content .= "echo '" . addslashes($json_data_to_cache) . "'; \n";
        $cache_file_content .= "exit(); \n";

        if (false === file_put_contents( $cache_file_path, $cache_file_content )) {
            error_log("Spider Analyser Cache: Failed to write to file " . $cache_file_path); return false;
        }
        return true;
    }

    // ajax_resp helper is in WP_Spider_Analyser_Base
}
?>
