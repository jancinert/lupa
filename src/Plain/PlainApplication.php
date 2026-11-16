<?php declare(strict_types=1);

namespace Lupa\Plain;

use Dibi\Connection;
use Lupa\Application;
use Lupa\Guard\DatabaseTarget;
use Lupa\InspectorException;
use Lupa\Project;
use Pdo\Sqlite;


/**
 * An application without a framework, described by the array its bootstrap file returns:
 * the directories under 'parameters' (appDir, logDir, tempDir) and the database connections under 'databases'.
 * A connection is given by what creates it, never as an object, so that the guard reads its target first:
 * a PDO by 'dsn', 'user', 'password' and 'options', a Dibi connection by the configuration with a 'driver'.
 */
final class PlainApplication implements Application
{
	/** The only parameters read, so that app_info cannot show other values of the bootstrap, such as a key under any name. */
	private const Directories = ['appDir', 'logDir', 'tempDir'];

	/** @var array<string, string> */
	private array $parameters = [];

	/** @var array<string, array<string, mixed>> */
	private array $databases = [];

	/** @var array<string, object> */
	private array $connections = [];


	/**
	 * @param array<mixed> $description
	 */
	public function __construct(array $description)
	{
		$parameters = (array) ($description['parameters'] ?? []);
		foreach (self::Directories as $name) {
			if (is_string($parameters[$name] ?? null)) {
				$this->parameters[$name] = $parameters[$name];
			}
		}

		foreach ((array) ($description['databases'] ?? []) as $name => $database) {
			if (!is_array($database) || (!isset($database['driver']) && !is_string($database['dsn'] ?? null))) {
				throw new InspectorException("Database '$name' of the bootstrap file needs a 'dsn' for PDO or a 'driver' for Dibi.");
			}

			foreach ($database as $key => $value) {
				$this->databases[(string) $name][(string) $key] = $value;
			}
		}
	}


	public static function boot(string $bootstrapFile): self
	{
		if (!is_file($bootstrapFile)) {
			throw new InspectorException("Bootstrap file '$bootstrapFile' not found.");
		}

		$description = (static fn() => require $bootstrapFile)();
		if (!is_array($description)) {
			throw new InspectorException("Bootstrap file '$bootstrapFile' must return an array with 'parameters' and 'databases'.");
		}

		return new self($description);
	}


	public function getInfo(): array
	{
		return ['framework' => 'none', 'parameters' => $this->parameters];
	}


	public function getWarning(): ?string
	{
		return null;
	}


	public function getDatabaseTargets(): array
	{
		$targets = [];
		foreach ($this->databases as $name => $database) {
			if (isset($database['driver'])) {
				$target = new DatabaseTarget($name, 'Dibi\Connection', DatabaseTarget::SourceObject);
				$target->applyDibiConfig($database);
			} else {
				$target = new DatabaseTarget($name, \PDO::class, DatabaseTarget::SourceObject);
				$target->applyDsn(is_string($database['dsn'] ?? null) ? $database['dsn'] : '');
			}

			$targets[] = $target;
		}

		return $targets;
	}


	public function initialize(): void
	{
	}


	/** Pins HTTP metadata reads to existing SQLite files inside the project and prevents opening them for writing. */
	public function restrictConnectionsToSqlite(Project $project): void
	{
		if ($this->connections) {
			throw new InspectorException('HTTP database restrictions must be applied before connecting.');
		}

		foreach ($this->databases as &$database) {
			$dsn = $database['dsn'] ?? null;
			if (isset($database['driver']) || !is_string($dsn) || strncmp($dsn, 'sqlite:', 7) !== 0) {
				throw new InspectorException('HTTP mode only supports existing local SQLite connections through PDO.');
			}

			$file = $project->resolveInside(substr($dsn, 7));
			if (!is_file($file)) {
				throw new InspectorException('HTTP mode requires an existing SQLite database file.');
			}

			$flags = PHP_VERSION_ID >= 80400 ? Sqlite::ATTR_OPEN_FLAGS : \PDO::SQLITE_ATTR_OPEN_FLAGS;
			$readOnly = PHP_VERSION_ID >= 80400 ? Sqlite::OPEN_READONLY : \PDO::SQLITE_OPEN_READONLY;
			$database['dsn'] = 'sqlite:' . $file;
			// Persistent PDO handles are keyed by DSN, not SQLite open flags, and may already allow writes.
			$database['options'] = [$flags => $readOnly, \PDO::ATTR_TIMEOUT => 1, \PDO::ATTR_PERSISTENT => false]
				+ (array) ($database['options'] ?? []);
		}
	}


	public function getDatabaseConnection(string $service): object
	{
		$database = $this->databases[$service] ?? null;
		if ($database === null) {
			throw new InspectorException("Database '$service' is not in the bootstrap file.");
		} elseif (isset($this->connections[$service])) {
			return $this->connections[$service];
		} elseif (isset($database['driver'])) {
			if (!class_exists('Dibi\Connection')) {
				throw new InspectorException("Database '$service' needs dibi/dibi.");
			}

			return $this->connections[$service] = new Connection($database + ['lazy' => true]);
		}

		return $this->connections[$service] = new \PDO(
			is_string($database['dsn'] ?? null) ? $database['dsn'] : '',
			is_string($database['user'] ?? null) ? $database['user'] : null,
			is_string($database['password'] ?? null) ? $database['password'] : null,
			(array) ($database['options'] ?? []) + [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION],
		);
	}
}
