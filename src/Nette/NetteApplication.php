<?php declare(strict_types=1);

namespace Lupa\Nette;

use Lupa\Application;
use Lupa\InspectorException;
use Lupa\Scalar;
use Nette\DI\Container;
use Tracy\Debugger;


/**
 * Nette adapter: an application represented by its DI container.
 */
final class NetteApplication implements Application
{
	private const InfoParameters = ['appDir', 'wwwDir', 'vendorDir', 'tempDir', 'logDir', 'debugMode', 'productionMode', 'consoleMode'];

	private Container $container;
	private bool $initialized;
	private ?string $warning;


	public function __construct(Container $container, bool $initialized = true, ?string $warning = null)
	{
		$this->container = $container;
		$this->initialized = $initialized;
		$this->warning = $warning;
	}


	/**
	 * Runs the bootstrap file, which returns a Configurator (preferred) or an already created DI container.
	 * From a Configurator the container is created without initializing it, because initialization
	 * may create services that connect, e.g. a Tracy panel of the database; initialize() does it after the guard.
	 * @param list<string> $configs  NEON files added to the Configurator after the application's own
	 */
	public static function boot(string $bootstrapFile, array $configs = [], bool $requireConfigurator = false): self
	{
		if (!is_file($bootstrapFile)) {
			throw new InspectorException("Bootstrap file '$bootstrapFile' not found.");
		}

		$result = (static fn() => require $bootstrapFile)();
		if ($result instanceof Container) {
			if ($requireConfigurator) {
				throw new InspectorException('The render tool needs a bootstrap file returning the Configurator before initialization.');
			} elseif ($configs) {
				throw new InspectorException("Configuration 'inspector.applicationConfig' needs a bootstrap file returning the Configurator.");
			}

			return new self($result);

		} elseif (is_object($result) && method_exists($result, 'loadContainer') && method_exists($result, 'setDebugMode')) {
			// on the command line the Configurator detects the production mode, whose container is never rebuilt,
			// so a change of the configuration would stay unseen; a development machine runs in the debug mode
			$result->setDebugMode(true);
			foreach ($configs as $config) {
				if (!is_file($config)) {
					throw new InspectorException("Application configuration '$config' not found.");
				}

				$result->addConfig($config);
			}

			[$container, $warning] = self::createContainer($result);
			return new self($container, false, $warning);
		}

		throw new InspectorException("Bootstrap file '$bootstrapFile' must return a Configurator or " . Container::class . '.');
	}


	/**
	 * Does what Configurator::createContainer() does, except the initialization (same in nette/bootstrap 2.4 to 3.2).
	 * When the configuration does not compile, the container of the last successful build is used and a warning says so.
	 * @return array{Container, ?string}
	 */
	private static function createContainer(object $configurator): array
	{
		$warning = null;
		try {
			$class = $configurator->loadContainer();
		} catch (\Throwable $e) {
			$file = self::findLastContainer($configurator);
			if ($file === null) {
				throw $e;
			}

			// newer nette/di rebuilds a container in the debug mode under a new class and the file returns its name
			$returned = require_once $file;
			$class = is_string($returned) ? $returned : basename($file, '.php');
			$warning = sprintf(
				'The configuration of the application does not compile (%s: %s). The results come from the container'
				. ' of the last successful build (%s); fix the configuration to refresh them.',
				get_class($e),
				$e->getMessage(),
				date('Y-m-d H:i:s', (int) filemtime($file)),
			);
		}

		[$parameters, $services] = (fn(): array => [$this->dynamicParameters ?? [], $this->services])->call($configurator);

		$container = is_string($class) && class_exists($class) ? new $class($parameters) : null;
		if (!$container instanceof Container) {
			throw new InspectorException('The Configurator created ' . Scalar::toString($class) . ', which is no ' . Container::class . '.');
		}

		foreach ((array) $services as $name => $service) {
			if (is_object($service)) {
				$container->addService((string) $name, $service);
			}
		}

		return [$container, $warning];
	}


	/**
	 * The newest container the Configurator has compiled before, or null.
	 */
	private static function findLastContainer(object $configurator): ?string
	{
		if (!method_exists($configurator, 'getCacheDirectory')) {
			return null;
		}

		$directory = Scalar::toString((fn() => $this->getCacheDirectory())->call($configurator));
		$newest = null;
		foreach (glob($directory . '/[Nn]ette.[Cc]onfigurator/Container_*.php') ?: [] as $file) { // capitals in nette/bootstrap 2.4
			if ($newest === null || filemtime($file) > filemtime($newest)) {
				$newest = $file;
			}
		}

		return $newest;
	}


	public function getContainer(): Container
	{
		return $this->container;
	}


	/**
	 * The DI container of the application a Nette toolkit works with.
	 */
	public static function getContainerOf(Application $application): Container
	{
		if (!$application instanceof self) {
			throw new InspectorException('This tool needs a Nette application.');
		}

		return $application->container;
	}


	public function getInfo(): array
	{
		$parameters = array_intersect_key($this->container->getParameters(), array_flip(self::InfoParameters));
		// Configurator::enableTracy() sets the directory of the Tracy log, but no parameter
		if (!isset($parameters['logDir']) && class_exists(Debugger::class, false) && is_string(Debugger::$logDirectory)) {
			$parameters['logDir'] = Debugger::$logDirectory;
		}

		return [
			'framework' => 'nette',
			'container' => get_class($this->container),
			'parameters' => $parameters,
		];
	}


	public function getWarning(): ?string
	{
		return $this->warning;
	}


	public function getDatabaseTargets(): array
	{
		return (new DatabaseTargetFinder)->find($this->container);
	}


	public function getDatabaseConnection(string $service): object
	{
		return $this->container->getService($service);
	}


	public function initialize(): void
	{
		if (!$this->initialized) {
			$this->initialized = true;
			$this->container->initialize();
		}
	}
}
