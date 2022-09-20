<?php
/**
 * Copies the .env.example into a new .env file and asks questions to fill it in.
 *
 * @package NebulaTools
 */

namespace Eighteen73\NebulaTools\DotEnv;

use Composer\IO\ConsoleIO;

/**
 * Copies the .env.example into a new .env file and asks questions to fill it in.
 */
class Editor {

	/**
	 * Track whether we've just made a fresh .env file
	 *
	 * @var bool
	 */
	protected bool $is_new = false;

	/**
	 * Create a new .env file based on .env.example
	 *
	 * @return void
	 */
	public function make_dotenv() {
		if ( file_exists( '.env' ) ) {
			return;
		}
		copy( '.env.example', '.env' );
		chmod( '.env', 0600 );
		$this->is_new = true;
	}

	/**
	 * interactively fill in the .env file
	 *
	 * @param ConsoleIO $io Composer's console
	 * @return void
	 */
	public function populate_dotenv( ConsoleIO $io ) {
		if ( ! $this->is_new ) {
			return;
		}

		// App name based on dir name that it was cloned into
		$app_name = $this->get_site_name();

		// Get user preferences
		$io->write( '' );
		$io->write( 'Answer these question to pre-fill your .env file.' );

		// Hostname
		do {
			$io->write( '' );
			$hostname_suggestions = $this->get_hostnames( $app_name );
			$custom_choice        = (string) ( count( $hostname_suggestions ) - 1 );
			$app_hostname         = $io->select( 'Website hostname: [0]', $hostname_suggestions, '0' );
			if ( $app_hostname === $custom_choice ) {
				$app_hostname = $io->ask( 'Custom hostname: ' );
			} else {
				$app_hostname = $hostname_suggestions[ $app_hostname ];
			}
		} while ( ! $app_hostname );

		$io->write( '' );
		$ssl     = $io->askConfirmation( 'Use https:// ? [yes]: ', true );
		$wp_home = $ssl ? "https://{$app_hostname}" : "http://{$app_hostname}";
		$io->write( '' );

		$default_db_name = str_replace( '-', '_', $app_name );
		do {
			$db_name = $io->ask( "Database name [{$default_db_name}]: ", $default_db_name );
		} while ( ! $db_name );
		do {
			$db_user = $io->ask( 'Database user: ' );
		} while ( ! $db_user );
		do {
			$db_password = $io->ask( 'Database password: ' );
		} while ( ! $db_password );

		// Write settings to file
		$this->write_dotenv_value( 'WP_HOME', $wp_home );
		$this->write_dotenv_value( 'DB_NAME', $db_name );
		$this->write_dotenv_value( 'DB_USER', $db_user );
		$this->write_dotenv_value( 'DB_PASSWORD', $db_password );

		$this->get_keys();
	}

	/**
	 * Rudimentary attempt to name the site sensibly if the dirname contains a TLD
	 *
	 * @return string
	 */
	protected function get_site_name(): string {
		$name = trim( strtolower( basename( getcwd() ) ) );
		$name = preg_replace( '/\.uk$/', '', $name );
		$name = preg_replace( '/\.co$/', '', $name );
		$name = preg_replace( '/\.com$/', '', $name );
		$name = preg_replace( '/[^a-zA-Z0-9_]/', '-', $name );

		return $name;
	}

	/**
	 * Suggest common development hostnames
	 *
	 * @param string $app_name The website's name
	 * @return array
	 */
	protected function get_hostnames( string $app_name ): array {
		$hostnames   = [];
		$hostnames[] = "{$app_name}.test";
		$hostnames[] = "{$app_name}.local";
		$hostnames[] = 'Enter Custom...';

		return $hostnames;
	}

	/**
	 * Get random security keys
	 *
	 * @return void
	 */
	protected function get_keys() {
		$keys_raw = file_get_contents( 'https://nebula-keys.eighteen73.co.uk/?json=true' );
		$keys     = json_decode( $keys_raw );
		foreach ( $keys as $key ) {
			// Don't treat $ in keys as $n replacements
			$this->write_dotenv_value( $key[0], str_replace( '$', '\$', $key[1] ) );
		}
	}

	/**
	 * Write a key's value to the .env file
	 *
	 * @param string $key The .env key
	 * @param string $value The new .env value
	 * @return void
	 */
	protected function write_dotenv_value( string $key, string $value ) {
		$pattern     = "/({$key}=['\"]*)\n/";
		$replacement = "{$key}=\"{$value}\"\n";
		file_put_contents( '.env', preg_replace( $pattern, $replacement, file_get_contents( '.env' ) ) );
	}
}
