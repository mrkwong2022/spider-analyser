<?php


if (!defined('ABSPATH')) {
    return;
}



class WP_Spider_Analyser extends WP_Spider_Analyser_Base
{

    public static $in_log = false;
    public static $debug = false;
    public static $blocked = false;
    public static $after_request = false;


    public static function init()
    {
        add_action('plugins_loaded', function () {
            load_plugin_textdomain(WB_SPA_DM, false, plugin_basename(WP_SPIDER_ANALYSER_PATH) . '/languages/');
        });

        // 插件列表页支持本地化语言展示
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
            if (!self::$in_log && $redirect_url) {
                self::$in_log = true;
                self::log(302);
            }
            return $redirect_url;
        }, 10, 2);


        //
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
        self::upgrade();
    }


    public static function parse_request()
    {
        // global $wpdb;

        if (!get_option('wb_spider_analyser_ver', 0)) {
            self::$after_request = true;
            return;
        }

        $db = self::db();

        $ip = self::getIp();
        $t = $db->prefix . 'wb_spider_ip';

        //self::txt_log('parse_request');


        $spider = self::spider();
        //self::txt_log('spider '.$spider);
        if (!$spider) {
            return;
        }

        $match = false;

        if ($ip) {
            $ips = explode('.', $ip);
            array_pop($ips);
            $ip3 = implode('.', $ips);
            $sql = "SELECT * FROM $t WHERE (status=4 OR status>10) AND (ip = '' OR ip LIKE %s) AND (name='' OR name = %s) GROUP BY CONCAT_WS('',ip,name) ";

            $list = $db->get_results($db->prepare($sql, $ip3 . '.%', $spider));

            if ($list) foreach ($list as $r) {
                $match = true;
                if ($r->name) { //match name
                    //check ip not match
                    if ($r->ip && $r->ip != $ip3 . '.*' && $ip != $r->ip) {
                        $match = false;
                    }
                } else { //only ip
                    //check ip not match
                    if ($r->ip && $r->ip != $ip3 . '.*' && $ip != $r->ip) {
                        $match = false;
                    }
                }
                if ($match) {
                    break;
                }
            }
        } else {
            $sql = "SELECT * FROM $t WHERE (status=4 OR status>10) AND name = %s GROUP BY name";

            $list = $db->get_results($db->prepare($sql, $spider));
            if ($list) {
                $match = true;
            }
        }

        self::$after_request = true;
        if ($match) {
            self::$blocked = true;
            wp_die('Blocked Spider Access!', 'IP Blocked', array('response' => 403));
            exit();
        }
    }


    public static function admin_notices()
    {
        global $current_screen;
        if (!current_user_can('update_plugins')) {
            return;
        }
        if (!preg_match('#spider_analyser#', $current_screen->parent_base)) {
            return;
        }
        $current         = get_site_transient('update_plugins');
        if (!$current) {
            return;
        }
        $plugin_file = plugin_basename(WP_SPIDER_ANALYSER_BASE_FILE);
        if (!isset($current->response[$plugin_file])) {
            return;
        }
        $all_plugins     = get_plugins();
        if (!$all_plugins || !isset($all_plugins[$plugin_file])) {
            return;
        }
        $plugin_data = $all_plugins[$plugin_file];
        $update = $current->response[$plugin_file];

        //print_r($update);
        $update_url = wp_nonce_url(self_admin_url('update.php?action=upgrade-plugin&plugin=') . $plugin_file, 'upgrade-plugin_' . $plugin_file);

        $pd_name = $plugin_data['Name'];
        echo  '<div class="update-message notice inline notice-warning notice-alt"><p>' . esc_html($pd_name) . __('有新版本可用。', WB_SPA_DM);
        echo  '<a href="' . esc_url($update->url) . '" target="_blank" aria-label="' . sprintf(_x('查看 %s 版本', '%s产品名', WB_SPA_DM), $pd_name) . esc_attr($update->new_version) . '">' . sprintf(__('查看版本 %s 详情', WB_SPA_DM), esc_html($update->new_version)) . '</a>';
        echo  _x('或', 'or', WB_SPA_DM) . '<a href="' . esc_url($update_url) . '" class="update-link" aria-label="' . sprintf(_x('现在更新%s', '%s产品名', WB_SPA_DM), $pd_name) . '">' . _x('现在更新', 'link', WB_SPA_DM) . '</a>。</p></div>';
    }

    public static function vue_assets()
    {

        $assets = include WP_SPIDER_ANALYSER_PATH . '/plugins_assets.php';

        if (!$assets || !is_array($assets)) {
            return;
        }

        $wp_styles = wp_styles();
        if (isset($assets['css']) && is_array($assets['css'])) foreach ($assets['css'] as $r) {
            $wp_styles->add($r['handle'], WP_SPIDER_ANALYSER_URL . $r['src'], $r['dep'], null, $r['args']);
            $wp_styles->enqueue($r['handle']); //.'?v=1'
        }
        if (isset($assets['js']) && is_array($assets['js'])) foreach ($assets['js'] as $r) {
            if (!$r['src'] && $r['in_line']) {
                wp_register_script($r['handle'], false, $r['dep'], false, true);
                wp_enqueue_script($r['handle']);
                wp_add_inline_script($r['handle'], $r['in_line'], 'after');
            } else if ($r['src']) {
                wp_enqueue_script($r['handle'], WP_SPIDER_ANALYSER_URL . $r['src'], $r['dep'], null, true);
            }
        }
    }

    public static function admin_enqueue_scripts($hook)
    {


        if (!preg_match('#wp_spider_analyser#', $hook)) {
            return;
        }
        add_filter('script_loader_tag', [__CLASS__, 'script_tag_handler'], 10, 3);

        wp_register_script('wbs-inline-js', false, null, false);
        wp_enqueue_script('wbs-inline-js');

        $wb_ajax_nonce = wp_create_nonce('wp_ajax_wb_spider_analyser');

        $options = self::cnf();

        $prompt_items = WBP::wb_get_json_fields('prompt.json', __DIR__ . '/json/');

        $wb_cnf = array(
            'home_url' => home_url(),
            'base_url' => admin_url(),
            'ajax_url' => admin_url('admin-ajax.php'),
            'dir_url' => WP_SPIDER_ANALYSER_URL,
            'pd_code' => "spider-analyser",
            'pd_title' => _x('Spider Analyser-蜘蛛分析插件', '产品名', WB_SPA_DM),
            'pd_version' => WP_SPIDER_ANALYSER_VERSION,
            'is_pro' => intval(get_option('wb_spider_analyser_ver', 0)),
            'action' => array(
                'act' => 'spider_analyser',
                'fetch' => 'get_setting',
                'push' => 'set_setting'
            ),
            'wbp_security' => $wb_ajax_nonce,
            'wb_spider_auto' => isset($options['auto_deny']) && $options['auto_deny'] == '1' ? '1' : '0',
            'locale' => get_locale(),
            'actpanel_visible' => in_array(get_locale(), ['zh_CN', 'zh_TW'], true),
            'prompt' => $prompt_items
        );


        $inline_script = 'var wbp_js_cnf=' . wp_json_encode($wb_cnf) . ';' . "\n";
        wp_add_inline_script('wbs-inline-js', $inline_script, 'before');
        echo WB_Vite::vite('src/main.js', WP_SPIDER_ANALYSER_PATH . '/assets/wbp/', WP_SPIDER_ANALYSER_URL . '/assets/wbp/');
    }

    /**
     * js输出加type="module"
     * 适用vite生成module js
     *
     * @param [type] $tag
     * @param [type] $handle
     * @param [type] $src
     * @return string
     */
    public static function script_tag_handler($tag, $handle, $src)
    {
        if (preg_match("/wbs-/i", $handle)) {
            return '<script type="module" src="' . esc_url($src) . '" defer></script>' . "\n";
        } else {
            return $tag;
        }
    }


    public static function match_type($url, &$query = null)
    {
        global $wp_filter;
        self::txt_log('match type fun');
        $cnf = self::cnf();

        self::txt_log($cnf);

        $type = null;
        $old_page = null;
        $php_self = null;
        $request_uri = null;

        $reset_url = false;

        do {
            if ($cnf['extral_rule']) foreach ($cnf['extral_rule'] as $r_type => $rule) {
                if (!$rule) {
                    continue;
                }
                $rule = str_replace(array(',', '\\*'), array('|', '.+?'), preg_quote($rule));
                if (preg_match('#(' . $rule . ')#i', $url)) {
                    $type = $r_type;
                    break;
                }
            }
            if ($type) {
                break;
            }

            //['index','post','page','category','tag','search','author','feed','sitemap','api','other'];
            if ($cnf['user_rule']) foreach ($cnf['user_rule'] as $r) {
                if (!$r['rule']) {
                    continue;
                }
                $rule = str_replace(array(',', '\\*'), array('|', '.+?'), preg_quote($r['rule']));
                if (preg_match('#' . $rule . '#i', $url)) {
                    $type = $r['name'];
                    break;
                }
            }
            if ($type) {
                break;
            }

            if (preg_match('#/wp-admin/admin-ajax\.php#', $url)) {
                $type = 'api';
                break;
            } else if (preg_match('#^/sitemap(-[a-z0-9_-]+)?\.xml#i', $url)) {
                $type = 'sitemap';
                break;
            }
            $parse = wp_parse_url($url);
            if (isset($parse['query']) && $parse['query']) {
                parse_str($parse['query'], $param);
                if (isset($param['s'])) {
                    $type = 'search';
                    break;
                }
            }
            if (!$parse['path'] || $parse['path'] == '/') {
                $type = 'index';
                break;
            }
            //if(preg_match('#sitemap#'))
            $request_uri = $_SERVER['REQUEST_URI'];
            $php_self = $_SERVER['PHP_SELF'];
            $path = $parse['path'];
            if (preg_match('#/?$#', $parse['path'])) {
                $path = trim($parse['path'], '/') . '/index.php';
            }

            self::txt_log('new wp');



            $wp = new WP();
            $_SERVER['REQUEST_URI'] = $url;
            $_SERVER['PHP_SELF'] = '/index.php';
            $old_page = isset($_GET['page']) ? sanitize_text_field($_GET['page']) : null;
            if ($old_page === null) {
            } else {
                unset($_GET['page']);
            }
            $reset_url = true;
            self::txt_log('wp parse request');
            /*ini_set('display_errors',true);
            ini_set('error_reporting',E_ALL);
            $old_filter = isset($wp_filter['parse_request'])?$wp_filter['parse_request']:null;
            remove_all_filters('parse_request');
            $wp->parse_request($url);
            if($old_filter){
                $wp_filter['parse_request'] = $old_filter;
            }*/

            $wp->query_vars = self::url_help($url);

            self::txt_log('wp build query string');
            $wp->build_query_string();
            self::txt_log('wp query_vars');
            self::txt_log($wp->query_vars);

            $old_filter = isset($wp_filter['parse_query']) ? $wp_filter['parse_query'] : null;
            remove_all_filters('parse_query');

            $wp_query = new WP_Query();
            $wp_query->parse_query($wp->query_vars);

            if ($old_filter) {
                $wp_filter['parse_query'] = $old_filter;
            }

            self::txt_log('wp_query query_vars');
            self::txt_log($wp_query->query_vars);

            if ($wp_query->is_author) {
                $type = 'author';
                break;
            }
            if ($wp_query->is_tag) {
                $type = 'tag';
                break;
            }
            if ($wp_query->is_feed) {
                $type = 'feed';
                break;
            }
            if ($wp_query->is_archive) {
                $type = 'category';
                break;
            }


            if ($wp_query->is_singular) {
                //$wp_query->query();
                //print_r($wp_query->get_posts());
                $posts = $wp_query->get_posts();
                if ($posts) {
                    if ($posts[0] instanceof WP_Post) {
                        $query = $posts[0];
                    }
                    if ($posts[0]->post_type == 'page') {
                        $type = 'page';
                        break;
                    }
                }

                $type = 'post';
                break;
            }




            $type = 'other';
        } while (0);

        if ($reset_url) {
            if ($old_page === null) {
            } else {
                $_GET['page'] = $old_page;
            }
            //print_r($wp);
            //print_r($wp_query);
            $_SERVER['PHP_SELF'] = $php_self;
            $_SERVER['REQUEST_URI'] = $request_uri;
        }

        return $type;
    }

    public static function url_help($req_url)
    {
        global $wp_rewrite, $wp;
        $private_query_vars = $wp->private_query_vars;
        $public_query_vars = $wp->public_query_vars;
        $query_vars     = array();
        $post_type_query_vars = array();
        $extra_query_vars = array();


        if ($req_url) {
            parse_str($req_url, $extra_query_vars);
        }

        // Fetch the rewrite rules.
        $rewrite = $wp_rewrite->wp_rewrite_rules();



        if (!empty($rewrite)) {
            // If we match a rewrite rule, this will be cleared.
            $error               = '404';

            $pathinfo         = isset($_SERVER['PATH_INFO']) ? $_SERVER['PATH_INFO'] : '';
            list($pathinfo) = explode('?', $pathinfo);
            $pathinfo         = str_replace('%', '%25', $pathinfo);

            list($req_uri) = explode('?', $_SERVER['REQUEST_URI']);
            $self            = $_SERVER['PHP_SELF'];
            $home_path       = trim(wp_parse_url(home_url(), PHP_URL_PATH), '/');
            $home_path_regex = sprintf('|^%s|i', preg_quote($home_path, '|'));

            /*
             * Trim path info from the end and the leading home path from the front.
             * For path info requests, this leaves us with the requesting filename, if any.
             * For 404 requests, this leaves us with the requested permalink.
             */
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

            // The requested permalink is in $pathinfo for path info requests and
            // $req_uri for other requests.
            if (!empty($pathinfo) && !preg_match('|^.*' . $wp_rewrite->index . '$|', $pathinfo)) {
                $requested_path = $pathinfo;
            } else {
                // If the request uri is the index, blank it out so that we don't try to match it against a rule.
                if ($req_uri == $wp_rewrite->index) {
                    $req_uri = '';
                }
                $requested_path = $req_uri;
            }
            $requested_file = $req_uri;


            // Look for matches.
            $request_match = $requested_path;
            if (empty($request_match)) {
                // An empty request could only match against ^$ regex.
                if (isset($rewrite['$'])) {
                    $matched_rule = '$';
                    $query              = $rewrite['$'];
                    $matches            = array('');
                }
            } else {
                foreach ((array) $rewrite as $match => $query) {
                    // If the requested file is the anchor of the match, prepend it to the path info.
                    if (!empty($requested_file) && strpos($match, $requested_file) === 0 && $requested_file != $requested_path) {
                        $request_match = $requested_file . '/' . $requested_path;
                    }

                    if (
                        preg_match("#^$match#", $request_match, $matches) ||
                        preg_match("#^$match#", urldecode($request_match), $matches)
                    ) {

                        if ($wp_rewrite->use_verbose_page_rules && preg_match('/pagename=\$matches\[([0-9]+)\]/', $query, $varmatch)) {
                            // This is a verbose page match, let's check to be sure about it.
                            $page = get_page_by_path($matches[$varmatch[1]]);
                            if (!$page) {
                                continue;
                            }

                            $post_status_obj = get_post_status_object($page->post_status);
                            if (
                                !$post_status_obj->public && !$post_status_obj->protected
                                && !$post_status_obj->private && $post_status_obj->exclude_from_search
                            ) {
                                continue;
                            }
                        }

                        // Got a match.
                        $matched_rule = $match;
                        break;
                    }
                }
            }


            if (isset($matched_rule)) {
                // Trim the query of everything up to the '?'.
                $query = preg_replace('!^.+\?!', '', $query);

                // Substitute the substring matches into the query.
                $query = addslashes(WP_MatchesMapRegex::apply($query, $matches));



                // Parse the query.
                parse_str($query, $perma_query_vars);

                // If we're processing a 404 request, clear the error var since we found something.
                if ('404' == $error) {
                    unset($error, $_GET['error']);
                }
            }

            // If req_uri is empty or if it is a request for ourself, unset error.
            if (empty($requested_path) || $requested_file == $self || strpos($_SERVER['PHP_SELF'], 'wp-admin/') !== false) {
                unset($error, $_GET['error']);

                if (isset($perma_query_vars) && strpos($_SERVER['PHP_SELF'], 'wp-admin/') !== false) {
                    unset($perma_query_vars);
                }
            }
        }

        /**
         * Filters the query variables allowed before processing.
         *
         * Allows (publicly allowed) query vars to be added, removed, or changed prior
         * to executing the query. Needed to allow custom rewrite rules using your own arguments
         * to work, or any other custom query variables you want to be publicly available.
         *
         * @since 1.5.0
         *
         * @param string[] $public_query_vars The array of allowed query variable names.
         */
        $public_query_vars = apply_filters('query_vars', $public_query_vars);

        foreach (get_post_types(array(), 'objects') as $post_type => $t) {
            if (is_post_type_viewable($t) && $t->query_var) {
                $post_type_query_vars[$t->query_var] = $post_type;
            }
        }

        foreach ($public_query_vars as $wpvar) {
            if (isset($extra_query_vars[$wpvar])) {
                $query_vars[$wpvar] = $extra_query_vars[$wpvar];
            } elseif (isset($_GET[$wpvar]) && isset($_POST[$wpvar]) && $_GET[$wpvar] !== $_POST[$wpvar]) {
                wp_die(__('A variable mismatch has been detected.'), __('Sorry, you are not allowed to view this item.'), 400);
            } elseif (isset($_POST[$wpvar])) {
                $query_vars[$wpvar] = $_POST[$wpvar];
            } elseif (isset($_GET[$wpvar])) {
                $query_vars[$wpvar] = $_GET[$wpvar];
            } elseif (isset($perma_query_vars[$wpvar])) {
                $query_vars[$wpvar] = $perma_query_vars[$wpvar];
            }

            if (!empty($query_vars[$wpvar])) {
                if (!is_array($query_vars[$wpvar])) {
                    $query_vars[$wpvar] = (string) $query_vars[$wpvar];
                } else {
                    foreach ($query_vars[$wpvar] as $vkey => $v) {
                        if (is_scalar($v)) {
                            $query_vars[$wpvar][$vkey] = (string) $v;
                        }
                    }
                }

                if (isset($post_type_query_vars[$wpvar])) {
                    $query_vars['post_type'] = $post_type_query_vars[$wpvar];
                    $query_vars['name']      = $query_vars[$wpvar];
                }
            }
        }

        // Convert urldecoded spaces back into '+'.
        foreach (get_taxonomies(array(), 'objects') as $taxonomy => $t) {
            if ($t->query_var && isset($query_vars[$t->query_var])) {
                $query_vars[$t->query_var] = str_replace(' ', '+', $query_vars[$t->query_var]);
            }
        }

        // Don't allow non-publicly queryable taxonomies to be queried from the front end.
        if (!is_admin()) {
            foreach (get_taxonomies(array('publicly_queryable' => false), 'objects') as $taxonomy => $t) {
                /*
                 * Disallow when set to the 'taxonomy' query var.
                 * Non-publicly queryable taxonomies cannot register custom query vars. See register_taxonomy().
                 */
                if (isset($query_vars['taxonomy']) && $taxonomy === $query_vars['taxonomy']) {
                    unset($query_vars['taxonomy'], $query_vars['term']);
                }
            }
        }

        // Limit publicly queried post_types to those that are 'publicly_queryable'.
        if (isset($query_vars['post_type'])) {
            $queryable_post_types = get_post_types(array('publicly_queryable' => true));
            if (!is_array($query_vars['post_type'])) {
                if (!in_array($query_vars['post_type'], $queryable_post_types, true)) {
                    unset($query_vars['post_type']);
                }
            } else {
                $query_vars['post_type'] = array_intersect($query_vars['post_type'], $queryable_post_types);
            }
        }

        // Resolve conflicts between posts with numeric slugs and date archive queries.
        $query_vars = wp_resolve_numeric_slug_conflicts($query_vars);

        foreach ((array) $private_query_vars as $var) {
            if (isset($extra_query_vars[$var])) {
                $query_vars[$var] = $extra_query_vars[$var];
            }
        }

        if (isset($error)) {
            $query_vars['error'] = $error;
        }

        return $query_vars;
    }


    public static function chart_data($day, $type, $compare = 0, $spider = null)
    {
        // global $wpdb;

        $db = self::db();
        $time = strtotime(current_time('mysql'));
        if ($day) {
            $time = $time - 86400 * $day;
        }

        if ($compare) {
            $time = $time - 86400 * ($day > 0 ? $day : 1);
        }
        $ymd = gmdate('Y-m-d', $time);
        $t = $db->prefix . 'wb_spider_log';

        if ($day > 2) {
            //group by h
            $format = '%m/%d';
            $op = '>=';

            $xdata = [];
            for ($i = 0; $i < $day; $i++) {
                $xdata[] = gmdate('m/d', $time + $i * 86400);
            }
        } else {
            $format = '%H:00-%H:59';
            $op = '=';
            $xdata = [];

            for ($i = 0; $i < 24; $i++) {
                $xdata[] = $i < 10 ? ('0' . $i . ':00-0' . $i . ':59') : ('' . $i . ':00-' . $i . ':59');
            }
        }
        $filed_more = '';
        $group_more = '';
        $where_more = '';
        /*if ($type == 3) {
            $filed_more = ',code';
            $group_more = ',code';
            $where_more = ' AND code IN(200,301,302,404)';
        }*/

        if ($spider) {
            $where_more = $db->prepare(" AND spider = %s", $spider);
        }

        $sql = "SELECT COUNT(1) num,COUNT(DISTINCT spider) spider,DATE_FORMAT(visit_date,'$format') ymd $filed_more FROM (SELECT * FROM $t WHERE DATE_FORMAT(visit_date,'%Y-%m-%d') $op '$ymd' $where_more) AS a GROUP BY ymd $group_more ORDER BY ymd";
        $list = $db->get_results($sql);
        $tmp = [];
        foreach ($list as $r) {
            if ($type == 2) {
                $tmp[$r->ymd] = $r->num;
            } else if ($type == 3) {
                $tmp[$r->ymd] = $r->spider > 0 ? ceil($r->num / $r->spider) : 0;

                //$tmp[$r->ymd] = $r->spider > 0 ? ceil($r->num/$r->spider) : 0;
                /*if (!isset($tmp[$r->ymd])) {
                    $tmp[$r->ymd] = [];
                }
                $code = in_array($r->code, ['301', '302']) ? '301/302' : $r->code;
                $tmp[$r->ymd][$code] = isset($tmp[$r->ymd][$code]) ?  $tmp[$r->ymd][$code] + $r->num : $r->num;*/
            } else {
                $tmp[$r->ymd] = $r->spider;
            }
        }

        $ydata = [];
        $codes = ['200', '301/302', '404'];
        $empty = 0;
        /*if ($type == 3) {
            $empty = [];
            foreach ($codes as $c) {
                $ydata[$c] = [];
                $empty[$c] = 0;
            }
        }*/

        foreach ($xdata as $v) {
            /*if ($type == 3) {
                $val = isset($tmp[$v]) ? $tmp[$v] : $empty;
                foreach ($codes as $c) {
                    $ydata[$c][] = isset($val[$c]) ? $val[$c] : 0;
                }
            } else {
                $ydata[] = isset($tmp[$v]) ? $tmp[$v] : $empty;
            }*/
            $ydata[] = isset($tmp[$v]) ? $tmp[$v] : $empty;
        }

        return [$xdata, $ydata];
    }


    public static function  array_sanitize_text_field($value)
    {
        if (is_array($value)) {
            foreach ($value as $k => $v) {
                $value[$k] = self::array_sanitize_text_field($v);
            }
            return $value;
        } else {
            return sanitize_text_field($value);
        }
    }


    public static function spider_analyser_ajax_save()
    {
        $op = sanitize_text_field(self::param('op'));
        if (!$op) {
            $op = sanitize_text_field(self::param('op', '', 'g'));
        }
        if (!$op) {
            return;
        }
        $arrow = [
            'list',
            'stop',
            'clean_log',
            'clean_all',
            'verify',
            'reset',
            'options',
            'update_setting',
            'get_localize',
            'get_comparison'
        ];
        if (!in_array($op, $arrow)) {
            return;
        }
        if (!current_user_can('manage_options')) {
            self::ajax_resp(['code' => 1, 'desc' => 'deny']);
            return;
        }

        if (!wp_verify_nonce(sanitize_text_field(self::param('_ajax_nonce')), 'wp_ajax_wb_spider_analyser')) {
            self::ajax_resp(['code' => 1, 'desc' => 'illegal']);
            return;
        }

        switch ($op) {
            case 'list':
                $ret = array('code' => 0, 'desc' => 'success');
                do {
                    $skip = self::param('skip');

                    if ($skip) {

                        if (is_array($skip)) {
                            $skips = self::array_sanitize_text_field($skip);
                            foreach ($skips as $skip) {
                                self::skip_spider($skip);
                                self::delete_log(['spider' => $skip]);
                            }
                        } else {
                            $spider = trim(sanitize_text_field($skip));
                            self::skip_spider($spider);
                            self::delete_log(['spider' => $spider]);
                        }
                        break;
                    }



                    $db = self::db();
                    $q = self::param('q');
                    if ($q && is_array($q)) {
                        $q = self::array_sanitize_text_field($q);
                    }

                    $day = isset($q['day']) ? intval($q['day']) : -1;
                    $t2 = $db->prefix . 'wb_spider';
                    $t = $db->prefix . 'wb_spider_log';
                    $where = array();
                    $total_where = array();
                    if ($day > -1) {
                        $time = strtotime(current_time('mysql'));
                        if ($day) {
                            $time = $time - 86400 * $day;
                        }
                        $ymd = gmdate('Y-m-d', $time);

                        $op = '=';
                        if ($day > 1) {
                            $op = '>=';
                        }

                        $where[] = "DATE_FORMAT(a.visit_date,'%Y-%m-%d') $op '$ymd'";
                        $total_where[] = "DATE_FORMAT(visit_date,'%Y-%m-%d') $op '$ymd'";
                    }
                    if (!empty($q['code'])) {
                        $where[] = $db->prepare("a.code=%s", $q['code']);
                    }
                    if (!empty($q['bot_type'])) {
                        $where[] = $db->prepare("b.bot_type = %s", $q['bot_type']);
                    }
                    if (!empty($q['spider'])) {
                        $where[] = $db->prepare("a.spider = %s", $q['spider']);
                    }
                    if (!empty($q['name'])) {
                        $where[] = $db->prepare("a.spider REGEXP %s", preg_quote($q['name']));
                    }
                    $num = absint(self::param('num', 30));
                    if (!$num) {
                        $num = 30;
                    }
                    $page = absint(self::param('page', 1));
                    if (!$page) {
                        $page = 1;
                    }

                    $offset = max(0, ($page - 1) * $num);

                    if ($where) {
                        $where = implode(' AND ', $where);
                    } else {
                        $where = '1=1';
                    }

                    if ($total_where) {
                        $total_where = implode(' AND ', $total_where);
                    } else {
                        $total_where = '1=1';
                    }

                    $order_by = 'num';
                    $sort = sanitize_text_field(self::param('sort'));
                    if ($sort == 'type') {
                        $order_by = 'a.spider';
                    } else if (in_array($sort, ['num', 'last_visit', 'spider'])) {
                        if ($sort == 'num') {
                            $order_by = $sort;
                        } else {
                            $order_by = 'a.' . $sort;
                        }
                    }
                    $sort_order = sanitize_text_field(self::param('order'));
                    $order_by .=  $sort_order == 'asc' ? ' ASC' : ' DESC';

                    $cache_param = ['list', $where, $order_by, $total_where, $offset];
                    $cache_file = self::cache($cache_param);
                    if ($cache_file) {
                        include $cache_file;
                    }

                    $total = $db->get_var("SELECT COUNT(1) total FROM $t WHERE $total_where");

                    $sql = "SELECT SQL_CALC_FOUND_ROWS a.spider,COUNT(1) num,MAX(a.visit_date) last_visit,b.bot_type,b.bot_url,b.status AS udg FROM $t a LEFT JOIN $t2 b ON a.spider=b.name WHERE $where GROUP BY a.spider ORDER BY $order_by ";
                    //$list = $db->get_results($sql);
                    $list = $db->get_results($sql . " LIMIT $offset,$num");

                    $row_total = $db->get_var("SELECT FOUND_ROWS()");

                    // $not_found = array();
                    $bot_info = self::read_spider_info();

                    foreach ($list as $r) {
                        $r->thumb = '';
                        $bot_key = strtolower($r->spider);
                        if ($bot_info && isset($bot_info[$bot_key])) {
                            $r->thumb = $bot_info[$bot_key]['thumb'] ?? '';
                        }
                        if (!$r->thumb) {
                            $r->thumb = 'https://static.wbolt.com/wp-content/uploads/2025/02/unknown-bot.svg';
                        }
                        $r->rate = round($r->num / $total * 100, 2);
                    }
                    /*$t2 = $db->prefix . 'wb_spider';
                    $t = $db->prefix . 'wb_spider_log';*/

                    $ret = array(
                        //'sql'=>$sql,
                        'num' => $num,
                        'total' => $row_total,
                        'code' => 0,
                        'data' => $list,
                    );
                    self::cache($cache_param, $ret, 3600);
                } while (0);

                self::ajax_resp($ret);
                break;
            case 'stop':

                $ret = array('code' => 0, 'desc' => 'success', 'data' => [], 'total' => 0);
                do {

                    if (!get_option('wb_spider_analyser_ver', 0)) {
                        break;
                    }
                    $add = self::param('add', null);
                    if ($add && is_array($add)) {

                        $db = self::db();
                        $t = $db->prefix . 'wb_spider_ip';
                        $add_data = self::array_sanitize_text_field($add);
                        list($name, $ip) = $add_data;
                        $cid = self::param('cid');
                        if ($cid && in_array($cid, [11, 12, 13, 14, 15, 16, 17])) {
                            $cid = intval($cid);
                        } else {
                            $cid = 4;
                        }
                        $db->suppress_errors();
                        if (is_array($ip)) {

                            //$ret['ips'] = $ip;

                            foreach ($ip as $v) {
                                $sql = $db->prepare("INSERT INTO $t(`name`, `ip`, `status`) VALUES(%s, %s, $cid)", $name, $v);
                                if (!$db->query($sql)) {
                                    $sql = $db->prepare("UPDATE $t SET status=$cid WHERE name=%s AND ip=%s", $name, $v);
                                    $db->query($sql);
                                }
                                self::delete_log(['spider' => $name, 'ip' => $v]);
                            }
                        } else if ($ip || $name) {
                            $sql = $db->prepare("INSERT INTO $t(`name`, `ip`, `status`) VALUES(%s, %s, $cid)", $name, $ip);
                            if (!$db->query($sql)) {
                                $sql = $db->prepare("UPDATE $t SET status=$cid WHERE name=%s AND ip=%s", $name, $ip);
                                $db->query($sql);
                            }
                            self::delete_log(['spider' => $name, 'ip' => $ip]);
                        }


                        break;
                    }

                    $removes = self::param('removes', null);
                    if ($removes && is_array($removes)) {

                        $db = self::db();
                        $t = $db->prefix . 'wb_spider_ip';
                        $removes = self::array_sanitize_text_field($removes);
                        foreach ($removes as $r) {
                            $db->query("DELETE FROM $t WHERE status=15 AND " . $db->prepare("name=%s AND ip=%s", $r[0], $r[1]));
                            $sql = $db->prepare("UPDATE $t SET status=1 WHERE name=%s AND ip=%s", $r[0], $r[1]);
                            $db->query($sql);
                        }

                        $db->query("DELETE FROM $t WHERE ip = '' AND status=1");
                        $db->query("DELETE FROM $t WHERE ip LIKE '%.*' AND status=1");
                        self::clear_cache();
                        break;
                    }
                    $remove = self::param('remove', null);
                    if ($remove && is_array($remove)) {

                        $db = self::db();
                        $t = $db->prefix . 'wb_spider_ip';
                        $remove = self::array_sanitize_text_field($remove);
                        list($name, $ip) =  $remove;
                        if ($name || $ip) {
                            $db->query("DELETE FROM $t WHERE status=15 AND " . $db->prepare("name=%s AND ip=%s", $name, $ip));
                            $sql = $db->prepare("UPDATE $t SET status=1 WHERE name=%s AND ip=%s", $name, $ip);
                            $db->query($sql);
                        }

                        $db->query("DELETE FROM $t WHERE ip = '' AND status=1");
                        $db->query("DELETE FROM $t WHERE ip LIKE '%.*' AND status=1");
                        self::clear_cache();

                        break;
                    }

                    $new = self::param('new', null);
                    if ($new && is_array($new)) {

                        $db = self::db();
                        $t = $db->prefix . 'wb_spider_ip';
                        $new_data = self::array_sanitize_text_field($new);
                        list($name, $ip) =  $new_data;
                        $cid = intval(self::param('cid', 0));
                        if (!$cid || !in_array($cid, [11, 12, 13, 14, 15, 16, 17])) {
                            $cid = 4;
                        }
                        $db->suppress_errors();
                        if ($ip && is_array($ip)) {
                            //$ret['ips'] = $ip;
                            foreach ($ip as $v) {
                                $sql = $db->prepare("INSERT INTO $t(`name`, `ip`, `status`) VALUES(%s, %s, $cid)", $name, $v);
                                if (!$db->query($sql)) {
                                    $sql = $db->prepare("UPDATE $t SET status=$cid WHERE name=%s AND ip=%s", $name, $v);
                                    $db->query($sql);
                                }
                                self::delete_log(['spider' => $name, 'ip' => $v]);
                            }
                        } else if ($name && is_array($name)) {
                            foreach ($name as $v) {
                                $sql = $db->prepare("INSERT INTO $t(`name`, `ip`, `status`) VALUES(%s, %s, $cid)", $v, $ip);
                                if (!$db->query($sql)) {
                                    $sql = $db->prepare("UPDATE $t SET status=$cid WHERE name=%s AND ip=%s", $v, $ip);
                                    $db->query($sql);
                                }
                                self::delete_log(['spider' => $v, 'ip' => $ip]);
                            }
                        } else if ($ip || $name) {
                            $sql = $db->prepare("INSERT INTO $t(`name`, `ip`, `status`) VALUES(%s, %s, $cid)", $name, $ip);
                            if (!$db->query($sql)) {
                                $sql = $db->prepare("UPDATE $t SET status=$cid WHERE name=%s AND ip=%s", $name, $ip);
                                $db->query($sql);
                            }
                            self::delete_log(['spider' => $name, 'ip' => $ip]);
                        }

                        self::clear_cache();
                        break;
                    }


                    $db = self::db();
                    $t = $db->prefix . 'wb_spider_ip';
                    $query_status = intval(self::param('status', 0));
                    if ($query_status) {
                        if ($query_status == 4) {
                            $where = "(status=4 OR status>10)";
                        } else {
                            $where = "status=" . $query_status;
                        }
                    } else {
                        $where = "(status=4 OR status>10)";
                    }

                    $type = intval(self::param('type', 0));
                    if ($type) { //['全部','名称','IP','IP段','名称及IP','自定义']
                        if ($type == 5) {
                            $where .= $db->prepare(" AND `status`=%d", 15);
                        } else if ($type == 1) {
                            $where .= " AND `name` <> '' AND (`ip` = '' OR `ip` IS NULL)";
                        } else if ($type == 2) {
                            $where .= " AND `ip` <> '' AND (`name` = '' OR `name` IS NULL)";
                        } else if ($type == 3) {
                            $where .= " AND `ip` LIKE '*' AND (`name` = '' OR `name` IS NULL)";
                        } else if ($type == 4) {
                            $where .= " AND `ip` <> '' AND `name` <> ''";
                        }
                    }
                    $path = intval(self::param('path', 0));
                    if ($path) {
                        $where .= $db->prepare(" AND `status`=%d", $path);
                    }

                    $kw = sanitize_text_field(self::param('kw'));
                    if ($kw) {
                        $where .= $db->prepare(" AND (`name` LIKE %s OR `ip` LIKE %s)", '%' . $kw . '%', '%' . $kw . '%');
                    }

                    $num = absint(self::param('num', 30));
                    if (!$num) {
                        $num = 30;
                    }
                    $page = absint(self::param('page', 1));
                    if (!$page) {
                        $page = 1;
                    }

                    $offset = max(0, ($page - 1) * $num);

                    $cache_param = ['stop', $where, $offset, $num];
                    $cache_file = self::cache($cache_param);
                    if ($cache_file) {
                        include $cache_file;
                    }
                    $sql = "SELECT SQL_CALC_FOUND_ROWS * FROM $t WHERE $where LIMIT $offset,$num";

                    $list = $db->get_results($sql);

                    $total = $db->get_var("SELECT FOUND_ROWS()");
                    $ret = array(
                        //'sql'=>$sql,
                        'num' => $num,
                        'total' => $total,
                        'code' => 0,
                        'data' => $list,
                    );

                    self::cache($cache_param, $ret, 3600);
                } while (0);

                header('content-type:text/json;');
                echo wp_json_encode($ret);
                exit();
                break;
            case 'clean_log':
                $db = self::db();
                $ret = array('code' => 0, 'desc' => 'success');
                foreach (array('wb_spider_sum', 'wb_spider_visit', 'wb_spider_log', 'wb_spider_post', 'wb_spider_post_link', 'wb_spider_ip') as $v) {
                    $t = $db->prefix . $v;
                    $db->query("TRUNCATE $t");
                }
                self::clear_cache();
                self::ajax_resp($ret);
                break;


            case 'clean_all':
                $ret = array('code' => 0, 'desc' => 'fail');
                $db = self::db();
                $t = $db->prefix . 'wb_spider_ip';
                $db->query("DELETE FROM $t  WHERE (status=4 OR status>10)");
                $db->query("DELETE FROM $t WHERE ip = '' AND status=1");
                $db->query("DELETE FROM $t WHERE ip LIKE '%.*' AND status=1");
                $ret['desc'] = 'success';
                self::clear_cache();
                self::ajax_resp($ret);
                break;
            case 'update_setting':

                $data = WP_Spider_Analyser_Admin::update_cnf();
                $ret = array('code' => 0, 'desc' => 'success', 'data' => $data);
                self::ajax_resp($ret);

                break;

            case 'verify':
                $ret = ['code' => 1, 'desc' => 'fail'];
                $param = array(
                    'code' => sanitize_text_field(self::param('key')),
                    'host' => sanitize_text_field(self::param('host')),
                    'ver' => 'spider-analyser',
                );
                $err = '';
                do {
                    if (empty($param['code']) || empty($param['host'])) {
                        $err = _x('不合法请求，参数无效', 'ajax返回提示', WB_SPA_DM);
                        break;
                    }

                    $http = wp_remote_post('https://www.wbolt.com/wb-api/v1/verify', array('timeout' => 30, 'sslverify' => false, 'body' => $param, 'headers' => array('referer' => home_url()),));
                    if (is_wp_error($http)) {
                        $err = _x('校验失败，请稍后再试（错误代码001）', 'ajax返回提示', WB_SPA_DM) . '[' . $http->get_error_message() . ']';
                        break;
                    }

                    if ($http['response']['code'] != 200) {
                        $err = _x('校验失败，请稍后再试（错误代码001）', 'ajax返回提示', WB_SPA_DM) . '[' . $http['response']['code'] . ']';
                        break;
                    }

                    $body = $http['body'];

                    if (empty($body)) {
                        $err = _x('发生异常错误，联系技术支持（错误代码 010）', 'ajax返回提示', WB_SPA_DM);
                        break;
                    }

                    $data = json_decode($body, true);

                    if (empty($data)) {
                        $err = _x('发生异常错误，联系技术支持（错误代码011）', 'ajax返回提示', WB_SPA_DM);
                        break;
                    }
                    if (empty($data['data'])) {
                        $err = _x('校验失败，请稍后再试（错误代码004)', 'ajax返回提示', WB_SPA_DM);
                        break;
                    }
                    if ($data['code']) {
                        $err_code = $data['data'];
                        switch ($err_code) {
                            case 100:
                            case 101:
                            case 102:
                            case 103:
                                $err = _x('插件配置参数错误，联系技术支持，错误代码：', 'ajax返回提示', WB_SPA_DM) .  $err_code;
                                break;
                            case 200:
                                $err = _x('输入key无效，请输入正确key（错误代码200）', 'ajax返回提示', WB_SPA_DM);
                                break;
                            case 201:
                                $err = _x('key使用次数超出限制范围（错误代码201）', 'ajax返回提示', WB_SPA_DM);
                                break;
                            case 202:
                            case 203:
                            case 204:
                                $err = _x('校验服务器异常，联系技术支持，错误代码：', 'ajax返回提示', WB_SPA_DM) .  $err_code;
                                break;
                            default:
                                $err = _x('发生异常错误，联系技术支持，错误代码：', 'ajax返回提示', WB_SPA_DM) .  $err_code;
                        }

                        break;
                    }

                    update_option('wb_spider_analyser_ver', $data['v'], false);
                    update_option('wb_spider_analyser_cnf_' . $data['v'], $data['data'], false);


                    $ret['code'] = 0;
                    $ret['desc'] = 'success';
                } while (false);
                if ($err) {
                    $ret['desc'] = $err;
                }
                self::ajax_resp($ret);
                break;

            case 'reset':
                $ver = get_option('wb_spider_analyser_ver', 0);
                if (!$ver) {
                    self::ajax_resp(array('code' => 1, 'data' => 'not verify'));
                    exit(0);
                }
                delete_option('wb_spider_analyser_ver');
                delete_option('wb_spider_analyser_cnf_' . $ver);

                $ret = ['code' => 0, 'desc' => 'success'];
                self::ajax_resp($ret);
                exit(0);
                break;

            case 'options':
                $ver = get_option('wb_spider_analyser_ver', 0);
                $cnf = '';
                if ($ver) {
                    $cnf = get_option('wb_spider_analyser_cnf_' . $ver, '');
                }
                self::ajax_resp(['o' => $cnf]);
                break;

            case 'get_localize':
                $ret = [
                    'code' => 0,
                    'desc' => 'success'
                ];

                $ret['data'] = self::localize_ajax_handle();

                self::ajax_resp($ret);
                break;

            case 'get_comparison':
                $ret = [
                    'code' => 0,
                    'desc' => 'success',
                    'data' => WBP::wb_get_json_fields('comparison.json', __DIR__ . '/json/')
                ];

                self::ajax_resp($ret);
                break;
        }
    }

    public static function spider_analyser_ajax()
    {
        // global $wpdb;

        $op = sanitize_text_field(self::param('op'));
        if (!$op) {
            $op = sanitize_text_field(self::param('op', '', 'g'));
        }

        if (!$op) {
            return;
        }
        $arrow = [
            'chk_ver',
            'promote',
            'chart_data',
            'top_url',
            'top_post',
            'top_spider',
            'summary',
            'code',
            'log',
            'log_cnf',
            'stop_cnf',
            'path_cnf',
            'path',
            'ip',
            'post',
            'get_setting',
            'down_log',
            'spider_history'
        ];
        if (!in_array($op, $arrow)) {
            return;
        }
        if (!current_user_can('manage_options')) {
            self::ajax_resp(['code' => 1, 'desc' => 'deny']);
            return;
        }

        switch ($op) {
            case 'chk_ver':
                $http = wp_remote_get('https://www.wbolt.com/wb-api/v1/themes/checkver?code=spider-analyser&ver=' . WP_SPIDER_ANALYSER_VERSION . '&chk=1', array('sslverify' => false, 'headers' => array('referer' => home_url()),));

                if (wp_remote_retrieve_response_code($http) == 200) {
                    echo esc_html(wp_remote_retrieve_body($http));
                }

                exit();
                break;
            case 'promote':

                $ret = ['code' => 0, 'desc' => 'success', 'data' => ''];
                $data = [];
                $expired = 0;
                $update_cache = false;
                do {
                    $option = get_option('wb_spider_analyser_promote', null);
                    do {
                        if (!$option || !is_array($option)) {
                            break;
                        }

                        if (!isset($option['expired']) || empty($option['expired'])) {
                            break;
                        }

                        $expired = intval($option['expired']);
                        if ($expired < current_time('U')) {
                            $expired = 0;
                            break;
                        }

                        if (!isset($option['data']) || empty($option['data'])) {
                            break;
                        }

                        $data = $option['data'];
                    } while (0);

                    if ($data) {
                        $ret['data'] = $data;
                        break;
                    }
                    if ($expired) {
                        break;
                    }

                    $update_cache = true;
                    $param = ['c' => 'spider-analyser', 'h' => $_SERVER['HTTP_HOST']];
                    $http = wp_remote_post('https://www.wbolt.com/wb-api/v1/promote', array('sslverify' => false, 'body' => $param, 'headers' => array('referer' => home_url()),));

                    if (is_wp_error($http)) {
                        $ret['error'] = $http->get_error_message();
                        break;
                    }
                    if (wp_remote_retrieve_response_code($http) !== 200) {
                        $ret['error-code'] = '201';
                        break;
                    }
                    $body = trim(wp_remote_retrieve_body($http));
                    if (!$body) {
                        $ret['empty'] = 1;
                        break;
                    }
                    $data = json_decode($body, true);
                    if (!$data) {
                        $ret['json-error'] = 1;
                        $ret['body'] = $body;
                        break;
                    }
                    //data = [title=>'',image=>'','expired'=>'2021-05-12','url=>'']
                    $ret['data'] = $data;
                    if (isset($data['expired']) && $data['expired'] && preg_match('#^\d{4}-\d{2}-\d{2}$#', $data['expired'])) {
                        $expired = strtotime($data['expired'] . ' 23:50:00');
                    }
                } while (0);
                if ($update_cache) {
                    if (!$expired) {
                        $expired = current_time('U') + 21600;
                    }
                    update_option('wb_spider_analyser_promote', ['data' => $ret['data'], 'expired' => $expired], false);
                }
                self::ajax_resp($ret);
                break;
            case 'chart_data':
                // $ret = array('code' => 0, 'desc' => 'success');
                $spider = sanitize_text_field(self::param('spider'));
                $day = absint(self::param('day', 0));
                $type = absint(self::param('type', 1));

                $cache_param = ['op' => 'chart_data', 'day' => $day, 'type' => $type, 'spider' => $spider];
                $cache_file = self::cache($cache_param);
                if ($cache_file) {
                    include $cache_file;
                }

                $data = self::chart_data($day, $type, 0, $spider);
                //$compare_day = $day>0?$day * 2 : 1;
                /*$compare = [];
                if ($type != 3) {

                }*/
                $compare = self::chart_data($day, $type, 1, $spider);

                $ret = array(
                    //'sql'=>$sql,
                    'code' => 0,
                    'data' => $data,
                    'compare' => $compare,
                );

                self::cache($cache_param, $ret, 3600); //60*60

                self::ajax_resp($ret);
                break;
            case 'code':

                $spider = sanitize_text_field(self::param('spider'));
                $day = absint(self::param('day', 0));
                $cache_param = ['op' => 'code', 'day' => $day, 'spider' => $spider];
                $cache_file = self::cache($cache_param);
                if ($cache_file) {
                    include $cache_file;
                }

                $db = self::db();
                $time = strtotime(current_time('mysql'));
                if ($day) {
                    $time = $time - 86400 * $day;
                }

                $ymd = gmdate('Y-m-d', $time);
                $t = $db->prefix . 'wb_spider_log';

                if ($day > 2) {
                    $op = '>=';
                } else {
                    $op = '=';
                }
                $where_more = '';
                if ($spider) {
                    $where_more = $db->prepare(" AND spider = %s", $spider);
                }
                $sql = "SELECT COUNT(1) num,code FROM $t WHERE DATE_FORMAT(visit_date,'%Y-%m-%d') $op '$ymd' $where_more GROUP BY code ORDER BY num DESC LIMIT 10";

                $list = $db->get_results($sql);

                $ret = array(
                    'code' => 0,
                    'data' => $list,
                );

                self::cache($cache_param, $ret, 3600); //60*60

                self::ajax_resp($ret);
                break;
            case 'top_url':

                $day = absint(self::param('day', 0));
                $cache_param = ['op' => 'top_url', 'day' => $day];
                $cache_file = self::cache($cache_param);
                if ($cache_file) {
                    include $cache_file;
                }

                $db = self::db();
                $time = strtotime(current_time('mysql'));
                if ($day) {
                    $time = $time - 86400 * $day;
                }
                $ymd = gmdate('Y-m-d', $time);
                $t = $db->prefix . 'wb_spider_log';
                $op = '=';
                if ($day > 1) {
                    $op = '>=';
                }

                $total = $db->get_var("SELECT COUNT(1) total FROM $t WHERE DATE_FORMAT(visit_date,'%Y-%m-%d') $op '$ymd'");

                $sql = "SELECT COUNT(1) num,url FROM (SELECT * FROM  $t WHERE DATE_FORMAT(visit_date,'%Y-%m-%d') $op '$ymd') AS a GROUP BY url_md5 ORDER BY num DESC LIMIT 10";

                $list = $db->get_results($sql);
                $data = [];

                foreach ($list as $r) {
                    $r->rate = round($r->num / $total * 100, 2);
                    $data[] = $r;
                }

                $ret = array(
                    //'sql'=>$sql,
                    'code' => 0,
                    'data' => $data,
                );

                self::cache($cache_param, $ret, 3600);

                self::ajax_resp($ret);
                break;


            case 'top_spider':

                $day = absint(self::param('day', 0));
                $cache_param = ['op' => 'top_spider', 'day' => $day];
                $cache_file = self::cache($cache_param);
                if ($cache_file) {
                    include $cache_file;
                }

                $db = self::db();
                $time = strtotime(current_time('mysql'));
                if ($day) {
                    $time = $time - 86400 * $day;
                }
                $ymd = gmdate('Y-m-d', $time);
                $t2 = $db->prefix . 'wb_spider';
                $t = $db->prefix . 'wb_spider_log';
                $op = '=';
                if ($day > 1) {
                    $op = '>=';
                }
                $total = $db->get_var("SELECT COUNT(1) total FROM $t WHERE DATE_FORMAT(visit_date,'%Y-%m-%d') $op '$ymd'");

                //LEFT JOIN $t2 b ON a.spider=b.name
                $sql = "SELECT COUNT(1) num,a.spider,1 AS udg FROM (SELECT  * FROM $t  WHERE DATE_FORMAT(visit_date,'%Y-%m-%d') $op '$ymd') AS a GROUP BY a.spider ORDER BY num DESC LIMIT 10";

                $list = $db->get_results($sql);
                $data = [];

                foreach ($list as $r) {
                    $r->rate = round($r->num / $total * 100, 2);
                    $data[] = $r;
                }

                $ret = array(
                    //'sql'=>$sql,
                    'code' => 0,
                    'data' => $data,
                );
                self::cache($cache_param, $ret, 3600);

                self::ajax_resp($ret);
                break;

            case 'summary':

                $cache_param = ['op' => 'summary'];
                $cache_file = self::cache($cache_param);
                if ($cache_file) {
                    include $cache_file;
                }

                $db = self::db();
                $ymd = current_time('Y-m-d');
                $t = $db->prefix . 'wb_spider_log';
                //蜘蛛数
                $data = [
                    '0' => ['spider' => 0, 'url' => 0, 'avg_url' => 0],
                    '1' => ['spider' => 0, 'url' => 0, 'avg_url' => 0],
                    '7' => ['spider' => 0, 'url' => 0, 'avg_url' => 0],
                    '30' => ['spider' => 0, 'url' => 0, 'avg_url' => 0]
                ];


                $row = $db->get_row("SELECT COUNT(1) url,COUNT(DISTINCT spider) spider FROM $t WHERE DATE_FORMAT(visit_date,'%Y-%m-%d')='$ymd' ");

                if ($row) {
                    $data['0']['spider'] = $row->spider;
                    $data['0']['url'] = $row->url;
                    $data['0']['avg_url'] = $row->spider > 0 ? ceil($row->url / $row->spider) : 0;
                }

                foreach ($data as $k => $r) {
                    if (!$k) continue;
                    $day = intval($k);
                    $ymd = gmdate('Y-m-d', strtotime(current_time('mysql')) - 86400 * $day);
                    $op = '=';
                    if ($day > 1) {
                        $op = '>=';
                    }
                    $row = $db->get_row("SELECT COUNT(1) url,COUNT(DISTINCT spider) spider FROM $t WHERE DATE_FORMAT(visit_date,'%Y-%m-%d') $op '$ymd' ");

                    if ($row) {
                        $data[$k]['spider'] = $row->spider;
                        $data[$k]['url'] = $row->url;
                        $data[$k]['avg_url'] = $row->spider > 0 ? ceil($row->url / $row->spider) : 0;
                    }
                }
                /*




                $ymd = gmdate('Y-m-d', strtotime(current_time('mysql')) - 86400 * 7);
                $row = $db->get_row("SELECT COUNT(1) url FROM $t WHERE DATE_FORMAT(visit_date,'%Y-%m-%d')>='$ymd' ");
                if ($row) {
                    $data['7']['url'] = ceil($row->url / 7);
                }
                $row2 = $db->get_row("SELECT SUM(num) spider FROM (SELECT COUNT(DISTINCT  spider) num,DATE_FORMAT(visit_date,'%Y-%m-%d') ymd FROM $t WHERE DATE_FORMAT(visit_date,'%Y-%m-%d')>='$ymd' GROUP BY ymd) as tmp ");
                if ($row2) {
                    $data['7']['spider'] = ceil($row2->spider / 7);
                }



                $ymd = gmdate('Y-m-d', strtotime(current_time('mysql')) - 86400 * 30);
                $row = $db->get_row("SELECT COUNT(1) url FROM $t WHERE DATE_FORMAT(visit_date,'%Y-%m-%d')>='$ymd' ");
                if ($row) {
                    $data['30']['url'] = ceil($row->url / 30);
                }
                $row3 = $db->get_row("SELECT SUM(num) spider FROM (SELECT COUNT(DISTINCT  spider) num,DATE_FORMAT(visit_date,'%Y-%m-%d') ymd FROM $t WHERE DATE_FORMAT(visit_date,'%Y-%m-%d')>='$ymd' GROUP BY ymd) as tmp ");
                if ($row3) {
                    $data['30']['spider'] = ceil($row3->spider / 30);
                }*/
                //$data['7']['avg_url'] = $data[2]['spider'] > 0 ? ceil($data[2]['url'] / $data[2]['spider']) : 0;
                //$data['30']['avg_url'] = $data[2]['spider'] > 0 ? ceil($data[2]['url'] / $data[2]['spider']) : 0;


                $ret = array(
                    //'sql'=>$sql,
                    'code' => 0,
                    'data' => $data,
                );

                self::cache($cache_param, $ret, 3600);

                self::ajax_resp($ret);

                break;

            case 'log':
                $db = self::db();
                $q = self::array_sanitize_text_field(self::param('q', []));
                $day = isset($q['day']) ? intval($q['day']) : -1;
                $t = $db->prefix . 'wb_spider_log';
                $t2 = $db->prefix . 'wb_spider';
                $where = array();
                if ($day > -1) {
                    $time = strtotime(current_time('mysql'));
                    if ($day) {
                        $time = $time - 86400 * $day;
                    }
                    $ymd = gmdate('Y-m-d', $time);

                    $op = '=';
                    if ($day > 1) {
                        $op = '>=';
                    }

                    $where[] = "DATE_FORMAT(a.visit_date,'%Y-%m-%d') $op '$ymd'";
                }

                if (!empty($q['spider'])) {
                    $where[] = $db->prepare("a.spider=%s", $q['spider']);
                }
                if (!empty($q['code'])) {
                    if ($q['code'] == '301/302') {
                        $where[] = "(a.code='301' OR a.code='302')";
                    } else {
                        $where[] = $db->prepare("a.code=%s", $q['code']);
                    }
                }
                if (!empty($q['url'])) {
                    $where[] = $db->prepare("a.url REGEXP %s", preg_quote($q['url']));
                }
                if (!empty($q['ip'])) {
                    $where[] = $db->prepare("a.visit_ip REGEXP %s", preg_quote($q['ip']));
                }
                $num = absint(self::param('num', 50));
                if (!$num) {
                    $num = 50;
                }
                $page = absint(self::param('page', 1));
                if (!$page) {
                    $page = 1;
                }

                $offset = max(0, ($page - 1) * $num);

                if ($where) {
                    $where = implode(' AND ', $where);
                } else {
                    $where = '1=1';
                }

                $cache_param = ['log', $where, $offset, $num];
                $cache_file = self::cache($cache_param);
                if ($cache_file) {
                    include $cache_file;
                }

                $sql = "SELECT SQL_CALC_FOUND_ROWS a.*,b.status AS udg FROM $t a left join $t2 b on a.spider=b.name WHERE $where ORDER BY a.id DESC LIMIT $offset,$num";
                $list = $db->get_results($sql);

                $total = $db->get_var("SELECT FOUND_ROWS()");
                $ret = array(
                    //'sql'=>$sql,
                    'num' => $num,
                    'total' => $total,
                    'code' => 0,
                    'data' => $list,
                );
                self::cache($cache_param, $ret, 3600);

                self::ajax_resp($ret);
                break;

            case 'log_cnf':
                $cache_param = ['log_cnf'];
                $cache_file = self::cache($cache_param);
                if ($cache_file) {
                    include $cache_file;
                }

                $ret['data'] = self::spider_log();
                self::cache($cache_param, $ret, 3600);

                self::ajax_resp($ret);
                break;

            case 'stop_cnf':
                $cache_param = ['stop_cnf'];
                $cache_file = self::cache($cache_param);
                if ($cache_file) {
                    include $cache_file;
                }

                $ret['data'] = self::spider_list_stop();
                self::cache($cache_param, $ret, 3600);

                self::ajax_resp($ret);
                break;

            case 'path_cnf':
                $cache_param = ['path_cnf'];
                $cache_file = self::cache($cache_param);
                if ($cache_file) {
                    include $cache_file;
                }
                $ret['data'] = self::spider_path();
                self::cache($cache_param, $ret, 3600);

                self::ajax_resp($ret);

                break;

            case 'path':
                $ret = array('code' => 0, 'desc' => 'success', 'data' => []);
                do {

                    $db = self::db();
                    $q = self::array_sanitize_text_field(self::param('q', []));
                    $day = isset($q['day']) ? intval($q['day']) : -1;
                    $t = $db->prefix . 'wb_spider_log';
                    $where = array();
                    if ($day > -1) {
                        $time = strtotime(current_time('mysql'));
                        if ($day) {
                            $time = $time - 86400 * $day;
                        }
                        $ymd = gmdate('Y-m-d', $time);

                        $op = '=';
                        if ($day > 1) {
                            $op = '>=';
                        }

                        $where[] = "DATE_FORMAT(visit_date,'%Y-%m-%d') $op '$ymd'";
                    }
                    $is_chart = sanitize_text_field(self::param('chart'));
                    if ($is_chart) {
                        if ($where) {
                            $where = implode(' AND ', $where);
                        } else {
                            $where = '1=1';
                        }
                        $cache_param = ['path', $is_chart, $where];
                        $cache_file = self::cache($cache_param);
                        if ($cache_file) {
                            include $cache_file;
                        }


                        $sql = "SELECT url_type,COUNT(1) num FROM (SELECT * FROM $t WHERE $where) AS a GROUP  BY url_type ";
                        $list = $db->get_results($sql);


                        $url_types = WP_Spider_Analyser_Admin::url_types();
                        $cnf = self::cnf();
                        if ($cnf['user_rule']) foreach ($cnf['user_rule'] as $r) {
                            $url_types[$r['name']] = $r['name'];
                        }
                        $data = [];
                        foreach ($url_types as $k => $v) {
                            $data[$k] = ['value' => 0, 'name' => $v];
                        }
                        foreach ($list as $r) {
                            $data[$r->url_type]['value'] = $r->num;
                        }


                        $ret['data'] = array_values($data);
                        self::cache($cache_param, $ret, 3600);
                        break;
                    }



                    if (!empty($q['spider'])) {
                        $where[] = $db->prepare("spider=%s", $q['spider']);
                    }
                    if (!empty($q['code'])) {
                        $where[] = $db->prepare("code=%s", $q['code']);
                    }
                    if (!empty($q['url'])) {
                        $where[] = $db->prepare("url REGEXP %s", preg_quote($q['url']));
                    }
                    if (!empty($q['ip'])) {
                        $where[] = $db->prepare("visit_ip REGEXP %s", preg_quote($q['ip']));
                    }
                    if (!empty($q['type'])) {
                        $where[] = $db->prepare("url_type=%s", $q['type']);
                    }
                    $num = absint(self::param('num', 50));
                    if (!$num) {
                        $num = 50;
                    }
                    $page = absint(self::param('page', 1));
                    if (!$page) {
                        $page = 1;
                    }


                    $offset = max(0, ($page - 1) * $num);

                    if ($where) {
                        $where = implode(' AND ', $where);
                    } else {
                        $where = '1=1';
                    }

                    $order_by = 'num';
                    $sort = sanitize_text_field(self::param('sort'));
                    if (in_array($sort, ['num', 'url_type', 'url'])) {
                        $order_by = $sort;
                    }
                    $sort_by = sanitize_text_field(self::param('order'));
                    $order_by .=  $sort_by == 'asc' ? ' ASC' : ' DESC';


                    $cache_param = ['path', $where, $order_by, $offset, $num];
                    $cache_file = self::cache($cache_param);
                    if ($cache_file) {
                        include $cache_file;
                    }

                    $sum = $db->get_var("SELECT COUNT(1) num FROM $t WHERE $where");

                    $sql = "SELECT SQL_CALC_FOUND_ROWS COUNT(1) num,url,url_type,'' type,ROUND(COUNT(1)/$sum * 100,2) percent 
                                FROM (SELECT * FROM $t WHERE $where ) AS a GROUP BY url_md5 ORDER BY $order_by LIMIT $offset,$num";

                    $list = $db->get_results($sql);

                    $total = $db->get_var("SELECT FOUND_ROWS()");
                    $ret = array(
                        //'sql'=>$sql,
                        'num' => $num,
                        'total' => $total,
                        'code' => 0,
                        'data' => $list,
                    );
                    self::cache($cache_param, $ret, 3600);
                } while (0);


                self::ajax_resp($ret);
                break;

            case 'ip':
                $ret = array('code' => 0, 'desc' => 'success', 'data' => [], 'total' => 0);
                do {
                    if (!get_option('wb_spider_analyser_ver', 0)) {
                        break;
                    }
                    $db = self::db();
                    $q = self::array_sanitize_text_field(self::param('q', []));
                    $day = isset($q['day']) ? intval($q['day']) : -1;
                    $t2 = $db->prefix . 'wb_spider';
                    $t = $db->prefix . 'wb_spider_log';
                    $where = array();
                    if ($day > -1) {
                        $time = strtotime(current_time('mysql'));
                        if ($day) {
                            $time = $time - 86400 * $day;
                        }
                        $ymd = gmdate('Y-m-d', $time);

                        $op = '=';
                        if ($day > 1) {
                            $op = '>=';
                        }

                        $where[] = "DATE_FORMAT(a.visit_date,'%Y-%m-%d') $op '$ymd'";
                    }

                    if (!empty($q['spider'])) {
                        $where[] = $db->prepare("a.spider=%s", $q['spider']);
                    }
                    if (!empty($q['name'])) {
                        $where[] = $db->prepare("a.spider REGEXP %s", preg_quote($q['name']));
                    }

                    $num = absint(self::param('num', 50));
                    if (!$num) {
                        $num = 50;
                    }
                    $page = absint(self::param('page', 1));
                    if (!$page) {
                        $page = 1;
                    }

                    $offset = max(0, ($page - 1) * $num);

                    if ($where) {
                        $where = implode(' AND ', $where);
                    } else {
                        $where = '1=1';
                    }

                    $order_by = 'num';
                    $sort = sanitize_text_field(self::param('sort'));
                    if (in_array($sort, ['num'])) {
                        $order_by = $sort;
                    } else if (in_array($sort, ['ip_range', 'spider'])) {
                        $order_by = 'a.' . $sort;
                    }
                    $sort_by = sanitize_text_field(self::param('order'));
                    $order_by .=  $sort_by == 'asc' ? ' ASC' : ' DESC';

                    $cache_param = ['ip', $where, $order_by, $offset, $num];
                    $cache_file = self::cache($cache_param);
                    if ($cache_file) {
                        include $cache_file;
                    }
                    $sum = $db->get_var("SELECT COUNT(1) num FROM $t a WHERE $where");

                    $sql = "SELECT SQL_CALC_FOUND_ROWS COUNT(1) num,a.spider,SUBSTRING_INDEX(a.visit_ip,'.',3) ip_range,ROUND(COUNT(1)/$sum * 100,2) percent,b.status AS udg 
                            FROM (SELECT * FROM $t a WHERE $where) AS a LEFT JOIN $t2 b ON a.spider=b.name 
                            GROUP BY a.spider,ip_range ORDER BY $order_by LIMIT $offset,$num";

                    //echo $sql;exit();
                    $list = $db->get_results($sql);

                    $total = $db->get_var("SELECT FOUND_ROWS()");
                    $ret = array(
                        //'sql'=>$sql,
                        'num' => $num,
                        'total' => $total,
                        'code' => 0,
                        'data' => $list,
                    );
                    self::cache($cache_param, $ret, 3600);
                } while (0);

                self::ajax_resp($ret);
                break;

            case 'post':
                $ret = array('code' => 0, 'desc' => 'success', 'data' => [], 'total' => 0);
                do {
                    if (!get_option('wb_spider_analyser_ver', 0)) {
                        break;
                    }
                    $dsb = self::param('dsb') ? 1 : 0;
                    $q = self::array_sanitize_text_field(self::param('q', []));
                    $day = isset($q['day']) ? intval($q['day']) : -1;

                    //if($dsb){

                    //}
                    $db = self::db();

                    $t = $db->prefix . 'wb_spider_log';
                    $t2 = $db->prefix . 'wb_spider_post';
                    $where = array();
                    $where2 = array();
                    $where[] = "url_type='post'";
                    if ($day > -1) {
                        $time = strtotime(current_time('mysql'));
                        if ($day) {
                            $time = $time - 86400 * $day;
                        }
                        $ymd = gmdate('Y-m-d', $time);

                        $op = '=';
                        if ($day > 1) {
                            $op = '>=';
                        }

                        $where[] = "DATE_FORMAT(visit_date,'%Y-%m-%d') $op '$ymd'";
                    }

                    if (!empty($q['spider'])) {
                        $where[] = $db->prepare("spider=%s", $q['spider']);
                    }
                    if (!empty($q['name'])) {
                        $kw = str_replace(home_url('/'), '/', $q['name']);
                        //$where[] = $db->prepare("CONCAT_WS('',a.url,c.post_title) REGEXP %s",preg_quote($kw));
                        $where[] = $db->prepare("url LIKE %s", "%$kw%");
                        $where2[] = $db->prepare("c.post_title LIKE %s", "%$kw%");;
                    }

                    if (!empty($q['type'])) {
                        $type = $q['type'];
                        if ($q['type'] == 3) {
                            $type = 0;
                        }
                        $where2[] = $db->prepare("b.status=%s", $type);
                    }

                    $num = absint(self::param('num', 50));
                    if (!$num) {
                        $num = 50;
                    }
                    $page = absint(self::param('page', 1));
                    if (!$page) {
                        $page = 1;
                    }

                    $offset = max(0, ($page - 1) * $num);

                    if ($where) {
                        $where = implode(' AND ', $where);
                    } else {
                        $where = '1=1';
                    }
                    if ($where2) {
                        $where2 = ' AND ' . implode(' AND ', $where2);
                    } else {
                        $where2 = '';
                    }

                    $order_by = 'num';
                    $sort = self::param('sort');
                    if (in_array($sort, ['num', 'url_in', 'url_out', 'post_date'])) {
                        $order_by = $sort;
                    }
                    $sort_by = sanitize_text_field(self::param('order'));
                    $order_by .=  $sort_by == 'asc' ? ' ASC' : ' DESC';


                    $cache_param = ['post', $where, $where2, $order_by, $offset, $num];
                    $cache_file = self::cache($cache_param);
                    if ($cache_file) {
                        include $cache_file;
                    }

                    $sql = "SELECT SQL_CALC_FOUND_ROWS COUNT(1) num,a.url_md5,a.url,b.url_in,b.url_out,b.status,b.post_id,c.post_title,c.post_date 
                                FROM (SELECT * FROM $t WHERE $where ) AS a,$t2 b,$db->posts c 
                                WHERE a.url_md5=b.url_md5 AND b.post_id=c.ID $where2 ";
                    $sql .= " GROUP BY a.url_md5 ORDER BY $order_by LIMIT $offset,$num";

                    $list = $db->get_results($sql);
                    $total = $db->get_var("SELECT FOUND_ROWS()");
                    foreach ($list as $k => $r) {
                        $list[$k]->post_url = get_permalink($r->post_id);
                        $list[$k]->post_edit_url = get_edit_post_link($r->post_id, 'url');
                    }


                    $ret = array(
                        //'sql'=>$sql,
                        'num' => $num,
                        'total' => $total,
                        'code' => 0,
                        'data' => $list,
                    );

                    //if($dsb){
                    self::cache($cache_param, $ret, 3600);
                    //}
                } while (0);

                self::ajax_resp($ret);
                break;


            case 'get_setting':

                $ret = array('code' => 0, 'desc' => 'success', 'data' => []);
                $ret['data'] = WP_Spider_Analyser_Admin::wp_spider_analyser_conf();

                self::ajax_resp($ret);
                break;

            case 'down_log':

                $db = self::db();
                set_time_limit(0);
                ini_set('memory_limit', '500M');
                $filename = 'spider-log.txt';
                header('Content-Type: application/application/octet-stream	');
                header('Content-Disposition: attachment;filename="' . $filename . '"');
                header('Cache-Control: max-age=0');
                header('Cache-Control: max-age=1');
                header('Expires: Mon, 26 Jul 1997 05:00:00 GMT'); // Date in the past
                header('Last-Modified: ' . gmdate('D, d M Y H:i:s') . ' GMT'); // always modified
                header('Cache-Control: cache, must-revalidate'); // HTTP/1.1
                header('Pragma: public'); // HTTP/1.0
                $fileHandle = fopen('php://output', 'wb+');
                $page = -1;
                $num = 1000;
                $t = $db->prefix . 'wb_spider_log';
                do {
                    $page++;
                    $offset = $num * $page;
                    $list = $db->get_results("SELECT * FROM $t WHERE 1 LIMIT $offset,$num");
                    if (!$list) {
                        break;
                    }
                    foreach ($list as $r) {
                        fwrite($fileHandle, wp_json_encode($r) . "\n");
                    }
                } while (1);

                fclose($fileHandle);
                exit();
                break;
            case 'spider_history':
                $ret = array('code' => 0, 'desc' => 'success');
                $post_id = absint(self::param('post_id', 0));
                $day = intval(self::param('day', -1));
                $list = array();
                do {
                    if (!$post_id) {
                        break;
                    }
                    $url = get_permalink($post_id);
                    $url = str_replace(home_url(), '', $url);
                    $url_md5 = md5($url);
                    $limit = '';
                    $cache_param = ['spider_history', $url_md5, $limit, $day];
                    $cache_file = self::cache($cache_param);
                    if ($cache_file) {
                        include $cache_file;
                    }
                    $db = self::db();

                    $sql = "SELECT `spider`, `visit_date`, `visit_ip` FROM `{$db->prefix}wb_spider_log` WHERE `url_md5`=%s ";
                    $sql = $db->prepare($sql, $url_md5);

                    if ($day > -1) {
                        $time = strtotime(current_time('mysql'));
                        if ($day) {
                            $time = $time - 86400 * $day;
                        }
                        $ymd = gmdate('Y-m-d', $time);

                        $op = '=';
                        if ($day > 1) {
                            $op = '>=';
                        }

                        $sql .= " AND DATE_FORMAT(visit_date,'%Y-%m-%d') $op '$ymd'";
                    }


                    $ret['data'] = $db->get_results($sql . " ORDER BY visit_date DESC $limit");
                    self::cache($cache_param, $ret, 3600);
                } while (0);


                self::ajax_resp($ret);

                break;
        }
    }

    public static function delete_log($param)
    {
        // global $wpdb;

        $db = self::db();
        $t = $db->prefix . 'wb_spider_log';
        $where = [];
        if (isset($param['spider']) && $param['spider']) {
            $where[] = $db->prepare("spider=%s", $param['spider']);
        }
        if (isset($param['ip']) && $param['ip']) {

            if (strpos($param['ip'], '*') > 0) {
                $where[] = $db->prepare("visit_ip LIKE  %s", str_replace('*', '%', $param['ip']));
            } else {
                $where[] = $db->prepare("visit_ip = %s", $param['ip']);
            }
            $t_ip = $db->prefix . 'wb_spider_ip';
            $db->query("DELETE FROM $t_ip WHERE status=2 AND " . $db->prepare("ip=%s", $param['ip']));
        }
        if ($where) {
            $db->query("DELETE FROM $t WHERE " . implode(' AND ', $where));
        }
        self::clear_cache();
    }

    public static function txt_log($msg, $mod = null)
    {

        if (!self::$debug) {
            return;
        }


        if (is_array($msg) || is_object($msg)) {
            $msg = wp_json_encode($msg);
        }

        if ($mod) {
            $msg = '[' . $mod . '] ' . $msg;
        }
        error_log('[' . current_time('mysql') . '] ' . $msg . "\n", 3, WP_SPIDER_ANALYSER_PATH . '/#log/running.log');
    }

    public static function plugin_activate()
    {
        if (!is_dir(WP_SPIDER_ANALYSER_PATH . '/#log/')) {
            mkdir(WP_SPIDER_ANALYSER_PATH . '/#log/', 0755);
        }
        self::set_up();

        self::upgrade();
    }

    public static function plugin_deactivate()
    {
        wp_clear_scheduled_hook('wb_wp_spider_trace_cron');
        wp_clear_scheduled_hook('wp_wb_spider_analyser_cron');
    }

    public static function wp_wb_spider_analyser_cron()
    {
        $cnf = self::cnf();
        self::txt_log('start do action wp_wb_spider_analyser_cron');

        self::log2db($cnf['log_update'], 0);

        self::check_404();


        if (current_time('H') == '01') {
            self::calc_log(gmdate('Y-m-d', strtotime(current_time('Y-m-d 00:00:00') - 1)));
            self::sync_wb_spider();
        }

        self::calc_log();

        self::del_old_log();

        self::set_url_type();

        if (get_option('wb_spider_analyser_ver', 0)) {
            self::cron_set_spider_post();

            self::scan_post_inner_link();
            self::update_post_url_num();

            self::check_ip();

            self::set_auto_deny();
        }


        self::txt_log('finnish do action wp_wb_spider_analyser_cron');
    }

    public static function log2db($type, $force = 0)
    {
        self::txt_log('log2db ' . $type, '定时任务');
        if ($type == 'db') {
            return;
        }
        if ($type == 'hour') {
            $dir = glob(WP_SPIDER_ANALYSER_PATH . '/#log/log-*.txt');
            $match = '#log-' . current_time('dH') . '\.txt$#';
            if ($dir) foreach ($dir as $txt) {
                if (!$force && preg_match($match, $txt)) {
                    continue;
                }
                self::read_txt($txt);
            }
        } else if ($type == 'day') {
            $dir = glob(WP_SPIDER_ANALYSER_PATH . '/#log/log-*.txt');
            $match = '#log-' . current_time('d') . '[0-9]{2}\.txt$#';
            if ($dir) foreach ($dir as $txt) {
                if (!$force && preg_match($match, $txt)) {
                    continue;
                }
                self::read_txt($txt);
            }
        }
        self::txt_log('log2db end', '定时任务');
    }

    public static function read_txt($file)
    {
        // global $wpdb;

        $f = fopen($file, 'r');
        if (!$f) {
            return;
        }
        $db = self::db();

        while (!feof($f)) {
            $line = fgets($f);
            if (!$line) {
                break;
            }
            $d = json_decode($line, true);
            //self::txt_log($line);
            $db->insert($db->prefix . 'wb_spider_log', $d);
        }
        fclose($f);
        // unlink($file);
        wp_delete_file($file);
    }

    public static function set_auto_deny()
    {
        self::txt_log('set_auto_deny start ', '定时任务');
        // global $wpdb;
        $cnf = self::cnf();
        if (empty($cnf['auto_deny'])) {
            return;
        }
        $db = self::db();

        $t = $db->prefix . 'wb_spider_ip';
        $db->query("UPDATE $t SET status=16 WHERE status = 2");
        self::txt_log('set_auto_deny end ', '定时任务');
    }

    public static function check_ip()
    {
        self::txt_log('check_ip start ', '定时任务');

        // global $wpdb;

        $db = self::db();
        //status[2=>可疑ip,1=>正常，3=>检测中,4=>禁止]
        $t = $db->prefix . 'wb_spider_ip';
        $t_log = $db->prefix . 'wb_spider_log';

        //SELECT DISTINCT visit_ip FROM `wp_wb_spider_log` WHERE
        $sql = "INSERT INTO $t(ip,name) ";
        $sql .= "SELECT DISTINCT a.visit_ip,a.spider FROM $t_log a WHERE a.visit_date > DATE_ADD(a.visit_date,INTERVAL -1 DAY)";
        $sql .= " AND NOT EXISTS(SELECT b.id FROM $t b WHERE b.ip=a.visit_ip AND b.name=a.spider)";

        $db->query($sql);

        $col = $db->get_col("SELECT DISTINCT ip FROM $t WHERE status = 0 LIMIT 1000 ");


        $api = 'https://www.wbolt.com/wb-api/v1/spider/ip';
        $arg = array(
            'timeout'   => 10,
            'sslverify' => false,
            'body' => array('ver' => get_option('wb_spider_analyser_ver', 0), 'host' => $_SERVER['HTTP_HOST'], 'ip' => implode(',', $col)),
            'headers' => array('referer' => home_url()),
        );
        $http = wp_remote_post($api, $arg);
        $body = wp_remote_retrieve_body($http);

        if (is_wp_error($http)) {
            self::txt_log($http->get_error_message());
            return;
        }
        $code = wp_remote_retrieve_response_code($http);
        if ($code !== 200) {
            return;
        }
        self::txt_log($body);
        if ($body && preg_match('#^[0-9,]+$#', trim($body))) {
            $exp = explode(',', $body);
            foreach ($col as $k => $ip) {
                if (isset($exp[$k]) && $exp[$k]) {
                    $db->query($db->prepare("UPDATE $t SET status=%d WHERE ip=%s", $exp[$k], $ip));
                }
            }
        }
        self::txt_log('check_ip end ', '定时任务');
    }

    public static function update_post_url_num()
    {
        // global $wpdb;
        self::txt_log('update_post_url_num start ', '定时任务');
        $db = self::db();

        $prefix = $db->prefix;

        $sql = "UPDATE `{$prefix}wb_spider_post` a,(SELECT COUNT(1) num, post_id FROM `{$prefix}wb_spider_post_link` ";
        $sql .= "WHERE link_url_md5 <> 'e10adc3949ba59abbe56e057f20f883e' GROUP BY post_id ) AS b";
        $sql .= " SET a.url_out = b.num  WHERE a.post_id=b.post_id";
        $db->query($sql);


        $sql = "UPDATE `{$prefix}wb_spider_post` a,(SELECT COUNT(1) num, link_url_md5 FROM `{$prefix}wb_spider_post_link` ";
        $sql .= " WHERE link_url_md5 <> 'e10adc3949ba59abbe56e057f20f883e' GROUP BY link_url_md5 ) AS b ";
        $sql .= " SET a.url_in = b.num  WHERE a.url_md5=b.link_url_md5";

        $db->query($sql);
        self::txt_log('update_post_url_num end ', '定时任务');
    }

    public static function scan_post_inner_link()
    {
        self::txt_log('scan_post_inner_link start ', '定时任务');
        // global $wpdb;
        $db = self::db();
        $error = $db->suppress_errors();
        $t = $db->prefix . 'wb_spider_post_link';
        $sql = "SELECT * FROM $db->posts p WHERE p.post_status='publish'";
        $sql .= " AND NOT EXISTS (SELECT post_id FROM $t a WHERE a.post_id=p.ID) LIMIT 1000";
        $list = $db->get_results($sql);
        foreach ($list as $r) {
            self::post_inner_link($r);
        }
        $db->suppress_errors($error);
        self::txt_log('scan_post_inner_link end ', '定时任务');
    }

    public static function post_inner_link($post)
    {

        // global $wpdb;
        $db = self::db();

        $t = $db->prefix . 'wb_spider_post_link';
        $db->query($db->prepare("DELETE FROM $t WHERE post_id=%d", $post->ID));

        $num = 0;
        if (preg_match_all("#href=('|\")(.+?)('|\")#is", $post->post_content, $match)) {
            //print_r($match[2]);
            foreach ($match[2] as $url) {
                $url = str_replace(home_url('/'), '/', $url);
                if ($url[0] != '/') {
                    continue;
                }
                $query_post = null;
                $type = self::match_type($url, $query_post);
                if ($type != 'post') {
                    continue;
                }
                self::txt_log([$type, $url]);
                $d = ['post_id' => $post->ID, 'link_url_md5' => md5($url), 'link_post_id' => 0];
                if ($query_post) {
                    $d['link_post_id'] = $query_post->ID;
                }

                if ($db->insert($t, $d)) {
                    $num++;
                }
            }
        }
        if (!$num) {
            $d = ['post_id' => $post->ID, 'link_url_md5' => md5('123456'), 'link_post_id' => 0];
            $db->insert($t, $d);
        }
    }

    public static function spider_edit_post($post_id, $post)
    {
        // global $wpdb;
        if (!get_option('wb_spider_analyser_ver', 0)) {
            return;
        }


        if ($post->post_status != 'publish') {
            return;
        }
        $db = self::db();

        $t = $db->prefix . 'wb_spider_post';
        $d = array('post_id' => $post_id, 'url_md5' => md5(str_replace(home_url('/'), '/', get_permalink($post))));
        $error = $db->suppress_errors();
        if (!$db->insert($t, $d)) {
            $db->update($t, array('url_md5' => $d['url_md5']), array('post_id' => $post_id));
        }
        //更新收录状态
        $post_id = intval($post_id);
        $db->query("UPDATE $t a ,$db->postmeta b SET a.status=b.meta_value WHERE a.post_id=$post_id AND a.post_id=b.post_id AND b.meta_key='url_in_baidu'");
        self::post_inner_link($post);
        $db->suppress_errors($error);
    }

    public static function cron_set_spider_post()
    {
        self::txt_log('cron_set_spider_post start ', '定时任务');
        // global $wpdb;
        $db = self::db();
        $error = $db->suppress_errors();

        //存量文章入库
        $t = $db->prefix . 'wb_spider_post';
        $sql = "INSERT INTO $t(`post_id`,`status`)  ";
        $sql .= "SELECT a.ID,IFNULL(b.meta_value,0) status FROM $db->posts a LEFT JOIN $db->postmeta b ON a.ID=b.post_id";
        $sql .= " AND b.meta_key='url_in_baidu' WHERE a.post_status='publish' ";
        $sql .= " AND NOT EXISTS (SELECT post_id FROM $t c WHERE c.post_id = a.ID )";
        $db->query($sql);

        //更新文章URL
        $list = $db->get_results("SELECT * FROM $t WHERE url_md5 IS NULL LIMIT 500");
        foreach ($list as $r) {
            $url = str_replace(home_url('/'), '/', get_permalink($r->post_id));
            $db->update($t, array('url_md5' => md5($url)), array('post_id' => $r->post_id));
        }

        //更新收录状态
        $db->query("UPDATE $t a ,$db->postmeta b SET a.status=b.meta_value WHERE a.post_id=b.post_id AND b.meta_key='url_in_baidu'");

        $db->suppress_errors($error);
        self::txt_log('cron_set_spider_post end ', '定时任务');
    }

    public static function set_url_type()
    {
        // global $wpdb;
        self::txt_log('set_url_type start ', '定时任务');
        $db = self::db();
        $t = $db->prefix . 'wb_spider_log';

        $list = $db->get_results("SELECT url,url_md5 FROM $t WHERE url_type IS NULL ORDER BY id DESC LIMIT 200");

        if ($list) foreach ($list as $r) {
            self::txt_log('match url ' . $r->url);
            $result = [];
            $type = self::match_type($r->url, $result);
            self::txt_log('match url type [' . $type . ']');
            if ($type) {
                self::txt_log('update url type [' . $r->url_md5 . ']');
                $db->query($db->prepare("UPDATE $t SET url_type=%s WHERE url_md5=%s", $type, $r->url_md5));
            }
        }
        self::txt_log('set_url_type start ', '定时任务');
    }

    public static function check_404()
    {
        self::txt_log('check_404 start', '定时任务');
        // global $wpdb;
        $db = self::db();
        $max_id = get_option('sp_an_max_id', 0);

        $t = $db->prefix . 'wb_spider_log';
        $list = $db->get_results("SELECT max(id) max_id,url,url_md5 FROM $t WHERE `code`='404' AND id>$max_id GROUP BY url_md5 ORDER BY max_id ASC LIMIT 500");


        foreach ($list as $r) {
            $url = home_url($r->url);
            $http = wp_remote_head($url);
            $code = wp_remote_retrieve_response_code($http);
            if ($code) {
                $db->query($db->prepare("UPDATE $t SET `code`=%s WHERE url_md5 =%s ", $code, $r->url_md5));
                $max_id = $r->max_id;
            }
        }
        update_option('sp_an_max_id', $max_id, false);
        self::txt_log('check_404 end', '定时任务');
    }

    public static function del_old_log()
    {
        // global $wpdb;
        self::txt_log('del_old_log start ', '定时任务');
        $cnf = self::cnf();
        $month = intval($cnf['log_keep']);
        if (!$month) {
            $month = 2;
        }


        $time_str = '-' . $month . ' month';
        if ($month == 1) {
            $time_str = '-7 day';
        } else if ($month == 2) {
            $time_str = '-1 month';
        }

        if ($month > 12) {
            return;
        }

        $db = self::db();

        $t = $db->prefix . 'wb_spider_log';

        $ymd = gmdate('Y-m-d', strtotime($time_str));

        $db->query("DELETE FROM $t WHERE DATE_FORMAT(visit_date,'%Y-%m-%d') < '$ymd' ");

        self::txt_log('del_old_log end ', '定时任务');
    }

    public static function calc_all_log()
    {
        // global $wpdb;


        $db = self::db();
        $t = $db->prefix . 'wb_spider_log';

        $cols = $db->get_col("SELECT DISTINCT DATE_FORMAT(visit_date,'%Y-%m-%d') FROM $t ");


        if ($cols) foreach ($cols as $ymd) {
            self::calc_log($ymd);
        }
    }

    public static function calc_log($ymd = null)
    {

        self::txt_log('calc_log start ' . $ymd, '定时任务');

        //global $wpdb;

        $db = self::db();
        $t = $db->prefix . 'wb_spider';
        $t_log = $t . '_log';
        $t_sum = $t . '_sum';
        $t_visit = $t . '_visit';
        if (!$ymd) {
            $ymd = current_time('Y-m-d');
        }

        $num = $db->get_var("SELECT COUNT(1) AS num FROM $t_log a WHERE NOT EXISTS(SELECT id FROM $t b WHERE a.spider=b.name)");
        if ($num > 0) {
            //new spider
            $db->query("INSERT INTO $t(name) SELECT DISTINCT spider FROM $t_log a WHERE NOT EXISTS(SELECT id FROM $t b WHERE a.spider=b.name)");
        }


        $list = $db->get_results("SELECT id,name FROM $t ");
        $spiders = [];
        foreach ($list as $r) {
            $spiders[$r->name] = $r->id;
        }

        //spider

        $sql = "SELECT COUNT(1) num,DATE_FORMAT(a.visit_date,'%Y%m%d%H') ymdh,MIN(a.visit_date) visit_date,a.spider,b.id AS spider_id FROM $t_log a,$t b WHERE a.spider=b.name AND DATE_FORMAT(a.visit_date,'%Y-%m-%d')='$ymd' GROUP BY a.spider,ymdh ";

        $list = $db->get_results($sql);

        //foreach($list as $r->r);

        //删除旧数据
        $db->query("DELETE FROM $t_sum WHERE FROM_UNIXTIME(created,'%Y-%m-%d')='$ymd'");

        foreach ($list as $r) {
            $d = array(
                'ymdh' => $r->ymdh,
                'created' => strtotime($r->visit_date),
                'spider' => $r->spider_id,
                'visit_times' => $r->num
            );
            $db->insert($t_sum, $d);
        }

        self::txt_log('calc_log end ', '定时任务');

        return;

        //spider url

        $sql = "SELECT COUNT(1) num,DATE_FORMAT(visit_date,'%Y%m%d') ymdh,MIN(visit_date) visit_date,spider,url FROM $t_log WHERE DATE_FORMAT(visit_date,'%Y-%m-%d')='$ymd' GROUP BY spider,ymdh,url_md5 ";

        $list = $db->get_results($sql);

        //foreach($list as $r->r);

        //删除旧数据
        $db->query("DELETE FROM $t_visit WHERE FROM_UNIXTIME(created,'%Y-%m-%d')='$ymd'");

        foreach ($list as $r) {
            $d = array(
                'ymdh' => $r->ymdh,
                'created' => strtotime($r->visit_date),
                'spider' => $spiders[$r->spider],
                'visit_times' => $r->num,
                'url_md5' => $r->url_md5,
                'url' => $r->url
            );
            $db->insert($t_visit, $d);
        }
    }

    public static function handle()
    {
        if (self::$in_log) {
            return;
        }

        if (self::$blocked) {
            return;
        }

        if (!self::$after_request) {
            return;
            /*$headers = headers_list();
            $is_30x = 0;
            foreach($headers as $s){
                if(preg_match('#^location#i',$s)){
                    $is_30x = 1;
                    break;
                }
            }
            if($is_30x){
                return;
            }
            */
        }

        self::$in_log = true;

        $has_error = error_get_last();

        global $wp, $wp_query;

        if ($has_error && self::should_handle_error($has_error)) {
            $code = '500';
        } else if (is_404()) {
            $code = '404';
        } else {
            $code = '200';
        }
        self::log($code);
    }

    protected static function should_handle_error($error)
    {
        $error_types_to_handle = array(
            E_ERROR,
            E_PARSE,
            E_USER_ERROR,
            E_COMPILE_ERROR,
            E_RECOVERABLE_ERROR,
        );

        if (isset($error['type']) && in_array($error['type'], $error_types_to_handle, true)) {
            return true;
        }

        return (bool) apply_filters('wp_should_handle_php_error', false, $error);
    }

    public static function cnf()
    {
        static $option = null;
        if (!$option) {
            $option = WP_Spider_Analyser_Admin::cnf();
        }

        return $option;
    }

    public static function spider()
    {
        try {
            if (!isset($_SERVER['REQUEST_METHOD']) || $_SERVER['REQUEST_METHOD'] != 'GET') {
                return null;
            }
            if (!isset($_SERVER['HTTP_USER_AGENT']) || empty($_SERVER['HTTP_USER_AGENT'])) {
                return null;
            }
            $agent = $_SERVER['HTTP_USER_AGENT'];
            $cnf = self::cnf();
            //forbid
            do {

                if (preg_match('#spider#i', $agent)) {
                    break;
                }
                if (preg_match('#bot#i', $agent)) {
                    break;
                }
                if (preg_match('#crawler#i', $agent)) {
                    break;
                }
                if (preg_match('#(Daumoa|Yahoo!|Qwantify|Seeker|Elefent|13TABS|iqdb|TinEye|Plukkie|PDFDriveCrawler)#i', $agent)) {
                    break;
                }

                $find_match = false;
                if ($cnf['user_define']) foreach ($cnf['user_define'] as $v) {
                    if (preg_match('#' . preg_quote($v) . '#i', $agent)) {
                        $find_match = true;
                        break;
                    }
                }
                if ($find_match) {
                    break;
                }

                return null;
            } while (0);

            $spider = '';

            //自定义蜘蛛
            if ($cnf['user_define']) foreach ($cnf['user_define'] as $v) {
                if (preg_match('#' . preg_quote($v) . '#i', $agent)) {
                    $spider = $v;
                    break;
                }
            }

            if ($spider) {
            } else if (preg_match('#sogou (web|inst|news|pic|wap) spider#i', $agent, $spider_match)) {
                $spider = 'sogou spider';
            } else if (preg_match('#[a-z0-9\.-]+ spider#i', $agent, $spider_match)) {
                $spider = $spider_match[0];
            } else if (preg_match('#[a-z0-9\.-]+ bot#i', $agent, $spider_match)) {
                $spider = $spider_match[0];
            } else if (preg_match('#[a-z0-9\.-]*spider[a-z0-9]*#i', $agent, $spider_match)) {
                $spider = $spider_match[0];
            } else if (preg_match('#[a-z0-9\.-]*bot[a-z0-9]*#i', $agent, $spider_match)) {
                $spider = $spider_match[0];
            } else if (preg_match('#[a-z0-9\.-]+ crawler#i', $agent, $spider_match)) {
                $spider = $spider_match[0];
            } else if (preg_match('#[a-z0-9\.-]*crawler[a-z0-9]*#i', $agent, $spider_match)) {
                $spider = $spider_match[0];
            } else if (preg_match('#(Daumoa|Yahoo!|Qwantify|Seeker|Elefent|13TABS|iqdb|TinEye|Plukkie|PDFDriveCrawler)#i', $agent, $spider_match)) {
                $spider = $spider_match[0];
            } else {
                $spider = 'other';
            }

            return $spider;
        } catch (Exception $ex) {
        }
        return null;
    }

    public static function getIp()
    {
        if (isset($_SERVER['HTTP_CLIENT_IP']) && $_SERVER['HTTP_CLIENT_IP']) {
            return $_SERVER['HTTP_CLIENT_IP'];
        } else if (isset($_SERVER['HTTP_X_FORWARDED_FOR']) && $_SERVER['HTTP_X_FORWARDED_FOR']) {
            return $_SERVER['HTTP_X_FORWARDED_FOR'];
        } else if (isset($_SERVER['REMOTE_ADDR']) && $_SERVER['REMOTE_ADDR']) {
            return $_SERVER['REMOTE_ADDR'];
        }
        return null;
    }

    public static function log($status = '')
    {
        global $wp_the_query;
        try {
            $spider = self::spider();
            if (!$spider) {
                return;
            }
            $cnf = self::cnf();
            //$agent = $_SERVER['HTTP_USER_AGENT'];
            //用户禁用，不记录
            $skip_list = self::get_skip_spider();
            if ($skip_list) foreach ($skip_list as $v) {
                if ($v && preg_match('#^' . preg_quote($v) . '$#i', $spider)) {
                    return;
                }
            }

            $url = $_SERVER['REQUEST_URI'];

            $d = array(
                'spider' => $spider,
                'visit_date' => current_time('mysql'),
                'code' => $status,
                'visit_ip' => self::getIp(),
                'url' => $url,
                'url_md5' => md5($url),
            );

            $type = null;

            if ($cnf['extral_rule']) foreach ($cnf['extral_rule'] as $r_type => $rule) {
                if (!$rule) {
                    continue;
                }

                $rule = str_replace(array(',', '\\*'), array('|', '.+?'), preg_quote($rule));
                if (preg_match('#(' . $rule . ')#i', $url)) {
                    $type = $r_type;
                    break;
                }
            }
            if (!$type && $cnf['user_rule']) foreach ($cnf['user_rule'] as $r) {
                if (!$r['rule']) {
                    continue;
                }
                $rule = str_replace(array(',', '\\*'), array('|', '.+?'), preg_quote($r['rule']));
                if (preg_match('#(' . $rule . ')#i', $url)) {
                    $type = $r['name'];
                    break;
                }
            }

            //['index','post','page','category','tag','search','author','feed','sitemap','api','other'];
            if ($type) {
            } else if (preg_match('#^/sitemap(-[a-z0-9_-]+)?\.xml#i', $d['url'])) {
                $type = 'sitemap';
            } else if (preg_match('#wp-admin/admin-ajax\.php#', $d['url'])) {
                $type = 'api';
            } else if ($wp_the_query && $wp_the_query instanceof WP_Query) {
                if ($wp_the_query->is_search()) {
                    $type = 'search';
                } else if ($wp_the_query->is_feed()) {
                    $type = 'feed';
                } else if ($wp_the_query->is_tag()) {
                    $type = 'tag';
                } else if ($wp_the_query->is_author()) {
                    $type = 'author';
                } else if ($wp_the_query->is_category() || $wp_the_query->is_archive()) {
                    $type = 'category';
                } else if ($wp_the_query->is_singular(array('page'))) {
                    $type = 'page';
                } else if ($wp_the_query->is_singular()) {
                    $type = 'post';
                } else if ($wp_the_query->is_home() || $wp_the_query->is_front_page()) {
                    $type = 'index';
                }
            }

            if ($type) {
                $d['url_type'] = $type;
            }


            if ($cnf['log_update'] == 'db') {
                $db = self::db();
                $db->insert($db->prefix . 'wb_spider_log', $d);
            } else {
                $log_file = WP_SPIDER_ANALYSER_PATH . '/#log/log-' . current_time('dH') . '.txt';
                error_log(wp_json_encode($d) . "\n", 3, $log_file);
                //error_log();
            }
        } catch (Exception $ex) {
        }
    }

    public static function admin_menu_handler()
    {
        global $submenu;
        add_menu_page(
            _x('蜘蛛分析', '菜单名称', WB_SPA_DM),
            _x('蜘蛛分析', '菜单名称', WB_SPA_DM),
            'administrator',
            'wp_spider_analyser',
            array(__CLASS__, 'spider_views'), //
            WP_SPIDER_ANALYSER_URL . 'assets/ico.svg'
        );
        $submenu_spa = [
            [
                'name' => _x('蜘蛛概况', '菜单名称', WB_SPA_DM),
                'slug' => 'wp_spider_analyser#/home'
            ],
            [
                'name' => _x('蜘蛛日志', '菜单名称', WB_SPA_DM),
                'slug' => 'wp_spider_analyser#/log'
            ],
            [
                'name' => _x('蜘蛛列表', '菜单名称', WB_SPA_DM),
                'slug' => 'wp_spider_analyser#/list'
            ],
            [
                'name' => _x('访问路径', '菜单名称', WB_SPA_DM),
                'slug' => 'wp_spider_analyser#/path'
            ],
            [
                'name' => _x('文章爬取', '菜单名称', WB_SPA_DM),
                'slug' => 'wp_spider_analyser#/post'
            ],
            [
                'name' => _x('插件设置', '菜单名称', WB_SPA_DM),
                'slug' => 'wp_spider_analyser#/setting'
            ]
        ];
        foreach ($submenu_spa as $item) {
            add_submenu_page('wp_spider_analyser', $item['name'], $item['name'], 'administrator', $item['slug'], array(__CLASS__, 'spider_views'));
        }

        if (!get_option('wb_spider_analyser_ver', 0)) {
            add_submenu_page('wp_spider_analyser', _x('升至Pro版', '菜单名称', WB_SPA_DM), '<span style="color: #FCB214;">' . _x('升至Pro版', '菜单名称', WB_SPA_DM) . '</span>', 'administrator', "https://www.wbolt.com/plugins/spider-analyser' target='_blank'");
        }

        unset($submenu['wp_spider_analyser'][0]);
    }

    public static function actionLinks($links, $file)
    {

        //print_r([$file]);
        if (!preg_match('#spider-analyser/#', $file)) {
            return $links;
        }
        if (!get_option('wb_spider_analyser_ver', 0)) {
            $a_link = '<a href="https://www.wbolt.com/plugins/spider-analyser" target="_blank"><span style="color: #FCB214;">' . _x('升至Pro版', 'link', WB_SPA_DM) . '</span></a>';
            array_unshift($links, $a_link);
        }
        $a_link = '<a href="' . menu_page_url('wp_spider_analyser', false) . '#/setting">' . _x('设置', 'link', WB_SPA_DM) . '</a>';
        array_unshift($links, $a_link);



        return $links;
    }

    public static function update_spider($spider)
    {
        $api = 'https://www.wbolt.com/wb-api/v1/spider/info';
        $arg = array(
            'timeout'   => 1,
            'blocking'  => false,
            'sslverify' => false,
            'body' => array('spider' => wp_json_encode($spider)),
            'headers' => array('referer' => home_url()),
        );
        wp_remote_post($api, $arg);
    }

    public static function spider_views()
    {
        if (!current_user_can('manage_options')) {
            wp_die(__('You do not have sufficient permissions to access this page.'));
        }
        // global $wpdb;

        $db = self::db();
        $t = $db->prefix . 'wb_spider';
        $t_log = $t . '_log';

        $num = $db->get_var("SELECT COUNT(1) AS num FROM $t_log a WHERE NOT EXISTS(SELECT id FROM $t b WHERE a.spider=b.name)");
        if ($num > 0) {
            $db->query("INSERT INTO $t(name) SELECT DISTINCT spider FROM $t_log a WHERE NOT EXISTS(SELECT id FROM $t b WHERE a.spider=b.name)");
        }


        $time = get_option('sync_wb_spider', 0);

        if (time() > $time) {
            update_option('sync_wb_spider', time() + 86400);
            self::sync_wb_spider();
        }


        echo '<div id="app"></div>';
    }


    public static function spider_path()
    {
        if (!current_user_can('manage_options')) {
            wp_die(__('You do not have sufficient permissions to access this page.'));
        }

        // global $wpdb;
        $db = self::db();
        $t = $db->prefix . 'wb_spider_log';
        $spider = $db->get_col("SELECT DISTINCT spider FROM $t");
        $code = $db->get_col("SELECT DISTINCT code FROM $t");
        $url_types = WP_Spider_Analyser_Admin::url_types();
        $cnf = self::cnf();
        if ($cnf['user_rule']) foreach ($cnf['user_rule'] as $r) {
            $url_types[$r['name']] = $r['name'];
        }

        $res['spider'] = $spider;
        $res['code'] = $code;
        $res['url_types'] = $url_types;
        $res['day'] = array(
            array(
                'value' => '-1',
                'label' => _x('所有时间', '筛选选项', WB_SPA_DM)
            ),
            array(
                'value' => '0',
                'label' => _x('今天', '筛选选项', WB_SPA_DM)
            ),
            array(
                'value' => '7',
                'label' => _x('近7天', '筛选选项', WB_SPA_DM)
            ),
            array(
                'value' => '30',
                'label' => _x('近30天', '筛选选项', WB_SPA_DM)
            )
        );

        return $res;
    }

    public static function spider_log()
    {
        $res = array();
        // global $wpdb;
        $db = self::db();
        $t = $db->prefix . 'wb_spider_log';
        $t_s = $db->prefix . 'wb_spider';
        $spider = $db->get_col("SELECT DISTINCT spider FROM $t");
        $code = $db->get_col("SELECT DISTINCT code FROM $t");
        $type = $db->get_col("SELECT DISTINCT bot_type FROM $t_s WHERE bot_type <> ''");
        $res['spider'] = $spider;
        $res['code'] = $code;
        $res['type'] = $type;

        $res['day'] = array(
            array(
                'value' => '-1',
                'label' => _x('所有时间', '筛选选项', WB_SPA_DM)
            ),
            array(
                'value' => '0',
                'label' => _x('今天', '筛选选项', WB_SPA_DM)
            ),
            array(
                'value' => '7',
                'label' => _x('近7天', '筛选选项', WB_SPA_DM)
            ),
            array(
                'value' => '30',
                'label' => _x('近30天', '筛选选项', WB_SPA_DM)
            )
        );

        return $res;
    }

    /**
     * 列表筛选选项
     *
     */
    public static function spider_list_stop()
    {
        $res = array();

        $res['type'] = [
            _x('全部', 'spider type', WB_SPA_DM),
            _x('名称', 'spider type', WB_SPA_DM),
            _x('IP', 'spider type', WB_SPA_DM),
            _x('IP段', 'spider type', WB_SPA_DM),
            _x('名称及IP', 'spider type', WB_SPA_DM),
            _x('自定义', 'spider type', WB_SPA_DM)
        ];
        $res['path'] = [
            '4' => _x('未知', 'spider path', WB_SPA_DM),
            '11' => _x('蜘蛛日志', 'spider path', WB_SPA_DM),
            '12' => _x('蜘蛛清单', 'spider path', WB_SPA_DM),
            '13' => _x('蜘蛛IP段', 'spider path', WB_SPA_DM),
            '14' => _x('疑似伪蜘蛛', 'spider path', WB_SPA_DM),
            '15' => _x('自定义', 'spider path', WB_SPA_DM),
            '16' => _x('智能拦截', 'spider path', WB_SPA_DM),
            '17' => _x('记录管理', 'spider path', WB_SPA_DM)
        ];

        return $res;
    }

    public static function get_skip_spider()
    {
        // global $wpdb;
        $db = self::db();
        $t = $db->prefix . 'wb_spider';
        return $db->get_col("SELECT `name` FROM $t WHERE `skip` = 1");
    }

    public static function skip_spider($spider)
    {
        // global $wpdb;
        $db = self::db();
        $t = $db->prefix . 'wb_spider';
        $db->query($db->prepare("UPDATE $t SET `skip`=1 WHERE `name`=%s", $spider));
    }

    public static function sync_wb_spider()
    {
        // global $wpdb;

        $db = self::db();
        $t = $db->prefix . 'wb_spider';
        $spiders = $db->get_col("SELECT name FROM $t");
        if (empty($spiders)) {
            return;
        }

        $api = 'https://www.wbolt.com/wb-api/v1/spider/info';
        $param = [
            'timeout' => 30,
            'sslverify' => false,
            'headers' => array('referer' => home_url()),
            'body' => ['udg' => 1, 'logo' => 1, 'locale' => get_locale()]
        ];
        $http = wp_remote_get($api, $param);
        do {
            if (is_wp_error($http)) {
                break;
            }
            $body = wp_remote_retrieve_body($http);
            if (!$body) {
                break;
            }
            $data = json_decode($body, true);
            if (!$data) {
                break;
            }
            if (!is_array($data)) {
                break;
            }

            self::save_spider_info($data['data']);

            $t = $db->prefix . 'wb_spider';
            $db->query("UPDATE $t set `status` = 1 WHERE `status` = 2");
            foreach ($data['data'] as $r) {
                if (!in_array($r['name'], $spiders)) {
                    continue;
                }
                $db->query($db->prepare("UPDATE $t SET `status` = 2,`bot_type` = %s,`bot_url`=%s WHERE name = %s", $r['bot_type'], $r['bot_url'], $r['name']));
            }
        } while (0);
    }

    public static function read_spider_info()
    {
        static $data = null;

        if ($data !== null) {
            return $data;
        }
        $locale = get_locale();

        $cache = [];
        do {
            $file = WP_SPIDER_ANALYSER_PATH . '/#info/spider_info_' . $locale . '.php';
            if (file_exists($file)) {
                $cache = include $file;
                if (!empty($cache) && is_array($cache)) {
                    break;
                }
            }
            $file = WP_SPIDER_ANALYSER_PATH . '/spider_info.php';
            if (file_exists($file)) {
                $cache = include $file;
                if (!empty($cache) && is_array($cache)) {
                    break;
                }
            }
        } while (0);
        $list = [];
        if ($cache) {
            foreach ($cache as $r) {
                $key = strtolower($r['name']);
                $list[$key] = $r;
            }
        }
        $data = $list;
        return $data;
    }

    public static function save_spider_info($data)
    {
        if (empty($data) || !is_array($data)) {
            return;
        }
        if (!is_dir(WP_SPIDER_ANALYSER_PATH . '/#info/')) {
            mkdir(WP_SPIDER_ANALYSER_PATH . '/#info/', 0755);
        }

        $locale = get_locale();
        $content = '<' . '?php' . "\n" . 'return ' . var_export($data, true) . ';';
        file_put_contents(WP_SPIDER_ANALYSER_PATH . '/#info/spider_info_' . $locale . '.php', $content);
    }

    public static function db_ver()
    {
        return 1.5;
    }

    public static function set_up()
    {
        self::setup_db();
    }

    public static function setup_db($create_tables = null)
    {

        // global $wpdb;


        $wb_tables = array(
            'wb_spider',
            'wb_spider_ip',
            'wb_spider_log',
            'wb_spider_post',
            'wb_spider_post_link',
            'wb_spider_sum',
            'wb_spider_visit',
        );
        if (!$create_tables && is_array($create_tables)) {
            $wb_tables = $create_tables;
        }

        $db = self::db();
        //数据表
        $tables = $db->get_col("SHOW TABLES LIKE '" . $db->prefix . "wb_spider%'");


        $set_up = array();
        foreach ($wb_tables as $table) {
            if (in_array($db->prefix . $table, $tables)) {
                continue;
            }
            $set_up[] = $table;
        }

        if (empty($set_up)) {
            return;
        }

        $sql = file_get_contents(WP_SPIDER_ANALYSER_PATH . '/install/init.sql');

        $charset_collate = $db->get_charset_collate();



        $sql = str_replace('`wp_wb_', '`' . $db->prefix . 'wb_', $sql);
        $sql = str_replace('ENGINE=InnoDB', $charset_collate, $sql);



        $sql_rows = explode('-- row split --', $sql);

        foreach ($sql_rows as $row) {

            if (preg_match('#`' . $db->prefix . '(wb_spider.*?)`\s+\(#', $row, $match)) {
                if (in_array($match[1], $set_up)) {
                    $db->query($row);
                }
            }
            //print_r($row);exit();
        }

        update_option('wb_spider_analyser_db_ver', self::db_ver());
    }

    public static function upgrade()
    {
        // global $wpdb;


        $db_ver = get_option('wb_spider_analyser_db_ver');
        if (!$db_ver) {
            return;
        }

        $db = self::db();
        if (version_compare($db_ver, '1.2') < 0) {
            $t = $db->prefix . 'wb_spider_log';
            $sql = $db->get_var('SHOW CREATE TABLE `' . $t . '`', 1);
            if (!preg_match('#`url_type`#is', $sql)) {
                $db->query("ALTER TABLE $t ADD `url_type` varchar(32) DEFAULT NULL");
                $db->query("ALTER TABLE $t ADD INDEX(`url_type`)");
            }
            update_option('wb_spider_analyser_db_ver', '1.2');
        }
        if (version_compare($db_ver, '1.3') < 0) {
            self::setup_db(array('wb_spider_ip', 'wb_spider_post', 'wb_spider_post_link'));
            update_option('wb_spider_analyser_db_ver', '1.3');
        }
        if (version_compare($db_ver, '1.4') < 0) {
            $t = $db->prefix . 'wb_spider';
            $sql = $db->get_var('SHOW CREATE TABLE `' . $t . '`', 1);
            if (!preg_match('#`skip`#is', $sql)) {
                $db->query("ALTER TABLE $t ADD `skip` tinyint(3) UNSIGNED NOT NULL DEFAULT '0'");
            }
            if (!preg_match('#`bot_type`#is', $sql)) {
                $db->query("ALTER TABLE $t ADD `bot_type` varchar(32) DEFAULT NULL");
                $db->query("ALTER TABLE $t ADD INDEX(`bot_type`)");
            }
            if (!preg_match('#`bot_url`#is', $sql)) {
                $db->query("ALTER TABLE $t ADD `bot_url` varchar(256) DEFAULT NULL");
            }
            update_option('wb_spider_analyser_db_ver', '1.4');

            $cnf = WP_Spider_Analyser_Admin::cnf();
            if (isset($cnf['forbid']) && is_array($cnf['forbid'])) {
                $t = $db->prefix . 'wb_spider';
                foreach ($cnf['forbid'] as $v) {
                    $db->query($db->prepare("UPDATE $t SET `skip` = 1 WHERE `name` = %s", $v));
                }
                unset($cnf['forbid']);
                update_option(WP_Spider_Analyser_Admin::$option, $cnf);
            }

            self::sync_wb_spider();
        }

        if (version_compare($db_ver, '1.5') < 0) {
            self::setup_db(array('wb_spider'));
            update_option('wb_spider_analyser_db_ver', '1.5');
        }
    }

    public static function cache($param, $data = null, $expire = 0, $code = 'json')
    {
        $key = md5(wp_json_encode($param));
        if (!is_dir(WP_SPIDER_ANALYSER_PATH . '/#log/')) {
            mkdir(WP_SPIDER_ANALYSER_PATH . '/#log/', 0755);
        }
        $cache_file = WP_SPIDER_ANALYSER_PATH . '/#log/' . $key . '.php';
        if (null === $data) {
            if (file_exists($cache_file)) {
                return $cache_file;
            }
            return false;
        }
        if (is_array($data)) {
            $data = wp_json_encode($data);
        }
        $expired = time() +  $expire;
        $content = '<' . '?php if(time()>' . $expired . '){return;}';
        if ($code) {
            if ($code == 'json') {
                $code = 'header("content-type:text/json;");';
            }
            $content .= $code;
        }
        $content .= '?' . '>' . $data . '<' . '?php exit();';
        file_put_contents($cache_file, $content);
    }

    public static function clear_cache()
    {
        $cache_file = WP_SPIDER_ANALYSER_PATH . '/#log/*.php';
        $files = glob($cache_file);
        if ($files && is_array($files)) {
            foreach ($files as $file) {
                // unlink($file);
                wp_delete_file($file);
            }
        }
    }


    public static function localize_ajax_handle()
    {
        $locale = get_locale();
        $cache_key = 'wb_localize_' . $locale . '_' . WB_SPA_DM . '_' . WP_SPIDER_ANALYSER_VERSION;
        $cache_data = get_transient($cache_key);
        if ($cache_data) {
            return $cache_data;
        }

        $lang_data = [];
        if (file_exists(__DIR__ . '/_localize.php')) {
            include __DIR__ . '/_localize.php';
        }

        apply_filters('wb_spa_locales_data', $lang_data);

        $format_data = [];
        foreach ($lang_data as $k => $v) {
            $format_data[WBP::set_localize_key($k)] = $v;
        }
        set_transient($cache_key, $format_data, DAY_IN_SECONDS);

        return $format_data;
    }
}
