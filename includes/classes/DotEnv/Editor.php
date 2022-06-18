<?php

namespace Eighteen73\NebulaTools\DotEnv;

use Composer\IO\ConsoleIO;

class Editor {
	protected bool $is_new = false;

	public function make_dotenv() {
		if ( file_exists( '.env' ) ) {
			return;
		}
		copy( '.env.example', '.env' );
		chmod( '.env', 0600 );
		$this->is_new = true;
	}

	public function populate_dotenv( ConsoleIO $io ) {
		if ( ! $this->is_new ) {
			return;
		}

		// App name based on dir name that it was cloned into
		$app_name = $this->get_site_name();

		// Get user preferences
		$io->write( '' );
		$io->write( 'Answer these question to pre-fill your .env file.' );

		// Hostname (string) (count($hostname_suggestions) - 1);
		do {
			$io->write( '' );
			$hostname_suggestions = $this->get_hostnames( $app_name );
			$custom_choice        = (string) ( count( $hostname_suggestions ) - 1 );
			$app_hostname         = $io->select( "Website hostname: [0]", $hostname_suggestions, '0' );
			if ( $app_hostname === $custom_choice ) {
				$app_hostname = $io->ask( 'Custom hostname: ' );
			} else {
				$app_hostname = $hostname_suggestions[ $app_hostname ];
			}
		} while ( ! $app_hostname );

		$io->write( '' );
		$ssl     = $io->askConfirmation( "Use https:// ? [yes]: ", true );
		$wp_home = $ssl ? "https://{$app_hostname}" : "http://{$app_hostname}";
		$io->write( '' );

		$default_db_name = str_replace( '-', '_', $app_name );
		do {
			$db_name = $io->ask( "Database name [{$default_db_name}]: ", $default_db_name );
		} while ( ! $db_name );
		do {
			$db_user = $io->ask( "Database user: " );
		} while ( ! $db_user );
		do {
			$db_password = $io->ask( "Database password: " );
		} while ( ! $db_password );

		// Write settings to file
		$this->write_dotenv_value( 'WP_HOME', $wp_home );
		$this->write_dotenv_value( 'DB_NAME', $db_name );
		$this->write_dotenv_value( 'DB_USER', $db_user );
		$this->write_dotenv_value( 'DB_PASSWORD', $db_password );

		$this->write_dotenv_value( 'AUTH_KEY', $this->random_key() );
		$this->write_dotenv_value( 'SECURE_AUTH_KEY', $this->random_key() );
		$this->write_dotenv_value( 'LOGGED_IN_KEY', $this->random_key() );
		$this->write_dotenv_value( 'NONCE_KEY', $this->random_key() );
		$this->write_dotenv_value( 'AUTH_SALT', $this->random_key() );
		$this->write_dotenv_value( 'SECURE_AUTH_SALT', $this->random_key() );
		$this->write_dotenv_value( 'LOGGED_IN_SALT', $this->random_key() );
		$this->write_dotenv_value( 'NONCE_SALT', $this->random_key() );
	}

	protected function random_key() {
		return bin2hex( random_bytes( 32 ) );
	}

	/**
	 * Rudimentary attempt to name the site sensibly if the dirname contains a TLD
	 * Could be improved
	 */
	protected function get_site_name(): string {
		$name = trim( strtolower( basename( getcwd() ) ) );
		$name = preg_replace( '/\.uk$/', '', $name );
		$name = preg_replace( '/\.co$/', '', $name );
		$name = preg_replace( '/\.com$/', '', $name );
		$name = preg_replace( '/[^a-zA-Z0-9_]/', '-', $name );

		return $name;
	}

	protected function get_hostnames( string $app_name ): array {
		$hostnames   = [];
		$hostnames[] = "{$app_name}.test";
		$hostnames[] = "{$app_name}.local";
		$hostnames[] = "Enter Custom...";

		return $hostnames;
	}

	protected function write_dotenv_value( $key, $value ) {
		$pattern     = "/({$key}=)\n/";
		$replacement = "{$key}={$value}\n";
		file_put_contents( '.env', preg_replace( $pattern, $replacement, file_get_contents( '.env' ) ) );
	}
}
