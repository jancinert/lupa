<?php declare(strict_types=1);

namespace Lupa\Database;

use Dibi\Drivers\MySqliDriver;
use Dibi\Drivers\PdoDriver;
use Dibi\Drivers\PostgreDriver;
use Dibi\Drivers\SqliteDriver;
use Dibi\Type;
use Lupa\InspectorException;
use Lupa\Scalar;


final class DibiConnection implements Connection
{
	private \Dibi\Connection $connection;


	public function __construct(\Dibi\Connection $connection)
	{
		$this->connection = $connection;
	}


	public function getPlatform(): string
	{
		$driver = $this->connection->getDriver();
		if ($driver instanceof MySqliDriver) {
			return self::MySql;
		} elseif ($driver instanceof SqliteDriver || is_a($driver, 'Dibi\Drivers\Sqlite3Driver')) {
			return self::Sqlite;
		} elseif ($driver instanceof PostgreDriver) {
			return self::PostgreSql;
		} elseif ($driver instanceof PdoDriver) {
			$pdo = $driver->getResource();
			if ($pdo === null) {
				throw new InspectorException('The Dibi connection is closed.');
			}

			return Scalar::toString($pdo->getAttribute(\PDO::ATTR_DRIVER_NAME));
		}

		return strtolower((string) preg_replace('~^.*\\\\|Driver$~', '', get_class($driver)));
	}


	public function execute(string $sql): void
	{
		$this->connection->nativeQuery($sql);
	}


	public function getReflector(): object
	{
		return $this->connection->getDriver()->getReflector();
	}


	public function quote(string $value): string
	{
		return $this->connection->getDriver()->escapeText($value);
	}


	public function fetch(string $sql, int $limit): Result
	{
		$result = $this->connection->nativeQuery($sql);
		if (!$result instanceof \Dibi\Result) {
			return new Result([], [], false);
		}

		$columns = [];
		$postgreSql = $this->getPlatform() === self::PostgreSql; // other drivers may not report the native type
		foreach ($result->getInfo()->getColumns() as $column) {
			$columns[] = ['name' => $column->getName(), 'table' => $column->getTableName() ?: null];
			if ($postgreSql && strcasecmp($column->getNativeType(), 'timetz') === 0) {
				$result->setType($column->getName(), Type::TEXT); // as a date and time it would get today's date and lose the zone
			}
		}

		$names = array_column($columns, 'name');
		if (count($names) !== count(array_unique($names))) {
			throw new InspectorException('The query returns duplicate column names. Give each column a distinct alias.');
		}

		$result->setRowClass(null);
		$result->setFormat(Type::DATETIME, 'Y-m-d H:i:s');
		$result->setFormat(Type::DATE, 'Y-m-d');
		$result->setFormat(Type::TIME, 'H:i:s'); // otherwise a time of day gets today's date
		$rows = [];
		while ($row = $result->fetch()) { // null at the end, false in Dibi 3
			if (count($rows) === $limit) {
				return new Result($columns, $rows, true);
			}

			$rows[] = array_values((array) $row);
		}

		return new Result($columns, $rows, false);
	}
}
