<?php
/**
 * Class MUPlugins
 *
 * Credit to roots/bedrock-autoloader for inspiration. This has been moved into nebula-tools for our purposes only to
 * remove a dependency and give us a little more control over its implementation.
 *
 * @package eighteen73/nebula-tools
 */

namespace Eighteen73\NebulaTools;

/**
 * Class MUPlugins
 */
class MUPlugins {

	/**
	 * Singleton instance
	 *
	 * @var static
	 */
	private static MUPlugins $instance;

	/**
	 * Store Autoloader cache and site option
	 *
	 * @var array
	 */
	private array $cache;

	/**
	 * Autoloaded plugins
	 *
	 * @var array
	 */
	private array $auto_plugins;

	/**
	 * Autoloaded mu-plugins
	 *
	 * @var array
	 */
	private array $mu_plugins;

	/**
	 * Number of plugins
	 *
	 * @var int
	 */
	private int $count = 0;

	/**
	 * Newly activated plugins
	 *
	 * @var array
	 */
	private array $activated = [];

	/**
	 * Relative path to the mu-plugins dir
	 *
	 * @var string
	 */
	private string $relative_path;

	/**
	 * Create singleton, populate vars, and set WordPress hooks
	 */
	public function __construct() {
		if ( isset( self::$instance ) ) {
			return;
		}

		self::$instance = $this;

		$this->relative_path = '/../' . basename( WPMU_PLUGIN_DIR );

		if ( is_admin() ) {
			add_filter( 'show_advanced_plugins', [ $this, 'show_in_admin' ], 0, 2 );
		}

		$this->load_plugins();
	}

	/**
	 * Run some checks then autoload our plugins.
	 */
	public function load_plugins() {
		$this->check_cache();
		$this->validate_plugins();
		$this->count_plugins();

		array_map(
			static function () {
				include_once WPMU_PLUGIN_DIR . '/' . func_get_args()[0];
			},
			array_keys( $this->cache['plugins'] )
		);

		add_action( 'plugins_loaded', [ $this, 'plugin_hooks' ], -9999 );
	}

	/**
	 * Filter show_advanced_plugins to display the autoloaded plugins
	 *
	 * @param bool   $show Whether to show the advanced plugins for the specified plugin type
	 * @param string $type The plugin type, i.e., `mustuse` or `dropins`
	 * @return false|mixed
	 * {@internal We add the plugin details ourselves, so we return false to disable the filter.}
	 */
	public function show_in_admin( $show, $type ) {
		$screen  = get_current_screen();
		$current = is_multisite() ? 'plugins-network' : 'plugins';

		if ( $screen->base !== $current || $type !== 'mustuse' || ! current_user_can( 'activate_plugins' ) ) {
			return $show;
		}

		$this->update_cache();

		$this->auto_plugins = array_map(
			function ( $auto_plugin ) {
				$auto_plugin['Name'] .= ' *';
				return $auto_plugin;
			},
			$this->auto_plugins
		);

		$GLOBALS['plugins']['mustuse'] = array_unique( array_merge( $this->auto_plugins, $this->mu_plugins ), SORT_REGULAR );

		return false;
	}

	/**
	 * This sets the cache or calls for an update
	 */
	private function check_cache() {
		$cache = get_site_option( 'nebula_autoloader' );

		if ( $cache === false || ( isset( $cache['plugins'], $cache['count'] ) && count( $cache['plugins'] ) !== $cache['count'] ) ) {
			$this->update_cache();
			return;
		}

		$this->cache = $cache;
	}

	/**
	 * Get the plugins and mu-plugins from the mu-plugin path and remove duplicates.
	 * Check cache against current plugins for newly activated plugins.
	 * After that, we can update the cache.
	 */
	private function update_cache() {
		require_once ABSPATH . 'wp-admin/includes/plugin.php';

		$this->auto_plugins = get_plugins( $this->relative_path );
		$this->mu_plugins   = get_mu_plugins();
		$plugins            = array_diff_key( $this->auto_plugins, $this->mu_plugins );
		$rebuild            = ! isset( $this->cache['plugins'] );
		$this->activated    = $rebuild ? $plugins : array_diff_key( $plugins, $this->cache['plugins'] );
		$this->cache        = [
			'plugins' => $plugins,
			'count'   => $this->count_plugins(),
		];

		update_site_option( 'nebula_autoloader', $this->cache );
	}

	/**
	 * This accounts for the plugin hooks that would run if the plugins were
	 * loaded as usual. Plugins are removed by deletion, so there's no way
	 * to deactivate or uninstall.
	 */
	public function plugin_hooks() {
		if ( ! is_array( $this->activated ) ) {
			return;
		}

		foreach ( $this->activated as $plugin_file => $plugin_info ) {
			do_action( 'activate_' . $plugin_file );
		}
	}

	/**
	 * Check that the plugin file exists, if it doesn't update the cache.
	 */
	private function validate_plugins() {
		foreach ( $this->cache['plugins'] as $plugin_file => $plugin_info ) {
			if ( ! file_exists( WPMU_PLUGIN_DIR . '/' . $plugin_file ) ) {
				$this->update_cache();
				break;
			}
		}
	}

	/**
	 * Count the number of autoloaded plugins.
	 *
	 * Count our plugins (but only once) by counting the top level folders in the
	 * mu-plugins dir. If it's more or less than last time, update the cache.
	 *
	 * @return int Number of autoloaded plugins.
	 */
	private function count_plugins() {
		if ( isset( $this->count ) ) {
			return $this->count;
		}

		$count = count( glob( WPMU_PLUGIN_DIR . '/*/', GLOB_ONLYDIR | GLOB_NOSORT ) );

		if ( ! isset( $this->cache['count'] ) || $count !== $this->cache['count'] ) {
			$this->count = $count;
			$this->update_cache();
		}

		return $this->count;
	}
}
