<?php declare(strict_types=1);

// Fixture application: a container compiled from config.neon with a lazy Dibi connection.

echo 'output printed while booting must not reach the result';

$loader = new Nette\DI\ContainerLoader(sys_get_temp_dir() . '/lupa-fixture-' . PHP_VERSION_ID, true);
$class = $loader->load(function (Nette\DI\Compiler $compiler): void {
	$compiler->addConfig(['parameters' => ['appDir' => __DIR__]]);
	$compiler->loadConfig(__DIR__ . '/config.neon');
}, __FILE__);

return new $class;
