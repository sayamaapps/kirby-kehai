<?php

/*
 * Loads Kehai's classes on demand, for the plugin (index.php), the CLI and
 * the tests. The CLI loads it and then boots the site, whose Kirby loads
 * the plugin again: the first loader wins and no class is declared twice.
 */
spl_autoload_register(function (string $class): void {
	if (str_starts_with($class, 'Kehai\\')) {
		$file = __DIR__ . '/' . str_replace('\\', '/', substr($class, 6)) . '.php';
		if (is_file($file)) {
			require $file;
		}
	}
});
