<?php declare(strict_types=1);

namespace Lupa\Database;

use Dibi\Event;
use Dibi\Exception;
use Lupa\Guard\DatabaseTarget;
use Lupa\InspectorException;
use Lupa\Tools\AppContext;
use Nette\Database\Explorer;


/**
 * Opens a database connection of the application that the environment guard has approved, for read-only use.
 */
final class Connections
{
	private ?string $default;
	private int $timeout;
	private int $sessionTimeout;


	/**
	 * @param int $timeout  seconds a query of the database tools may take
	 * @param int $sessionTimeout  seconds a statement of the application may take in a locked session, the time limit of a tool call
	 */
	public function __construct(?string $default = null, int $timeout = 5, int $sessionTimeout = 30)
	{
		$this->default = $default;
		$this->timeout = $timeout;
		$this->sessionTimeout = $sessionTimeout;
	}


	public function getTimeout(): int
	{
		return $this->timeout;
	}


	public function open(AppContext $context, ?string $name = null): ReadOnlyRunner
	{
		$target = $this->selectTarget($context->databases, $name ?? $this->default);
		return new ReadOnlyRunner(self::wrap($context->application->getDatabaseConnection($target->service)), $this->timeout);
	}


	/**
	 * Switches every guarded database connection of the application to read-only for the rest of the process,
	 * so that code of the application run by an active tool cannot write. Every later connect of a Dibi or
	 * Nette Database connection is switched too, so one that cannot be opened now is only listed as unreachable.
	 * A session that refuses read-only, or a plain PDO connection that cannot be opened, stops the tool.
	 * Statements of the session also get a time limit on the server, which would otherwise go on running
	 * a query after the worker is stopped at its own limit.
	 * @return array{readOnly: list<string>, unreachable: list<string>}
	 */
	public function lockSessions(AppContext $context): array
	{
		$result = ['readOnly' => [], 'unreachable' => []];
		foreach ($context->databases as $target) {
			if ($target->driver === 'redis') {
				continue;
			}

			$failure = fn(\Throwable $e) => new InspectorException(
				"Cannot make the connection '$target->service' read-only: " . $e->getMessage(),
				0,
				$e,
			);
			try {
				$service = $context->application->getDatabaseConnection($target->service);
			} catch (Exception|\PDOException $e) {
				throw $failure($e);
			}

			$lock = fn() => $this->lockSession($service, $target->service);
			try {
				self::wrap($service)->getPlatform(); // connects
			} catch (Exception|\PDOException $e) {
				if (!self::lockOnConnect($service, $lock)) {
					throw $failure($e);
				}

				$result['unreachable'][] = $target->service;
				continue;
			}

			try {
				$lock();
			} catch (Exception|\PDOException $e) {
				throw $failure($e);
			}

			self::lockOnConnect($service, $lock);
			$result['readOnly'][] = $target->service;
		}

		return $result;
	}


	private function lockSession(object $service, string $name): void
	{
		$connection = self::wrap($service);
		$platform = $connection->getPlatform();
		$statement = [
			Connection::MySql => 'SET SESSION TRANSACTION READ ONLY',
			Connection::Sqlite => 'PRAGMA query_only = ON',
			Connection::PostgreSql => 'SET SESSION CHARACTERISTICS AS TRANSACTION READ ONLY',
		][$platform] ?? null;
		if ($statement === null) {
			throw new InspectorException("Cannot make the '$platform' connection '$name' read-only.");
		}

		$connection->execute($statement);
		if ($platform === Connection::MySql) {
			ReadOnlyRunner::setMySqlTimeout($connection, $this->sessionTimeout);
		} elseif ($platform === Connection::PostgreSql) {
			$connection->execute("SET statement_timeout = '{$this->sessionTimeout}s'");
		}
	}


	/**
	 * Runs the lock after every connect of the service; a session that cannot be locked is closed again.
	 * Returns false for a service without such a hook.
	 * @param \Closure(): void $lock
	 */
	private static function lockOnConnect(object $service, \Closure $lock): bool
	{
		if ($service instanceof \Dibi\Connection) {
			$service->onEvent[] = static function (Event $event) use ($lock): void {
				if ($event->type === Event::CONNECT && $event->connection->isConnected()) {
					try {
						$lock();
					} catch (\Throwable $e) {
						$event->connection->disconnect();
						throw $e;
					}
				}
			};
			return true;
		}

		$connection = $service instanceof Explorer ? $service->getConnection() : $service;
		if ($connection instanceof \Nette\Database\Connection) {
			$connection->onConnect[] = static function (\Nette\Database\Connection $connection) use ($lock): void {
				try {
					$lock();
				} catch (\Throwable $e) {
					$connection->disconnect();
					throw $e;
				}
			};
			return true;
		}

		return false;
	}


	/**
	 * @param list<DatabaseTarget> $targets
	 */
	private function selectTarget(array $targets, ?string $name): DatabaseTarget
	{
		$targets = array_values(array_filter($targets, fn(DatabaseTarget $target) => $target->driver !== 'redis'));
		foreach ($targets as $target) {
			if ($name === null || $target->service === $name) {
				return $target;
			}
		}

		throw new InspectorException($name === null
			? 'The application has no database connection.'
			: "Connection '$name' not found; the application has: "
				. implode(', ', array_map(fn(DatabaseTarget $target) => $target->service, $targets))
				. '.');
	}


	private static function wrap(object $service): Connection
	{
		if ($service instanceof \Dibi\Connection) {
			return new DibiConnection($service);
		} elseif ($service instanceof Explorer) {
			$service = $service->getConnection();
		}

		if ($service instanceof \Nette\Database\Connection) {
			return new PdoConnection($service->getPdo(), $service->getSupplementalDriver());
		} elseif ($service instanceof \PDO) {
			return new PdoConnection($service);
		}

		throw new InspectorException('Unsupported database connection ' . get_class($service) . '.');
	}
}
