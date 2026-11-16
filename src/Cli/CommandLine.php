<?php declare(strict_types=1);

namespace Lupa\Cli;

use Lupa\AuditLog;
use Lupa\Config;
use Lupa\Inspector;
use Lupa\InspectorException;
use Lupa\Masking;
use Lupa\Mcp\ArgumentValidator;
use Lupa\Mcp\Server;
use Lupa\Project;
use Lupa\Tools\ToolCatalog;
use Lupa\Worker\Worker;
use Lupa\Worker\WorkerClient;


final class CommandLine
{
	private const Usage = <<<'TXT'
		Lupa %s – MCP server for inspecting a PHP application

		Usage:
		  lupa serve                 run the MCP server on stdin/stdout
		  lupa call <tool> [<json>]  run one tool and print the result
		  lupa init [--framework none] [--docker <container>] [--docker-workdir <dir>] [--php <command>]
		                             create lupa.neon and lupa-bootstrap.php, register the server in .mcp.json
		                             and, where the directories exist, in .cursor/mcp.json and .vscode/mcp.json;
		                             --php names the PHP command when it is not php, e.g. "ddev exec php";
		                             --framework none sets up an application without a framework

		Options:
		  --project <dir>     root of the application (default: found from the current directory)
		  --config <file>     configuration file (default: <project>/lupa.neon)
		  --autoload <file>   Composer autoloader of the application (default: <project>/vendor/autoload.php)

		Set LUPA_DISABLED=1 to disable the inspector.

		TXT;

	private const Options = ['project', 'config', 'autoload', 'framework', 'docker', 'docker-workdir', 'php'];

	private string $script;
	private ?string $composerAutoload;

	/** @var resource */
	private $stdout;

	/** @var resource */
	private $stderr;


	/**
	 * @param string $script  path of bin/lupa, used to start worker processes
	 * @param ?string $composerAutoload  autoloader path given by the Composer bin proxy
	 */
	public function __construct(string $script, ?string $composerAutoload)
	{
		$this->script = $script;
		$this->composerAutoload = $composerAutoload;
		$this->stdout = STDOUT;
		$this->stderr = STDERR;
	}


	/**
	 * @param list<string> $argv
	 */
	public function run(array $argv): int
	{
		[$command, $arguments, $options] = self::parseArguments(array_slice($argv, 1));
		if (array_intersect(['--help', '-h'], array_slice($argv, 1))) { // also after a command, which must not run then
			$command = 'help';
		}

		try {
			$unknown = array_diff(array_keys($options), self::Options);
			if ($unknown && $command !== 'help') {
				throw new InspectorException("Unknown option '--" . reset($unknown) . "'. Run 'lupa help'.");
			}

			switch ($command) {
				case 'serve':
					return $this->serve($options);
				case 'worker':
					return (new Worker($this->locateProject($options)))->run(STDIN, $this->stdout);
				case 'call':
					return $this->call($arguments, $options);
				case 'init':
					return (new Installer($this->stdout))->install($this->locateProject($options)->root, $options);
				case '--version':
					fwrite($this->stdout, Inspector::Version . "\n");
					return 0;
				case null:
				case 'help':
				case '--help':
					fwrite($this->stdout, sprintf(self::Usage, Inspector::Version));
					return 0;
				default:
					throw new InspectorException("Unknown command '$command'. Run 'lupa help'.");
			}
		} catch (InspectorException $e) {
			fwrite($this->stderr, 'Error: ' . $e->getMessage() . "\n");
			return 1;
		}
	}


	/**
	 * @param array<string, string> $options
	 */
	private function serve(array $options): int
	{
		[$project, $catalog, $audit, $worker, $config] = $this->prepare($options);
		$server = new Server($catalog, $worker, $audit, $config->instructions);
		fwrite($this->stderr, sprintf("Lupa %s serving %s (%d tools)\n", Inspector::Version, $project->root, count($catalog->getTools())));
		$server->run(STDIN, $this->stdout);
		return 0;
	}


	/**
	 * @param list<string> $arguments
	 * @param array<string, string> $options
	 */
	private function call(array $arguments, array $options): int
	{
		$name = $arguments[0] ?? null;
		if ($name === null) {
			throw new InspectorException('Missing tool name.');
		}

		$toolArguments = isset($arguments[1]) ? json_decode($arguments[1], true) : [];
		if (!is_array($toolArguments)) {
			throw new InspectorException('Tool arguments must be a JSON object.');
		}

		[, $catalog, $audit, $worker] = $this->prepare($options);
		$tool = $catalog->getTool($name);
		if ($tool === null) {
			throw new InspectorException("Unknown tool '$name'.");
		} elseif ($errors = (new ArgumentValidator)->validate($tool->inputSchema, $toolArguments)) {
			throw new InspectorException(implode("\n", $errors));
		}

		$start = microtime(true);
		$response = $worker->execute($name, $toolArguments);
		$duration = (int) round((microtime(true) - $start) * 1000);
		$audit->write('cli', $name, $toolArguments, $response->ok, $response->errorKind, $duration);

		fwrite($this->stdout, Server::encode($response->toArray() + ['durationMs' => $duration], JSON_PRETTY_PRINT) . "\n");
		return $response->ok ? 0 : 1;
	}


	/**
	 * @param array<string, string> $options
	 * @return array{Project, ToolCatalog, AuditLog, WorkerClient, Config}
	 */
	private function prepare(array $options): array
	{
		$project = $this->locateProject($options);
		$project->loadAutoloader();
		$container = Inspector::isDisabledByEnvironment() ? null : Inspector::createContainer($project->configFile);
		$config = $container ? $container->getByType(Config::class) : null;
		if ($config === null || !$config->enabled) {
			throw new InspectorException('Lupa is disabled (enabled: false in the configuration or ' . Inspector::KillSwitch . ' is set).');
		}

		$audit = $container->getByType(AuditLog::class);
		$audit->open();
		$worker = new WorkerClient(PHP_BINARY, $this->script, $project, $config->timeout, $container->getByType(Masking::class));
		return [$project, $container->getByType(ToolCatalog::class), $audit, $worker, $config];
	}


	/**
	 * @param array<string, string> $options
	 */
	private function locateProject(array $options): Project
	{
		return Project::locate($options, $this->composerAutoload, (string) getcwd());
	}


	/**
	 * @param list<string> $args
	 * @return array{?string, list<string>, array<string, string>}
	 */
	private static function parseArguments(array $args): array
	{
		$command = null;
		$positional = [];
		$options = [];
		for ($i = 0; $i < count($args); $i++) {
			$arg = $args[$i];
			if (preg_match('~^--([a-z][a-z-]*)(?:=(.*))?$~', $arg, $m) && !in_array($arg, ['--help', '--version'], true)) {
				$options[$m[1]] = $m[2] ?? (string) ($args[++$i] ?? '');
			} elseif ($command === null) {
				$command = $arg;
			} else {
				$positional[] = $arg;
			}
		}

		return [$command, $positional, $options];
	}
}
