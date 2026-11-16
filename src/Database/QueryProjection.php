<?php declare(strict_types=1);

namespace Lupa\Database;

use Lupa\InspectorException;
use Lupa\Masking;


/**
 * Output slots retain the column origins established during validation.
 */
final class QueryProjection
{
	private Masking $masking;

	/** @var list<array{name: ?string, sources: list<string>, label: ?string}> */
	private array $columns;


	/**
	 * @param list<array{name: ?string, sources: list<string>, label: ?string}> $columns
	 */
	public function __construct(Masking $masking, array $columns)
	{
		$this->masking = $masking;
		$this->columns = $columns;
	}


	/**
	 * @param array<string, array{name: string, parts: list<string>, sql: string, tables?: list<string>}> $sources
	 * @param list<string> $labels
	 * @return list<bool>
	 */
	public function getMasks(array $sources, array $labels): array
	{
		if (count($this->columns) !== count($labels)) {
			throw new InspectorException('The result columns do not match the validated SELECT.');
		}

		$masks = [];
		foreach ($this->columns as $i => $column) {
			$tables = [];
			foreach ($column['sources'] as $alias) {
				foreach ($sources[$alias]['tables'] ?? [$sources[$alias]['name']] as $table) {
					$tables[$table] = true;
				}
			}

			$names = array_values(array_filter([$column['name'], $column['label']], fn(?string $name) => $name !== null));
			if (!$names) {
				$names = [$labels[$i]];
			}

			$mask = $this->masking->isSecretKey($labels[$i]);
			foreach ($tables ? array_map('strval', array_keys($tables)) : [null] as $table) {
				foreach ($names as $name) {
					$mask = $mask || $this->masking->isMaskedColumn($table, $name);
				}
			}

			$masks[] = $mask;
		}

		return $masks;
	}
}
