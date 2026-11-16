<?php declare(strict_types=1);

/**
 * Console commands, the address of the application in route_match, and the active tools render and cache_invalidate
 * with their safeguards.
 */

use Lupa\Inspector;
use Lupa\Tools\ToolCatalog;
use Lupa\Worker\WorkerClient;
use Tester\Assert;

require __DIR__ . '/bootstrap.php';

skipOnNette2();


$runtimeDir = TempDir . '/runtime';
@mkdir($runtimeDir . '/temp/cache/latte', 0777, true); // @ may exist
@mkdir($runtimeDir . '/log', 0777, true); // @ may exist
putenv('FIXTURE_RUNTIME_DIR=' . $runtimeDir);

$db = new SQLite3($runtimeDir . '/app.sqlite');
$db->exec('CREATE TABLE customers (id INTEGER PRIMARY KEY, name TEXT)');
$db->close();


// active tools are offered only when enabled
$names = fn(string $config) => array_map(
	fn($tool) => $tool->name,
	Inspector::createContainer(FixtureDir . '/' . $config)->getByType(ToolCatalog::class)->getTools(),
);
Assert::notContains('render', $names('lupa-full.neon'));
Assert::notContains('cache_invalidate', $names('lupa-full.neon'));
Assert::contains('render', $names('lupa-render.neon'));
Assert::false(Inspector::createContainer(FixtureDir . '/lupa-render.neon')->getByType(ToolCatalog::class)->getTool('render')->readOnly);

file_put_contents(TempDir . '/typo.neon', "inspector:\n\tenableActive: [rendr]");
Assert::exception(
	fn() => Inspector::createContainer(TempDir . '/typo.neon')->getByType(ToolCatalog::class),
	Lupa\InspectorException::class,
	"%A%'rendr', which is not an active tool.",
);


$client = new WorkerClient(PHP_BINARY, BinFile, createFixtureProject('lupa-render.neon'), 30);
$call = function (string $tool, array $arguments = []) use ($client): array {
	$response = $client->execute($tool, $arguments);
	Assert::true($response->ok, "$tool: $response->text");
	assertResult($tool, $response->data);
	return $response->data;
};


// console commands are described, not run
$data = $call('console_commands', ['name' => 'report']);
Assert::same(['app:report', 'Prints the daily report.'], [$data['commands'][0]['name'], $data['commands'][0]['description']]);
Assert::same([['date', true]], array_map(fn($argument) => [$argument['name'], $argument['required']], $data['commands'][0]['arguments']));
Assert::contains(['--dry-run', 'd'], array_map(fn($option) => [$option['name'], $option['shortcut']], $data['commands'][0]['options']));
Assert::same('***', array_column($data['commands'][0]['options'], 'default', 'name')['--api-key']);


// a path in route_match is resolved against the address the HTTP request of the application knows
putenv('HTTP_HOST=example.test'); // reaches $_SERVER of the worker, as on a web server
$data = $call('route_match', ['url' => 'status/away']);
Assert::same(['http://example.test/status/away', 'Status', 'away'], [$data['url'], $data['presenter'], $data['action']]);
putenv('HTTP_HOST');


// render
$data = $call('render', ['presenter' => 'Status']);
Assert::same([200, ['ok' => true, 'contact' => 'j***@***.com']], [$data['status'], $data['payload']]);
Assert::same(['readOnly' => ['database'], 'unreachable' => []], $data['safety']['databases']);

$data = $call('render', ['presenter' => 'Status', 'action' => 'sensitive']);
Assert::notContains('synthetic-render-secret', $data['html']);
Assert::notContains('person&#64;example.test', $data['html']);
if (class_exists(DOMDocument::class)) {
	Assert::contains('Public content', $data['html']);
} else {
	Assert::same('***', $data['html']);
}

$data = $call('render', ['url' => 'http://localhost/status/away']);
Assert::same(['Status', 'away', 'http://example.test/elsewhere?token=***'], [$data['presenter'], $data['action'], $data['redirect']]);
Assert::notContains('fixture-redirect-value', json_encode($data));

$response = $client->execute('render', ['url' => 'http://localhost/guarded']);
Assert::false($response->ok);
Assert::contains('readonly database', $response->text);
Assert::same(0, (new SQLite3($runtimeDir . '/app.sqlite'))->querySingle('SELECT COUNT(*) FROM customers'));

