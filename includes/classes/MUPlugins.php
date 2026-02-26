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
	 * @var self
	 */
	private static self $instance;

	/**
	 * Store Autoloader cache and site option
	 *
	 * @var array
	 */
	private array $cache = [];

	/**
	 * Autoloaded plugins
	 *
	 * @var array
	 */
	private array $auto_plugins = [];

	/**
	 * Autoloaded mu-plugins
	 *
	 * @var array
	 */
	private array $mu_plugins = [];

	/**
	 * Number of plugins
	 *
	 * @var int|null
	 */
	private ?int $count = null;

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
	 * Whether we are in proxy generation mode
	 *
	 * @var bool
	 */
	private bool $is_proxy_mode = false;

	/**
	 * Create singleton, populate vars, and set WordPress hooks
	 */
	public function __construct() {
		if ( isset( self::$instance ) ) {
			return;
		}

		self::$instance = $this;

		// Check if we are being loaded from the new '_autoload.php' entry point
		$backtrace = debug_backtrace( DEBUG_BACKTRACE_IGNORE_ARGS, 10 );
		foreach ( $backtrace as $frame ) {
			if ( isset( $frame['file'] ) ) {
				$basename = basename( $frame['file'] );
				if ( $basename === '_autoload.php' ) {
					$this->is_proxy_mode = true;
					break;
				}
				if ( $basename === 'nebula-autoloader.php' ) {
					$this->is_proxy_mode = false;
					break;
				}
			}
		}

		$this->relative_path = $this->calculate_relative_path();

		if ( is_admin() ) {
			add_filter( 'show_advanced_plugins', [ $this, 'show_in_admin' ], 0, 2 );
		}

		$this->load_plugins();
	}

	/**
	 * Calculate the relative path from the plugins directory to the mu-plugins directory.
	 *
	 * @return string
	 */
	private function calculate_relative_path(): string {
		$from = explode( '/', wp_normalize_path( WP_PLUGIN_DIR ) );
		$to   = explode( '/', wp_normalize_path( WPMU_PLUGIN_DIR ) );

		// Remove common prefix
		while ( ! empty( $from ) && ! empty( $to ) && $from[0] === $to[0] ) {
			array_shift( $from );
			array_shift( $to );
		}

		// Calculate how many levels to go up from 'from', then append 'to'
		$relative = str_repeat( '../', count( $from ) ) . implode( '/', $to );

		// Prepend a slash if it doesn't have one (get_plugins expects a relative suffix to WP_PLUGIN_DIR)
		return '/' . ltrim( $relative, '/' );
	}

	/**
	 * Run some checks then autoload our plugins.
	 */
	public function load_plugins() {
		$this->check_cache();
		$this->validate_plugins();
		$this->get_plugin_count();

		// In proxy mode, WordPress handles the loading of the generated files alphabetically.
		if ( ! $this->is_proxy_mode ) {
			foreach ( array_keys( $this->cache['plugins'] ) as $plugin_file ) {
				include_once WPMU_PLUGIN_DIR . '/' . $plugin_file;
			}
		}

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

		// In proxy mode, standard WordPress scanning will see our proxies, but we still
		// want to show them with the asterisk for consistency.
		$this->update_cache();

		// Hide the proxy files themselves from the list so we don't get duplicates.
		$this->mu_plugins = array_filter(
			$this->mu_plugins,
			function ( $file ) {
				return strpos( $file, '_autoload-' ) !== 0;
			},
			ARRAY_FILTER_USE_KEY
		);

		$GLOBALS['plugins']['mustuse'] = array_unique( array_merge( $this->auto_plugins, $this->mu_plugins ), SORT_REGULAR );

		return false;
	}

	/**
	 * This sets the cache or calls for an update
	 */
	private function check_cache() {
		$cache = get_site_option( 'nebula_autoloader' );

		if ( ! is_array( $cache ) || ! isset( $cache['plugins'], $cache['count'] ) ) {
			$this->update_cache();
			return;
		}

		$this->cache = $cache;

		// If we are in proxy mode but no proxies exist yet, force a sync now.
		if ( $this->is_proxy_mode && empty( glob( WPMU_PLUGIN_DIR . '/_autoload-*.php' ) ) ) {
			$this->update_cache();
		}
	}

	/**
	 * Get the number of plugin directories in mu-plugins directly from the filesystem.
	 *
	 * @return int
	 */
	private function count_plugins_on_disk(): int {
		return count( glob( WPMU_PLUGIN_DIR . '/*/', GLOB_ONLYDIR | GLOB_NOSORT ) );
	}

	/**
	 * Get the plugins and mu-plugins from the mu-plugin path and remove duplicates.
	 * Check cache against current plugins for newly activated plugins.
	 * After that, we can update the cache.
	 */
	private function update_cache() {
		require_once ABSPATH . 'wp-admin/includes/plugin.php';

		$auto_plugins     = get_plugins( $this->relative_path );
		$this->mu_plugins = get_mu_plugins();
		$plugins          = array_diff_key( $auto_plugins, $this->mu_plugins );

		$this->auto_plugins = $plugins;

		$rebuild         = ! isset( $this->cache['plugins'] );
		$this->activated = $rebuild ? $plugins : array_diff_key( $plugins, $this->cache['plugins'] );
		$this->count     = $this->count_plugins_on_disk();
		$this->cache     = [
			'plugins' => $plugins,
			'count'   => $this->count,
		];

		update_site_option( 'nebula_autoloader', $this->cache );

		if ( $this->is_proxy_mode ) {
			$this->sync_proxies();
		}
	}

	/**
	 * Synchronise proxy files in the mu-plugins directory.
	 */
	private function sync_proxies() {
		$existing_proxies = glob( WPMU_PLUGIN_DIR . '/_autoload-*.php' );
		$current_proxies  = [];

		foreach ( $this->cache['plugins'] as $file => $details ) {
			$slug              = dirname( $file );
			$proxy_file        = WPMU_PLUGIN_DIR . "/_autoload-{$slug}.php";
			$current_proxies[] = $proxy_file;

			if ( ! file_exists( $proxy_file ) ) {
				$this->write_proxy( $proxy_file, $file );
			}
		}

		// Cleanup orphaned proxies
		foreach ( $existing_proxies as $proxy ) {
			if ( ! in_array( $proxy, $current_proxies, true ) ) {
				unlink( $proxy );
			}
		}

		$this->ensure_gitignore();
	}

	/**
	 * Write a proxy file that includes the original plugin's header.
	 *
	 * @param string $proxy_path Absolute path to the proxy file.
	 * @param string $plugin_rel Relative path to the actual plugin file from mu-plugins.
	 */
	private function write_proxy( string $proxy_path, string $plugin_rel ) {
		$source_path = WPMU_PLUGIN_DIR . '/' . $plugin_rel;
		$header      = '';

		if ( file_exists( $source_path ) ) {
			$content = file_get_contents( $source_path, false, null, 0, 8192 );
			if ( preg_match( '/\/\*\*.*?\*\//s', $content, $matches ) ) {
				$header = $matches[0];
			}
		}

		$php_content = "<?php\n{$header}\n\nrequire_once __DIR__ . '/{$plugin_rel}';\n";
		file_put_contents( $proxy_path, $php_content );
	}

	/**
	 * Ensure the .gitignore file exists and ignores our generated proxies.
	 */
	private function ensure_gitignore() {
		$gitignore_path = WPMU_PLUGIN_DIR . '/.gitignore';
		$pattern        = '_autoload-*.php';
		$content        = file_exists( $gitignore_path ) ? file_get_contents( $gitignore_path ) : '';

		if ( strpos( $content, $pattern ) === false ) {
			$content .= ( $content ? "\n" : '' ) . $pattern . "\n";
			file_put_contents( $gitignore_path, $content );
		}
	}

	/**
	 * This accounts for the plugin hooks that would run if the plugins were
	 * loaded as usual. Plugins are removed by deletion, so there's no way
	 * to deactivate or uninstall.
	 */
	public function plugin_hooks() {
		if ( empty( $this->activated ) ) {
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
	 * Get the number of autoloaded plugins, validating against the cache if necessary.
	 *
	 * @return int Number of autoloaded plugins.
	 */
	private function get_plugin_count() {
		if ( $this->count !== null ) {
			return $this->count;
		}

		$this->count = $this->count_plugins_on_disk();

		if ( ! isset( $this->cache['count'] ) || $this->count !== $this->cache['count'] ) {
			$this->update_cache();
		}

		return $this->count;
	}
}
