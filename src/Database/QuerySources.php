<?php declare(strict_types=1);

namespace Lupa\Database;

use Lupa\InspectorException;
use Lupa\Scalar;


final class QuerySources
{
	/**
	 * @param array<string, array{name: string, parts: list<string>, sql: string}> $sources  alias => table
	 * @return array<string, array<string, bool>>  alias => column => included in a wildcard
	 */
	public static function read(Connection $connection, array $sources): array
	{
		$columns = [];
		foreach ($sources as $alias => $source) {
			switch ($connection->getPlatform()) {
				case Connection::MySql:
					$names = self::readMySql($connection, $source);
					break;
				case Connection::PostgreSql:
					$names = self::readPostgreSql($connection, $source['sql']);
					break;
				case Connection::Sqlite:
					$names = self::readSqlite($connection, $source);
					break;
				default:
					throw new InspectorException('The database platform is not supported.');
			}

			if (!$names) {
				self::reject();
			}

			$columns[$alias] = $names;
		}

		return $columns;
	}


	/**
	 * Resolves PostgreSQL column origins after the SELECT, while its relation locks are still held.
	 * @param array<string, array{name: string, parts: list<string>, sql: string}> $sources
	 * @return array<string, array{name: string, parts: list<string>, sql: string, tables?: list<string>}>
	 */
	public static function resolveOrigins(Connection $connection, array $sources): array
	{
		if ($connection->getPlatform() !== Connection::PostgreSql) {
			return $sources;
		}

		foreach ($sources as $alias => $source) {
			$sources[$alias]['tables'] = self::readPostgreSqlOrigins($connection, $source['sql']);
		}

		return $sources;
	}


	/**
	 * @param array{name: string, parts: list<string>, sql: string} $source
	 * @return array<string, bool>
	 */
	private static function readMySql(Connection $connection, array $source): array
	{
		$parts = $source['parts'];
		$schema = count($parts) === 2
			? QueryValidator::unquoteIdentifier($parts[0])
			: $connection->fetch('SELECT DATABASE()', 1)->rows[0][0] ?? '';
		if (
			count($parts) > 2
			|| in_array(strtolower(Scalar::toString($schema)), ['mysql', 'sys', 'information_schema', 'performance_schema'], true)
		) {
			self::reject();
		}

		$table = $source['sql'];
		$definition = $connection->fetch("SHOW CREATE TABLE $table", 1)->rows[0][1] ?? '';
		if (!preg_match('~^CREATE (?:TEMPORARY )?TABLE\b~i', Scalar::toString($definition))) {
			self::reject();
		}

		$result = $connection->fetch("SHOW FULL COLUMNS FROM $table", 4096);
		if ($result->truncated) {
			self::reject();
		}

		$names = [];
		foreach ($result->rows as [$name, , , , , , $extra]) {
			// MariaDB before 10.2 reports a generated column as VIRTUAL or PERSISTENT only; DEFAULT_GENERATED is a plain column
			if (preg_match('~\b(?:VIRTUAL|STORED|PERSISTENT)\b~i', Scalar::toString($extra))) {
				self::reject();
			}

			$names[strtolower(Scalar::toString($name))] = stripos(Scalar::toString($extra), 'INVISIBLE') === false;
		}

		return $names;
	}


	/**
	 * @return list<string>
	 */
	private static function readPostgreSqlOrigins(Connection $connection, string $table): array
	{
		$hex = bin2hex($table);
		$identifier = "pg_catalog.convert_from(pg_catalog.decode('$hex', 'hex'), 'UTF8')";
		$result = $connection->fetch(
			'WITH RECURSIVE descendants(oid) AS ('
			. " SELECT pg_catalog.to_regclass($identifier)::oid"
			. ' UNION SELECT i.inhrelid FROM pg_catalog.pg_inherits i JOIN descendants d ON i.inhparent = d.oid),'
			. ' relations(oid) AS (SELECT oid FROM descendants'
			. ' UNION SELECT i.inhparent FROM pg_catalog.pg_inherits i JOIN relations r ON i.inhrelid = r.oid)'
			. ' SELECT c.relname, c.relkind, n.nspname, CASE WHEN EXISTS ('
			. ' SELECT 1 FROM pg_catalog.pg_attribute a WHERE a.attrelid = c.oid AND NOT a.attisdropped'
			. " AND COALESCE(pg_catalog.row_to_json(a)->>'attgenerated', '') <> '') THEN 1 ELSE 0 END"
			. ' FROM relations r JOIN pg_catalog.pg_class c ON c.oid = r.oid'
			. ' JOIN pg_catalog.pg_namespace n ON n.oid = c.relnamespace',
			4096,
		);
		if ($result->truncated || !$result->rows) {
			self::reject();
		}

		$names = [];
		foreach ($result->rows as [$name, $kind, $schema, $generated]) {
			if (
				!in_array($kind, ['r', 'p'], true)
				|| in_array($schema, ['pg_catalog', 'information_schema'], true)
				|| strpos(Scalar::toString($schema), 'pg_toast') === 0
				|| Scalar::toInt($generated) !== 0
			) {
				self::reject();
			}

			$names[] = Scalar::toString($name);
		}

		return $names;
	}


