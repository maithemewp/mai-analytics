<?php
/**
 * Declares this library to maithemewp/mai-package-loader, which loads the
 * newest copy on a site whichever plugin bundles it. Bump the version with
 * every release, together with Mai_Logger::VERSION.
 */

return [
	'name'    => 'maithemewp/mai-logger',
	'version' => '0.2.0',
	'classes' => [
		'Mai_Logger' => 'Mai_Logger.php',
	],
];
