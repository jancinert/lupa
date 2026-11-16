<?php declare(strict_types=1);

use Tester\Assert;
use Tester\Environment;

require __DIR__ . '/bootstrap.php';


$cgi = dirname(PHP_BINARY) . '/php-cgi' . (PHP_OS_FAMILY === 'Windows' ? '.exe' : '');
if (!function_exists('proc_open') || !is_file($cgi)) {
	Environment::skip('The harness needs proc_open and a sibling php-cgi binary; the endpoint runs with proc_open disabled.');
}

$root = TempDir . '/cgi-shutdown';
mkdir($root, 0700);
mkdir($root . '/runtime', 0700);
file_put_contents($root . '/lupa.neon', "inspector:\n\tframework: none\n\tbootstrap: bootstrap.php\n\taudit: runtime/audit.jsonl\n");
file_put_contents($root . '/autoload.php', <<<'PHP'
	<?php
	file_put_contents(__DIR__ . '/autoload-runs', 'x', FILE_APPEND);
	register_shutdown_function(static function (): void {
		header('X-Application-Secret: synthetic-cgi-secret');
		setcookie('application_debug', 'synthetic-cgi-secret');
		echo 'synthetic-cgi-secret';
		ini_set('memory_limit', '16M');
		str_repeat('x', 100000000);
	});
	if (getenv('LUPA_TEST_CASE') === 'autoload-exit') { exit; }
	PHP);
file_put_contents($root . '/bootstrap.php', <<<'PHP'
	<?php
	if (getenv('LUPA_TEST_CASE') === 'audit-failure') {
		set_error_handler(static function (): void {
			header('X-Application-Secret: synthetic-cgi-secret');
			echo 'synthetic-cgi-secret';
			ini_set('memory_limit', '16M');
			str_repeat('x', 100000000);
		});
		unlink(__DIR__ . '/runtime/audit.jsonl');
		mkdir(__DIR__ . '/runtime/audit.jsonl', 0700);
		exit;
	}
	if (getenv('LUPA_TEST_CASE') === 'bootstrap-exit') { exit; }
	return ['parameters' => [], 'databases' => []];
	PHP);
$template = <<<'PHP'
	<?php declare(strict_types=1);
	require AUTOLOAD;
	$_SERVER['HTTPS'] = 'on';
	$_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . str_repeat('c', 64);
	$_SERVER['HTTP_ACCEPT'] = 'application/json, text/event-stream';
	$project = new Lupa\Project(__DIR__, __DIR__ . '/autoload.php', __DIR__ . '/lupa.neon');
	(new Lupa\Http\Endpoint($project, hash('sha256', str_repeat('c', 64))))->serve();
	PHP;
$runner = $root . '/endpoint.php';
file_put_contents($runner, str_replace('AUTOLOAD', var_export(VendorAutoload, true), $template));
$call = static function (string $case, string $body) use ($root, $cgi, $runner): string {
	$env = array_merge(getenv(), [
		'LUPA_TEST_CASE' => $case,
		'REDIRECT_STATUS' => '200',
		'SCRIPT_FILENAME' => $runner,
		'REQUEST_METHOD' => 'POST',
		'CONTENT_TYPE' => 'application/json',
		'CONTENT_LENGTH' => (string) strlen($body),
	]);
	$process = proc_open([
		$cgi,
		'-d', 'cgi.force_redirect=0',
		'-d', 'output_buffering=4096',
		'-d', 'disable_functions=proc_open,proc_terminate,proc_get_status,proc_close',
	], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $root, $env);
	Assert::true(is_resource($process));
	fwrite($pipes[0], $body);
	fclose($pipes[0]);
	$output = stream_get_contents($pipes[1]);
	$errors = stream_get_contents($pipes[2]);
	fclose($pipes[1]);
	fclose($pipes[2]);
	proc_close($process);
	Assert::notContains('synthetic-cgi-secret', $output . $errors);
	$parts = preg_split('~\r?\n\r?\n~', $output, 2);
	Assert::count(2, $parts);
	Assert::notContains('set-cookie:', strtolower($parts[0]));
	Assert::contains('Cache-Control: no-store', $parts[0]);
	return $parts[1];
};
$initialize = '{"jsonrpc":"2.0","id":1,"method":"initialize","params":{"protocolVersion":"2025-11-25"}}';
$tool = '{"jsonrpc":"2.0","id":1,"method":"tools/call","params":{"name":"app_info"}}';
$normal = json_decode($call('normal', $initialize), true, 512, JSON_THROW_ON_ERROR);
Assert::same('2025-11-25', $normal['result']['protocolVersion']);
foreach (['autoload-exit' => $initialize, 'bootstrap-exit' => $tool, 'audit-failure' => $tool] as $case => $request) {
	$result = json_decode($call($case, $request), true, 512, JSON_THROW_ON_ERROR);
	Assert::same(-32603, $result['error']['code']);
}
// Notifications must stay empty even when an autoloader exits before normal completion.
$runs = file_get_contents($root . '/autoload-runs');
Assert::same('', $call('autoload-exit', '{"jsonrpc":"2.0","method":"notifications/initialized"}'));
Assert::same($runs, file_get_contents($root . '/autoload-runs'));
