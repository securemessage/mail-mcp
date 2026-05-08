<?php
/**
 * Tool classes autoloader.
 *
 * Registers a PSR-style autoloader for unnamespaced tool classes
 * in the tools/ directory (e.g., ConnectionTools, MailboxTools, InstanceTools).
 */

spl_autoload_register(function ($class) {
	if (str_contains($class, '\\')) {
		return;
	}
	$toolFile = APPLICATION_ROOT . 'tools' . DIRECTORY_SEPARATOR . $class . '.php';
	if (file_exists($toolFile)) {
		require $toolFile;
	}
});
