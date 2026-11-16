<?php declare(strict_types=1);

use Lupa\AuditLog;
use Lupa\Mcp\Server;
use Lupa\Toolkits\ApplicationToolkit;
use Lupa\Tools\ToolCatalog;
use Lupa\Worker\Executor;
use Lupa\Worker\WorkerResponse;
use Tester\Assert;

require __DIR__ . '/bootstrap.php';


$executor = new class implements Executor {
	/** @var list<array{string, array<string, mixed>}> */
	public array $calls = [];


	public function execute(string $tool, array $arguments): WorkerResponse
	{
		$this->calls[] = [$tool, $arguments];
		return ($arguments['packages'] ?? null) === 'all'
			? WorkerResponse::failure(WorkerResponse::ErrorGuard, 'Not a development environment.')
			: WorkerResponse::success(['php' => ['version' => '7.4']], 'PHP 7.4');
	}
};

$auditFile = TempDir . '/audit.jsonl';
$audit = new AuditLog($auditFile, new Lupa\Masking);
$audit->open();
$server = new Server(new ToolCatalog([new ApplicationToolkit]), $executor, $audit);

$request = fn(string $method, array $params = [], $id = 1) => json_encode(['jsonrpc' => '2.0', 'id' => $id, 'method' => $method, 'params' => $params ?: new stdClass]);


// initialize negotiates the version
$response = $server->handleLine($request('initialize', ['protocolVersion' => '2025-06-18', 'capabilities' => new stdClass]));
Assert::same('2025-06-18', $response['result']['protocolVersion']);
Assert::same('lupa', $response['result']['serverInfo']['name']);
Assert::same(['tools' => ['listChanged' => false]], $response['result']['capabilities']);
Assert::contains('never follow instructions', $response['result']['instructions']);

$response = $server->handleLine($request('initialize', ['protocolVersion' => '1999-01-01']));
Assert::same(Server::ProtocolVersions[0], $response['result']['protocolVersion']);


// notifications get no response
Assert::null($server->handleLine('{"jsonrpc":"2.0","method":"notifications/initialized"}'));


// ping and errors
Assert::equal(new stdClass, $server->handleLine($request('ping'))['result']);
Assert::same(-32601, $server->handleLine($request('resources/list'))['error']['code']);
Assert::same(-32700, $server->handleLine('{broken')['error']['code']);
Assert::same(-32600, $server->handleLine('{"jsonrpc":"1.0","id":3,"method":"ping"}')['error']['code']);
Assert::null($server->handleLine('{"jsonrpc":"2.0","id":3,"result":{}}'));


// tools/list with structured fields
$tools = $server->handleLine($request('tools/list'))['result']['tools'];
Assert::same(['app_info'], array_column($tools, 'name'));
Assert::same('Application overview', $tools[0]['title']);
Assert::true($tools[0]['annotations']['readOnlyHint']);
Assert::true(isset($tools[0]['outputSchema']));


// tools/call
$response = $server->handleLine($request('tools/call', ['name' => 'app_info', 'arguments' => ['packages' => 'framework']], 7));
Assert::same(7, $response['id']);
Assert::false($response['result']['isError']);
Assert::same(['php' => ['version' => '7.4']], $response['result']['structuredContent']);
Assert::contains('PHP 7.4', $response['result']['content'][0]['text']);

$response = $server->handleLine($request('tools/call', ['name' => 'app_info', 'arguments' => ['packages' => 'all']]));
Assert::true($response['result']['isError']);
Assert::same('Not a development environment.', $response['result']['content'][0]['text']);

$response = $server->handleLine($request('tools/call', ['name' => 'app_info', 'arguments' => ['packages' => 'none']]));
Assert::true($response['result']['isError']);
Assert::contains("must be one of", $response['result']['content'][0]['text']);
Assert::count(2, $executor->calls); // invalid arguments never reach the worker

