<?php declare(strict_types=1);

namespace Lupa\Database;


/**
 * A database connection of the application, reduced to running native SQL.
 */
interface Connection
{
	public const
		MySql = 'mysql',
		Sqlite = 'sqlite',
		PostgreSql = 'pgsql';

	/**
	 * mysql, sqlite, pgsql or the driver name of another platform.
	 */
	function getPlatform(): string;

	function execute(string $sql): void;

	/**
	 * Runs a query and returns at most $limit rows; the result says whether more rows were available.
	 */
	function fetch(string $sql, int $limit): Result;

	/**
	 * The reflection of the database library the connection comes from (Dibi\Reflector or the driver
	 * of nette/database), which knows platforms the inspector has no queries for. Null for a plain PDO.
	 */
	function getReflector(): ?object;

	/**
	 * Returns the string as an SQL literal of this connection, with its quotes.
	 */
	function quote(string $value): string;
}
