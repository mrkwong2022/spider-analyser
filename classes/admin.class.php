<?php
/**
 * Spider Analyser Base and Admin Classes.
 *
 * @package Spider_Analyser
 * @since 2.0.0
 */

if ( ! defined('ABSPATH') ) {
	return;
}

/**
 * Base class for Spider Analyser.
 * Provides common utility methods.
 */
class WP_Spider_Analyser_Base {

	/**
	 * Get a request parameter.
	 * Defaults to checking $_POST, then $_GET if not found in $_POST, unless $param_type is specified.
	 *
	 * @param string $param_key The key of the parameter.
	 * @param mixed  $default_value Default value if the parameter is not set.
	 * @param string $param_type Type of request to check ('p' for POST, 'g' for GET, any other for POST then GET).
	 * @return mixed The value of the parameter, or the default value.
	 */
	public static function param( $param_key, $default_value = '', $param_type = 'p' ) {
		if ( 'p' === $param_type ) {
			return isset( $_POST[ $param_key ] ) ? wp_unslash( $_POST[ $param_key ] ) : $default_value;
		} elseif ( 'g' === $param_type ) {
			return isset( $_GET[ $param_key ] ) ? wp_unslash( $_GET[ $param_key ] ) : $default_value;
		}

		if ( isset( $_POST[ $param_key ] ) ) {
			return wp_unslash( $_POST[ $param_key ] );
		}
		if ( isset( $_GET[ $param_key ] ) ) {
			return wp_unslash( $_GET[ $param_key ] );
		}
		return $default_value;
	}

	/**
	 * Get the WordPress database object.
	 *
	 * @global wpdb $wpdb WordPress database abstraction object.
	 * @return wpdb WordPress database object.
	 */
	public static function db() {
		static $db_instance = null;
		if ( null === $db_instance ) {
			global $wpdb;
			$db_instance = $wpdb;
		}
		return $db_instance;
	}

	/**
	 * Send a JSON response for AJAX requests and terminate execution.
	 *
	 * @param mixed $response_data Data to be JSON encoded and output.
	 */
	public static function ajax_resp( $response_data ) {
		if ( ! headers_sent() ) {
			header( 'Content-Type: application/json; charset=' . get_option( 'blog_charset' ) );
		}
		echo wp_json_encode( $response_data );
		wp_die();
	}

    /**
     * Recursively sanitize an array of text fields.
     *
     * @param array|string $input_value The array or string to sanitize.
     * @return array|string Sanitized array or string.
     */
    public static function array_sanitize_text_field( $input_value ) {
        if ( is_array( $input_value ) ) {
            foreach ( $input_value as $key => $value ) {
                $input_value[ $key ] = static::array_sanitize_text_field( $value );
            }
            return $input_value;
        } else {
            return is_scalar( $input_value ) ? sanitize_text_field( $input_value ) : $input_value;
        }
    }
}

/**
 * Admin class for Spider Analyser.
 * Handles admin-specific functionalities, settings pages, and configurations.
 */
class WP_Spider_Analyser_Admin extends WP_Spider_Analyser_Base {
	/**
	 * WordPress option name for plugin settings.
	 * @var string
	 */
	public static $option = 'wp_spider_analyser_option';

	/**
	 * Initialize admin-specific hooks.
	 */
	public static function init() {
		if ( is_admin() ) {
			add_filter( 'plugin_row_meta', array( __CLASS__, 'plugin_row_meta' ), 10, 2 );
		}
	}

	/**
	 * Get the defined spider types.
	 *
	 * @return array Array of spider types.
	 */
	public static function spider_types() {
		$spider_types_array = array(
			_x( 'Feed爬取类', 'spider type', WB_SPA_DM ),
			_x( 'SEO/SEM类', 'spider type', WB_SPA_DM ),
			_x( '工具类', 'spider type', WB_SPA_DM ),
			_x( '搜索引擎', 'spider type', WB_SPA_DM ),
			_x( '漏洞扫描类', 'spider type', WB_SPA_DM ),
			_x( '病毒扫描类', 'spider type', WB_SPA_DM ),
			_x( '网站截图类', 'spider type', WB_SPA_DM ),
			_x( '网站爬虫类', 'spider type', WB_SPA_DM ),
			_x( '网站监控', 'spider type', WB_SPA_DM ),
			_x( '速度测试类', 'spider type', WB_SPA_DM ),
			_x( '链接检测类', 'spider type', WB_SPA_DM ),
			_x( '其他', 'spider type', WB_SPA_DM ),
		);
		return apply_filters( 'wp_spider_analyser_spider_types', $spider_types_array );
	}

