<?php declare(strict_types=1);

// Loads the inspector's own classes without Composer, so that bin/lupa works
// from a checkout next to an application that does not have the package installed.

require_once __DIR__ . '/exceptions.php';

spl_autoload_register(function (string $class): void {
	$prefix = 'Lupa\\';
	if (strncmp($class, $prefix, strlen($prefix)) !== 0) {
		return;
	}

	$file = __DIR__ . '/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
	if (is_file($file)) {
		require $file;
	}
});
