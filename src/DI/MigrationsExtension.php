<?php declare(strict_types=1);

namespace Lupa\DI;

use Lupa\Toolkits\MigrationsToolkit;
use Nette\Schema\Expect;
use Nette\Schema\Schema;


/**
 * Section 'migrations': where the Ruckusing migrations and the table of executed versions are.
 */
final class MigrationsExtension extends Extension
{
	public function getConfigSchema(): Schema
	{
		return Expect::structure([
			'directory' => Expect::string('db/migrate'), // relative to the project root
			'table' => Expect::string('schema_migrations'),
		]);
	}


	public function loadConfiguration(): void
	{
		$this->getContainerBuilder()->addDefinition($this->prefix('toolkit'))
			->setFactory(MigrationsToolkit::class, ['directory' => $this->getSettings()->directory, 'table' => $this->getSettings()->table])
			->setAutowired(false);
	}
}
