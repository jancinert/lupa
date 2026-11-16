<?php declare(strict_types=1);

namespace Lupa\DI;

use Lupa\AuditLog;
use Lupa\Config;
use Lupa\Inspector;
use Lupa\Scalar;
use Lupa\Tools\ToolCatalog;
use Lupa\Tools\Toolkit;
use Nette\Schema\Expect;
use Nette\Schema\Schema;


/**
 * Section 'inspector': the bootstrap of the application, the kill switch, the time limit, the audit log,
 * the disabled tools and the enabled active ones. Collects every service implementing Toolkit into the tool catalog.
 */
final class InspectorExtension extends Extension
{
	private string $configDir;


	public function __construct(string $configDir)
	{
		$this->configDir = $configDir;
	}


	public function getConfigSchema(): Schema
	{
		return Expect::structure([
			// none: the bootstrap returns an array; without a value it follows what the application has installed
			'framework' => Expect::anyOf('nette', 'none')->nullable(),
			'bootstrap' => Expect::string('lupa-bootstrap.php'),
			'enabled' => Expect::bool(true),
			'timeout' => Expect::int(30)->min(1),
			'audit' => Expect::string('log/lupa/audit.jsonl'),
			'disabledTools' => Expect::listOf('string'), // names or patterns with *, e.g. db_*
			'instructions' => Expect::string(''), // added to the instructions the model reads on connect
			'enableActive' => Expect::listOf('string'), // tools with side effects, off unless named here
			'applicationConfig' => Expect::listOf('string'), // NEON files added to the application's Configurator
		]);
	}


	public function getFramework(): string
	{
		return Scalar::toString($this->getSettings()->framework ?? Inspector::detectFramework());
	}


	public function loadConfiguration(): void
	{
		$config = $this->getSettings();
		$builder = $this->getContainerBuilder();
		$builder->addDefinition($this->prefix('config'))
			->setFactory(Config::class, [
				$this->resolvePath(Scalar::toString($config->bootstrap)),
				$config->enabled,
				$config->timeout,
				$this->resolvePath(Scalar::toString($config->audit)),
				$config->disabledTools,
				array_map(fn($path) => $this->resolvePath(Scalar::toString($path)), (array) $config->applicationConfig),
				$config->instructions,
				$this->getFramework(),
			]);
		$builder->addDefinition($this->prefix('audit'))
			->setFactory(AuditLog::class, [$this->resolvePath(Scalar::toString($config->audit))]);
	}


	public function beforeCompile(): void
	{
		$builder = $this->getContainerBuilder();
		$toolkits = [];
		foreach (array_keys($builder->findByType(Toolkit::class)) as $name) {
			$toolkits[] = '@' . $name;
		}

		$builder->addDefinition($this->prefix('catalog'))
			->setFactory(ToolCatalog::class, [$toolkits, $this->getSettings()->disabledTools, $this->getSettings()->enableActive]);
	}


	private function resolvePath(string $path): string
	{
		return preg_match('#^([a-z]:)?[/\\\\]#i', $path)
			? $path
			: $this->configDir . DIRECTORY_SEPARATOR . $path;
	}
}
