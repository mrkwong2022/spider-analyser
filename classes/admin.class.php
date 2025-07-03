<?php

if (!defined('ABSPATH')) {
    return;
}

class WP_Spider_Analyser_Base
{
    public static function param($key, $default = '', $type = 'p')
    {
        if ('p' === $type) {
            if (isset($_POST[$key])) {
                return $_POST[$key];
            }
            return $default;
        } else if ('g' === $type) {
            if (isset($_GET[$key])) {
                return $_GET[$key];
            }
            return $default;
        }
        if (isset($_POST[$key])) {
            return $_POST[$key];
        }
        if (isset($_GET[$key])) {
            return $_GET[$key];
        }
        return $default;
    }
    /**
     * @see wpdb
     * @return mixed
     */
    public static function db()
    {
        static $db = null;
        if ($db) {
            return $db;
        }
        $db = $GLOBALS['wpdb'];
        if ($db instanceof wpdb) {
            return $db;
        }
        return $db;
    }

    public static function ajax_resp($ret)
    {
        header('content-type:text/json;charset=utf-8');
        echo wp_json_encode($ret);
        exit();
    }
}

class WP_Spider_Analyser_Admin extends WP_Spider_Analyser_Base
{
    public static $option = 'wp_spider_analyser_option';

    public static function init()
    {
        if (is_admin()) {
            add_filter('plugin_row_meta', array(__CLASS__, 'plugin_row_meta'), 10, 2);
        }
    }

    public static function spider_types()
    {

        $types = array(
            _x('Feed爬取类', 'spider type', WB_SPA_DM),
            _x('SEO/SEM类', 'spider type', WB_SPA_DM),
            _x('工具类', 'spider type', WB_SPA_DM),
            _x('搜索引擎', 'spider type', WB_SPA_DM),
            _x('漏洞扫描类', 'spider type', WB_SPA_DM),
            _x('病毒扫描类', 'spider type', WB_SPA_DM),
            _x('网站截图类', 'spider type', WB_SPA_DM),
            _x('网站爬虫类', 'spider type', WB_SPA_DM),
            _x('网站监控', 'spider type', WB_SPA_DM),
            _x('速度测试类', 'spider type', WB_SPA_DM),
            _x('链接检测类', 'spider type', WB_SPA_DM),
            _x('其他', 'spider type', WB_SPA_DM),
        );
        return apply_filters('spider_analyser_url_types', $types);
    }
    public static function url_types()
    {
        $types =  array(
            'index' => _x('首页', 'url类型', WB_SPA_DM),
            'post' => _x('文章页', 'url类型', WB_SPA_DM),
            'page' => _x('独立页', 'url类型', WB_SPA_DM),
            'category' => _x('分类页', 'url类型', WB_SPA_DM),
            'tag' => _x('标签页', 'url类型', WB_SPA_DM),
            'search' => _x('搜索页', 'url类型', WB_SPA_DM),
            'author' => _x('作者页', 'url类型', WB_SPA_DM),
            'feed' => _x('Feed', 'url类型', WB_SPA_DM),
            'sitemap' => _x('SiteMap', 'url类型', WB_SPA_DM),
            'api' => _x('API', 'url类型', WB_SPA_DM),
            'other' => _x('其他', 'url类型', WB_SPA_DM)
        );
        return apply_filters('spider_analyser_url_types', $types);
    }

    public static function cnf()
    {
        // global $wpdb;

        $def = array(
            'log_keep' => '2',
            'auto_deny' => 0,
            'user_define' => array(),
            'user_rule' => array(),
            'extral_rule' => array(),
            'log_update' => 'hour'
        );

        $cnf = get_option(self::$option, array());
        //'forbid'=>array(),
        foreach ($def as $key => $val) {
            if (!isset($cnf[$key])) {
                $cnf[$key] = $val;
            }
        }
        $url_types = self::url_types();
        if ($url_types) foreach ($url_types as $k => $v) {
            if (!isset($cnf['extral_rule'][$k])) {
                $cnf['extral_rule'][$k] = '';
            }
        }


        //,'spider'=>array()


        return $cnf;
    }

