<?php
/**
 * Controller for the plugin.
 * 
 * @author Paul Clark <http://pdclark.com>
 */
class GPU_Controller {

	/**
	 * @var GPU_Controller Instance of this class.
	 */
	private static $instance = false;

	/**
	 * @var string Key for plugin options in wp_options table
	 */
	const OPTION_KEY = GPU_SLUG;

	/**
	 * @var int How often should transients be updated, in seconds.
	 */
	protected static $update_interval;

	/**
	 * @var array Options from wp_options
	 */
	protected $options;

	/**
	 * @var GPU_Admin Admin object
	 */
	protected $admin;

	/**
	 * @see self::disable_git_ssl()
	 * @var array List of URLs related to Git repositories.
	 */
	var $git_urls = array();

	/**
	 * @var array Installed plugins that list a Git URI.
	 */
	var $plugins = array();
	
	/**
	 * Don't use this. Use ::get_instance() instead.
	 */
	public function __construct() {
		if ( !self::$instance ) {
			$message = '<code>' . __CLASS__ . '</code> is a singleton.<br/> Please get an instantiate it with <code>' . __CLASS__ . '::get_instance();</code>';
			wp_die( $message );
		}       
	}

	/**
	 * If a variable is accessed from outside the class,
	 * return a value from method get_$var()
	 * 
	 * For example, $inbox->unread_count returns $inbox->get_unread_count()
	 * 
	 * @return pretty-much-anything
	 */
	public function __get( $var ) {
		$method = 'get_' . $var;

		if ( method_exists( $this, $method ) ) {
			return $this->$method();
		}else {
			return $this->$var;
		}
	}
	
	public static function get_instance() {
		if ( !is_a( self::$instance, __CLASS__ ) ) {
			self::$instance = true;
			self::$instance = new self();
			self::$instance->init();
		}
		return self::$instance;
	}
	
	/**
	 * Initial setup. Called by get_instance.
	 */
	protected function init() {

		$this->options = get_site_option( self::OPTION_KEY );

		// Filter allows search results to be updated more or less frequently.
		// Default is 60 minutes
		GPU_Controller::$update_interval = apply_filters( 'gpu_update_interval', 60*60 );

		add_action( 'admin_init', array( $this, 'clear_cache_if_debugging' ) );

		add_filter( 'extra_plugin_headers', array($this, 'extra_plugin_headers') );

		add_filter( 'pre_set_site_transient_update_plugins', array( $this, 'pre_set_site_transient_update_plugins' ) );



		// Todo: Move below methods into Updater class if appropriate

		// Build Git plugin list
		add_action( 'admin_init', array($this, 'load_plugins'), 20 );

		// Plugin details screen
		add_filter( 'plugins_api', array( $this, 'get_plugin_info' ), 10, 3 );

		// Cleanup and activate plugins after update
		add_filter( 'upgrader_post_install', array( $this, 'upgrader_post_install' ), 10, 3 );

		// HTTP Timeout
		add_filter( 'http_request_timeout', array( $this, 'http_request_timeout' ) );

		add_filter( 'http_request_args', array($this, 'disable_git_ssl_verify'), 10, 2 );

	}

	public function get_option( $key ) {
		if ( isset( $this->options[ $key ] ) ) {
			return $this->options[ $key ];
		}else {
			return false;
		}
	}

	/**
	 * Load HTML template from views directory.
	 * Contents of $args are turned into variables for use in the template.
	 * 
	 * For example, $args = array( 'foo' => 'bar' );
	 *   becomes variable $foo with value 'bar'
	 */
	public static function get_template( $file, $args = array() ) {
		extract( $args );

		include GPU_PLUGIN_DIR . "/views/$file.php";

	}

	/**
	 * Log data to FireBug using FirePHP
	 * 
	 * @link http://getfirebug.com/
	 * @link http://www.firephp.org/
	 * @return void
	 */
	public function log( $variable, $label='' ) {
		if ( class_exists('FB') && defined('WP_DEBUG') && WP_DEBUG ) {
			FB::log( $variable, $label );
		}
	}

	/**
	 * Clear transient caches if WP_DEBUG is enabled
	 * 
	 * @return void
	 */
	public function clear_cache_if_debugging() {
		if ( ( defined( 'WP_DEBUG' ) && WP_DEBUG ) ) {
			delete_site_transient( 'update_plugins' );
			delete_site_transient( 'git_plugins' );
		}
	}

	/**
	 * Additional headers
	 *
	 * @return array Plugin header key names
	 */
	public function extra_plugin_headers( $headers ) {
		$headers[] = 'Git URI';
		$headers[] = 'Git Branch';

		return $headers;
	}

	/**
	 * Check if an update is available from plugin Git repos
	 *
	 * @param object $transient the plugin data transient
	 * @return object $transient updated plugin data transient
	 */
	public function pre_set_site_transient_update_plugins( $transient ) {

		// If transient doesn't contain checked info, return without modification.
		if ( empty( $transient->last_checked ) && empty( $transient->checked ) ) {
			return $transient;
		}

		// Iterate over all plugins
		foreach( (array) $this->plugins as $plugin ) {

			// TODO: Move version compare to Update parent class

			// Compare remote version to local version
			$remote_is_newer = ( 1 === version_compare( $plugin->remote_version, $plugin->local_version ) );

			if ( $remote_is_newer ) {

				$response = array(
					'slug'        => $plugin->folder_name,
					'new_version' => $plugin->new_version,
					'url'         => $plugin->homepage,
					'package'     => $plugin->zip_url,
				);

				// Add update data for this plugin
				$transient->response[ $plugin->slug ] = (object) $response;

			}
		}

		return $transient;

	}

