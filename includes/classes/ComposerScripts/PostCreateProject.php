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

		$dotenv_editor = new Editor();
		$dotenv_editor->make_dotenv();
		$dotenv_editor->populate_dotenv( $io );

		self::check_database( $io );

		$io->write( '' );
		$io->write( 'Thank you for installing Nebula!' );
		$io->write( '' );
	}

	/**
	 * Verify the .env file's database settings
	 *
	 * @param ConsoleIO $io Composer's console
	 * @return void
	 */
	private static function check_database( ConsoleIO $io ) {
		$can_connect    = false;
		$has_database   = false;
		$empty_database = false;

		// Test for database
		do {
			$dotenv = Dotenv::createImmutable( getcwd() );
			$dotenv->load();

			$db_host = $_ENV['DB_HOST'] ?? 'localhost';

			/**
			 * Test for connection
			 */

			$dsn = "mysql:host={$db_host}";
			try {
				$dbh         = new PDO( $dsn, $_ENV['DB_USER'], $_ENV['DB_PASSWORD'] );
				$can_connect = true;
			} catch ( PDOException $e ) {
				$io->write( '' );
				$io->ask( 'Can\'t connect to database engine. Please correct the .env file then hit ENTER.' );
				continue;
			}

			/**
			 * Test for database
			 */

			$dsn .= ";dbname={$_ENV['DB_NAME']}";
			try {
				$dbh          = new PDO( $dsn, $_ENV['DB_USER'], $_ENV['DB_PASSWORD'] );
				$has_database = true;
			} catch ( PDOException $e ) {
				$io->write( '' );
				$io->write( 'The database does not exist.' );
				$io->write( "We can create \"{$_ENV['DB_NAME']}\" now or you can set it up (changing .env if necessary) then retry." );
				$io->write( '' );
				$action = $io->select(
					'What should we do? [0]',
					[
						'Create database',
						'Retry',
					],
					'0'
				);
				if ( $action === '0' ) {
					// Create database and allow to loop
					$dbh->exec( "CREATE DATABASE `{$_ENV['DB_NAME']}`" );
					continue;
				}
			}

			/**
			 * Test for empty database
			 */

			$query  = $dbh->query( 'SHOW TABLES' );
			$tables = $query->fetchAll( PDO::FETCH_COLUMN );
			if ( ! empty( $tables ) ) {
				$io->write( '' );
				$io->write( "Database \"{$_ENV['DB_NAME']}\" is not empty." );
				$io->write( 'Please wipe it manually or enter an alternative DB_NAME into .env then retry.' );
				$io->write( '' );
				$io->ask( "When you're ready hit ENTER" );
				continue;
			}
			$empty_database = true;

		} while ( ! $can_connect || ! $has_database || ! $empty_database );
	}
}