putenv('FIXTURE_OFFLINE_DATABASE=1');
$data = $call('render', ['presenter' => 'Status']);
Assert::same(['readOnly' => [], 'unreachable' => ['database']], $data['safety']['databases']);

// a connection opened only after the lock is read-only too
putenv('FIXTURE_DATABASE_APPEARS=1');
$data = $call('render', ['presenter' => 'Status', 'action' => 'write']);
putenv('FIXTURE_DATABASE_APPEARS');
putenv('FIXTURE_OFFLINE_DATABASE');
Assert::same(['readOnly' => [], 'unreachable' => ['database']], $data['safety']['databases']);
Assert::contains('tried to write to the read-only database', $data['summary']);
Assert::same(0, (new SQLite3($runtimeDir . '/missing/app.sqlite'))->querySingle('SELECT COUNT(*) FROM customers'));

putenv('FIXTURE_WRITE_ON_INITIALIZE=1');
$response = $client->execute('render', ['presenter' => 'Status']);
putenv('FIXTURE_WRITE_ON_INITIALIZE');
Assert::false($response->ok);
Assert::contains('readonly database', $response->text);
Assert::same(0, (new SQLite3($runtimeDir . '/app.sqlite'))->querySingle('SELECT COUNT(*) FROM customers'));

putenv('FIXTURE_MAIL_ON_INITIALIZE=1');
$data = $call('render', ['presenter' => 'Status']);
putenv('FIXTURE_MAIL_ON_INITIALIZE');
Assert::same([['to' => ['f***@***.test'], 'subject' => 'Initialization probe']], $data['mails']);

putenv('FIXTURE_RETURN_CONTAINER=1');
$response = $client->execute('render', ['presenter' => 'Status']);
putenv('FIXTURE_RETURN_CONTAINER');
Assert::false($response->ok);
Assert::contains('returning the Configurator before initialization', $response->text);

$data = $call('render', ['presenter' => 'Status', 'action' => 'page']);
Assert::same([200, 'Status page'], [$data['status'], $data['title']]);
Assert::contains('Contact: j***@***.com', $data['html']);

$data = $call('render', ['presenter' => 'Status', 'action' => 'write']);
Assert::same(500, $data['status']);
Assert::contains('tried to write to the read-only database', $data['summary']);
Assert::same(0, (new SQLite3($runtimeDir . '/app.sqlite'))->querySingle('SELECT COUNT(*) FROM customers'));

$data = $call('render', ['presenter' => 'Status', 'action' => 'mail']);
Assert::same(['sent' => true], $data['payload']);
Assert::same([['to' => ['j***@***.com'], 'subject' => 'Order confirmed']], $data['mails']);
Assert::contains('mail.mailer', $data['safety']['replaced']);

$response = $client->execute('render', ['presenter' => 'Status', 'action' => 'who', 'roles' => ['editor'], 'userId' => 7]);
Assert::true($response->ok, $response->text); // through the legacy storage before nette/security 3.1
Assert::same(['loggedIn' => true, 'roles' => ['editor']], $response->data['payload']);

Assert::same(404, $call('render', ['presenter' => 'Status', 'action' => 'missing'])['status']);
Assert::contains('signals change state', $client->execute('render', ['url' => 'http://localhost/status/page?do=delete'])->text);

$data = $call('render', ['presenter' => 'Status', 'action' => 'broken']);
Assert::same(500, $data['status']);
Assert::same(
	['src/Presenters/templates/Status/broken.latte', 2],
	[$data['exceptions'][0]['template']['source'], $data['exceptions'][0]['template']['line']],
);


// cache_invalidate
$cache = new Nette\Caching\Cache(new Nette\Caching\Storages\FileStorage($runtimeDir . '/temp/cache'), 'App.Orders');
$cache->save('a', 1);
$cache->save('b', 2);
Assert::same(1, $call('cache_invalidate', ['namespace' => 'App.Orders', 'key' => 'a'])['removed']);
Assert::null($cache->load('a'));
Assert::same(2, $cache->load('b'));
Assert::same(1, $call('cache_invalidate', ['namespace' => 'App.Orders', 'all' => true])['removed']);
Assert::contains('no cache storage service', $client->execute('cache_invalidate', ['tag' => 'orders'])->text);
