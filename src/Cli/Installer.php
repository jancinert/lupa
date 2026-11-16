<?php declare(strict_types=1);

namespace Lupa\Cli;

use Lupa\Inspector;
use Lupa\InspectorException;


/**
 * Creates the files the inspector needs in the project. Existing files are never overwritten;
 * an existing list of MCP servers only gets the entry of the inspector added.
 */
final class Installer
{
	private const ConfigTemplate = <<<'NEON'
		# Lupa – development use only.

		inspector:
			# PHP file returning the Configurator of the application, or its DI container.
			bootstrap: lupa-bootstrap.php

			# Kill switch; the environment variable LUPA_DISABLED=1 works too.
			enabled: true

			# Seconds a single tool call may take.
			timeout: 30

			# Every tool call is recorded here.
			audit: log/lupa/audit.jsonl

			# Tool names or patterns hidden from the AI client, e.g. [db_*, tracy_report].
			disabledTools: []

			# Text added to the instructions the model reads on connect, e.g. workflows of the project's toolkits.
			instructions: ''

			# Tools with side effects, off unless named: render (runs a presenter), cache_invalidate.
			enableActive: []

			# NEON files added to the application's Configurator, e.g. a database account with SELECT rights only.
			applicationConfig: []

		guard:
			# Database hosts the application may use. Add the database service of docker compose, e.g. mysql.
			allowedHosts: [localhost, 127.0.0.1, '::1']

			# Database names that must never be inspected, e.g. shared test or production copies.
			deniedDatabases: []

		masking:
			# Further keys and columns to hide; passwords, tokens and similar keys are hidden always.
			keys: []
			columns: {}   # e.g. {users: [phone, address]}

			# Names that only look secret and may be shown, e.g. [hash] for hashes of files.
			ignoreKeys: []

			# Personal data recognized by its shape: email, phone, birthNumber, iban, card.
			detectors: [email, phone, birthNumber, iban, card]

		database:
			# Connection service the database tools use when the application has more of them.
			connection: null
			timeout: 5
			rowLimit: 100

		migrations:
			# Ruckusing migrations, relative to the project root, and the table of executed versions.
			directory: db/migrate
			table: schema_migrations

		# Project toolkits are services implementing Lupa\Tools\Toolkit, or extensions with a section of their own:
		# services:
		# 	- App\Inspector\ProjectToolkit

		NEON;

	private const BootstrapTemplate = <<<'PHP'
		<?php

		declare(strict_types=1);

		// Returns the Configurator of the application for Lupa, before createContainer() is called,
		// so that the environment guard can check the database targets before the container initializes.
		// Returning a created container also works, but then the guard runs only after the initialization.
		// Keep it free of side effects: no session start, no output, no database queries, no error reporting
		// to external services.

		%s

		PHP;

	private const PlainBootstrap = <<<'PHP'
		<?php

		declare(strict_types=1);

		// Describes an application without a framework for Lupa. Connections are given by what creates them,
		// never as objects: the environment guard reads where they point before anything connects.

		return [
			'parameters' => [
				'appDir' => __DIR__ . '/src',
				'logDir' => __DIR__ . '/log',   // Tracy logs, when the application uses Tracy
				'tempDir' => __DIR__ . '/temp',
			],
			'databases' => [
				// a PDO connection
				'main' => ['dsn' => 'mysql:host=127.0.0.1;dbname=app', 'user' => 'app', 'password' => getenv('DB_PASSWORD') ?: ''],
				// or a Dibi connection, by its configuration
				// 'main' => ['driver' => 'mysqli', 'host' => '127.0.0.1', 'database' => 'app', 'username' => 'app', 'password' => ''],
			],
		];

		PHP;

	/** What the bootstrap returns, by the shape of app/Bootstrap.php. */
	private const BootstrapReturns = [
		'static' => 'return App\Bootstrap::boot();',
		'skeleton' => <<<'PHP'
			// App\Bootstrap creates the container itself, so its steps before createContainer() run here, in its scope.
			// A public method of App\Bootstrap that returns the Configurator would do the same without reaching into it.
			return (function () {
				$this->initializeEnvironment();
				$this->setupContainer();
				return $this->configurator;
			})->call(new App\Bootstrap);
			PHP,
		'object' => <<<'PHP'
			// App\Bootstrap creates the container itself. Add a method to it that stops before createContainer()
			// and use it from bootWebApplication() too:
			//
			//     public function bootConfigurator(): Nette\Bootstrap\Configurator
			//     {
			//         $this->initializeEnvironment();
			//         $this->setupContainer();
			//         return $this->configurator;
			//     }

			return (new App\Bootstrap)->bootConfigurator();
			PHP,
		'unknown' => <<<'PHP'
			// TODO: return the Configurator of the application here.
			throw new LogicException('lupa-bootstrap.php is not set up: make it return the Configurator of the application.');
			PHP,
	];

	/** Lists of MCP servers of the AI clients: file => key of the list. The first one is always created. */
	private const ClientFiles = ['.mcp.json' => 'mcpServers', '.cursor/mcp.json' => 'mcpServers', '.vscode/mcp.json' => 'servers'];

	/** @var resource */
	private $output;


	/**
	 * @param resource $output
	 */
	public function __construct($output)
	{
		$this->output = $output;
	}


