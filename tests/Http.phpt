<?php declare(strict_types=1);

use Lupa\Http\Endpoint;
use Lupa\Http\RequestHandler;
use Lupa\Http\ToolPolicy;
use Lupa\Inspector;
use Lupa\Plain\PlainApplication;
use Lupa\Project;
use Lupa\Tools\ToolCatalog;
use Lupa\Worker\HttpExecutor;
use Tester\Assert;

require __DIR__ . '/bootstrap.php';


$key = str_repeat('a', 64);
$headers = [
	'Authorization' => 'Bearer ' . $key,
	'Content-Type' => 'application/json',
	'Accept' => 'application/json, text/event-stream',
	'MCP-Protocol-Version' => '2025-11-25',
];
$request = static fn(string $method, array $params = [], $id = 1): string => json_encode([
	'jsonrpc' => '2.0', 'id' => $id, 'method' => $method, 'params' => $params ?: new stdClass,
]);
$attempts = 0;
$handler = new RequestHandler(hash('sha256', $key), ['https://example.test'], static function () use (&$attempts) {
	$attempts++;
	throw new RuntimeException('password=synthetic-factory-secret');
});
$ping = $request('ping');
Assert::same(401, $handler->handle('POST', [], $ping, true)->status);
Assert::same(403, $handler->handle('POST', $headers, $ping, false)->status);
Assert::same(403, $handler->handle('POST', $headers + ['Origin' => 'https://attacker.test'], $ping, true)->status);
Assert::same(403, $handler->handle('POST', $headers + ['Origin' => 'null'], $ping, true)->status);
Assert::same(401, $handler->handle('POST', ['Authorization' => 'Bearer ' . str_repeat('b', 64)] + $headers, $ping, true)->status);
Assert::same(400, $handler->handle('POST', $headers + ['authorization' => 'Bearer ' . $key], $ping, true)->status);
Assert::same(405, $handler->handle('GET', $headers, '', true)->status);
Assert::same(405, $handler->handle('DELETE', $headers, '', true)->status);
Assert::same(415, $handler->handle('POST', ['Content-Type' => 'text/plain'] + $headers, $ping, true)->status);
Assert::same(406, $handler->handle('POST', ['Accept' => 'text/event-stream'] + $headers, $ping, true)->status);
Assert::same(406, $handler->handle('POST', ['Accept' => 'application/json;q=0,text/event-stream'] + $headers, $ping, true)->status);
Assert::same(413, $handler->handle('POST', $headers, str_repeat('x', RequestHandler::MaxBodyBytes + 1), true)->status);
Assert::same(400, $handler->handle('POST', $headers, '{broken password=synthetic-secret', true)->status);
Assert::same(400, $handler->handle('POST', $headers, '[' . $ping . ']', true)->status);
Assert::same(400, $handler->handle('POST', $headers + ['Content-Length' => '1'], $ping, true)->status);
Assert::same(400, $handler->handle('POST', ['MCP-Protocol-Version' => '2099-01-01'] + $headers, $ping, true)->status);
Assert::same(400, $handler->handle('POST', $headers + ['MCP-Session-Id' => 'unexpected'], $ping, true)->status);
Assert::same(0, $attempts);
Assert::same(202, $handler->handle('POST', $headers, '{"jsonrpc":"2.0","method":"notifications/initialized"}', true)->status);
Assert::same(0, $attempts);
$unavailable = $handler->handle('POST', $headers + ['Origin' => 'https://example.test'], $ping, true);
Assert::same(503, $unavailable->status);
Assert::notContains('synthetic-factory-secret', $unavailable->body);
Assert::same(1, $attempts);