	/**
	 *	Build $this->plugins, a list of Github-hosted plugins based on installed plugin headers
	 *
	 * @return void
	 */
	public function load_plugins( $plugins ) {
		$this->plugins = get_site_transient( 'git_plugins' );

		if ( false !== $this->plugins ) {
			return;
		}

		global $wp_version;

		foreach ( get_plugins() as $slug => $args ) {
			$args = array_merge( array( 'slug' => $slug ), $args );

			$plugin = $this->get_plugin_updater_object( $args );
			continue;

			if (false === $plugin ) {
				continue;
			}

			// Using folder name as key for array_key_exists() check in $this->get_plugin_info()
			$this->plugins[ $plugin->key ] = $repo;

		}

		// Refresh plugin list and Git metadata every 6 hours
		set_site_transient( 'git_plugins', $this->plugins, 60*60*6 );

	}


	/**
	 * Callback fn for the http_request_timeout filter
	 *
	 * @return int timeout value
	 */
	public function http_request_timeout() {
		return 2;
	}


	/**
	 * Disable SSL only for Git repo URLs
	 *
	 * @return array $args http_request_args
	 */
	public function disable_git_ssl_verify( $args, $url ) {
		if ( empty( $this->plugins ) ) {
			return;
		}

		if ( in_array( $url, apply_filters( 'gpu_ssl_disabled_urls', array() ) ) ) {
			$args['sslverify'] = false; 
		}

		return $args;
	}

	/**
	 * Return appropriate repository handler based on URI
	 *
	 * @return object
	 */
	public function get_plugin_updater_object( $args ) {

		if ( GPU_Updater_Github::updates_this_plugin( $args ) ) {
			return new GPU_Updater_Github( $args );
		}

		// switch( $parsed['host'] ) {
		// 	case 'github.com':
		// 	case 'www.github.com':
		// 		list( /*nothing*/, $username, $repository ) = explode('/', $parsed['path'] );
		// 		return new GPU_Updater_Github( array_merge($args, array( 'username' => $username, 'repository' => $repository, )) );
		// 	break;
		// 	case 'bitbucket.org':
		// 	case 'www.bitbucket.org':
		// 		list( /*nothing*/, $username, $repository ) = explode('/', $parsed['path'] );
		// 		return new GPU_Updater_Bitbucket( array_merge($args, array( 'username' => $username, 'repository' => $repository, 'user' => $parsed['user'], 'pass' => $parsed['pass'] )) );
		// 	break;
		// }

		// if ( '.git' == substr($parsed['path'], -4) ) {
		// 	return new GPU_Updater_Gitweb( array_merge( $args, $parsed ) );
		// }


		return false;
	}

	/**
	 * Get Plugin info
	 *
	 * @param  bool   $false    Always false
	 * @param  string $action   The API function being performed
	 * @param  object $args     Plugin arguments
	 * @return object $response The plugin info
	 */
	public function get_plugin_info( $false, $action, $response ) {
		// Check if this call API is for the right plugin

		if ( !array_key_exists( $response->slug, (array)$this->plugins ) ) {
			return false;
		}

		$plugin = $this->plugins[ $response->slug ];

		$response->slug = $plugin->slug;
		$response->plugin_name  = $plugin->name;
		$response->version = $plugin->new_version;
		$response->author = $plugin->author;
		$response->homepage = $plugin->homepage;
		$response->requires = $plugin->requires;
		$response->tested = $plugin->tested;
		$response->downloaded   = 0;
		$response->last_updated = $plugin->last_updated;
		$response->sections = array( 'description' => $plugin->description );
		$response->download_link = $plugin->zip_url;

		return $response;
	}


	/**
	 * Upgrader/Updater
	 * Move & activate the plugin, echo the update message
	 *
	 * @since 1.0
	 * @param boolean $true always true
	 * @param mixed $hook_extra not used
	 * @param array $result the result of the move
	 * @return array $result the result of the move
	 */
	public function upgrader_post_install( $true, $hook_extra, $result ) {

		global $wp_filesystem;

		$plugin = $this->plugins[ dirname($hook_extra['plugin']) ];

		// Move & Activate
		$proper_destination = WP_PLUGIN_DIR.'/'.$plugin->folder_name;
		$wp_filesystem->move( $result['destination'], $proper_destination );
		$result['destination'] = $proper_destination;
		$activate = activate_plugin( WP_PLUGIN_DIR.'/'.$plugin->slug );

		// Output the update message
		$fail		= __('The plugin has been updated, but could not be reactivated. Please reactivate it manually.', 'github_plugin_updater');
		$success	= __('Plugin reactivated successfully.', 'github_plugin_updater');
		echo is_wp_error( $activate ) ? $fail : $success;
		return $result;

	}

}
