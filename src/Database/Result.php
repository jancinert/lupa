<?php declare(strict_types=1);

namespace Lupa\Database;


final class Result
{
	/** @var list<array{name: string, table: ?string}> */
	public array $columns;

	/** @var list<list<mixed>> values in the order of the columns */
	public array $rows;

	/** More rows were available than the limit. */
	public bool $truncated;


	/**
	 * @param list<array{name: string, table: ?string}> $columns
	 * @param list<list<mixed>> $rows
	 */
	public function __construct(array $columns, array $rows, bool $truncated)
	{
		$this->columns = $columns;
		$this->rows = $rows;
		$this->truncated = $truncated;
	}


	/**
	 * Rows keyed by column name; a later column of the same name wins.
	 * @param list<string>|null $names  fixed labels for metadata queries when the driver can rename them
	 * @return list<array<string, mixed>>
	 */
	public function toAssoc(?array $names = null): array
	{
		$names ??= array_column($this->columns, 'name');
		return array_map(fn(array $row) => array_combine($names, $row) ?: [], $this->rows);
	}
}
