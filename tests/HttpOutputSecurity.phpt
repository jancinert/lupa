<?php declare(strict_types=1);

use Tester\Assert;
use Tester\Environment;

require __DIR__ . '/bootstrap.php';


if (!function_exists('proc_open')) {
	Environment::skip('The HTTP test harness needs proc_open; the endpoint subprocess has it disabled.');
}

$root = TempDir . '/http-output';
mkdir($root, 0700);
mkdir($root . '/runtime', 0700);
file_put_contents($root . '/lupa.neon', "inspector:\n\tframework: none\n\tbootstrap: bootstrap.php\n\taudit: runtime/audit.jsonl\n");
file_put_contents($root . '/bootstrap.php', <<<'PHP'
	<?php
	$case = $_SERVER['REQUEST_URI'];
	if ($case === '/boot-exit') { echo 'synthetic-late-secret'; exit; }
	if ($case === '/boot-oom') { ini_set('memory_limit', '16M'); str_repeat('x', 100000000); }
	if ($case === '/boot-flush') { echo 'synthetic-late-secret'; flush(); }
	if ($case === '/tool-oom') { ini_set('display_errors', '1'); ini_set('memory_limit', (string) (memory_get_usage() + 3000000)); }
	return ['parameters' => [], 'databases' => ['main' => ['dsn' => 'sqlite:' . __DIR__ . '/app.sqlite']]];
	PHP);
// enough tables for the tool to exhaust the memory the bootstrap of /tool-oom leaves
$db = new SQLite3($root . '/app.sqlite');
$db->exec('BEGIN');
for ($i = 0; $i < 5000; $i++) {
	$db->exec("CREATE TABLE table_$i (id INTEGER PRIMARY KEY)");
}
$db->exec('COMMIT');
$db->close();
file_put_contents($root . '/autoload.php', <<<'PHP'
	<?php
	header_register_callback(static function (): void {
		header('X-Late-Secret: synthetic-late-secret');
		setcookie('late_debug', 'synthetic-late-secret');
	});
	register_shutdown_function(static function (): void {
		if ($_SERVER['REQUEST_URI'] === '/late-header-callback') {
			header_register_callback(static function (): void {
				header('X-Late-Secret: synthetic-late-secret');
				setcookie('late_debug', 'synthetic-late-secret');
			});
		}
		header('X-Late-Secret: synthetic-late-secret');
		setcookie('late_debug', 'synthetic-late-secret');
		echo 'synthetic-late-secret';
		register_shutdown_function(static function (): void { echo 'synthetic-late-secret'; });
		if ($_SERVER['REQUEST_URI'] === '/late-flush') { flush(); }
		if ($_SERVER['REQUEST_URI'] === '/late-exit') { exit; }
		if ($_SERVER['REQUEST_URI'] === '/late-fatal') { trigger_error('synthetic-late-secret', E_USER_ERROR); }
		if ($_SERVER['REQUEST_URI'] === '/late-oom') { ini_set('memory_limit', '16M'); str_repeat('x', 100000000); }
	});
	$GLOBALS['lateDestructor'] = new class {
		public function __destruct() { echo 'synthetic-late-secret'; }
	};
	PHP);
$template = <<<'PHP'
	<?php declare(strict_types=1);
	require AUTOLOAD;
	$_SERVER['HTTPS'] = 'on'; // isolated loopback fixture, not TLS verification
	$project = new Lupa\Project(__DIR__, __DIR__ . '/autoload.php', __DIR__ . '/lupa.neon');
	(new Lupa\Http\Endpoint($project, hash('sha256', str_repeat('c', 64))))->serve();
	PHP;
