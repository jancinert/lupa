<?php declare(strict_types=1);

namespace Lupa\DI;

use Lupa\Tools\Toolkit;
use Nette\DI\CompilerExtension;


/**
 * Registers a toolkit without settings of its own. Its constructor dependencies are autowired.
 * A toolkit of a framework is left out when the application uses another one.
 */
final class ToolkitExtension extends CompilerExtension
{
	/** @var class-string<Toolkit> */
	private string $class;
	private ?string $framework;


	/**
	 * @param class-string<Toolkit> $class
	 */
	public function __construct(string $class, ?string $framework = null)
	{
		$this->class = $class;
		$this->framework = $framework;
	}


	public function loadConfiguration(): void
	{
		foreach ($this->compiler->getExtensions(InspectorExtension::class) as $inspector) {
			if ($this->framework !== null && $inspector->getFramework() !== $this->framework) {
				return;
			}
		}

		$this->getContainerBuilder()->addDefinition($this->prefix('toolkit'))
			->setFactory($this->class)
			->setAutowired(false);
	}
}
