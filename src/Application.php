<?php declare(strict_types=1);

namespace Lupa;

use Lupa\Guard\DatabaseTarget;


/**
 * The booted application as the framework adapter exposes it to the framework-independent core.
 */
interface Application
{
	/**
	 * Framework name and the facts the adapter knows about the application (directories, modes).
	 * @return array<string, mixed>
	 */
	function getInfo(): array;

	/**
	 * A problem the application booted in spite of, which every result has to carry; null when there is none.
	 */
	function getWarning(): ?string;

	/**
	 * Where the database connections point, read without connecting.
	 * @return list<DatabaseTarget>
	 */
	function getDatabaseTargets(): array;

	/**
	 * Finishes booting once the guard has approved the targets. It may create services, which may connect.
	 */
	function initialize(): void;

	/**
	 * The connection object (Dibi\Connection, Nette\Database\Connection or Explorer) of a checked target,
	 * identified by DatabaseTarget::$service.
	 */
	function getDatabaseConnection(string $service): object;
}
