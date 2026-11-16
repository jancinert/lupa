<?php declare(strict_types=1);

// An application without a framework: the runtime directory is given by the test in FIXTURE_RUNTIME_DIR.

$runtimeDir = (string) getenv('FIXTURE_RUNTIME_DIR');

return [
	'parameters' => ['appDir' => __DIR__ . '/src', 'logDir' => $runtimeDir . '/log', 'tempDir' => $runtimeDir . '/temp'],
	'databases' => [
		'main' => ['dsn' => 'sqlite:' . $runtimeDir . '/app.sqlite'],
		'legacy' => [
			'driver' => class_exists(Dibi\Drivers\SqliteDriver::class) ? 'sqlite' : 'sqlite3',
			'database' => $runtimeDir . '/app.sqlite',
		],
	] + (getenv('FIXTURE_REMOTE') ? ['remote' => ['dsn' => 'mysql:host=db.remote.invalid;dbname=app']] : []),
];