    public static function plugin_row_meta($links, $file)
    {

        $base = plugin_basename(WP_SPIDER_ANALYSER_BASE_FILE);
        if ($file == $base) {
            $links[] = '<a href="https://www.wbolt.com/plugins/spider-analyser">' . _x('插件主页', 'btn', WB_SPA_DM) . '</a>';
            $links[] = '<a href="https://www.wbolt.com/spider-analyser-plugin-documentation.html">' . _x('说明文档', 'btn', WB_SPA_DM) . '</a>';
            $links[] = '<a href="https://www.wbolt.com/plugins/spider-analyser#J_commentsSection">' . _x('反馈', 'btn', WB_SPA_DM) . '</a>';
        }

        return $links;
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

    public static function update_cnf()
    {
        // global $wpdb;

        $tab = self::param('tab', null);
        if (null !== $tab) {
            $tab = sanitize_text_field($tab);
            switch ($tab) {
                case 'rule':
                    $cnf = self::cnf();
                    $extral_rule = self::array_sanitize_text_field(self::param('extral_rule', []));
                    if ($extral_rule && is_array($extral_rule)) {
                        $cnf['extral_rule'] = $extral_rule;
                    }
                    $user_rule = self::array_sanitize_text_field(self::param('user_rule', []));
                    if ($user_rule && is_array($user_rule)) {
                        $cnf['user_rule'] = $user_rule;
                    } else {
                        $cnf['user_rule'] =  [];
                    }
                    update_option(self::$option, $cnf);
                    break;
                case 'log':
                    $cnf = self::cnf();
                    $opt = self::param('opt', []);
                    if ($opt && is_array($opt)) {
                        $old_log_update = $cnf['log_update'];
                        if (!isset($opt['user_define'])) {
                            $opt['user_define'] = [];
                        }
                        foreach (['user_define', 'log_keep', 'log_update'] as $f) {
                            if (isset($opt[$f])) {
                                $cnf[$f] = $opt[$f];
                            }
                        }
                        update_option(self::$option, $cnf);
                        if ($old_log_update != 'db' && $cnf['log_update'] == 'db') {
                            WP_Spider_Analyser::log2db($old_log_update, 1);
                        }
                    }

                    break;
                case 'list':
                    $db = self::db();
                    $name = sanitize_text_field(self::param('name'));
                    $stop = self::param('stop', null);
                    $skip = self::param('skip', null);
                    if (null !== $stop) {
                        $stop = intval($stop);
                        $t = $db->prefix . 'wb_spider_ip';
                        if ($stop) {
                            $db->query($db->prepare("DELETE FROM $t WHERE id=%d", $stop));
                        } else if ($name) {
                            $db->insert($t, ['name' => $name, 'ip' => '', 'status' => 17]);
                            return $db->insert_id;
                        }
                    } else if (null !== $skip) {
                        $skip = intval($skip);
                        if ($name) {
                            $db->update($db->prefix . 'wb_spider', ['skip' => $skip], ['name' => $name]);
                        }
                    }
                    break;
                case 'auto':
                    $cnf = self::cnf();
                    $auto = self::param('auto', null);
                    if (null !== $auto) {
                        $cnf['auto_deny'] = $auto ? 1 : 0;
                        update_option(self::$option, $cnf);
                    }

                    break;
                case 'reset':
                    $w_key = implode('_', ['wb', 'spider', 'analyser', '']);
                    $id = get_option($w_key . 'ver', 0);
                    if ($id) {
                        update_option($w_key . 'ver', 0);
                        update_option($w_key . 'cnf_' . $id, '');
                    }
                    break;
            }
            return 1;
        }

        $type = self::param('type');


        if ($type && is_array($type)) {
            $spider = self::array_sanitize_text_field($type);
            $spider_info = array();
            foreach ($spider as $r) {
                $spider_info[$r['name']] = $r;
            }
            if ($spider_info) {
                $info = array('expired' => current_time('U', 1) + 1 * HOUR_IN_SECONDS, 'data' => $spider_info);

                update_option('wb_spider_info', $info, false);
            }
        }
        $opt = self::param('opt', []);
        $opt_data = self::array_sanitize_text_field($opt);
        if (!is_array($opt_data['user_define'])) {
            $opt_data['user_define'] = array();
        }
        $user_define = array();
        foreach ($opt_data['user_define'] as $k => $v) {
            $v = trim($v);
            if (!$v) {
                continue;
            }
            $user_define[] = $v;
        }
        $opt_data['user_define'] = $user_define;

        if (!is_array($opt_data['user_rule'])) {
            $opt_data['user_rule'] = array();
        }
        $user_rule = array();
        foreach ($opt_data['user_rule'] as $k => $v) {
            $name = trim($v['name']);
            if (!$name) {
                continue;
            }
            $rule = trim($v['rule']);
            if (!$rule) {
                continue;
            }
            $user_rule[] = array('name' => $name, 'rule' => $rule);
        }
        $opt_data['user_rule'] = $user_rule;
    }

    public static function logStat()
    {
        // global $wpdb;
        $db = self::db();

        $t = $db->prefix . 'wb_spider_log';
        $row =  $db->get_row("SELECT COUNT(1) num,MAX(visit_date) AS updated FROM $t ");
        if (!$row) {
            return ['num' => 0, 'updated' => '----'];
        }
        return ['num' => $row->num, 'updated' => $row->updated];
    }

    public static function wp_spider_analyser_conf()
    {
        // global $wpdb;

        $data = [];
        $tab = sanitize_text_field(self::param('tab'));
        if (!$tab) {
            return $data;
        }


        switch ($tab) {
            case 'rule':
                $cnf = self::cnf();
                $data['opt'] = ['user_rule' => $cnf['user_rule'], 'extral_rule' => $cnf['extral_rule']];
                $data['url_type'] = self::url_types();
                break;

            case 'log':
                $cnf = self::cnf();
                $data['user_define'] = $cnf['user_define'];
                $data['log_keep'] = $cnf['log_keep'];
                $data['log_update'] = $cnf['log_update'];

                $data['logStat'] = self::logStat();

                break;

            case 'list':
                $db = self::db();
                $t_s = $db->prefix . 'wb_spider';
                $t_p = $db->prefix . 'wb_spider_ip';
                $t = $db->prefix . 'wb_spider_log';
                $where = ['1=1'];
                $q = self::array_sanitize_text_field(self::param('q', []));
                if (!empty($q['code'])) {
                    //{1:'忽略',2:'记录'}
                    if ($q['code'] == 1) {
                        $where[] = "a.`skip`=1";
                    } else if ($q['code'] == 2) {
                        $where[] = "a.`skip`=0";
                    }
                }
                if (!empty($q['type'])) {
                    $where[] = $db->prepare("a.`bot_type` = %s", $q['type']);
                }

                if (!empty($q['name'])) {
                    $where[] = $db->prepare("a.name REGEXP %s", preg_quote($q['name']));
                }

                $num = absint(self::param('num', 15));
                $page = absint(self::param('page', 1));;
                if (!$num) {
                    $num = 15;
                }
                if (!$page) {
                    $page = 1;
                }

                $offset = max(0, ($page - 1) * $num);
                //所有蜘蛛

                $sql = "SELECT SQL_CALC_FOUND_ROWS a.*,a.status AS udg,a.`name` AS spider,b.id AS stop_id 
                            FROM $t_s a LEFT JOIN $t_p b ON a.name = b.name AND b.status=17  WHERE " . implode(' AND ', $where) . ' LIMIT ' . $offset . ',' . $num;
                //$data['sql'] = $sql;
                $spider_list = $db->get_results($sql);
                //$data['slist'] = $spider_list;
                $total = $db->get_var("SELECT FOUND_ROWS()");
                $data['total'] = $total;

                //近7天访问量
                $cache_name = 'wb_spider_week_stats';
                $v_rate = get_transient($cache_name);
                if (!$v_rate) {
                    $spider_recent_num = $db->get_var("SELECT COUNT(1) num FROM $t WHERE visit_date>DATE_ADD(NOW(),INTERVAL -30 DAY)");
                    $spider_recent_num = max($spider_recent_num, 1);
                    $sql = "SELECT ROUND(COUNT(1) * 100 / $spider_recent_num ,2) AS rate, spider 
                        FROM (SELECT * FROM $t WHERE visit_date>DATE_ADD(NOW(),INTERVAL -30 DAY)) AS t 
                        GROUP BY spider ORDER BY rate DESC";
                    $spider_recent = $db->get_results($sql);
                    $v_rate = array();
                    if ($spider_recent) foreach ($spider_recent as $v) {
                        $v_rate[$v->spider] = $v->rate;
                    }
                    set_transient($cache_name, $v_rate, 86400);
                }


                foreach ($spider_list as $k => $r) {
                    $r->rate = 0;
                    if (isset($v_rate[$r->name])) {
                        $r->rate = $v_rate[$r->name];
                    }
                }

                $data['list'] = $spider_list;
                $is_nav = self::param('nav');
                if ($is_nav) {
                    $data['spider_type'] = self::spider_types();
                }
                break;
        }

        return $data;
    }
}
