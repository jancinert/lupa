<?php declare(strict_types=1);

namespace Lupa\Database;

use Lupa\InspectorException;
use Lupa\Scalar;


/**
 * Connection of nette/database, used through its PDO.
 */
final class PdoConnection implements Connection
{
	private \PDO $pdo;
	private ?object $reflector;


	/**
	 * @param ?object $reflector  the driver of nette/database the PDO belongs to
	 */
	public function __construct(\PDO $pdo, ?object $reflector = null)
	{
		$this->pdo = $pdo;
		$this->reflector = $reflector;
		$this->pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
	}


	public function getPlatform(): string
	{
		return Scalar::toString($this->pdo->getAttribute(\PDO::ATTR_DRIVER_NAME));
	}


	public function execute(string $sql): void
	{
		$this->pdo->exec($sql);
	}


	public function getReflector(): ?object
	{
		return $this->reflector;
	}


	public function quote(string $value): string
	{
		$quoted = $this->pdo->quote($value);
		if (!is_string($quoted)) {
			throw new InspectorException('The database driver cannot quote a string.');
		}

		return $quoted;
	}


	public function fetch(string $sql, int $limit): Result
	{
		$statement = $this->pdo->query($sql);
		if ($statement === false) {
			return new Result([], [], false);
		}

		$columns = [];
		for ($i = 0; $i < $statement->columnCount(); $i++) {
			$meta = $statement->getColumnMeta($i);
			if (!isset($meta['name'])) {
				throw new InspectorException('The database driver did not report column names.');
			}

			$columns[] = ['name' => (string) $meta['name'], 'table' => ($meta['table'] ?? '') ?: null];
		}

		$rows = [];
		while (($row = $statement->fetch(\PDO::FETCH_NUM)) !== false) {
			if (count($rows) === $limit) {
				$statement->closeCursor();
				return new Result($columns, $rows, true);
			}

			$rows[] = $row;
		}

		return new Result($columns, $rows, false);
	}
}
