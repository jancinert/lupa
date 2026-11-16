<?php declare(strict_types=1);

namespace Lupa\Tests\Fixtures;

use Nette\DI\Compiler;
use Nette\DI\ContainerLoader;


/**
 * The parts of Nette\Bootstrap\Configurator the inspector relies on, without requiring nette/bootstrap.
 */
final class FakeConfigurator
{
	/** @var array<string, mixed> */
	protected array $dynamicParameters = [];

	/** @var array<string, object> */
	protected array $services = [];

	/** @var list<string> */
	private array $neonFiles;

	private string $cacheDirectory;

	private bool $debugMode = false; // as the real one detects it on the command line


	public function __construct(string $neonFile, ?string $cacheDirectory = null)
	{
		$this->neonFiles = [$neonFile];
		$this->cacheDirectory = $cacheDirectory ?? sys_get_temp_dir() . '/lupa-fixture-' . PHP_VERSION_ID;
	}


	public function setDebugMode(bool $value): self
	{
		$this->debugMode = $value;
		return $this;
	}


	public function addConfig(string $neonFile): self
	{
		$this->neonFiles[] = $neonFile;
		return $this;
	}


	public function loadContainer(): string
	{
		// like the real one, only the debug mode compiles a changed file again
		$loader = new ContainerLoader($this->getCacheDirectory() . '/nette.configurator', $this->debugMode);
		return $loader->load(function (Compiler $compiler): void {
			foreach ($this->neonFiles as $file) {
				$compiler->loadConfig($file);
			}

			$compiler->addExtension('connectOnInitialize', new ConnectOnInitializeExtension);
		}, [$this->neonFiles, $this->debugMode]);
	}


	protected function getCacheDirectory(): string
	{
		return $this->cacheDirectory;
	}


	public function createContainer(): void
	{
		throw new \LogicException('The inspector must create the container without initializing it.');
	}
}
