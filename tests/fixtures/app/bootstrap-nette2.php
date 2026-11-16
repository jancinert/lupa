<?php declare(strict_types=1);

// The fixture application the way an application on Nette 2.4 boots: through Nette\Configurator.

$runtimeDir = (string) getenv('FIXTURE_RUNTIME_DIR');

$configurator = new Nette\Configurator;
$configurator->setDebugMode(true);
$configurator->setTempDirectory($runtimeDir . '/temp');
$configurator->addParameters([
	'appDir' => __DIR__ . '/src',
	'logDir' => $runtimeDir . '/log',
	'runtimeDir' => $runtimeDir,
]);
$configurator->addConfig(__DIR__ . '/config-nette2.neon');
return $configurator;
