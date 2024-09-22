<?php
/**
 * An interactive script that is run after Nebula's project creation to help the developer fill in the .env file.
 *
 * @package NebulaTools
 */

namespace Eighteen73\NebulaTools\ComposerScripts;

use Composer\IO\ConsoleIO;
use Composer\Script\Event;
use Dotenv\Dotenv;
use Eighteen73\NebulaTools\DotEnv\Editor;
use PDO;
use PDOException;

/**
 * An interactive script that is run after Nebula's project creation to help the developer fill in the .env file.
 */
class PostCreateProject {

	/**
	 * The main run command
	 *
	 * @param Event $event The active Composer event
	 * @return void
	 */
	public static function run( Event $event ) {
		$composer = $event->getComposer();
		$io       = $event->getIO();

		self::add_composer_lock_to_repo();

		$dotenv_editor = new Editor();
		$dotenv_editor->make_dotenv();
		$dotenv_editor->populate_dotenv( $io );

		self::reset_database( $io );

		$io->write( '' );
		$io->write( 'Thank you for installing Nebula!' );
		$io->write( '' );
	}

	/**
	 * Update Nebula's .gitignore so the resulting project includes the composer.lock file. This file was deliberately
	 * excluded within Nebula itself.
	 *
	 * @return void
	 */
	private static function add_composer_lock_to_repo() {
		if ( ! file_exists( '.gitignore' ) ) {
			return;
		}
		$content = file_get_contents( '.gitignore' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- runs outside of WordPress
		$content = str_replace( "/composer.lock\n", '', $content );
		file_put_contents( '.gitignore', $content ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- runs outside of WordPress
	}

	/**
	 * The database connection was verified in populate_dotenv() so here we only need
	 * to check that it's empty for a fresh install.
	 *
	 * @param ConsoleIO $io Composer's console
	 * @return void
	 */
	private static function reset_database( ConsoleIO $io ) {
		$dotenv = Dotenv::createImmutable( getcwd() );
		$dotenv->load();

		$db_host = $_ENV['DB_HOST'] ?? 'localhost';
		$dbh     = new \PDO( "mysql:host={$db_host};dbname={$_ENV['DB_NAME']}", $_ENV['DB_USER'], $_ENV['DB_PASSWORD'] ); // phpcs:ignore WordPress.DB.RestrictedClasses.mysql__PDO -- runs outside of WordPress

		$database_is_empty = false;
		do {
			$query  = $dbh->query( 'SHOW TABLES' );
			$tables = $query->fetchAll( PDO::FETCH_COLUMN );
			if ( empty( $tables ) ) {
				$database_is_empty;
				break;
			}

			$io->write( '' );
			$io->write( "Database \"{$_ENV['DB_NAME']}\" is not empty." );
			$io->write( 'We can delete all tables now or you can wipe it manually then select retry.' );
			$io->write( '' );
			$action = $io->select(
				'What should we do? [0]',
				[
					'Delete all tables automatically (THERE WILL BE DATA LOSS!)',
					'Retry',
				],
				'0'
			);

			if ( $action === '0' ) {

				$drop_queries = $dbh->query( "SELECT concat('DROP TABLE IF EXISTS `', table_name, '`;') FROM information_schema.tables WHERE table_schema = '{$_ENV['DB_NAME']}'" );
				$dbh->query( 'SET FOREIGN_KEY_CHECKS = 0' );
				foreach ( $drop_queries as $drop_query ) {
					$dbh->query( $drop_query[0] );
				}
				$dbh->query( 'SET FOREIGN_KEY_CHECKS = 1' );
			}
		} while ( ! $database_is_empty );
	}
}
