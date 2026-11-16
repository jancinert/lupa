<?php declare(strict_types=1);

use Tester\Assert;
use Tester\Environment;

require __DIR__ . '/bootstrap.php';


if (!function_exists('proc_open')) {
	Environment::skip('The test harness needs proc_open to run a separate PHP process with proc_open disabled.');
}

$root = TempDir . '/lifecycle';
mkdir($root, 0700);
mkdir($root . '/runtime', 0700);
$key = str_repeat('c', 64);
file_put_contents($root . '/lupa.neon', "inspector:\n\tframework: none\n\tbootstrap: bootstrap.php\n\taudit: runtime/audit.jsonl\n");
$runner = $root . '/serve.php';
$template = <<<'PHP'
	<?php declare(strict_types=1);
	require AUTOLOAD;
	$_SERVER = [
		'REQUEST_METHOD' => 'POST',
		'HTTPS' => 'on',
		'HTTP_AUTHORIZATION' => 'Bearer ' . KEY,
		'CONTENT_TYPE' => 'application/json',
		'HTTP_CONTENT_TYPE' => 'application/json',
		'HTTP_ACCEPT' => 'application/json, text/event-stream',
		'HTTP_MCP_PROTOCOL_VERSION' => '2025-11-25',
	];
	$project = new Lupa\Project(__DIR__, __DIR__ . '/autoload.php', __DIR__ . '/lupa.neon');
	(new Lupa\Http\Endpoint($project, hash('sha256', KEY)))->serve(STDIN);
	PHP;
file_put_contents($runner, str_replace(['AUTOLOAD', 'KEY'], [var_export(VendorAutoload, true), var_export($key, true)], $template));
$request = '{"jsonrpc":"2.0","id":1,"method":"tools/call","params":{"name":"app_info"}}';
$run = static function (string $bootstrap, string $autoload = '') use ($root, $runner, $request): array {
	file_put_contents($root . '/bootstrap.php', "<?php\n" . $bootstrap);
	file_put_contents($root . '/autoload.php', "<?php\n" . $autoload);
	$process = proc_open([
		PHP_BINARY,
		'-d', 'disable_functions=proc_open,proc_terminate,proc_get_status,proc_close',
		'-d', 'log_errors=1',
		'-d', 'error_log=' . $root . '/php-errors.log',
		$runner,
	], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
	Assert::true(is_resource($process));
	fwrite($pipes[0], $request);
	fclose($pipes[0]);
	$output = (string) stream_get_contents($pipes[1]);
	$errors = (string) stream_get_contents($pipes[2]);
	fclose($pipes[1]);
	fclose($pipes[2]);
	proc_close($process);
	Assert::notContains('synthetic-lifecycle-secret', $output . $errors);
	Assert::notContains('@@lupa-result:', $output);
	$decoded = json_decode($output, true);
	Assert::true(is_array($decoded), $output);
	return $decoded;
};

$response = $run("echo 'password=synthetic-lifecycle-secret'; return ['parameters' => [], 'databases' => []];");
Assert::false($response['result']['isError']);
Assert::same('none', $response['result']['structuredContent']['application']['framework']);
$lateOutput = <<<'PHP'
	register_shutdown_function(static function (): void {
		echo 'password=synthetic-lifecycle-secret';
		register_shutdown_function(static function (): void {
			echo 'password=synthetic-lifecycle-secret';
		});
	});
	$GLOBALS['lateDestructor'] = new class {
		public function __destruct()
		{
			echo 'password=synthetic-lifecycle-secret';
		}
	};
	PHP;
$response = $run("return ['parameters' => [], 'databases' => []];", $lateOutput);
Assert::false($response['result']['isError']);
Assert::same('none', $response['result']['structuredContent']['application']['framework']);
$response = $run("echo 'password=synthetic-lifecycle-secret'; exit;", $lateOutput);
Assert::same(-32603, $response['error']['code']);
$response = $run("trigger_error('password=synthetic-lifecycle-secret', E_USER_WARNING); return [];");
Assert::true($response['result']['isError']);
Assert::contains('***', $response['result']['content'][0]['text']);
$response = $run("echo 'password=synthetic-lifecycle-secret'; exit;");
Assert::same(-32603, $response['error']['code']);
if (shutdownRunsAfterExhaustedMemory()) { // otherwise PHP ends at once and nothing is sent at all
	$response = $run("echo 'password=synthetic-lifecycle-secret'; ini_set('memory_limit', '16M'); str_repeat('x', 100000000);");
	Assert::same(-32603, $response['error']['code']);
}
$response = $run(
	'return [];',
	"echo 'password=synthetic-lifecycle-secret'; trigger_error('password=synthetic-lifecycle-secret', E_USER_WARNING); throw new RuntimeException('password=synthetic-lifecycle-secret');",
);
Assert::same(-32603, $response['error']['code']);
$response = $run('return [];', "echo 'password=synthetic-lifecycle-secret'; exit;");
Assert::same(-32603, $response['error']['code']);
file_put_contents($runner, str_replace("'HTTP_AUTHORIZATION'", "'REDIRECT_HTTP_AUTHORIZATION'", file_get_contents($runner)));
$response = $run("return ['parameters' => [], 'databases' => []];");
Assert::false($response['result']['isError']);
Assert::same('none', $response['result']['structuredContent']['application']['framework']);
// a request with a wrong token is refused before its body is read
file_put_contents(
	$runner,
	str_replace("'Bearer ' . ", "'Bearer d' . ", file_get_contents($runner))
	. "\nfile_put_contents(__DIR__ . '/unread-body', stream_get_contents(STDIN));\n",
);
Assert::same(-32600, $run('return [];')['error']['code']);
Assert::same($request, file_get_contents($root . '/unread-body')); // still unread
$log = (string) file_get_contents($root . '/runtime/audit.jsonl');
Assert::notContains('synthetic-lifecycle-secret', $log);
Assert::contains('execution ended', $log);
if (is_file($root . '/php-errors.log')) {
	Assert::notContains('synthetic-lifecycle-secret', (string) file_get_contents($root . '/php-errors.log'));
}
