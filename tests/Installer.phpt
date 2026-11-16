<?php declare(strict_types=1);

use Lupa\Cli\Installer;
use Lupa\Config;
use Tester\Assert;

require __DIR__ . '/bootstrap.php';


$output = fopen('php://memory', 'w+');
$dir = TempDir . '/project';
mkdir($dir);
file_put_contents($dir . '/.mcp.json', '{"mcpServers":{"other":{"command":"npx","env":{}}}}');

Assert::same(0, (new Installer($output))->install($dir, ['docker' => 'app-www']));
rewind($output);
$log = stream_get_contents($output);
Assert::contains('Created lupa.neon', $log);
Assert::contains('Created lupa-bootstrap.php', $log);
Assert::contains('Added the server to .mcp.json', $log);
Assert::notContains('.cursor', $log);
$servers = json_decode((string) file_get_contents($dir . '/.mcp.json'), true)['mcpServers'];
Assert::same(['other', 'lupa'], array_keys($servers));
Assert::same(['command' => 'npx', 'env' => []], $servers['other']);
Assert::same('docker', $servers['lupa']['command']);
Assert::contains("throw new LogicException('lupa-bootstrap.php is not set up", file_get_contents($dir . '/lupa-bootstrap.php'));

// the generated configuration is valid and the bootstrap is valid PHP
$config = Lupa\Inspector::createContainer($dir . '/lupa.neon')->getByType(Config::class);
Assert::same(30, $config->timeout);
exec(escapeshellarg(PHP_BINARY) . ' -l ' . escapeshellarg($dir . '/lupa-bootstrap.php'), $lint, $code);
Assert::same(0, $code);

// existing files are kept
file_put_contents($dir . '/lupa.neon', 'enabled: false');
(new Installer($output))->install($dir, []);
Assert::same('enabled: false', file_get_contents($dir . '/lupa.neon'));
rewind($output);
Assert::contains("Skipped .mcp.json: it already has the server 'lupa'.", stream_get_contents($output));

// other AI clients get the server when their directory exists; the bootstrap follows the shape of App\Bootstrap
foreach (['static' => 'public static function boot(): Configurator {}', 'object' => 'public function bootWebApplication() {}'] as $shape => $method) {
	$project = TempDir . "/$shape";
	mkdir("$project/app", 0777, true);
	mkdir("$project/.cursor");
	mkdir("$project/.vscode");
	file_put_contents("$project/app/Bootstrap.php", "<?php\nnamespace App;\nclass Bootstrap { $method }\n");
	file_put_contents("$project/.vscode/mcp.json", 'not json');
	$steps = fopen('php://memory', 'w+');
	(new Installer($steps))->install($project, ['php' => 'ddev exec php']);
	$entry = ['command' => 'ddev', 'args' => ['exec', 'php', 'vendor/bin/lupa', 'serve']];
	Assert::same(['mcpServers' => ['lupa' => $entry]], json_decode((string) file_get_contents("$project/.mcp.json"), true));
	Assert::same(['mcpServers' => ['lupa' => $entry]], json_decode((string) file_get_contents("$project/.cursor/mcp.json"), true));
	Assert::same('not json', file_get_contents("$project/.vscode/mcp.json"));
	$bootstrap = (string) file_get_contents("$project/lupa-bootstrap.php");
	Assert::contains($shape === 'static' ? 'return App\Bootstrap::boot();' : 'return (new App\Bootstrap)->bootConfigurator();', $bootstrap);
	exec(escapeshellarg(PHP_BINARY) . ' -l ' . escapeshellarg("$project/lupa-bootstrap.php"), $lint, $code);
	Assert::same(0, $code);
	rewind($steps);
	// a bootstrap that cannot work yet is the first thing the next steps name
	Assert::same($shape === 'object', strpos((string) stream_get_contents($steps), '1. Finish lupa-bootstrap.php') !== false);
}

// the Bootstrap of nette/web-project needs no change: its own steps run up to the Configurator
$skeleton = TempDir . '/skeleton';
mkdir("$skeleton/app", 0777, true);
file_put_contents("$skeleton/app/Bootstrap.php", <<<'PHP'
	<?php
	namespace App;
	class Bootstrap
	{
		private $configurator;
		public function __construct() { $this->configurator = new \stdClass; $this->configurator->steps = []; }
		public function bootWebApplication() { $this->initializeEnvironment(); $this->setupContainer(); return null; }
		public function initializeEnvironment(): void { $this->configurator->steps[] = 'environment'; }
		private function setupContainer(): void { $this->configurator->steps[] = 'container'; }
	}
	PHP);
(new Installer($output))->install($skeleton, []);
// in a process of its own, App\Bootstrap of the fixtures is not the one meant here
file_put_contents("$skeleton/run.php", "<?php require __DIR__ . '/app/Bootstrap.php'; echo json_encode((require __DIR__ . '/lupa-bootstrap.php')->steps);");
exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg("$skeleton/run.php"), $ran);
Assert::same(['["environment","container"]'], $ran);

// a class the generated code could not create or call that way gets the note instead; comments do not count
$variants = [
	'namespace' => ['namespace App;', 'namespace App\Core;'],
	'constructor' => ['__construct()', '__construct(string $root)'],
	'static' => ['public function initializeEnvironment', 'public static function initializeEnvironment'],
	'comment' => ['private function setupContainer(): void {', '/* private function setupContainer(): void { */ function other() {'],
];
foreach ($variants as $name => [$search, $replace]) {
	$other = TempDir . "/skeleton-$name";
	mkdir("$other/app", 0777, true);
	file_put_contents("$other/app/Bootstrap.php", str_replace($search, $replace, (string) file_get_contents("$skeleton/app/Bootstrap.php")));
	(new Installer($output))->install($other, []);
	Assert::contains('->bootConfigurator();', (string) file_get_contents("$other/lupa-bootstrap.php"), $name);
}

Assert::exception(fn() => (new Installer($output))->install($skeleton, ['framework' => 'symfony']), Lupa\InspectorException::class);


// server entries
Assert::same(
	['command' => 'docker', 'args' => ['exec', '-i', '-w', '/srv', 'app-www', 'php', 'vendor/bin/lupa', 'serve']],
	Installer::createServerEntry(['docker' => 'app-www', 'docker-workdir' => '/srv']),
);
Assert::same(['command' => 'php', 'args' => ['vendor/bin/lupa', 'serve']], Installer::createServerEntry([]));


// an application without a framework
$plain = TempDir . '/plain';
mkdir($plain);
(new Installer($output))->install($plain, ['framework' => 'none']);
Assert::contains("\tframework: none\n", (string) file_get_contents($plain . '/lupa.neon'));
$description = require $plain . '/lupa-bootstrap.php';
Assert::same(['parameters', 'databases'], array_keys($description));
Assert::same('none', Lupa\Inspector::createContainer($plain . '/lupa.neon')->getByType(Lupa\Config::class)->framework);
