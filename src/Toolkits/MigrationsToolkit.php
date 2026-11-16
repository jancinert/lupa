<?php declare(strict_types=1);

namespace Lupa\Toolkits;

use Dibi\Exception;
use Lupa\Database\Connection;
use Lupa\Database\Connections;
use Lupa\InspectorException;
use Lupa\Scalar;
use Lupa\Tools\AppContext;
use Lupa\Tools\Arguments;
use Lupa\Tools\Schema;
use Lupa\Tools\Tool;
use Lupa\Tools\Toolkit;
use Lupa\Tools\ToolResult;


/**
 * Ruckusing migrations: files named <version>_<Class>.php and the table of executed versions.
 */
final class MigrationsToolkit implements Toolkit
{
	private const Results = [
		'migrations_status' => [
			'directory' => 'string',
			'table' => 'string',
			'files' => 'integer',
			'executed' => 'integer',
			'newestExecuted' => '?string',
			'pending' => [['version' => 'string', 'class' => '?string', 'file' => 'string', 'declaredClass' => '?string']],
			'executedWithoutFile' => 'string[]',
		],
		'migrations_conflicts' => [
			'sameVersion' => 'array', // lists of files sharing a version
			'sameClass' => 'array',
			'classMismatch' => [['version' => 'string', 'class' => '?string', 'file' => 'string', 'declaredClass' => '?string']],
			'pendingOlderThanNewest' => [['version' => 'string', 'class' => '?string', 'file' => 'string', 'declaredClass' => '?string']],
			'newestExecuted' => '?string',
		],
	];

	private Connections $connections;
	private string $directory;
	private string $table;


	public function __construct(Connections $connections, string $directory = 'db/migrate', string $table = 'schema_migrations')
	{
		$this->connections = $connections;
		$this->directory = $directory;
		$this->table = $table;
	}


	public function getTools(): array
	{
		$connection = ['connection' => Schema::string('Name of the connection service when the application has more of them.')];
		return Tool::withResults([
			new Tool(
				'migrations_status',
				'Migrations status',
				'Compares the migration files with the versions recorded as executed: pending migrations,'
				. ' executed versions without a file, and the newest executed one.',
				Schema::object($connection),
			),
			new Tool(
				'migrations_conflicts',
				'Migration conflicts',
				'Finds files sharing a version (Ruckusing then treats the second one as executed and skips it), files'
				. ' sharing a class name (a fatal error when both run), files declaring another class than their name says'
				. ' (Ruckusing instantiates the class named by the file), and pending migrations older than the newest'
				. ' executed one, which typically come from a merged branch.',
				Schema::object($connection),
			),
		], self::Results);
	}


	public function call(string $tool, array $arguments, AppContext $context): ToolResult
	{
		$arguments = new Arguments($arguments);
		$files = $this->readFiles($context);
		$executed = $this->readExecuted($context, $arguments->optionalString('connection'));
		switch ($tool) {
			case 'migrations_status':
				return $this->showStatus($files, $executed);
			case 'migrations_conflicts':
				return $this->findConflicts($files, $executed);
			default:
				throw new InspectorException("Unknown tool '$tool'.");
		}
	}


	/**
	 * @param list<array{version: string, class: string, file: string, declaredClass: ?string}> $files
	 * @param list<string> $executed
	 */
	private function showStatus(array $files, array $executed): ToolResult
	{
		$executedSet = array_flip($executed);
		$pending = array_values(array_filter($files, fn(array $file) => !isset($executedSet[$file['version']])));
		$fileVersions = array_flip(array_column($files, 'version'));
		$missing = array_values(array_filter($executed, fn(string $version) => !isset($fileVersions[$version])));
		$data = [
			'directory' => $this->directory,
			'table' => $this->table,
			'files' => count($files),
			'executed' => count($executed),
			'newestExecuted' => $executed ? max($executed) : null,
			'pending' => $pending,
			'executedWithoutFile' => $missing,
		];
		return new ToolResult($data, sprintf(
			'%d file(s), %d executed, %d pending, %d executed version(s) without a file.',
			count($files),
			count($executed),
			count($pending),
			count($missing),
		));
	}


	/**
	 * @param list<array{version: string, class: string, file: string, declaredClass: ?string}> $files
	 * @param list<string> $executed
	 */
	private function findConflicts(array $files, array $executed): ToolResult
	{
		$byVersion = $byClass = [];
		foreach ($files as $file) {
			$byVersion[$file['version']][] = $file['file'];
			$byClass[strtolower($file['class'])][] = $file['file'];
		}

		$newest = $executed ? max($executed) : null;
		$executedSet = array_flip($executed);
		$data = [
			'sameVersion' => array_values(array_filter($byVersion, fn(array $list) => count($list) > 1)),
			'sameClass' => array_values(array_filter($byClass, fn(array $list) => count($list) > 1)),
			'classMismatch' => array_values(array_filter(
				$files,
				fn(array $file) => $file['declaredClass'] !== null && strcasecmp($file['declaredClass'], $file['class']) !== 0,
			)),
			'pendingOlderThanNewest' => $newest === null ? [] : array_values(array_filter(
				$files,
				fn(array $file) => !isset($executedSet[$file['version']]) && strcmp($file['version'], $newest) < 0,
			)),
			'newestExecuted' => $newest,
		];
		return new ToolResult($data, sprintf(
			'%d shared version(s), %d shared class name(s), %d class name mismatch(es), %d pending migration(s) older than the newest executed.',
			count($data['sameVersion']),
			count($data['sameClass']),
			count($data['classMismatch']),
			count($data['pendingOlderThanNewest']),
		));
	}


	/**
	 * @return list<array{version: string, class: string, file: string, declaredClass: ?string}>
	 */
	private function readFiles(AppContext $context): array
	{
		$dir = $context->project->resolveInside($this->directory);
		$result = [];
		foreach (scandir($dir) ?: [] as $name) {
			if (preg_match('~^(\d+)_(.*)\.php$~', $name, $m)) { // the pattern Ruckusing uses
				$code = (string) @file_get_contents($dir . '/' . $name); // @ an unreadable file has no declared class
				$result[] = [
					'version' => $m[1],
					'class' => $m[2],
					'file' => $name,
					'declaredClass' => preg_match('~^\s*(?:final\s+|abstract\s+)?class\s+(\w+)~mi', $code, $c) ? $c[1] : null,
				];
			}
		}

		usort($result, fn(array $a, array $b) => strcmp($a['version'], $b['version']) ?: strcmp($a['file'], $b['file']));
		return $result;
	}


	/**
	 * @return list<string>
	 */
	private function readExecuted(AppContext $context, ?string $connection): array
	{
		if (!preg_match('~^\w+$~', $this->table)) {
			throw new InspectorException("Configuration 'migrations.table' must be a plain table name.");
		}

		try {
			$rows = $this->connections->open($context, $connection)
				->run(fn(Connection $db) => $db->fetch("SELECT version FROM {$this->table}", 1_000_000)->rows);
		} catch (Exception|\PDOException $e) {
			throw new InspectorException("Reading table '{$this->table}' failed: " . $e->getMessage() . " Set 'migrations.table' in lupa.neon.");
		}

		$versions = array_map(fn(array $row) => Scalar::toString($row[0]), $rows);
		sort($versions, SORT_STRING);
		return $versions;
	}
}