$root = TempDir . '/http';
mkdir($root, 0700);
mkdir($root . '/runtime', 0700);
$pdo = new PDO('sqlite:' . $root . '/app.sqlite');
$pdo->exec("CREATE TABLE users (id INTEGER, username TEXT, password_hash TEXT DEFAULT 'synthetic-schema-secret')");
$pdo->exec("CREATE TABLE products (id INTEGER, name TEXT DEFAULT 'Public product')");
$pdo->exec("INSERT INTO products VALUES (1, 'Public product')");
file_put_contents($root . '/bootstrap.php', '<?php return ' . var_export([
	'parameters' => ['appDir' => $root, 'tempDir' => $root . '/runtime'],
	'databases' => ['main' => ['dsn' => 'sqlite:' . $root . '/app.sqlite']],
], true) . ';');
file_put_contents(
	$root . '/lupa.neon',
	"inspector:\n\tframework: none\n\tbootstrap: bootstrap.php\n\taudit: runtime/audit.jsonl\nmasking:\n\tcolumns:\n\t\tusers: [username, password_hash]\n",
);
$project = new Project($root, VendorAutoload, $root . '/lupa.neon');
$endpoint = new Endpoint($project, hash('sha256', $key), ['https://example.test']);
$call = static function (string $method, array $params = []) use ($endpoint, $headers, $request): array {
	$response = $endpoint->handle('POST', $headers, $request($method, $params), true);
	Assert::same(200, $response->status);
	Assert::same('no-store', $response->headers['Cache-Control']);
	return json_decode($response->body, true);
};

$initialized = $call('initialize', ['protocolVersion' => '2025-11-25', 'capabilities' => new stdClass]);
Assert::same('2025-11-25', $initialized['result']['protocolVersion']);
Assert::same('2025-11-25', $call('initialize', ['protocolVersion' => '2024-11-05'])['result']['protocolVersion']);
$notification = $endpoint->handle('POST', $headers, '{"jsonrpc":"2.0","method":"notifications/initialized"}', true);
Assert::same(202, $notification->status);
Assert::same('', $notification->body);
$listed = array_column($call('tools/list')['result']['tools'], 'name');
sort($listed);
Assert::same(['app_info', 'db_env', 'db_relations', 'db_table', 'db_tables'], $listed);
$info = $call('tools/call', ['name' => 'app_info']);
Assert::false($info['result']['isError']);
Assert::same('none', $info['result']['structuredContent']['application']['framework']);
Assert::notContains('db_query', $info['result']['structuredContent']['inspector']['tools']);
$invalid = $call(
	'tools/call',
	['name' => 'app_info', 'arguments' => ['unused' => 'p' . str_repeat('[', 32000) . 'x' . str_repeat(']', 32000)]],
);
Assert::true($invalid['result']['isError']);
$entries = file($root . '/runtime/audit.jsonl', FILE_IGNORE_NEW_LINES);
$entry = json_decode(end($entries), true, 512, JSON_THROW_ON_ERROR);
Assert::same(['unused' => '***'], $entry['arguments']);
$collisions = [];
for ($i = 0; $i < 4000; $i++) {
	$collisions['token=' . $i] = 0;
}
Assert::true($call('tools/call', ['name' => 'app_info', 'arguments' => $collisions])['result']['isError']);
$entries = file($root . '/runtime/audit.jsonl', FILE_IGNORE_NEW_LINES);
$entry = json_decode(end($entries), true, 512, JSON_THROW_ON_ERROR);
Assert::count(4000, $entry['arguments']);
Assert::same('***', $entry['arguments']['token=***']);
Assert::same('***', $entry['arguments']['token=*** [4000]']);
Assert::same([], $call('ping')['result']);
$tables = $call('tools/call', ['name' => 'db_tables']);
Assert::false($tables['result']['isError']);
Assert::same(['products', 'users'], array_column($tables['result']['structuredContent']['tables'], 'name'));
$columns = $call('tools/call', ['name' => 'db_table', 'arguments' => ['table' => 'users']]);
Assert::false($columns['result']['isError']);
Assert::notContains('synthetic-schema-secret', json_encode($columns));
Assert::contains('***', json_encode($columns));
$publicColumns = $call('tools/call', ['name' => 'db_table', 'arguments' => ['table' => 'products']]);
Assert::same('***', array_column($publicColumns['result']['structuredContent']['columns'], 'default', 'name')['name']);
foreach (['db_query', 'db_explain', 'render', 'cache_invalidate', 'tracy_report'] as $tool) {
	$denied = $call('tools/call', ['name' => $tool, 'arguments' => ['sql' => 'DELETE FROM products']]);
	Assert::true(isset($denied['error']) || ($denied['result']['isError'] ?? false));
}