	/**
	 * @return array<string, bool>
	 */
	private static function readPostgreSql(Connection $connection, string $table): array
	{
		// keeps the relation stable between its catalog lookup and the SELECT
		$connection->execute("LOCK TABLE $table IN ACCESS SHARE MODE");
		$hex = bin2hex($table);
		$identifier = "pg_catalog.convert_from(pg_catalog.decode('$hex', 'hex'), 'UTF8')";
		$result = $connection->fetch(
			'SELECT a.attname AS name, pg_catalog.row_to_json(a)->>\'attgenerated\' AS generated'
			. ' FROM pg_catalog.pg_attribute a JOIN pg_catalog.pg_class c ON c.oid = a.attrelid'
			. ' JOIN pg_catalog.pg_namespace n ON n.oid = c.relnamespace'
			. " WHERE c.oid = pg_catalog.to_regclass($identifier) AND c.relkind IN ('r','p')"
			. " AND n.nspname NOT IN ('pg_catalog','information_schema') AND n.nspname NOT LIKE 'pg_toast%'"
			. ' AND a.attnum > 0 AND NOT a.attisdropped ORDER BY a.attnum',
			4096,
		);
		if ($result->truncated) {
			self::reject();
		}

		$names = [];
		foreach ($result->rows as [$name, $generated]) {
			if (!empty($generated)) {
				self::reject();
			}

			$names[Scalar::toString($name)] = true;
		}

		return $names;
	}


	/**
	 * @param array{name: string, parts: list<string>, sql: string} $source
	 * @return array<string, bool>
	 */
	private static function readSqlite(Connection $connection, array $source): array
	{
		$parts = $source['parts'];
		$name = $source['name'];
		if (count($parts) > 2 || strpos($name, 'sqlite_') === 0) {
			self::reject();
		}

		$schemas = count($parts) === 2
			? [QueryValidator::unquoteIdentifier($parts[0])]
			: array_unique(array_merge(
				['temp', 'main'],
				array_map([Scalar::class, 'toString'], array_column($connection->fetch('PRAGMA database_list', 128)->rows, 1)),
			));
		$literal = "'" . str_replace("'", "''", $name) . "'";
		$table = '"' . str_replace('"', '""', $name) . '"';
		foreach ($schemas as $schema) {
			$quoted = '"' . str_replace('"', '""', Scalar::toString($schema)) . '"';
			$entry = $connection->fetch(
				"SELECT type, sql FROM $quoted.sqlite_master WHERE name = $literal COLLATE NOCASE",
				1,
			)->rows[0] ?? null;
			if ($entry === null) {
				continue;
			} elseif ($entry[0] !== 'table' || !preg_match('~^CREATE TABLE\b~i', Scalar::toString($entry[1] ?? ''))) {
				self::reject();
			}

			$result = $connection->fetch("PRAGMA $quoted.table_xinfo($table)", 4096);
			if (!$result->rows) {
				$result = $connection->fetch("PRAGMA $quoted.table_info($table)", 4096); // SQLite before table_xinfo
			}

			if ($result->truncated) {
				self::reject();
			}

			$names = [];
			foreach ($result->rows as $row) {
				if (!empty($row[6])) {
					self::reject();
				}

				$names[strtolower(Scalar::toString($row[1]))] = true;
			}

			return $names;
		}

		return [];
	}


	private static function reject(): void
	{
		throw new InspectorException('Query sources must be verifiable base tables without generated columns.');
	}
}
