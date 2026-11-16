<?php declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

Tester\Environment::setup();

define('FixtureDir', __DIR__ . '/fixtures/app');
define('BinFile', __DIR__ . '/../bin/lupa');
define('VendorAutoload', __DIR__ . '/../vendor/autoload.php');

// each test process gets its own directory
define('TempDir', __DIR__ . '/tmp/' . getmypid());
@mkdir(TempDir, 0777, true); // @ the directory may already exist
Tester\Helpers::purge(TempDir);


/**
 * For tests whose fixture application is written for Nette 3; Nette2.phpt covers Nette 2.4.
 */
function skipOnNette2(): void
{
	if (!interface_exists(Nette\Routing\Router::class)) {
		Tester\Environment::skip('The fixture application of this test needs Nette 3.');
	}
}


function createFixtureProject(string $config = 'lupa.neon'): Lupa\Project
{
	return new Lupa\Project(FixtureDir, VendorAutoload, FixtureDir . '/' . $config);
}


function compileContainer(string $neonFile): Nette\DI\Container
{
	// TempDir is purged for each test, so no rebuild checks are needed (nette/di 3.0.0 fails in them on PHP 7.4)
	$loader = new Nette\DI\ContainerLoader(TempDir . '/container', false);
	$class = $loader->load(function (Nette\DI\Compiler $compiler) use ($neonFile): void {
		$compiler->loadConfig($neonFile);
	}, $neonFile);
	return new $class;
}


/**
 * Checks the result of a tool against the schema the tool declares.
 * @param array<string, mixed> $data
 */
function assertResult(string $name, array $data): void
{
	static $catalog;
	$catalog ??= Lupa\Inspector::createContainer(FixtureDir . '/lupa-render.neon')->getByType(Lupa\Tools\ToolCatalog::class);
	$tool = $catalog->getTool($name);
	if ($tool !== null && $tool->outputSchema !== null) {
		Tester\Assert::same([], findSchemaErrors($tool->outputSchema, $data, $name));
	}
}


/**
 * Compares a decoded JSON value with a JSON Schema of types, properties and items.
 * @param array<string, mixed> $schema
 * @param mixed $value
 * @return list<string>
 */
function findSchemaErrors(array $schema, $value, string $path): array
{
	$isList = is_array($value) && array_values($value) === $value;
	$kinds = [
		'string' => is_string($value),
		'integer' => is_int($value),
		'number' => is_int($value) || is_float($value),
		'boolean' => is_bool($value),
		'null' => $value === null,
		'array' => $isList,
		'object' => is_array($value) && ($value === [] || !$isList), // an empty PHP array is both
	];
	$types = (array) ($schema['type'] ?? []);
	if ($types && !array_filter($types, fn(string $type) => $kinds[$type])) {
		return ["$path is " . gettype($value) . ', the schema says ' . implode('|', $types)];
	}

	$errors = [];
	if ($kinds['object'] && is_array($schema['properties'] ?? null)) {
		foreach ($schema['properties'] as $name => $property) {
			if (array_key_exists($name, $value)) {
				$errors = array_merge($errors, findSchemaErrors($property, $value[$name], "$path.$name"));
			}
		}
	}

	if ($isList && isset($schema['items'])) {
		foreach ($value as $i => $item) {
			$errors = array_merge($errors, findSchemaErrors($schema['items'], $item, "{$path}[$i]"));
		}
	}

	return $errors;
}


/**
 * Some builds of PHP end the process at once when the memory runs out, without the shutdown functions the inspector
 * answers from: the packages of deb.sury.org for PHP 7.4 to 8.1 do. The call then fails without a fallback.
 */
function shutdownRunsAfterExhaustedMemory(): bool
{
	static $runs;
	if ($runs === null) {
		$file = TempDir . '/exhausted-memory.php';
		file_put_contents($file, "<?php register_shutdown_function(function () { echo 'ran'; });\n"
			. "ini_set('display_errors', '0'); ini_set('memory_limit', '16M'); str_repeat('x', 100000000);\n");
		exec(escapeshellarg(PHP_BINARY) . ' -n ' . escapeshellarg($file) . ' 2>&1', $output);
		$runs = in_array('ran', $output, true);
	}

	return $runs;
}


/**
 * Seconds a guard against runaway masking may take: the measuring of code coverage slows the code several times.
 */
function getTimeLimit(float $seconds): float
{
	return $seconds * (getenv('NETTE_TESTER_COVERAGE') ? 6 : 1);
}