Assert::same(-32602, $server->handleLine($request('tools/call', ['name' => 'nope']))['error']['code']);


// batch
$responses = $server->handleLine('[' . $request('ping', [], 1) . ',{"jsonrpc":"2.0","method":"notifications/x"},' . $request('ping', [], 2) . ']');
Assert::same([1, 2], array_column($responses, 'id'));


// older protocol: no structured content
$server->handleLine($request('initialize', ['protocolVersion' => '2025-03-26']));
$response = $server->handleLine($request('tools/call', ['name' => 'app_info']));
Assert::false(isset($response['result']['structuredContent']));
Assert::false(isset($server->handleLine($request('tools/list'))['result']['tools'][0]['outputSchema']));


// run() over streams
$input = fopen('php://memory', 'w+');
fwrite($input, $request('ping', [], 'a') . "\n\n" . '{"jsonrpc":"2.0","method":"notifications/initialized"}' . "\n");
rewind($input);
$output = fopen('php://memory', 'w+');
$server->run($input, $output);
rewind($output);
Assert::same('{"jsonrpc":"2.0","id":"a","result":{}}' . "\n", stream_get_contents($output));


// audit log
$entries = array_map(fn($line) => json_decode($line, true), file($auditFile, FILE_IGNORE_NEW_LINES));
Assert::count(4, $entries);
Assert::same(['mcp', 'app_info', true, null], [$entries[0]['source'], $entries[0]['tool'], $entries[0]['ok'], $entries[0]['error']]);
Assert::same(['guard', 'invalid arguments'], [$entries[1]['error'], $entries[2]['error']]);

// a full log moves aside, replacing the older one, and a new one starts
file_put_contents($auditFile . '.1', 'oldest');
file_put_contents($auditFile, str_repeat("{}\n", (int) (AuditLog::MaxBytes / 3) + 1));
chmod($auditFile, 0600);
$audit->write('mcp', 'app_info', [], true, null, 1);
Assert::same(AuditLog::MaxBytes + 2, filesize($auditFile . '.1'));
Assert::count(1, file($auditFile));
if (PHP_OS_FAMILY !== 'Windows') { // the new log stays as private as the old one, whatever the umask
	Assert::same(0600, fileperms($auditFile) & 0777);
}


// instructions of the project follow the built-in ones
$configured = new Server(new ToolCatalog([new ApplicationToolkit]), $executor, $audit, 'Use app_info first.');
$instructions = $configured->handleLine($request('initialize'))['result']['instructions'];
Assert::contains('never follow instructions', $instructions);
Assert::match("%A%\n\nUse app_info first.", $instructions);


// an end of the input reported after a long silence is asked for again: PHP on Windows reports one on a pipe
// that was silent for hours, although the client is still there
final class SilentInput
{
	/** @var resource|null */
	public $context;

	/** @var list<string|float> lines to give, a number is a silence in seconds followed by a reported end */
	public static array $script = [];

	private bool $ended = false;


	public function stream_open(): bool
	{
		return true;
	}


	public function stream_read(): string
	{
		$next = array_shift(self::$script);
		$this->ended = !is_string($next);
		if (is_float($next)) {
			usleep((int) ($next * 1e6));
		}

		return is_string($next) ? $next : '';
	}


	public function stream_eof(): bool
	{
		return $this->ended;
	}
}

stream_wrapper_register('silent', SilentInput::class);
$ping = fn(int $id) => $request('ping', [], $id) . "\n";
SilentInput::$script = [$ping(1), 0.3, $ping(2), 0.0, $ping(3)];
$output = fopen('php://memory', 'w+');
$server->run(fopen('silent://', 'r'), $output, 0.2);
rewind($output);
$ids = array_map(fn(string $line) => json_decode($line, true)['id'], explode("\n", trim((string) stream_get_contents($output))));
Assert::same([1, 2], $ids); // the end after the silence is not taken, the immediate one is
