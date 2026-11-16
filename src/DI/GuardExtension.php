<?php declare(strict_types=1);

namespace Lupa\DI;

use Lupa\Guard\EnvironmentGuard;
use Nette\Schema\Expect;
use Nette\Schema\Schema;


/**
 * Section 'guard': where the application may connect before any tool runs.
 */
final class GuardExtension extends Extension
{
	private const DefaultHosts = ['localhost', '127.0.0.1', '::1'];


	public function getConfigSchema(): Schema
	{
		return Expect::structure([
			'allowedHosts' => Expect::listOf('string')->nullable()->default(null), // null means the defaults; a list replaces them
			'deniedDatabases' => Expect::listOf('string'),
		]);
	}


	public function loadConfiguration(): void
	{
		$this->getContainerBuilder()->addDefinition($this->prefix('guard'))
			->setFactory(
				EnvironmentGuard::class,
				[$this->getSettings()->allowedHosts ?? self::DefaultHosts, $this->getSettings()->deniedDatabases],
			);
	}
}
