<?php declare(strict_types=1);

namespace Lupa\Http;

use Lupa\Toolkits\ApplicationToolkit;
use Lupa\Toolkits\DatabaseToolkit;
use Lupa\Tools\ToolCatalog;


/** Only fixed metadata operations of the built-in toolkits can run without a killable worker. */
final class ToolPolicy
{
	private const Toolkits = [
		'app_info' => ApplicationToolkit::class,
		'db_tables' => DatabaseToolkit::class,
		'db_table' => DatabaseToolkit::class,
		'db_relations' => DatabaseToolkit::class,
		'db_env' => DatabaseToolkit::class,
	];


	public static function allows(ToolCatalog $catalog, string $tool): bool
	{
		$definition = $catalog->getTool($tool);
		$toolkit = $catalog->getToolkit($tool);
		return isset(self::Toolkits[$tool]) && $definition !== null && $definition->readOnly
			&& $toolkit !== null && get_class($toolkit) === self::Toolkits[$tool];
	}


	public static function filter(ToolCatalog $catalog): ToolCatalog
	{
		return $catalog->select(array_values(array_filter(
			array_keys(self::Toolkits),
			fn(string $name): bool => self::allows($catalog, $name),
		)));
	}
}