file_put_contents($root . '/endpoint.php', str_replace('AUTOLOAD', var_export(VendorAutoload, true), $template));
$socket = stream_socket_server('tcp://127.0.0.1:0');
Assert::true(is_resource($socket));
$address = stream_socket_get_name($socket, false);
fclose($socket);
$log = $root . '/server.log';
$process = proc_open([
	PHP_BINARY,
	'-d', 'disable_functions=proc_open,proc_terminate,proc_get_status,proc_close',
	'-d', 'output_buffering=4096',
	'-S', $address, $root . '/endpoint.php',
], [0 => ['pipe', 'r'], 1 => ['file', $log, 'a'], 2 => ['file', $log, 'a']], $pipes);
Assert::true(is_resource($process));
fclose($pipes[0]);
try {
	for ($attempt = 0; $attempt < 50; $attempt++) {
		$ready = @stream_socket_client('tcp://' . $address, $errno, $message, 0.1); // @ server may still be starting
		if (is_resource($ready)) {
			fclose($ready);
			break;
		}
		usleep(100000);
	}
	Assert::true(is_resource($ready) || $attempt < 50, (string) file_get_contents($log));
	$call = static function (string $path, string $method = 'POST', string $body = '', bool $authenticated = true) use ($address): array {
		$socket = stream_socket_client('tcp://' . $address, $errno, $message, 3);
		Assert::true(is_resource($socket), $message);
		stream_set_timeout($socket, 10);
		$headers = "$method $path HTTP/1.1\r\nHost: localhost\r\nConnection: close\r\nContent-Type: application/json\r\nAccept: application/json, text/event-stream\r\n";
		if ($authenticated) {
			$headers .= 'Authorization: Bearer ' . str_repeat('c', 64) . "\r\n";
		}
		fwrite($socket, $headers . 'Content-Length: ' . strlen($body) . "\r\n\r\n" . $body);
		$raw = stream_get_contents($socket);
		Assert::false(stream_get_meta_data($socket)['timed_out']);
		fclose($socket);
		Assert::notContains('synthetic-late-secret', $raw);
		[$headers, $body] = explode("\r\n\r\n", $raw, 2);
		Assert::notContains('set-cookie:', strtolower($headers));
		Assert::notContains('x-late-secret:', strtolower($headers));
		Assert::contains('Cache-Control: no-store', $headers);
		Assert::same(1, preg_match('~^HTTP/1\.[01] (\d{3})\b~', $headers, $match), $headers);
		return [(int) $match[1], $body];
	};
	$request = '{"jsonrpc":"2.0","id":1,"method":"tools/call","params":{"name":"app_info"}}';
	// a build of PHP that ends at once when the memory runs out would take the whole test server with it
	$exhausting = shutdownRunsAfterExhaustedMemory();
	foreach (['/normal', '/late-exit', '/late-flush', '/late-header-callback', '/late-fatal', '/late-oom', '/boot-flush'] as $path) {
		if ($path === '/late-oom' && !$exhausting) {
			continue;
		}

		[$status, $body] = $call($path, 'POST', $request);
		Assert::same(200, $status);
		Assert::false(json_decode($body, true, 512, JSON_THROW_ON_ERROR)['result']['isError']);
	}
	foreach ($exhausting ? ['/boot-exit', '/boot-oom'] : ['/boot-exit'] as $path) {
		[$status, $body] = $call($path, 'POST', $request);
		Assert::same(200, $status);
		Assert::same(-32603, json_decode($body, true, 512, JSON_THROW_ON_ERROR)['error']['code']);
	}
	if ($exhausting) {
		// the bootstrap turns display_errors on and the memory runs out in Lupa, which no output buffer catches
		[$status, $body] = $call('/tool-oom', 'POST', '{"jsonrpc":"2.0","id":1,"method":"tools/call","params":{"name":"db_tables"}}');
		Assert::same(200, $status);
		Assert::same(-32603, json_decode($body, true, 512, JSON_THROW_ON_ERROR)['error']['code'], $body);
	}
	Assert::same([202, ''], $call('/normal', 'POST', '{"jsonrpc":"2.0","method":"notifications/initialized"}'));
	Assert::same([405, ''], $call('/normal', 'HEAD'));
	[$status, $body] = $call('/normal', 'POST', $request, false);
	Assert::same(401, $status);
	Assert::same(-32600, json_decode($body, true, 512, JSON_THROW_ON_ERROR)['error']['code']);
} finally {
	proc_terminate($process);
	proc_close($process);
}
Assert::notContains('synthetic-late-secret', (string) file_get_contents($log));
