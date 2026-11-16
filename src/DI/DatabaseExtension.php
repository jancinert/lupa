<?php declare(strict_types=1);

namespace Lupa\DI;

use Lupa\Config;
use Lupa\Database\Connections;
use Lupa\Toolkits\DatabaseToolkit;
use Nette\DI\Definitions\Statement;
use Nette\Schema\Expect;
use Nette\Schema\Schema;


/**
 * Section 'database': which connection the database tools use and their limits.
 */
final class DatabaseExtension extends Extension
{
	public function getConfigSchema(): Schema
	{
		return Expect::structure([
			'connection' => Expect::string()->nullable(),
			'timeout' => Expect::int(5)->min(1),
			'rowLimit' => Expect::int(100)->min(1)->max(10000),
			'columnLimit' => Expect::int(50)->min(1),
			'cellLimit' => Expect::int(2000)->min(10),
		]);
	}


	public function loadConfiguration(): void
	{
		$config = $this->getSettings();
		$builder = $this->getContainerBuilder();
		$statement = class_exists(Statement::class) ? Statement::class : 'Nette\DI\Statement'; // nette/di 2.4
		$toolTimeout = new $statement(['@' . Config::class, '$timeout']); // set in the section 'inspector'
		$builder->addDefinition($this->prefix('connections'))
			->setFactory(Connections::class, [$config->connection, $config->timeout, $toolTimeout]);
		$builder->addDefinition($this->prefix('toolkit'))
			->setFactory(DatabaseToolkit::class, [
				'rowLimit' => $config->rowLimit,
				'columnLimit' => $config->columnLimit,
				'cellLimit' => $config->cellLimit,
			])
			->setAutowired(false);
	}
}
