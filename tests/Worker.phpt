<?php declare(strict_types=1);

/**
 * Worker processes against the fixture application.
 */

use Lupa\Worker\WorkerClient;
use Lupa\Worker\WorkerResponse;
use Tester\Assert;

require __DIR__ . '/bootstrap.php';

skipOnNette2();


$client = new WorkerClient(PHP_BINARY, BinFile, createFixtureProject(), 3);


// app_info
$response = $client->execute('app_info', []);
Assert::true($response->ok, $response->text);
Assert::match('PHP %a%, nette, %d% packages, 1 guarded connection(s).', $response->text);
Assert::same('nette', $response->data['application']['framework']);
Assert::same(realpath(FixtureDir), realpath($response->data['application']['parameters']['appDir']));
Assert::same(['db.local', 'app_dev', 'compiled'], [
	$response->data['databases'][0]['host'],
	$response->data['databases'][0]['database'],
	$response->data['databases'][0]['source'],
]);
Assert::notContains('secret', json_encode($response->data));
Assert::contains('nette/di', array_column($response->data['packages'], 'name'));
Assert::contains('test_sleep', $response->data['inspector']['tools']);

// the password of the connection is in the NEON line and in the generated factory, and must not leak from either
$response = $client->execute('di_service', ['name' => 'database']);
Assert::true($response->ok, $response->text);
Assert::notContains('secret', json_encode($response->data));
Assert::same('database: Dibi\Connection(…)', $response->data['definedIn'][0]['text']); // arguments may be literal secrets under any name

$all = $client->execute('app_info', ['packages' => 'all']);
Assert::count($all->data['packageCount'], $all->data['packages']);


