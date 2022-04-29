<?php

namespace Eighteen73\NebulaTools\ComposerScripts;

use Composer\Script\Event;
use Eighteen73\NebulaTools\DotEnv\Editor;

class PostCreateProject {
	public static function run( Event $event ) {
		$composer = $event->getComposer();
		$io       = $event->getIO();

		$dotenv_editor = new Editor();
		$dotenv_editor->make_dotenv();
		$dotenv_editor->populate_dotenv( $io );

		$io->write( '' );
		$io->write( 'Thank you for installing Nebula!' );
		$io->write( '' );
	}
}