	/**
	 * Get the defined URL types.
	 *
	 * @return array Array of URL types.
	 */
	public static function url_types() {
		$url_types_array = array(
			'index'    => _x( '首页', 'url类型', WB_SPA_DM ),
			'post'     => _x( '文章页', 'url类型', WB_SPA_DM ),
			'page'     => _x( '独立页', 'url类型', WB_SPA_DM ),
			'category' => _x( '分类页', 'url类型', WB_SPA_DM ),
			'tag'      => _x( '标签页', 'url类型', WB_SPA_DM ),
			'search'   => _x( '搜索页', 'url类型', WB_SPA_DM ),
			'author'   => _x( '作者页', 'url类型', WB_SPA_DM ),
			'feed'     => _x( 'Feed', 'url类型', WB_SPA_DM ),
			'sitemap'  => _x( 'SiteMap', 'url类型', WB_SPA_DM ),
			'api'      => _x( 'API', 'url类型', WB_SPA_DM ),
			'other'    => _x( '其他', 'url类型', WB_SPA_DM ),
		);
		return apply_filters( 'wp_spider_analyser_url_types', $url_types_array );
	}

    /**
     * Add admin menu page for the plugin.
     */
    public static function admin_menu_handler() {
        // Example: add_menu_page(...);
        // This is a stub. Actual implementation would add the menu page.
        // For now, ensuring the callback is valid.
        if (function_exists('add_menu_page')) {
             add_menu_page(
                __('Spider Analyser', WB_SPA_DM),
                __('Spider Analyser', WB_SPA_DM),
                'manage_options',
                'wp_spider_analyser', // Menu slug
                array(__CLASS__, 'admin_page_content'), // Callback function for page content
                'dashicons-search' // Icon
            );
        }
    }

    /**
     * Callback for rendering the admin page content.
     * This is a stub and should be implemented to display the actual admin page.
     */
    public static function admin_page_content() {
        // Placeholder for admin page content.
        // The actual UI is likely rendered by the Vue app.
        echo '<div class="wrap"><div id="wbs_app_spider_analyser"></div></div>';
    }

    /**
     * Add action links to the plugin entry on the plugins page.
     *
     * @param array  $links Existing plugin action links.
     * @param string $plugin_file Path to the plugin file relative to the plugins directory.
     * @return array Modified plugin action links.
     */
    public static function plugin_action_links( $links, $plugin_file_name ) { // Renamed from actionLinks for clarity
        $this_plugin_base_name = plugin_basename( WP_SPIDER_ANALYSER_BASE_FILE );
		if ( $plugin_file_name === $this_plugin_base_name ) {
            // Example: Add a settings link
            $settings_page_url = admin_url( 'admin.php?page=wp_spider_analyser' ); // Assuming 'wp_spider_analyser' is the menu slug
            $settings_link = '<a href="' . esc_url( $settings_page_url ) . '">' . esc_html__( 'Settings', WB_SPA_DM ) . '</a>';
            array_unshift( $links, $settings_link );
        }
        return $links;
    }

	/**
	 * Get the plugin configuration settings.
	 * Merges stored options with default values.
	 *
	 * @return array Plugin configuration array.
	 */
	public static function cnf() {
		$default_config = array(
			'log_keep'      => '2',
			'auto_deny'     => 0,
			'user_define'   => array(),
			'user_rule'     => array(),
			'extral_rule'   => array(),
			'log_update'    => 'hour',
		);

		$current_config = get_option( static::$option, array() );
		$current_config = wp_parse_args( $current_config, $default_config );

		$url_types_array = static::url_types();
		if ( is_array( $url_types_array ) ) {
			foreach ( $url_types_array as $type_key => $type_value ) {
				if ( ! isset( $current_config['extral_rule'][ $type_key ] ) ) {
					$current_config['extral_rule'][ $type_key ] = '';
				}
			}
		}
		return $current_config;
	}

