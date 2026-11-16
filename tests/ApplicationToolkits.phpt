<?php declare(strict_types=1);

/**
 * Application tools through worker processes against the full fixture application:
 * router, forms, ACL, Ruckusing migrations, file cache, database environment and usage, DI extensions.
 */

use Lupa\Worker\WorkerClient;
use Tester\Assert;

require __DIR__ . '/bootstrap.php';

skipOnNette2();


$runtimeDir = TempDir . '/runtime';
@mkdir($runtimeDir . '/temp/cache', 0777, true); // @ may exist
@mkdir($runtimeDir . '/log', 0777, true); // @ may exist
putenv('FIXTURE_RUNTIME_DIR=' . $runtimeDir);

$db = new SQLite3($runtimeDir . '/app.sqlite');
$db->exec('CREATE TABLE customers (id INTEGER PRIMARY KEY, name TEXT, email TEXT, note TEXT, password TEXT)');
$db->exec('CREATE TABLE orders (id INTEGER PRIMARY KEY, customer_id INTEGER REFERENCES customers(id), total INTEGER)');
$db->exec('CREATE TABLE schema_migrations (version VARCHAR(255))');
foreach (['20240101000000', '20240102000000', '20240104000000'] as $version) {
	$db->exec("INSERT INTO schema_migrations VALUES ('$version')");
}

$db->close();


// a cache entry with an object of a class the worker does not know
final class CachedCustomer
{
	public string $email = 'jan@example.com';
	private string $password = 'secret';
}

$cache = new Nette\Caching\Cache(new Nette\Caching\Storages\FileStorage($runtimeDir . '/temp/cache'), 'App.Orders');
$cache->save('order-7', ['id' => 7, 'customer' => new CachedCustomer, 'token' => 'abc'], ['expire' => '+1 hour', 'files' => __FILE__]);


$client = new WorkerClient(PHP_BINARY, BinFile, createFixtureProject('lupa-full.neon'), 20);
$call = function (string $tool, array $arguments = []) use ($client): array {
	$response = $client->execute($tool, $arguments);
	Assert::true($response->ok, "$tool: $response->text");
	assertResult($tool, $response->data);
	return $response->data;
};


// router
$data = $call('routes');
Assert::same(['index.php', 'admin/<presenter>/<action>[/<id>]', 'order/<id \d+>', '<presenter>/<action>', 'item/<id \d+>'], array_column($data['routes'], 'mask'));
Assert::same([true, false, false, false, false], array_column($data['routes'], 'oneWay'));
Assert::same('Admin', $data['routes'][1]['module']);

$data = $call('route_match', ['url' => 'http://example.test/order/7?tab=items']);
Assert::same(['Order', 'detail', ['id' => '7', 'tab' => 'items']], [$data['presenter'], $data['action'], $data['parameters']]);
Assert::same(2, $data['route']['index']);
Assert::same('Admin:Users', $call('route_match', ['url' => 'http://example.test/admin/users/edit/3'])['presenter']);
Assert::false($call('route_match', ['url' => 'http://example.test/a/b/c/d'])['matched']);

// a path is resolved against http://localhost/ when the application knows no address, here without an HTTP request
$data = $call('route_match', ['url' => '/order/7?tab=items']);
Assert::same(['http://localhost/order/7?tab=items', 'Order', 'detail'], [$data['url'], $data['presenter'], $data['action']]);

// an application in a subdirectory: its absolute URLs, as link builds them, and paths are matched in that directory
file_put_contents(TempDir . '/subdirectory.neon', <<<'NEON'
	services:
		router:
			factory: Nette\Application\Routers\RouteList
			setup:
				- addRoute('orders/<id \d+>', 'Order:detail')
		httpRequest: Nette\Http\Request(Nette\Http\UrlScript('http://example.test/shop/', '/shop/'))
	NEON);
$router = new Lupa\Nette\Toolkits\RouterToolkit(new Lupa\Masking);
$context = new Lupa\Tools\AppContext(
	new Lupa\Nette\NetteApplication(compileContainer(TempDir . '/subdirectory.neon')),
	createFixtureProject(),
	new Lupa\Tools\ToolCatalog([$router]),
	[],
);
$match = fn(array $arguments): array => $router->call('route_match', $arguments, $context)->data;
Assert::same('Order', $match(['url' => 'http://example.test/shop/orders/5'])['presenter'] ?? null);
Assert::same('http://example.test/shop/orders/5', $match(['url' => '/orders/5'])['url']);
Assert::false($match(['url' => 'http://example.test/shop/orders/5', 'scriptPath' => '/'])['matched']);
Assert::false($match(['url' => 'http://other.test/shop/orders/5'])['matched']); // another address keeps the directory /

// a route in a list with a path sees the URL without that path
$data = $call('route_match', ['url' => 'http://example.test/shop/item/5']);
Assert::same(['Order', 'detail', ['id' => '5'], 4], [$data['presenter'], $data['action'], $data['parameters'], $data['route']['index']]);

Assert::same('http://example.test/order/7', $call('link', ['destination' => 'Order:detail', 'parameters' => ['id' => 7]])['url']);
Assert::same('http://localhost/order/8', $call('link', ['destination' => ':Order:detail', 'parameters' => ['id' => 8], 'baseUrl' => 'http://localhost/'])['url']);


// forms
$data = $call('form', ['service' => 'orderFormFactory']);
$controls = array_column($data['controls'], null, 'name');
Assert::same(['name', 'email', 'password', 'status', 'note', 'address-city', 'save'], array_keys($controls));
Assert::true($controls['name']['required']);
Assert::contains('At most %d characters.', array_column($controls['name']['rules'], 'message'));
Assert::same('j***@***.com', $controls['email']['value']);
Assert::null($controls['password']['value']);
Assert::same(['open' => 'Open', 'other' => 'Other'], $controls['status']['items']);
$condition = $controls['note']['rules'][0];
Assert::same([true, 'status', 'other'], [$condition['condition'], $condition['control'], $condition['argument']]);
Assert::same(['address'], $data['containers']);