// output of the application cannot forge or break the result
$response = $client->execute('test_echo', ['seconds' => 1]);
Assert::true($response->ok);
Assert::same(['_notice' => Lupa\Tools\Tool::UntrustedNotice, 'arguments' => ['seconds' => 1]], $response->data); // the tool is marked as returning data of the application
Assert::same(Lupa\Tools\Tool::UntrustedNotice . "
echoed", $response->text);


// failures
$response = $client->execute('test_fail', []);
Assert::same([false, WorkerResponse::ErrorTool, 'This went wrong for j***@***.com.'], [$response->ok, $response->errorKind, $response->text]); // masked

$response = $client->execute('test_crash', []);
Assert::same(WorkerResponse::ErrorCrash, $response->errorKind);
Assert::contains('Allowed memory size', $response->text);

$start = microtime(true);
$response = $client->execute('test_sleep', ['seconds' => 30]);
Assert::same(WorkerResponse::ErrorTimeout, $response->errorKind);
Assert::true(microtime(true) - $start < 10);


// guard
$client = new WorkerClient(PHP_BINARY, BinFile, createFixtureProject('lupa-denied.neon'), 10);
$response = $client->execute('app_info', []);
Assert::same(WorkerResponse::ErrorGuard, $response->errorKind);
Assert::contains("connects to host 'db.local', which is not in guard.allowedHosts", $response->text);


// a Configurator: the guard runs before the container initializes and connects
$client = new WorkerClient(PHP_BINARY, BinFile, createFixtureProject('lupa-configurator.neon'), 10);
$response = $client->execute('app_info', []);
Assert::same(WorkerResponse::ErrorGuard, $response->errorKind, $response->text);
Assert::contains("connects to host 'remote.invalid'", $response->text);


// inspector.applicationConfig overrides the connection before the guard reads it
$client = new WorkerClient(PHP_BINARY, BinFile, createFixtureProject('lupa-override.neon'), 10);
$response = $client->execute('app_info', []);
Assert::true($response->ok, $response->text);
Assert::same(['db.local', 'app_readonly'], [$response->data['databases'][0]['host'], $response->data['databases'][0]['database']]);


// result parsing
Assert::null(WorkerClient::parseResult("noise\n", 'abc'));
Assert::null(WorkerClient::parseResult("@@lupa-result:other\n{\"ok\":true,\"text\":\"x\"}\n", 'abc'));
Assert::same('x', WorkerClient::parseResult("@@lupa-result:abc\n{\"ok\":true,\"text\":\"x\"}\ntrailing output", 'abc')->text);


// a configuration that changes and stops compiling, with the real Configurator, which detects the production mode
// on the command line: the container is rebuilt all the same, and when that fails, the last one that compiled
// is used and every result says so
$broken = TempDir . '/broken';
mkdir("$broken/var/log", 0777, true);
file_put_contents("$broken/var/log/exception.log", "[2026-11-12 10-00-00] RuntimeException: Made-up failure.  @  CLI\n");
file_put_contents("$broken/lupa.neon", "inspector:\n\tbootstrap: boot.php\n\taudit: audit.jsonl\n");
file_put_contents(
	"$broken/boot.php",
	"<?php\nif (is_file(__DIR__ . '/down')) {\n\tthrow new RuntimeException('Database is down.');\n}\n\n"
	. "\$configurator = new Nette\Configurator;\n\$configurator->setTempDirectory(__DIR__ . '/temp');\n"
	. "\$configurator->enableTracy(__DIR__ . '/var/log');\n\$configurator->addConfig(__DIR__ . '/app.neon');\nreturn \$configurator;\n",
);
$valid = "parameters:\n\tcolor: red\nservices:\n\tthing: stdClass\n";
$mtime = time();
$configure = function (string $neon) use ($broken, &$mtime): void {
	file_put_contents("$broken/app.neon", $neon);
	touch("$broken/app.neon", $mtime += 10); // the Configurator notices a change by the modification time
};
$client = new WorkerClient(PHP_BINARY, BinFile, new Lupa\Project($broken, VendorAutoload, "$broken/lupa.neon"), 20);

$configure(str_replace('stdClass', 'Missing\Service', $valid));
$response = $client->execute('di_parameters', ['path' => 'color']);
Assert::same(WorkerResponse::ErrorBoot, $response->errorKind, $response->text); // nothing compiled yet
Assert::contains('Missing\Service', $response->text);

$configure($valid);
$response = $client->execute('di_parameters', ['path' => 'color']);
Assert::true($response->ok, $response->text);
Assert::same('red', $response->data['value']);
Assert::false(isset($response->data['_warning']));

// the Tracy log is where Configurator::enableTracy() put it, although no parameter names the directory
$response = $client->execute('tracy_errors', ['level' => 'exception']);
Assert::true($response->ok, $response->text);
Assert::same(['RuntimeException: Made-up failure.'], array_column($response->data['entries'], 'message'));

$configure(str_replace(['red', 'stdClass'], ['blue', 'Missing\Service'], $valid));
$response = $client->execute('di_parameters', ['path' => 'color']);
Assert::true($response->ok, $response->text);
Assert::same('red', $response->data['value']);
Assert::match('The configuration of the application does not compile (%a%Missing\Service%a%). The results come from the container of the last successful build (%a%); fix the configuration to refresh them.', $response->data['_warning']);
Assert::contains('does not compile', $response->text);

$configure(str_replace('red', 'green', $valid));
$response = $client->execute('di_parameters', ['path' => 'color']);
Assert::same('green', $response->data['value']);
Assert::false(isset($response->data['_warning']));

// the directory of the Tracy log is remembered for a start that fails
touch("$broken/down");
$response = $client->execute('tracy_errors', ['level' => 'exception']);
Assert::true($response->ok, $response->text);
Assert::count(1, $response->data['entries']);
Assert::match('%A%directories of its last start: %A%logDir var%c%log%A%', $response->data['_warning']);


// tools that read only files answer also when the application does not start
$stopped = TempDir . '/stopped';
mkdir("$stopped/log", 0777, true);
mkdir("$stopped/custom-log");
mkdir("$stopped/custom-temp/cache", 0777, true);
file_put_contents("$stopped/custom-log/error.log", "[2026-11-09 10-00-00] PHP Warning: made-up warning  @  CLI\n");
file_put_contents("$stopped/lupa.neon", "inspector:\n\tbootstrap: boot.php\n\taudit: audit.jsonl\n");
file_put_contents(
	"$stopped/boot.php",
	"<?php\nif (is_file(__DIR__ . '/down')) {\n\tthrow new RuntimeException('Database is down.');\n}\n\n"
	. "return new Nette\DI\Container(['logDir' => __DIR__ . '/custom-log', 'tempDir' => __DIR__ . '/custom-temp']);\n",
);
$client = new WorkerClient(PHP_BINARY, BinFile, new Lupa\Project($stopped, VendorAutoload, "$stopped/lupa.neon"), 10);
// the directory of Lupa outlives the run, while the path of the project repeats with the process id of a later run
@unlink(Lupa\Inspector::getTempDirectory("$stopped/lupa.neon") . '/last-start.json'); // @ there is none in most runs

touch("$stopped/down");
$response = $client->execute('tracy_errors', []);
Assert::true($response->ok, $response->text);
Assert::same(0, $response->data['total']); // the usual log directory, the application has not started yet
Assert::match('Booting the application failed: RuntimeException: Database is down.%a%usual directories log and temp%a%', $response->data['_warning']);
Assert::same(WorkerResponse::ErrorBoot, $client->execute('di_parameters', [])->errorKind);

unlink("$stopped/down");
Assert::same(1, $client->execute('tracy_errors', [])->data['total']);
touch("$stopped/down");
$response = $client->execute('tracy_errors', []);
Assert::same(1, $response->data['total']); // the directory of the last start
Assert::contains('directories of its last start: logDir custom-log, tempDir custom-temp.', $response->data['_warning']);

// the other tools that read only files, in the same directories
$report = 'exception--2026-11-09--10-00--0123456789.html';
(new Tracy\BlueScreen)->renderToFile(new RuntimeException('Made-up failure.'), "$stopped/custom-log/$report");
file_put_contents("$stopped/page.latte", "<h1>Page</h1>\n<p>{\$name}</p>\n");
$latte = new Latte\Engine;
$latte->setTempDirectory("$stopped/custom-temp/cache/latte");
$latte->renderToString("$stopped/page.latte", ['name' => 'x']);
$compiled = (string) (glob("$stopped/custom-temp/cache/latte/*.php") ?: [''])[0];
$compiledLine = 1 + (int) key(preg_grep('~/\* (?:line|pos) 2\b~', (array) file($compiled)) ?: [0 => '']);
(new Nette\Caching\Cache(new Nette\Caching\Storages\FileStorage("$stopped/custom-temp/cache"), 'App.Pages'))->save('page-1', 'cached-value');

$results = [];
foreach ([
	'tracy_report' => ['file' => $report],
	'latte_map_line' => ['file' => basename($compiled), 'line' => $compiledLine],
	'cache_namespaces' => [],
	'cache_get' => ['namespace' => 'App.Pages', 'key' => 'page-1'],
] as $tool => $arguments) {
	$response = $client->execute($tool, $arguments);
	Assert::true($response->ok, "$tool: $response->text");
	Assert::contains('directories of its last start', $response->data['_warning'], $tool);
	$results[$tool] = $response->data;
}

Assert::same('Made-up failure.', $results['tracy_report']['exceptions'][0]['message']);
Assert::same(['page.latte', 2], [$results['latte_map_line']['source'], $results['latte_map_line']['line']]);
Assert::same(['App.Pages'], array_column($results['cache_namespaces']['namespaces'], 'namespace'));
Assert::same('cached-value', $results['cache_get']['value']);


// a tool that reads only files does not initialize the application, whose services may fail
$failing = TempDir . '/failing';
mkdir("$failing/log", 0777, true);
file_put_contents("$failing/lupa.neon", "inspector:\n\tbootstrap: boot.php\n\taudit: audit.jsonl\n");
file_put_contents("$failing/boot.php", "<?php\nreturn new Lupa\Tests\Fixtures\FakeConfigurator(__DIR__ . '/app.neon', __DIR__ . '/cache');\n");
file_put_contents("$failing/app.neon", "services:\n\tdatabase: DateTime('not a date')\n"); // created while the container initializes
$client = new WorkerClient(PHP_BINARY, BinFile, new Lupa\Project($failing, VendorAutoload, "$failing/lupa.neon"), 10);
$response = $client->execute('di_parameters', []);
Assert::match('Initializing the application failed: %a%', $response->text);
$response = $client->execute('tracy_errors', []);
Assert::true($response->ok, $response->text);
Assert::false(isset($response->data['_warning']));


// a bootstrap that ends the process: the tools that read only files answer all the same
$ending = TempDir . '/ending';
mkdir("$ending/log", 0777, true);
file_put_contents("$ending/lupa.neon", "inspector:\n\tbootstrap: boot.php\n\taudit: audit.jsonl\n");
$client = new WorkerClient(PHP_BINARY, BinFile, new Lupa\Project($ending, VendorAutoload, "$ending/lupa.neon"), 10);
foreach ([
	"echo 'Maintenance';\nexit(0);\n" => 'Booting the application ended the process. Only files were read%a%',
	"ini_set('memory_limit', '64M');\nstr_repeat('x', 200 * 1024 * 1024);\n" => 'Booting the application ended the process: Allowed memory size%a%. Only files were read%a%',
] as $code => $warning) {
	file_put_contents("$ending/boot.php", "<?php\n$code");
	$response = $client->execute('tracy_errors', []);
	if (strpos($code, 'memory_limit') !== false && !shutdownRunsAfterExhaustedMemory()) {
		Assert::false($response->ok); // this build of PHP ends at once, so the call fails instead of reading the files
		Assert::contains('The worker ended without a result', $response->text);
		continue;
	}

	Assert::true($response->ok, $response->text);
	Assert::match($warning, $response->data['_warning']);
	Assert::same(WorkerResponse::ErrorCrash, $client->execute('di_parameters', [])->errorKind);
}
