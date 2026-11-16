<?php declare(strict_types=1);

/**
 * An application without a framework, described by the array its bootstrap returns.
 */

use Lupa\Inspector;
use Lupa\Tools\Tool;
use Lupa\Tools\ToolCatalog;
use Lupa\Worker\WorkerClient;
use Lupa\Worker\WorkerResponse;
use Tester\Assert;

require __DIR__ . '/bootstrap.php';


$root = __DIR__ . '/fixtures/plain';
$runtimeDir = TempDir . '/runtime';
@mkdir($runtimeDir . '/log', 0777, true); // @ may exist
putenv('FIXTURE_RUNTIME_DIR=' . $runtimeDir);

$db = new SQLite3($runtimeDir . '/app.sqlite');
$db->exec('CREATE TABLE customers (id INTEGER PRIMARY KEY, name TEXT, email TEXT, note TEXT)');
$db->exec('CREATE TABLE orders (id INTEGER PRIMARY KEY, customer_id INTEGER REFERENCES customers(id))');
$db->exec("INSERT INTO customers VALUES (1, 'Customer 1', 'customer1@example.com', 'private note')");
$db->close();
file_put_contents($runtimeDir . '/log/error.log', "[2026-11-09 10-00-00] PHP Warning: made-up warning  @  CLI\n");


// only the toolkits that need no framework are offered
$tools = array_map(
	fn(Tool $tool) => $tool->name,
	Inspector::createContainer($root . '/lupa.neon')->getByType(ToolCatalog::class)->getTools(),
);
Assert::contains('db_query', $tools);
Assert::contains('tracy_errors', $tools);
Assert::contains('migrations_status', $tools);
Assert::contains('latte_check', $tools); // Latte and the cache are libraries an application may use on their own
Assert::contains('cache_get', $tools);
Assert::notContains('di_services', $tools);
Assert::notContains('routes', $tools);

$client = new WorkerClient(PHP_BINARY, BinFile, new Lupa\Project($root, VendorAutoload, $root . '/lupa.neon'), 20);
$call = function (string $tool, array $arguments = []) use ($client): array {
	$response = $client->execute($tool, $arguments);
	Assert::true($response->ok, "$tool: $response->text");
	return $response->data;
};

$data = $call('app_info');
Assert::same('none', $data['application']['framework']);
Assert::same(['main', 'legacy'], array_column($data['databases'], 'service'));
Assert::same([true, true], array_column($data['databases'], 'local'));


// the database through PDO and through Dibi
foreach (['main', 'legacy'] as $connection) {
	$data = $call('db_query', ['sql' => 'SELECT name, email, note FROM customers', 'connection' => $connection]);
	Assert::same([['name' => 'Customer 1', 'email' => 'c***@***.com', 'note' => '***']], $data['rows'], $connection);
	Assert::same(['customers', 'orders'], array_column($call('db_tables', ['connection' => $connection])['tables'], 'name'), $connection);
	Assert::same('customers', $call('db_relations', ['connection' => $connection])['relations'][0]['referencedTable'], $connection);
}

Assert::same(['src/list.php'], array_keys($call('db_usage', ['table' => 'customers', 'path' => 'src'])['files']));


// logs from the directory the bootstrap names
$data = $call('tracy_errors');
Assert::same(1, $data['total']);
Assert::contains('made-up warning', $data['entries'][0]['message']);


// Latte templates, checked by a plain engine, and the cache of nette/caching in the temp directory the bootstrap names
$data = $call('latte_check', ['path' => 'src']);
Assert::same([1, 0], [$data['checked'], $data['errorCount']]);
Assert::same(['nosuchfilter'], array_map('strtolower', array_column($data['warnings'], 'filter'))); // Latte 2.10 lowercases
@mkdir($runtimeDir . '/temp/cache', 0777, true); // @ may exist
$cache = new Nette\Caching\Cache(new Nette\Caching\Storages\FileStorage($runtimeDir . '/temp/cache'), 'Plain');
$cache->save('customer', ['email' => 'customer1@example.com']);
Assert::same(['Plain'], array_column($call('cache_namespaces')['namespaces'], 'namespace'));
Assert::same(['email' => 'c***@***.com'], $call('cache_get', ['namespace' => 'Plain', 'key' => 'customer'])['value']);


// the guard reads a target before anything connects
putenv('FIXTURE_REMOTE=1');
$response = $client->execute('db_query', ['sql' => 'SELECT name FROM customers']);
Assert::same(WorkerResponse::ErrorGuard, $response->errorKind, $response->text);
Assert::contains('db.remote.invalid', $response->text);
putenv('FIXTURE_REMOTE');


// a bootstrap that returns something else
$broken = TempDir . '/broken';
mkdir($broken);
file_put_contents("$broken/lupa.neon", "inspector:\n\tframework: none\n\tbootstrap: boot.php\n\taudit: audit.jsonl\n");
file_put_contents("$broken/boot.php", "<?php\nreturn new stdClass;\n");
$response = (new WorkerClient(PHP_BINARY, BinFile, new Lupa\Project($broken, VendorAutoload, "$broken/lupa.neon"), 20))->execute(
	'app_info',
	[],
);
Assert::same(WorkerResponse::ErrorBoot, $response->errorKind);
Assert::contains("must return an array with 'parameters' and 'databases'", $response->text);
