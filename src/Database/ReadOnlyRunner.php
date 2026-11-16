<?php declare(strict_types=1);

namespace Lupa\Database;

use Lupa\InspectorException;
use Lupa\Scalar;


/**
 * Runs queries inside a read-only transaction that is always rolled back, with a time limit set on the server.
 */
final class ReadOnlyRunner
{
	private Connection $connection;
	private int $timeout;


	public function __construct(Connection $connection, int $timeout)
	{
		$this->connection = $connection;
		$this->timeout = $timeout;
	}


	public function getConnection(): Connection
	{
		return $this->connection;
	}


	/**
	 * @template T
	 * @param callable(Connection): T $callback
	 * @return T
	 */
	public function run(callable $callback)
	{
		$platform = $this->connection->getPlatform();
		switch ($platform) {
			case Connection::MySql:
				self::setMySqlTimeout($this->connection, $this->timeout);
				$this->connection->execute('START TRANSACTION READ ONLY');
				break;
			case Connection::Sqlite:
				$this->connection->execute('BEGIN');
				$this->connection->execute('PRAGMA query_only = ON');
				// SQLite has no statement timeout; the query runs inside the worker, which is stopped at its own time limit
				break;
			case Connection::PostgreSql:
				$this->connection->execute('BEGIN READ ONLY');
				$this->connection->execute("SET LOCAL statement_timeout = '{$this->timeout}s'");
				break;
			default:
				throw new InspectorException("Database platform '$platform' is not supported.");
		}

		try {
			$result = $callback($this->connection);
		} catch (\Throwable $e) {
			try {
				$this->close($platform);
			} catch (\Throwable $ignored) {
				// the connection may be gone, e.g. after a timeout; the original failure is the one to report
			}

			throw $e;
		}

		$this->close($platform);
		return $result;
	}


	private function close(string $platform): void
	{
		$this->connection->execute('ROLLBACK');
		if ($platform === Connection::Sqlite) {
			$this->connection->execute('PRAGMA query_only = OFF');
		}
	}


	/**
	 * MySQL limits SELECT statements in milliseconds, MariaDB all statements in seconds.
	 */
	public static function setMySqlTimeout(Connection $connection, int $timeout): void
	{
		$variables = [];
		$sql = "SHOW VARIABLES WHERE Variable_name IN ('version', 'max_statement_time', 'max_execution_time')";
		foreach ($connection->fetch($sql, 3)->rows as $row) {
			$variables[strtolower(Scalar::toString($row[0]))] = Scalar::toString($row[1] ?? null);
		}

		$version = $variables['version'] ?? '';
		$mariaDb = stripos($version, 'mariadb') !== false;
		if (!isset($variables[$mariaDb ? 'max_statement_time' : 'max_execution_time'])) {
			throw new InspectorException(
				"The database server $version cannot limit the time of a statement, which the database tools need."
				. ' MySQL can since 5.7.8, MariaDB since 10.1.',
			);
		}

		$connection->execute($mariaDb
			? 'SET SESSION max_statement_time = ' . $timeout
			: 'SET SESSION max_execution_time = ' . ($timeout * 1000));
	}
}