	/**
	 * Add custom links to the plugin row in the plugins list table.
	 *
	 * @param array  $plugin_links An array of the plugin's action links.
	 * @param string $plugin_file_name Path to the plugin file relative to the plugins directory.
	 * @return array Updated array of plugin action links.
	 */
	public static function plugin_row_meta( $plugin_links, $plugin_file_name ) {
		$this_plugin_base_name = plugin_basename( WP_SPIDER_ANALYSER_BASE_FILE );
		if ( $plugin_file_name === $this_plugin_base_name ) {
			$plugin_links[] = '<a href="https://www.wbolt.com/plugins/spider-analyser" target="_blank" rel="noopener">' . esc_html( _x( '插件主页', 'Plugin row meta link', WB_SPA_DM ) ) . '</a>';
			$plugin_links[] = '<a href="https://www.wbolt.com/spider-analyser-plugin-documentation.html" target="_blank" rel="noopener">' . esc_html( _x( '说明文档', 'Plugin row meta link', WB_SPA_DM ) ) . '</a>';
			$plugin_links[] = '<a href="https://www.wbolt.com/plugins/spider-analyser#J_commentsSection" target="_blank" rel="noopener">' . esc_html( _x( '反馈', 'Plugin row meta link', WB_SPA_DM ) ) . '</a>';
		}
		return $plugin_links;
	}

	/**
	 * Update plugin configuration settings based on submitted data.
	 * Handles different settings tabs.
	 *
	 * @return int|array|null Returns 1 on success for tab-based updates,
	 *                        updated config array for general updates, or null if no action taken.
	 */
	public static function update_cnf() {
		// Nonce should be verified by the calling AJAX handler.
		$current_tab = sanitize_text_field( static::param( 'tab', null ) );

		if ( null !== $current_tab ) {
			$current_config = static::cnf();

			switch ( $current_tab ) {
				case 'rule':
					$extra_rules_param = static::param( 'extral_rule', array() );
					if ( is_array( $extra_rules_param ) ) {
						$current_config['extral_rule'] = static::array_sanitize_text_field( $extra_rules_param );
					}

					$user_rules_param = static::param( 'user_rule', array() );
					if ( is_array( $user_rules_param ) ) {
						$current_config['user_rule'] = static::array_sanitize_text_field( $user_rules_param );
					} else {
						$current_config['user_rule'] = array();
					}
					update_option( static::$option, $current_config, 'no' );
					break;

				case 'log':
					$log_options = static::param( 'opt', array() );
					if ( is_array( $log_options ) ) {
						$old_log_update_method = $current_config['log_update'];

						if ( isset( $log_options['user_define'] ) ) {
							$current_config['user_define'] = static::array_sanitize_text_field( $log_options['user_define'] );
						} else {
							$current_config['user_define'] = array();
						}
						if ( isset( $log_options['log_keep'] ) ) {
							$current_config['log_keep'] = sanitize_text_field( $log_options['log_keep'] );
						}
						if ( isset( $log_options['log_update'] ) ) {
							$current_config['log_update'] = sanitize_text_field( $log_options['log_update'] );
						}

						update_option( static::$option, $current_config, 'no' );
						if ( 'db' !== $old_log_update_method && 'db' === $current_config['log_update'] ) {
							if ( class_exists( 'WP_Spider_Analyser' ) && method_exists( 'WP_Spider_Analyser', 'log2db') ) {
								WP_Spider_Analyser::log2db( $old_log_update_method, 1 );
							}
						}
					}
					break;

				case 'list':
					$db_instance   = static::db();
					$spider_name   = sanitize_text_field( static::param( 'name' ) );
					$stop_param_id = static::param( 'stop', null );
					$skip_param_id = static::param( 'skip', null );

					if ( null !== $stop_param_id ) {
						$stop_param_id        = absint( $stop_param_id );
						$table_spider_ip_name = $db_instance->prefix . 'wb_spider_ip';
						if ( $stop_param_id > 0 ) {
							$db_instance->delete( $table_spider_ip_name, array( 'id' => $stop_param_id ), array( '%d' ) );
						} elseif ( $spider_name ) {
							$db_instance->insert( $table_spider_ip_name, array( 'name' => $spider_name, 'ip' => '', 'status' => 17 ), array( '%s', '%s', '%d' ) );
							return $db_instance->insert_id;
						}
					} elseif ( null !== $skip_param_id && $spider_name ) {
						$skip_value = absint( $skip_param_id ) ? 1 : 0;
						$db_instance->update(
							$db_instance->prefix . 'wb_spider',
							array( 'skip' => $skip_value ),
							array( 'name' => $spider_name ),
							array( '%d' ),
							array( '%s' )
						);
					}
					break;

				case 'auto':
					$auto_deny_param = static::param( 'auto', null );
					if ( null !== $auto_deny_param ) {
						$current_config['auto_deny'] = absint( $auto_deny_param ) ? 1 : 0;
						update_option( static::$option, $current_config, 'no' );
					}
					break;

				case 'reset':
					$option_prefix = 'wb_spider_analyser_';
					$version_id    = get_option( $option_prefix . 'ver', 0 );
					if ( $version_id ) {
						delete_option( $option_prefix . 'ver' );
						delete_option( $option_prefix . 'cnf_' . sanitize_key($version_id) ); // Sanitize version key part
					}
					break;
			}
			return 1;
		}

		$spider_types_param = static::param( 'type' );
		if ( $spider_types_param && is_array( $spider_types_param ) ) {
			$sanitized_spider_types = static::array_sanitize_text_field( $spider_types_param );
			$spider_information     = array();
			foreach ( $sanitized_spider_types as $spider_data_row ) {
				if ( isset( $spider_data_row['name'] ) ) {
					$spider_information[ $spider_data_row['name'] ] = $spider_data_row;
				}
			}
			if ( $spider_information ) {
				$option_info_value = array(
					'expired' => current_time( 'U', true ) + HOUR_IN_SECONDS,
					'data'    => $spider_information,
				);
				update_option( 'wb_spider_info', $option_info_value, 'no' );
			}
		}

		$options_param = static::param( 'opt', array() );
		if ( is_array( $options_param ) ) {
			$sanitized_opt_data = static::array_sanitize_text_field( $options_param );
			$current_config = static::cnf();

			if ( isset( $sanitized_opt_data['user_define'] ) && is_array( $sanitized_opt_data['user_define'] ) ) {
				$user_defined_rules = array();
				foreach ( $sanitized_opt_data['user_define'] as $value ) {
					$trimmed_value = trim( $value );
					if ( ! empty( $trimmed_value ) ) {
						$user_defined_rules[] = $trimmed_value;
					}
				}
				$current_config['user_define'] = array_values( array_unique( $user_defined_rules ) );
			}

			if ( isset( $sanitized_opt_data['user_rule'] ) && is_array( $sanitized_opt_data['user_rule'] ) ) {
				$user_specific_rules = array();
				foreach ( $sanitized_opt_data['user_rule'] as $value ) {
					if ( is_array($value) && !empty(trim($value['name'])) && !empty(trim($value['rule'])) ) {
						$user_specific_rules[] = array( 'name' => trim( $value['name'] ), 'rule' => trim( $value['rule'] ) );
					}
				}
				$current_config['user_rule'] = $user_specific_rules;
			}
			if ( isset( $sanitized_opt_data['log_keep'] ) ) $current_config['log_keep'] = $sanitized_opt_data['log_keep'];
			if ( isset( $sanitized_opt_data['log_update'] ) ) $current_config['log_update'] = $sanitized_opt_data['log_update'];
			if ( isset( $sanitized_opt_data['auto_deny'] ) ) $current_config['auto_deny'] = absint($sanitized_opt_data['auto_deny']) ? 1: 0;

			update_option( static::$option, $current_config, 'no' );
			return $current_config;
		}
		return null;
	}