$response = $client->execute('form', ['service' => 'orders']);
Assert::false($response->ok);
Assert::contains('has no method create()', $response->text);

// a method that does not return a form is never called; it could change data
$response = $client->execute('form', ['service' => 'orders', 'method' => 'findByCustomerEmail', 'arguments' => ['x']]);
Assert::false($response->ok);
Assert::contains('does not declare a form as its return type', $response->text);


// ACL
$data = $call('acl_roles');
Assert::same(['guest' => [], 'editor' => ['guest'], 'admin' => ['editor']], $data['roles']);
Assert::same(['orders' => null, 'invoices' => 'orders'], $data['resources']);

$data = $call('acl_check', ['role' => 'editor', 'resource' => 'invoices', 'privilege' => 'read']);
Assert::false($data['allowed']);
Assert::same(['guest'], $data['roleAncestors']);
Assert::same(['orders'], $data['resourceAncestors']);
Assert::contains(['role' => 'editor', 'resource' => 'invoices', 'privilege' => null, 'type' => 'deny', 'assertion' => false], $data['rules']);
Assert::true($call('acl_check', ['role' => 'editor', 'resource' => 'orders', 'privilege' => 'read'])['allowed']);

$data = $call('acl_matrix', ['resource' => 'orders']);
Assert::same(['edit', 'read'], $data['privileges']);
Assert::same(['*' => false, 'edit' => true, 'read' => true], $data['matrix']['editor']);
Assert::same(['*' => true, 'edit' => true, 'read' => true], $data['matrix']['admin']);

$response = $client->execute('acl_check', ['role' => 'nobody']);
Assert::contains("Role 'nobody' does not exist", $response->text);


// migrations
$data = $call('migrations_status');
Assert::same(['20231231000000_Late.php', '20240103000000_First.php'], array_column($data['pending'], 'file'));
Assert::same(['20240104000000'], $data['executedWithoutFile']);
Assert::same('20240104000000', $data['newestExecuted']);

$data = $call('migrations_conflicts');
Assert::same([['20240102000000_Duplicate.php', '20240102000000_Second.php']], $data['sameVersion']);
Assert::same([['20240101000000_First.php', '20240103000000_First.php']], $data['sameClass']);
Assert::same([['20240103000000_First.php', 'First_20240103']], array_map(fn($file) => [$file['file'], $file['declaredClass']], $data['classMismatch']));
Assert::same(['20231231000000_Late.php', '20240103000000_First.php'], array_column($data['pendingOlderThanNewest'], 'file'));


// cache
$data = $call('cache_namespaces');
Assert::same([['namespace' => 'App.Orders', 'entries' => 1]], array_map(fn($ns) => array_slice($ns, 0, 2), $data['namespaces']));

$entry = $call('cache_namespaces', ['namespace' => 'App.Orders'])['entries'][0]['entry'];
$data = $call('cache_get', ['namespace' => 'App.Orders', 'key' => 'order-7']);
Assert::same($entry, $data['entry']);
Assert::same(['id' => 7, 'customer' => ['@class' => 'CachedCustomer', 'email' => 'j***@***.com', 'password' => '***'], 'token' => '***'], $data['value']);
Assert::same([realpath(__FILE__)], array_map('realpath', $data['dependsOnFiles']));
Assert::notNull($data['expires']);
Assert::same($data['value'], $call('cache_get', ['namespace' => 'App.Orders', 'entry' => $entry])['value']);


// database environment and usage
Assert::same('sqlite', $call('db_env')['platform']);

$data = $call('db_usage', ['table' => 'customers', 'column' => 'email', 'path' => 'src']);
Assert::same(['src/Model/OrderFacade.php', 'src/Presenters/LegacyPresenter.php'], array_keys($data['files']));


// DI extensions and parameter origins
$data = $call('di_extensions', ['name' => 'inject']);
Assert::same(['inject', 'Nette\DI\Extensions\InjectExtension', 'config-full.neon', false], [
	$data['extensions'][0]['name'],
	$data['extensions'][0]['class'],
	$data['extensions'][0]['registeredIn'],
	$data['extensions'][0]['configured'],
]);

$data = $call('di_parameters', ['path' => 'mail.from']);
Assert::same([['file' => 'config-full.neon', 'value' => 'i***@***.com']], $data['setIn']);
Assert::same('***', $call('di_parameters', ['path' => 'mail.password'])['setIn'][0]['value']);

// everything under a secret key is hidden, also when asked for by a path below it
foreach (['credentials', 'credentials.gateway', 'credentials.gateway.live'] as $path) {
	$data = $call('di_parameters', ['path' => $path]);
	Assert::same(['***', '***'], [$data['value'], $data['setIn'][0]['value']], $path);
}

Assert::notContains('fixture-nested-value', json_encode($call('di_parameters')));

// limits are validated
$invalid = [
	['db_usage', ['table' => 'orders', 'limit' => -1]],
	['latte_check', ['limit' => 0]],
	['tracy_report', ['file' => 'x.html', 'stackLimit' => -5]],
	['tracy_report', ['file' => 'x.html', 'agentReportLength' => 200001]],
];
foreach ($invalid as [$tool, $arguments]) {
	$response = $client->execute($tool, $arguments);
	Assert::false($response->ok, $tool);
	Assert::contains('must be between 1 and', $response->text);
}
