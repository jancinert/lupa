<?php declare(strict_types=1);

namespace Lupa\Database;

use Lupa\Scalar;


/**
 * The schema as the database library reads it, for platforms the inspector has no queries of its own for.
 * The reflector is Dibi\Reflector or the driver of nette/database; both have the four methods used here.
 */
final class LibrarySchema
{
	/**
	 * @return list<array{name: string, type: string, rowsEstimate: null}>
	 */
	public static function listTables(object $reflector): array
	{
		$tables = [];
		foreach (self::read($reflector, 'getTables') as $table) {
			$tables[] = [
				'name' => Scalar::toString($table['name'] ?? null),
				'type' => empty($table['view']) ? 'BASE TABLE' : 'VIEW',
				'rowsEstimate' => null,
			];
		}

		usort($tables, fn(array $a, array $b) => strcmp($a['name'], $b['name']));
		return $tables;
	}


	/**
	 * @return array{columns: list<array<string, mixed>>, indexes: list<array<string, mixed>>}
	 */
	public static function describeTable(object $reflector, string $table): array
	{
		$indexes = $primary = [];
		foreach (self::read($reflector, 'getIndexes', $table) as $index) {
			$columns = array_map([Scalar::class, 'toString'], array_values((array) ($index['columns'] ?? [])));
			$indexes[] = ['name' => $index['name'] ?? null, 'unique' => !empty($index['unique']), 'columns' => $columns];
			$primary += empty($index['primary']) ? [] : array_flip($columns);
		}

		$columns = [];
		foreach (self::read($reflector, 'getColumns', $table) as $column) {
			$name = Scalar::toString($column['name'] ?? null);
			$size = empty($column['size']) ? '' : '(' . Scalar::toString($column['size']) . ')';
			$columns[] = [
				'name' => $name,
				'type' => strtolower(Scalar::toString($column['nativetype'] ?? null)) . $size,
				'nullable' => !empty($column['nullable']),
				'default' => $column['default'] ?? null,
				'key' => !empty($column['primary']) || isset($primary[$name]) ? 'PRI' : '',
				'extra' => empty($column['autoincrement']) ? '' : 'auto_increment',
			];
		}

		return ['columns' => $columns, 'indexes' => $indexes];
	}


	/**
	 * @return list<array{name: ?string, table: string, column: string, referencedTable: string, referencedColumn: ?string}>
	 */
	public static function listForeignKeys(object $reflector, string $table): array
	{
		$keys = [];
		foreach (self::read($reflector, 'getForeignKeys', $table) as $key) {
			$referenced = array_values((array) ($key['foreign'] ?? []));
			foreach (array_values((array) ($key['local'] ?? [])) as $i => $column) {
				$keys[] = [
					'name' => isset($key['name']) ? Scalar::toString($key['name']) : null,
					'table' => $table,
					'column' => Scalar::toString($column),
					'referencedTable' => Scalar::toString($key['table'] ?? null),
					'referencedColumn' => isset($referenced[$i]) ? Scalar::toString($referenced[$i]) : null,
				];
			}
		}

		return $keys;
	}


	/**
	 * @return list<array<mixed>>  what the method of the reflector lists
	 */
	private static function read(object $reflector, string $method, string ...$arguments): array
	{
		$items = $reflector->$method(...$arguments);
		if (!is_iterable($items)) {
			return [];
		}

		$rows = [];
		foreach ($items as $item) {
			if (is_array($item)) {
				$rows[] = $item;
			}
		}

		return $rows;
	}
}
