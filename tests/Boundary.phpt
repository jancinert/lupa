<?php declare(strict_types=1);

/**
 * The core does not know the adapter for Nette applications, so that another adapter can stand beside it
 * and the two can become separate packages. Only the places that wire the adapter in may name it.
 */

use Tester\Assert;

require __DIR__ . '/bootstrap.php';


$wiring = ['Inspector.php', 'Worker/Worker.php'];

$src = dirname(__DIR__) . '/src';
$found = [];
$files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($src, FilesystemIterator::SKIP_DOTS));
foreach ($files as $file) {
	$name = str_replace('\\', '/', substr($file->getPathname(), strlen($src) + 1));
	if ($file->getExtension() !== 'php' || strncmp($name, 'Nette/', 6) === 0) {
		continue;
	}

	$code = php_strip_whitespace($file->getPathname()); // without comments, which may mention the libraries
	$body = (string) preg_replace('~\buse [\w\\\\ ,{}]+;~', '', $code); // without imports of the Nette libraries
	$relative = preg_match('~\bnamespace Lupa;~', $code) && preg_match('~(?<![\w\\\\])Nette\\\\~', $body);
	if (strpos($code, 'Lupa\Nette\\') !== false || $relative) {
		$found[] = $name;
	}
}

sort($found);
Assert::same($wiring, $found);
