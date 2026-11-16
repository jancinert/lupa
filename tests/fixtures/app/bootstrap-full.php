<?php declare(strict_types=1);

// Fixture application with an SQLite database, a presenter and templates.
// The runtime directory (database, logs, compiled templates) is given by the test in FIXTURE_RUNTIME_DIR.

$runtimeDir = (string) getenv('FIXTURE_RUNTIME_DIR');

$loader = new Nette\DI\ContainerLoader($runtimeDir . '/container', true);
$class = $loader->load(function (Nette\DI\Compiler $compiler) use ($runtimeDir): void {
	$compiler->addConfig(['parameters' => [
		'appDir' => __DIR__ . '/src',
		'logDir' => $runtimeDir . '/log',
		'tempDir' => $runtimeDir . '/temp',
		'runtimeDir' => $runtimeDir,
	]]);
	$compiler->addExtension('extensions', new Nette\DI\Extensions\ExtensionsExtension);
	$compiler->loadConfig(__DIR__ . '/config-full.neon');
}, $runtimeDir);

return new $class;