	/**
	 * @param array<string, string> $options  docker, docker-workdir, php, framework
	 */
	public function install(string $root, array $options): int
	{
		if (!is_dir($root)) {
			throw new InspectorException("Project directory '$root' not found.");
		}

		$framework = $options['framework'] ?? 'nette';
		if (!in_array($framework, ['nette', 'none'], true)) {
			throw new InspectorException("Unknown framework '$framework', use nette or none.");
		}

		$plain = $framework === 'none';
		$shape = $plain ? 'plain' : self::detectBootstrap($root);
		$this->createFile(
			$root . '/lupa.neon',
			$plain
				? str_replace(
					"inspector:\n",
					"inspector:\n\t# The application has no framework: the bootstrap returns an array.\n\tframework: none\n\n",
					self::ConfigTemplate,
				)
				: self::ConfigTemplate,
		);
		$created = $this->createFile(
			$root . '/lupa-bootstrap.php',
			$plain ? self::PlainBootstrap : sprintf(self::BootstrapTemplate, self::BootstrapReturns[$shape]),
		);
		foreach (self::ClientFiles as $file => $key) {
			if ($file === '.mcp.json' || is_dir(dirname("$root/$file"))) {
				$this->registerServer($root, $file, $key, self::createServerEntry($options));
			}
		}

		$steps = [
			'plain' => 'Describe the application in lupa-bootstrap.php: its directories and database connections.',
			'skeleton' => 'Check lupa-bootstrap.php: it runs the steps of App\Bootstrap before createContainer(), which must have'
				. ' no side effects beyond enabling Tracy and the class loader.',
			'object' => 'Finish lupa-bootstrap.php, it does not work yet: App\Bootstrap needs the method its comment shows.',
			'unknown' => 'Finish lupa-bootstrap.php, it does not work yet: make it return the Configurator of the application.',
		];
		if ($created && isset($steps[$shape])) {
			$first = $steps[$shape];
		} elseif ($plain) { // a bootstrap kept from before is the user's own
			$first = 'Check lupa-bootstrap.php describes the application: its directories and database connections.';
		} else {
			$first = 'Check lupa-bootstrap.php returns the Configurator without side effects.';
		}
		fwrite($this->output, "\nNext steps:\n"
			. "1. $first\n"
			. "2. Add the database host to guard.allowedHosts in lupa.neon.\n"
			. "3. Add lupa.neon, lupa-bootstrap.php and log/lupa/ to .gitignore if they are local.\n"
			. "4. Try it: vendor/bin/lupa call app_info\n"
			. "5. Restart the AI client to load the MCP server.\n");
		return 0;
	}


	/**
	 * @param array<string, string> $options
	 * @return array{command: string, args: list<string>}
	 */
	public static function createServerEntry(array $options): array
	{
		$php = preg_split('~\s+~', trim($options['php'] ?? 'php')) ?: ['php']; // e.g. "ddev exec php"
		$serve = array_merge($php, ['vendor/bin/lupa', 'serve']);
		if (isset($options['docker'])) {
			$workdir = $options['docker-workdir'] ?? '/var/www/html';
			return ['command' => 'docker', 'args' => array_merge(['exec', '-i', '-w', $workdir, $options['docker']], $serve)];
		}

		return ['command' => $serve[0], 'args' => array_slice($serve, 1)];
	}


	/**
	 * static: App\Bootstrap::boot() returns the Configurator; skeleton: an instance creates the container itself
	 * in the steps of nette/web-project; object: it does so in steps of its own.
	 */
	private static function detectBootstrap(string $root): string
	{
		$file = $root . '/app/Bootstrap.php';
		$code = is_file($file) ? php_strip_whitespace($file) : ''; // without comments, which may only talk about the methods
		if (preg_match('~\bstatic\s+function\s+boot\s*\(~', $code)) {
			return 'static';
		} elseif (!preg_match('~\bfunction\s+bootWebApplication\s*\(~', $code)) {
			return 'unknown';
		}

		// the generated code creates App\Bootstrap without arguments and calls both steps on the instance
		return preg_match('~\bnamespace\s+App\s*;~', $code)
			&& !preg_match('~\bfunction\s+__construct\s*\(\s*[^)\s]~', $code)
			&& preg_match('~(?<!static)\s+function\s+initializeEnvironment\s*\(\s*\)~', $code)
			&& preg_match('~(?<!static)\s+function\s+setupContainer\s*\(\s*\)~', $code)
			&& strpos($code, '$this->configurator') !== false
			? 'skeleton'
			: 'object';
	}


	/**
	 * Adds the inspector to a list of MCP servers, keeping what the file already has.
	 * @param array{command: string, args: list<string>} $entry
	 */
	private function registerServer(string $root, string $name, string $key, array $entry): void
	{
		$file = "$root/$name";
		$exists = file_exists($file);
		$data = $exists ? json_decode((string) file_get_contents($file)) : new \stdClass;
		if (!$data instanceof \stdClass) {
			fwrite($this->output, "Skipped $name: it is not a JSON object.\n");
			return;
		}

		$servers = $data->$key ?? null;
		$servers = $servers instanceof \stdClass ? $servers : new \stdClass;
		if (isset($servers->{Inspector::Name})) {
			fwrite($this->output, "Skipped $name: it already has the server '" . Inspector::Name . "'.\n");
			return;
		}

		$servers->{Inspector::Name} = $entry;
		$data->$key = $servers;
		$json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
		if (file_put_contents($file, $json) === false) {
			throw new InspectorException("Cannot write '$file'.");
		}

		fwrite($this->output, $exists ? "Added the server to $name\n" : "Created $name\n");
	}


	private function createFile(string $file, string $content): bool
	{
		$name = basename($file);
		if (file_exists($file)) {
			fwrite($this->output, "Skipped $name: it already exists.\n");
			return false;
		}

		if (file_put_contents($file, $content) === false) {
			throw new InspectorException("Cannot write '$file'.");
		}

		fwrite($this->output, "Created $name\n");
		return true;
	}
}
