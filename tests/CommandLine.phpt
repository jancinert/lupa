<?php declare(strict_types=1);

/**
 * bin/lupa as an MCP client starts it.
 */

use Tester\Assert;

require __DIR__ . '/bootstrap.php';


/**
 * @param list<string> $arguments
 * @param array<string, string> $env
 * @return array{int, string, string}  exit code, stdout, stderr
 */
function runBin(array $arguments, string $input = '', array $env = []): array
{
	$command = array_merge([PHP_BINARY, BinFile], $arguments, ['--project', FixtureDir, '--autoload', VendorAutoload]);
	$process = proc_open($command, [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']], $pipes, null, $env + getenv(), ['bypass_shell' => true]);
	fwrite($pipes[0], $input);
	fclose($pipes[0]);
	$stdout = stream_get_contents($pipes[1]);
	$stderr = stream_get_contents($pipes[2]);
	return [proc_close($process), $stdout, $stderr];
}


// MCP session
$messages = [
	['jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize', 'params' => ['protocolVersion' => '2025-11-25', 'capabilities' => new stdClass, 'clientInfo' => ['name' => 'test', 'version' => '1']]],
	['jsonrpc' => '2.0', 'method' => 'notifications/initialized'],
	['jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/list'],
	['jsonrpc' => '2.0', 'id' => 3, 'method' => 'tools/call', 'params' => ['name' => 'app_info', 'arguments' => new stdClass]],
];
$input = implode("\n", array_map('json_encode', $messages)) . "\n";
[$code, $stdout, $stderr] = runBin(['serve'], $input);
Assert::same(0, $code, $stderr);
Assert::contains('Lupa', $stderr);

$responses = array_map(fn($line) => json_decode($line, true), explode("\n", trim($stdout)));
Assert::same([1, 2, 3], array_column($responses, 'id'));
Assert::same('2025-11-25', $responses[0]['result']['protocolVersion']);
Assert::contains('app_info', array_column($responses[1]['result']['tools'], 'name'));
Assert::false($responses[2]['result']['isError'], $responses[2]['result']['content'][0]['text']);
Assert::same('db.local', $responses[2]['result']['structuredContent']['databases'][0]['host']);


// call
[$code, $stdout] = runBin(['call', 'app_info', '{"packages":"all"}']);
Assert::same(0, $code);
Assert::true(json_decode($stdout, true)['ok']);

[$code, , $stderr] = runBin(['call', 'app_info', '{"packages":1}']);
Assert::same(1, $code);
Assert::contains("Argument 'packages' must be of type string.", $stderr);


// kill switch
[$code, $stdout, $stderr] = runBin(['serve'], '', ['LUPA_DISABLED' => '1']);
Assert::same(1, $code);
Assert::same('', $stdout);
Assert::contains('Lupa is disabled', $stderr);


// help and unknown command
[$code, $stdout] = runBin(['help']);
Assert::same(0, $code);
Assert::contains('lupa serve', $stdout);

[$code, , $stderr] = runBin(['nonsense']);
Assert::same(1, $code);
Assert::contains("Unknown command 'nonsense'", $stderr);

// help asked after a command shows the help instead of running the command
foreach ([['init', '--help'], ['call', '-h'], ['-h']] as $arguments) {
	[$code, $stdout] = runBin($arguments);
	Assert::same(0, $code);
	Assert::contains('lupa serve', $stdout);
}
Assert::false(is_file(FixtureDir . '/.mcp.json'));

// a mistyped option is refused instead of being ignored
[$code, , $stderr] = runBin(['call', 'app_info', '--porject', 'x']);
Assert::same(1, $code);
Assert::contains("Unknown option '--porject'", $stderr);
