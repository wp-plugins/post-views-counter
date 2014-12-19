<?php
/*
Plugin Name: Post Views Counter
Description: Forget WP-PostViews. Display how many times a post, page or custom post type had been viewed in a simple, fast and reliable way.
Version: 1.0.4
Author: dFactory
Author URI: http://www.dfactory.eu/
Plugin URI: http://www.dfactory.eu/plugins/post-views-counter/
License: MIT License
License URI: http://opensource.org/licenses/MIT
Text Domain: post-views-counter
Domain Path: /languages

Post Views Counter
Copyright (C) 2014, Digital Factory - info@digitalfactory.pl

Permission is hereby granted, free of charge, to any person obtaining a copy of this software and associated documentation files (the "Software"), to deal in the Software without restriction, including without limitation the rights to use, copy, modify, merge, publish, distribute, sublicense, and/or sell copies of the Software, and to permit persons to whom the Software is furnished to do so, subject to the following conditions:

The above copyright notice and this permission notice shall be included in all copies or substantial portions of the Software.

THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY, FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM, OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN THE SOFTWARE.
*/


if(!defined('ABSPATH')) exit;

define('POST_VIEWS_COUNTER_URL', plugins_url('', __FILE__));
define('POST_VIEWS_COUNTER_PATH', plugin_dir_path(__FILE__));
define('POST_VIEWS_COUNTER_REL_PATH', dirname(plugin_basename(__FILE__)).'/');

include_once(POST_VIEWS_COUNTER_PATH.'includes/update.php');
include_once(POST_VIEWS_COUNTER_PATH.'includes/settings.php');
include_once(POST_VIEWS_COUNTER_PATH.'includes/query.php');
include_once(POST_VIEWS_COUNTER_PATH.'includes/cron.php');
include_once(POST_VIEWS_COUNTER_PATH.'includes/counter.php');
include_once(POST_VIEWS_COUNTER_PATH.'includes/columns.php');
include_once(POST_VIEWS_COUNTER_PATH.'includes/frontend.php');
include_once(POST_VIEWS_COUNTER_PATH.'includes/widgets.php');


class Post_Views_Counter
{
	private static $_instance;
	private $instances;
	private $options;
	private $defaults = array(
		'general' => array(
			'post_types_count' => array('post'),
			'counter_mode' => 'php',
			'post_views_column' => true,
			'time_between_counts' => array(
				'number' => 24,
				'type' => 'hours'
			),
			'reset_counts' => array(
				'number' => 30,
				'type' => 'days'
			),
			'exclude' => array(
				'groups' => array(),
				'roles' => array()
			),
			'exclude_ips' => array(),
			'deactivation_delete' => false,
			'cron_run' => true,
			'cron_update' => true
		),
		'display' => array(
			'label' => 'Post Views:',
			'post_types_display' => array('post'),
			'restrict_display' => array(
				'groups' => array(),
				'roles' => array()
			),
			'position' => 'after',
			'display_style' => array(
				'icon' => true,
				'text' => true
			),
			'link_to_post' => true,
			'icon_class' => 'dashicons-visibility'
		),
		'version' => '1.0.4'
	);


	public static function instance()
	{
		if(self::$_instance === null)
			self::$_instance = new self();

		return self::$_instance;
	}


	private function __clone() {}
	private function __wakeup() {}


	private function __construct()
	{
		register_activation_hook(__FILE__, array(&$this, 'activation'));
		register_deactivation_hook(__FILE__, array(&$this, 'deactivation'));

		// settings
		$this->options = array(
			'general' => array_merge($this->defaults['general'], get_option('post_views_counter_settings_general', $this->defaults['general'])),
			'display' => array_merge($this->defaults['display'], get_option('post_views_counter_settings_display', $this->defaults['display']))
		);

		// actions
		add_action('plugins_loaded', array(&$this, 'load_textdomain'));
		add_action('admin_enqueue_scripts', array(&$this, 'admin_scripts_styles'));
		add_action('wp_loaded', array(&$this, 'load_pluggable_functions'), 10);

		// filters
		add_filter('plugin_action_links', array(&$this, 'plugin_settings_link'), 10, 2);
	}


