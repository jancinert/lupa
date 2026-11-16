<?php declare(strict_types=1);

use Lupa\OutputBuffer;
use Tester\Assert;
use Tester\Environment;

require __DIR__ . '/bootstrap.php';


$level = ob_get_level();
ob_start(static function (string $output): string { throw new RuntimeException('Output handler failed.'); });
Assert::false(OutputBuffer::discardAbove($level));
Assert::true(OutputBuffer::discardAbove($level));

$cgi = dirname(PHP_BINARY) . '/php-cgi' . (PHP_OS_FAMILY === 'Windows' ? '.exe' : '');
if (!function_exists('proc_open') || !is_file($cgi)) {
	Environment::skip('The harness needs proc_open and a sibling php-cgi binary.');
}

$root = TempDir . '/buffers';
mkdir($root, 0700);
mkdir($root . '/runtime', 0700);
file_put_contents($root . '/lupa.neon', "inspector:\n\tframework: none\n\tbootstrap: bootstrap.php\n\taudit: runtime/audit.jsonl\n");
$application = <<<'PHP'
	<?php
	if (getenv('LUPA_BUFFER_STAGE') === APPLICATION_STAGE) {
		$buffer = static function (): void {
			set_error_handler(static function (): bool { return true; });
			ob_start(static function (string $output): string {
				if (in_array(getenv('LUPA_BUFFER_EXIT'), ['4', '5'], true)) {
					file_put_contents(__DIR__ . '/handler-ran', 'yes');
					if (getenv('LUPA_BUFFER_EXIT') === '5') { exit; }
					throw new RuntimeException('password=synthetic-buffer-secret');
				}
				return 'synthetic-buffer-secret';
			}, 0, (int) getenv('LUPA_BUFFER_FLAGS'));
			ob_start();
			header('X-Application-Secret: synthetic-buffer-secret');
			setcookie('application_debug', 'synthetic-buffer-secret');
			echo 'synthetic-buffer-secret';
		};
		if (getenv('LUPA_BUFFER_EXIT') === '3') {
			register_shutdown_function($buffer);
		} else {
			$buffer();
		}
		if (getenv('LUPA_BUFFER_EXIT') === '1') { exit; }
		if (getenv('LUPA_BUFFER_EXIT') === '2') { throw new RuntimeException('password=synthetic-buffer-secret'); }
	}
	return ['parameters' => [], 'databases' => []];
	PHP;
foreach (['autoload', 'bootstrap'] as $stage) {
	file_put_contents($root . '/' . $stage . '.php', str_replace('APPLICATION_STAGE', var_export($stage, true), $application));
}

$template = <<<'PHP'
	<?php declare(strict_types=1);
	require AUTOLOAD;
	$_SERVER['HTTPS'] = 'on';
	$_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . str_repeat('c', 64);
	$_SERVER['HTTP_ACCEPT'] = 'application/json, text/event-stream';
	$_SERVER['HTTP_MCP_PROTOCOL_VERSION'] = '2025-11-25';
	$project = new Lupa\Project(__DIR__, __DIR__ . '/autoload.php', __DIR__ . '/lupa.neon');
	(new Lupa\Http\Endpoint($project, hash('sha256', str_repeat('c', 64))))->serve(PHP_SAPI === 'cli' ? STDIN : null);
	file_put_contents(__DIR__ . '/completed', 'yes');
	PHP;
$runner = $root . '/endpoint.php';
file_put_contents($runner, str_replace('AUTOLOAD', var_export(VendorAutoload, true), $template));
$request = '{"jsonrpc":"2.0","id":1,"method":"tools/call","params":{"name":"app_info"}}';
foreach ([PHP_BINARY, $cgi] as $binary) {
	foreach ([0, 16, 32, 48, 64, 80, 96, 112] as $flags) {
		foreach (['autoload', 'bootstrap'] as $stage) {
			foreach ([0, 1, 2, 3, 4, 5] as $action) {
				$exit = $action === 1;
				// Exiting bypasses normal cleanup and exercises the endpoint shutdown guard.
				if (($exit && !in_array($flags, [0, 48], true))
					|| ($action === 2 && $flags !== 0)
					|| ($action === 3 && !in_array($flags, [0, 112], true))
					|| ($action >= 4 && $flags !== 0)
				) {
					continue;
				}
				@unlink($root . '/completed'); // @ the previous case may have exited before writing it
				@unlink($root . '/handler-ran'); // @ only failing output handlers write this marker
				file_put_contents($root . '/request', $request);
				$env = array_merge(getenv(), [
					'LUPA_BUFFER_STAGE' => $stage,
					'LUPA_BUFFER_FLAGS' => (string) $flags,
					'LUPA_BUFFER_EXIT' => (string) $action,
					'REDIRECT_STATUS' => '200',
					'SCRIPT_FILENAME' => $runner,
					'REQUEST_METHOD' => 'POST',
					'CONTENT_TYPE' => 'application/json',
					'CONTENT_LENGTH' => (string) strlen($request),
				]);
				$process = proc_open([
					$binary, '-d', 'cgi.force_redirect=0', '-d', 'output_buffering=4096',
					'-d', 'disable_functions=proc_open,proc_terminate,proc_get_status,proc_close', $runner,
				], [
					0 => ['file', $root . '/request', 'r'],
					1 => ['file', $root . '/output', 'w'],
					2 => ['file', $root . '/errors', 'w'],
				], $pipes, $root, $env);
				Assert::true(is_resource($process));
				$deadline = microtime(true) + 5;
				do {
					$status = proc_get_status($process);
					if (!$status['running']) {
						break;
					}
					usleep(10000);
				} while (microtime(true) < $deadline);
				if ($status['running']) {
					proc_terminate($process, 9);
				}
				proc_close($process);
				Assert::false($status['running'], "$stage flags=$flags exit=" . (int) $exit . ' did not finish');
				$output = file_get_contents($root . '/output');
				Assert::same('', file_get_contents($root . '/errors'));
				Assert::notContains('synthetic-buffer-secret', $output);
				if ($binary === $cgi) {
					$parts = preg_split('~\r?\n\r?\n~', $output, 2);
					Assert::count(2, $parts);
					Assert::notContains('set-cookie:', strtolower($parts[0]));
					Assert::contains('Cache-Control: no-store', $parts[0]);
					$output = $parts[1];
				}
				if ($action >= 4) {
					clearstatcache();
					Assert::true(is_file($root . '/handler-ran'));
					// PHP can abandon final flushing when an output callback itself throws or exits.
					if ($output === '') {
						continue;
					}
				}
				$response = json_decode($output, true, 512, JSON_THROW_ON_ERROR);
				if ($action !== 3 && ($exit || !($flags & PHP_OUTPUT_HANDLER_REMOVABLE))) {
					Assert::same(-32603, $response['error']['code']);
				} else {
					Assert::false($response['result']['isError']);
					Assert::same('none', $response['result']['structuredContent']['application']['framework']);
				}
				clearstatcache();
				Assert::same(!$exit, is_file($root . '/completed'), "$binary $stage flags=$flags exit=" . (int) $exit);
			}
		}
	}
}
