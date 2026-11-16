<?php declare(strict_types=1);

/**
 * bin/lupa as an MCP client starts it.
 */

use Tester\Assert;

require __DIR__ . '/bootstrap.php';


/**
 * @param list<string> $arguments
 * @param array<string, string> $env
 * @return array{int, string, string}  exit code, stdout, stderr
 */
function runBin(array $arguments, string $input = '', array $env = []): array
{
	$command = array_merge([PHP_BINARY, BinFile], $arguments, ['--project', FixtureDir, '--autoload', VendorAutoload]);
	$process = proc_open($command, [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']], $pipes, null, $env + getenv(), ['bypass_shell' => true]);
	fwrite($pipes[0], $input);
	fclose($pipes[0]);
	$stdout = stream_get_contents($pipes[1]);
	$stderr = stream_get_contents($pipes[2]);
	return [proc_close($process), $stdout, $stderr];
}


// MCP session
$messages = [
	[
		'jsonrpc' => '2.0',
		'id' => 1,
		'method' => 'initialize',
		'params' => [
			'protocolVersion' => '2025-11-25',
			'capabilities' => new stdClass,
			'clientInfo' => ['name' => 'test', 'version' => '1'],
		],
	],
	['jsonrpc' => '2.0', 'method' => 'notifications/initialized'],
	['jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/list'],
	['jsonrpc' => '2.0', 'id' => 3, 'method' => 'tools/call', 'params' => ['name' => 'app_info', 'arguments' => new stdClass]],
];
$input = implode("\n", array_map('json_encode', $messages)) . "\n";
[$code, $stdout, $stderr] = runBin(['serve'], $input);
Assert::same(0, $code, $stderr);
Assert::contains('Lupa', $stderr);

$responses = array_map(fn($line) => json_decode($line, true), explode("\n", trim($stdout)));
Assert::same([1, 2, 3], array_column($responses, 'id'));
Assert::same('2025-11-25', $responses[0]['result']['protocolVersion']);
Assert::contains('app_info', array_column($responses[1]['result']['tools'], 'name'));
Assert::false($responses[2]['result']['isError'], $responses[2]['result']['content'][0]['text']);
Assert::same('db.local', $responses[2]['result']['structuredContent']['databases'][0]['host']);


// call
[$code, $stdout] = runBin(['call', 'app_info', '{"packages":"all"}']);
Assert::same(0, $code);
Assert::true(json_decode($stdout, true)['ok']);

[$code, , $stderr] = runBin(['call', 'app_info', '{"packages":1}']);
Assert::same(1, $code);
Assert::contains("Argument 'packages' must be of type string.", $stderr);


// kill switch
[$code, $stdout, $stderr] = runBin(['serve'], '', ['LUPA_DISABLED' => '1']);
Assert::same(1, $code);
Assert::same('', $stdout);
Assert::contains('Lupa is disabled', $stderr);


// help and unknown command
[$code, $stdout] = runBin(['help']);
Assert::same(0, $code);
Assert::contains('lupa serve', $stdout);

[$code, , $stderr] = runBin(['nonsense']);
Assert::same(1, $code);
Assert::contains("Unknown command 'nonsense'", $stderr);

// help asked after a command shows the help instead of running the command
foreach ([['init', '--help'], ['call', '-h'], ['-h']] as $arguments) {
	[$code, $stdout] = runBin($arguments);
	Assert::same(0, $code);
	Assert::contains('lupa serve', $stdout);
}
Assert::false(is_file(FixtureDir . '/.mcp.json'));

// a mistyped option is refused instead of being ignored
[$code, , $stderr] = runBin(['call', 'app_info', '--porject', 'x']);
Assert::same(1, $code);
Assert::contains("Unknown option '--porject'", $stderr);


// Lupa from its own checkout next to applications whose autoloader is elsewhere and offers no nette/di
$runIn = static function (string $root, array $arguments): array {
	$process = proc_open(
		array_merge([PHP_BINARY, BinFile], $arguments, ['--project', $root, '--autoload', "$root/libs/autoload.php"]),
		[['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']],
		$pipes,
		null,
		null,
		['bypass_shell' => true],
	);
	fclose($pipes[0]);
	$stdout = (string) stream_get_contents($pipes[1]);
	$stderr = (string) stream_get_contents($pipes[2]);
	return [proc_close($process), $stdout, $stderr];
};

// a legacy Nette application with its packages in libs/ and no app/Bootstrap.php is recognized by its classes
$legacy = TempDir . '/legacy';
mkdir("$legacy/libs", 0777, true);
file_put_contents("$legacy/libs/Configurator.php", "<?php\nnamespace Nette;\nclass Configurator {}\n");
file_put_contents("$legacy/libs/autoload.php", "<?php\nspl_autoload_register(function (\$class) {\n"
	. "\tif (\$class === 'Nette\\\\Configurator') { require __DIR__ . '/Configurator.php'; }\n});\n");
[$code, $stdout, $stderr] = $runIn($legacy, ['init']);
Assert::same(0, $code, $stderr);
Assert::contains('Setting up a Nette application', $stdout);
Assert::contains("\tframework: nette\n", (string) file_get_contents("$legacy/lupa.neon"));

// an application without any packages gets the libraries Lupa needs from the checkout, though not its Nette
$bare = TempDir . '/bare';
mkdir("$bare/libs", 0777, true);
file_put_contents("$bare/libs/autoload.php", "<?php\n");
file_put_contents("$bare/lupa-bootstrap.php", "<?php\nreturn ['parameters' => [], 'databases' => []];\n");
file_put_contents("$bare/lupa.neon", "inspector:\n\taudit: audit.jsonl\n");
[$code, $stdout, $stderr] = $runIn($bare, ['call', 'app_info']);
Assert::same(0, $code, $stdout . $stderr); // without mbstring too, when the checkout has a polyfill for nette/neon 4
Assert::same('none', json_decode($stdout, true)['data']['application']['framework']);

// an application with nette/di but without nette/schema, as one of Nette 2.4, gets no package of the checkout,
// whose versions need not fit those of the application, but is told what to add
$partial = TempDir . '/partial';
mkdir("$partial/libs", 0777, true);
file_put_contents("$partial/libs/autoload.php", str_replace('VENDOR', var_export(dirname(VendorAutoload), true), <<<'PHP'
	<?php
	if (!class_exists(Composer\Autoload\ClassLoader::class)) {
		require VENDOR . '/composer/ClassLoader.php';
	}
	$loader = new Composer\Autoload\ClassLoader;
	foreach (require VENDOR . '/composer/autoload_classmap.php' as $class => $file) {
		if (preg_match('~/nette/(di|utils|neon|php-generator|robot-loader)/~', str_replace('\\', '/', $file))) {
			$loader->addClassMap([$class => $file]);
		}
	}
	$loader->register();
	PHP));
file_put_contents("$partial/lupa-bootstrap.php", "<?php\nreturn ['parameters' => [], 'databases' => []];\n");
file_put_contents("$partial/lupa.neon", "inspector:\n\taudit: audit.jsonl\n");
[$code, $stdout, $stderr] = $runIn($partial, ['call', 'app_info']);
Assert::same(1, $code, $stdout);
Assert::match('Error: The application has nette/di, %a% but not nette/schema, which Lupa needs%A%', $stderr);

// init reads what Composer installed without running the autoloader, which may check another PHP, as in Docker,
// and with --framework it does not look at all
$docker = TempDir . '/docker';
mkdir("$docker/libs/composer", 0777, true);
file_put_contents("$docker/libs/autoload.php", "<?php\nthrow new RuntimeException('Composer detected issues in your platform');\n");
file_put_contents("$docker/libs/composer/installed.json", json_encode(['packages' => [['name' => 'nette/bootstrap']]]));
[$code, $stdout, $stderr] = $runIn($docker, ['init', '--docker', 'app']);
Assert::same(0, $code, $stdout . $stderr);
Assert::contains("\tframework: nette\n", (string) file_get_contents("$docker/lupa.neon"));
$forced = TempDir . '/forced';
mkdir("$forced/libs", 0777, true);
file_put_contents("$forced/libs/autoload.php", "<?php\nthrow new RuntimeException('Composer detected issues in your platform');\n");
[$code, $stdout, $stderr] = $runIn($forced, ['init', '--framework', 'none']);
Assert::same(0, $code, $stdout . $stderr);
Assert::contains("\tframework: none\n", (string) file_get_contents("$forced/lupa.neon"));

// packages that only claim to replace the libraries Lupa needs end with a message, not a fatal error
$claimed = TempDir . '/claimed';
mkdir("$claimed/libs/composer", 0777, true);
file_put_contents("$claimed/libs/autoload.php", "<?php\n");
$replace = ['nette/di' => '*', 'nette/neon' => '*', 'nette/schema' => '*'];
file_put_contents("$claimed/libs/composer/installed.json", json_encode(['packages' => [['name' => 'acme/meta', 'replace' => $replace]]]));
file_put_contents("$claimed/lupa-bootstrap.php", "<?php\nreturn ['parameters' => [], 'databases' => []];\n");
file_put_contents("$claimed/lupa.neon", "inspector:\n\taudit: audit.jsonl\n");
[$code, $stdout, $stderr] = $runIn($claimed, ['call', 'app_info']);
Assert::same(1, $code, $stdout);
Assert::same(
	"Error: Lupa needs nette/di, nette/neon and nette/schema, which the application does not provide. Install Lupa into it with 'composer require --dev jancinert/lupa'.\n",
	$stderr,
);