	/**
	 * Get statistics for the spider log.
	 *
	 * @return array Associative array with 'num' (total log entries) and 'updated' (last log entry date).
	 */
	public static function logStat() {
		$db_instance = static::db();
		$table_log_name = $db_instance->prefix . 'wb_spider_log';

		$log_stats_row  = $db_instance->get_row( "SELECT COUNT(1) AS num, MAX(visit_date) AS updated FROM `{$table_log_name}`" );
		if ( ! $log_stats_row || ! $log_stats_row->updated ) {
			return array( 'num' => 0, 'updated' => _x( 'N/A', 'Not available placeholder for date', WB_SPA_DM ) );
		}
		return array( 'num' => (int) $log_stats_row->num, 'updated' => $log_stats_row->updated );
	}

	/**
	 * Get configuration data for different admin settings tabs.
	 * Used by AJAX handlers to populate settings forms.
	 *
	 * @return array Configuration data for the specified tab.
	 */
	public static function wp_spider_analyser_conf() {
		$config_data_to_return = array();
		$current_tab_param = sanitize_text_field( static::param( 'tab' ) );
		if ( ! $current_tab_param ) {
			return $config_data_to_return;
		}

		$current_config = static::cnf();

		switch ( $current_tab_param ) {
			case 'rule':
				$config_data_to_return['opt']      = array(
					'user_rule'   => isset( $current_config['user_rule'] ) ? $current_config['user_rule'] : array(),
					'extral_rule' => isset( $current_config['extral_rule'] ) ? $current_config['extral_rule'] : array()
				);
				$config_data_to_return['url_type'] = static::url_types();
				break;

			case 'log':
				$config_data_to_return['user_define'] = isset( $current_config['user_define'] ) ? $current_config['user_define'] : array();
				$config_data_to_return['log_keep']    = isset( $current_config['log_keep'] ) ? $current_config['log_keep'] : '2';
				$config_data_to_return['log_update']  = isset( $current_config['log_update'] ) ? $current_config['log_update'] : 'hour';
				$config_data_to_return['logStat']     = static::logStat();
				break;

			case 'list':
				$db_instance          = static::db();
				$table_spider_name    = $db_instance->prefix . 'wb_spider';
				$table_spider_ip_name = $db_instance->prefix . 'wb_spider_ip';
				$table_log_name       = $db_instance->prefix . 'wb_spider_log';

				$where_conditions = array( '1=1' );
				$query_params     = static::array_sanitize_text_field( static::param( 'q', array() ) );

				if ( ! empty( $query_params['code'] ) ) {
					if ( '1' === $query_params['code'] ) {
						$where_conditions[] = "a.`skip` = 1";
					} elseif ( '2' === $query_params['code'] ) {
						$where_conditions[] = "a.`skip` = 0";
					}
				}
				if ( ! empty( $query_params['type'] ) ) {
					$where_conditions[] = $db_instance->prepare( "a.`bot_type` = %s", $query_params['type'] );
				}
				if ( ! empty( $query_params['name'] ) ) {
					$where_conditions[] = $db_instance->prepare( "a.name REGEXP %s", preg_quote( $query_params['name'], '#' ) );
				}

				$items_per_page = absint( static::param( 'num', 15 ) );
				$current_page   = absint( static::param( 'page', 1 ) );
				$items_per_page = $items_per_page > 0 ? $items_per_page : 15;
				$current_page   = $current_page > 0 ? $current_page : 1;
				$offset_val     = ( $current_page - 1 ) * $items_per_page;

				$where_sql = implode( ' AND ', $where_conditions );
				$sql_query = $db_instance->prepare(
					"SELECT SQL_CALC_FOUND_ROWS a.*, a.status AS udg, a.`name` AS spider_name_alias, b.id AS stop_id
					 FROM `{$table_spider_name}` a
					 LEFT JOIN `{$table_spider_ip_name}` b ON a.name = b.name AND b.status = 17
					 WHERE {$where_sql}
					 LIMIT %d, %d",
					$offset_val,
					$items_per_page
				);

				$spider_list_results = $db_instance->get_results( $sql_query );
				$total_items         = (int) $db_instance->get_var( "SELECT FOUND_ROWS()" );
				$config_data_to_return['total'] = $total_items;

				$cache_key_week_stats = 'wb_spider_30_day_stats';
				$visit_rate_data      = get_transient( $cache_key_week_stats );

				if ( false === $visit_rate_data ) {
					$thirty_days_ago_sql = "DATE_SUB(NOW(), INTERVAL 30 DAY)";
					$recent_visits_total_q = "SELECT COUNT(1) FROM `{$table_log_name}` WHERE visit_date > {$thirty_days_ago_sql}";
					$recent_spider_visits_count = (int) $db_instance->get_var( $recent_visits_total_q );
					$recent_spider_visits_count = max( $recent_spider_visits_count, 1 );

					$sql_recent_visits = "SELECT ROUND(COUNT(1) * 100 / {$recent_spider_visits_count} , 2) AS rate, spider
										  FROM (SELECT spider FROM `{$table_log_name}` WHERE visit_date > {$thirty_days_ago_sql}) AS t
										  GROUP BY spider ORDER BY rate DESC";
					$recent_spider_data = $db_instance->get_results( $sql_recent_visits );
					$visit_rate_data    = array();
					if ( $recent_spider_data ) {
						foreach ( $recent_spider_data as $visit_value ) {
							$visit_rate_data[ $visit_value->spider ] = (float) $visit_value->rate;
						}
					}
					set_transient( $cache_key_week_stats, $visit_rate_data, DAY_IN_SECONDS );
				}

				if ( $spider_list_results ) {
					foreach ( $spider_list_results as $key_index => $row_data ) {
						$current_spider_name = isset( $row_data->spider_name_alias ) ? $row_data->spider_name_alias : $row_data->name;
						$spider_list_results[ $key_index ]->rate = isset( $visit_rate_data[ $current_spider_name ] ) ? $visit_rate_data[ $current_spider_name ] : 0;
					}
				}

				$config_data_to_return['list'] = $spider_list_results;
				if ( static::param( 'nav' ) ) {
					$config_data_to_return['spider_type'] = static::spider_types();
				}
				break;
		}
		return $config_data_to_return;
	}
}
?>