$catalog = Inspector::createContainer($root . '/lupa.neon')->getByType(ToolCatalog::class);
Assert::true(ToolPolicy::allows($catalog, 'db_table'));
Assert::false(ToolPolicy::allows($catalog, 'db_query'));
$custom = new class implements Lupa\Tools\Toolkit {
	public function getTools(): array
	{
		return (new Lupa\Toolkits\ApplicationToolkit)->getTools();
	}


	public function call(string $tool, array $arguments, Lupa\Tools\AppContext $context): Lupa\Tools\ToolResult
	{
		throw new RuntimeException('A custom toolkit must never run in HTTP mode.');
	}
};
Assert::false(ToolPolicy::allows(new ToolCatalog([$custom]), 'app_info'));
Assert::same([], ToolPolicy::filter(new ToolCatalog([$custom]))->getTools());
$executor = new HttpExecutor($project);
Assert::false($executor->execute('db_query', ['sql' => 'DELETE FROM products'])->ok);
Assert::false($executor->execute('app_info', [])->ok);
Assert::same(1, (int) $pdo->query('SELECT COUNT(*) FROM products')->fetchColumn());

$persistent = new PDO('sqlite:' . $root . '/app.sqlite', null, null, [PDO::ATTR_PERSISTENT => true]);
$app = new PlainApplication(['databases' => ['main' => [
	'dsn' => 'sqlite:' . $root . '/app.sqlite',
	'options' => [PDO::ATTR_PERSISTENT => true],
]]]);
$app->restrictConnectionsToSqlite($project);
$readOnly = $app->getDatabaseConnection('main');
Assert::false($readOnly->getAttribute(PDO::ATTR_PERSISTENT));
Assert::same('Public product', $readOnly->query('SELECT name FROM products')->fetchColumn());
Assert::exception(fn() => $readOnly->exec("INSERT INTO products VALUES (2, 'Unexpected')"), PDOException::class);
Assert::exception(fn() => $app->restrictConnectionsToSqlite($project), Lupa\InspectorException::class);
foreach ([
	['main' => ['dsn' => 'sqlite:' . $root . '/missing.sqlite']],
	['main' => ['dsn' => 'mysql:host=localhost;dbname=app']],
	['main' => ['driver' => 'sqlite3', 'database' => $root . '/app.sqlite']],
] as $databases) {
	$unsupported = new PlainApplication(['databases' => $databases]);
	Assert::exception(fn() => $unsupported->restrictConnectionsToSqlite($project), Lupa\InspectorException::class);
}
Assert::false(is_file($root . '/missing.sqlite'));

$logFile = $root . '/runtime/audit.jsonl';
$log = file_get_contents($logFile);
Assert::notContains($key, $log);
Assert::notContains('synthetic-schema-secret', $log);
$limitFile = $logFile . '.http-limit.lock';
$lock = fopen($limitFile, 'c+');
flock($lock, LOCK_EX);
Assert::same(429, $endpoint->handle('POST', $headers, $ping, true)->status);
flock($lock, LOCK_UN);
fclose($lock);
// the limit counts the requests of the last 60 seconds, not of a window that starts anew each minute
file_put_contents($limitFile, json_encode(array_merge(array_fill(0, 30, time() - 61), array_fill(0, 59, time() - 30))));
Assert::same(200, $endpoint->handle('POST', $headers, $ping, true)->status);
Assert::same(429, $endpoint->handle('POST', $headers, $ping, true)->status);
Assert::count(60, json_decode(file_get_contents($limitFile), true));
file_put_contents($limitFile, '{broken');
Assert::same(503, $endpoint->handle('POST', $headers, $ping, true)->status);
file_put_contents($limitFile, json_encode(['start' => time(), 'count' => 1]));
Assert::same(503, $endpoint->handle('POST', $headers, $ping, true)->status);
// Keep fractional timestamps well inside the window: loading the container may take more than half a second.
file_put_contents($limitFile, json_encode(array_fill(0, 60, microtime(true) - 0.5)));
Assert::same(429, $endpoint->handle('POST', $headers, $ping, true)->status);
file_put_contents($limitFile, json_encode(array_fill(0, 60, microtime(true) - 60.5)));
Assert::same(200, $endpoint->handle('POST', $headers, $ping, true)->status);