	/**
	 * Execution of plugin activation function
	*/
	public function activation()
	{
		global $wpdb, $charset_collate;

		// required for dbdelta
		require_once(ABSPATH.'wp-admin/includes/upgrade.php');

		// creates post views table
		dbDelta('
			CREATE TABLE IF NOT EXISTS '.$wpdb->prefix.'post_views (
				id bigint unsigned NOT NULL,
				type tinyint(1) unsigned NOT NULL,
				period varchar(8) NOT NULL,
				count bigint unsigned NOT NULL,
				PRIMARY KEY  (type, period, id),
				UNIQUE INDEX id_period (id, period) USING BTREE,
				INDEX type_period_count (type, period, count) USING BTREE
			) '.$charset_collate.';'
		);

		// adds default options
		add_option('post_views_counter_settings_general', $this->defaults['general'], '', 'no');
		add_option('post_views_counter_settings_display', $this->defaults['display'], '', 'no');
		add_option('post_views_counter_version', $this->defaults['version'], '', 'no');
	}


	/**
	 * Execution of plugin deactivation function
	*/
	public function deactivation()
	{
		// deletes default options
		if($this->options['general']['deactivation_delete'])
		{
			delete_option('post_views_counter_settings_general');
			delete_option('post_views_counter_settings_display');
		}

		// removes schedule
		wp_clear_scheduled_hook('pvc_reset_counts');
		remove_action('pvc_reset_counts', array(Post_Views_Counter()->get_instance('cron'), 'reset_counts'));
	}


	/**
	 * Loads text domain
	*/
	public function load_textdomain()
	{
		load_plugin_textdomain('post-views-counter', false, POST_VIEWS_COUNTER_REL_PATH.'languages/');
	}
	
	
	/**
	 * Load pluggable template functions
	*/
	public function load_pluggable_functions() 
	{
	    include_once(POST_VIEWS_COUNTER_PATH.'includes/functions.php');
	}


	/**
	 * Sets instance of class
	*/
	public function add_instance($name, $instance)
	{
		$this->instances[$name] = $instance;
	}


	/**
	 * Gets instance of class
	*/
	public function get_instance($name)
	{
		if(in_array($name, array('counter', 'settings'), true))
			return $this->instances[$name];
	}


	/**
	 * Gets allowed attribute
	*/
	public function get_attribute($attribute)
	{
		if(in_array($attribute, array('options', 'defaults'), true))
		{
			switch(func_num_args())
			{
				case 1:
					return $this->{$attribute};

				case 2:
					return $this->{$attribute}[func_get_arg(1)];

				case 3:
					return $this->{$attribute}[func_get_arg(1)][func_get_arg(2)];

				case 4:
					return $this->{$attribute}[func_get_arg(1)][func_get_arg(2)][func_get_arg(3)];
			}
		}
		else
			return false;
	}


	/**
	 * Equeues admin scripts and styles
	*/
	public function admin_scripts_styles($page)
	{
		// loads only for settings page
		if($page === 'settings_page_post-views-counter')
		{
			wp_register_script(
				'post-views-counter-admin-chosen',
				POST_VIEWS_COUNTER_URL.'/assets/chosen/chosen.jquery.min.js',
				array('jquery')
			);

			wp_register_script(
				'post-views-counter-admin',
				POST_VIEWS_COUNTER_URL.'/js/admin.js',
				array('jquery', 'post-views-counter-admin-chosen')
			);

			wp_enqueue_script('post-views-counter-admin-chosen');
			wp_enqueue_script('post-views-counter-admin');

			wp_localize_script(
				'post-views-counter-admin',
				'pvcArgsSettings',
				array(
					'resetToDefaults' => __('Are you sure you want to reset these settings to defaults?', 'post-views-counter')
				)
			);

			wp_register_style(
				'post-views-counter-admin',
				POST_VIEWS_COUNTER_URL.'/css/admin.css'
			);

			wp_register_style(
				'post-views-counter-chosen',
				POST_VIEWS_COUNTER_URL.'/assets/chosen/chosen.min.css'
			);

			wp_enqueue_style('post-views-counter-chosen');
			wp_enqueue_style('post-views-counter-admin');
		}
	}


	/**
	 * Adds link to settings page
	*/
	public function plugin_settings_link($links, $file)
	{
		if(!is_admin() || !current_user_can('manage_options'))
			return $links;

		static $plugin;

		$plugin = plugin_basename(__FILE__);

		if($file == $plugin)
		{
			$settings_link = sprintf('<a href="%s">%s</a>', admin_url('options-general.php').'?page=post-views-counter', __('Settings', 'post-views-counter'));

			array_unshift($links, $settings_link);
		}

		return $links;
	}
}


function Post_Views_Counter()
{
	static $instance;

  	// first call to instance() initializes the plugin
  	if($instance === null || !($instance instanceof Post_Views_Counter))
    	$instance = Post_Views_Counter::instance();

  	return $instance;
}

Post_Views_Counter();
?>