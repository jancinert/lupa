<?php declare(strict_types=1);

namespace Lupa;

use Nette\DI\Compiler;
use Nette\DI\Container;
use Nette\DI\ContainerLoader;
use Nette\DI\Extensions\ExtensionsExtension;
use Nette\InvalidArgumentException;
use Nette\InvalidStateException;
use Nette\Neon\Exception;


final class Inspector
{
	public const Version = '0.1.0';

	public const Name = 'lupa';

	/** Environment variable that disables the inspector regardless of the configuration. */
	public const KillSwitch = 'LUPA_DISABLED';

	/** @var array<string, class-string<Tools\Toolkit>> configuration section => toolkit without settings */
	private const SimpleToolkits = [
		'application' => Toolkits\ApplicationToolkit::class,
		'di' => Nette\Toolkits\ContainerToolkit::class,
		'presenters' => Nette\Toolkits\PresenterToolkit::class,
		'tracy' => Toolkits\TracyToolkit::class,
		'latte' => Nette\Toolkits\LatteToolkit::class,
		'router' => Nette\Toolkits\RouterToolkit::class,
		'forms' => Nette\Toolkits\FormToolkit::class,
		'acl' => Nette\Toolkits\AclToolkit::class,
		'cache' => Nette\Toolkits\CacheToolkit::class,
		'console' => Nette\Toolkits\ConsoleToolkit::class,
		'render' => Nette\Toolkits\RenderToolkit::class,
	];


	public static function isDisabledByEnvironment(): bool
	{
		$value = getenv(self::KillSwitch);
		return $value !== false && $value !== '' && $value !== '0';
	}


	/**
	 * Builds the inspector's own DI container from lupa.neon. Each toolkit is a compiler extension
	 * with its configuration section; a project adds its own as services.
	 */
	public static function createContainer(string $configFile): Container
	{
		if (!is_file($configFile)) {
			throw new ConfigException("Configuration file '$configFile' not found. Create it with 'lupa init'.");
		}

		$configFile = (string) realpath($configFile);
		$loader = new ContainerLoader(self::getTempDirectory($configFile), true);
		try {
			$class = $loader->load(function (Compiler $compiler) use ($configFile): ?string {
				$compiler->addExtension('extensions', new ExtensionsExtension);
				$compiler->addExtension('inspector', new DI\InspectorExtension(dirname($configFile)));
				$compiler->addExtension('guard', new DI\GuardExtension);
				$compiler->addExtension('masking', new DI\MaskingExtension);
				$compiler->addExtension('database', new DI\DatabaseExtension);
				$compiler->addExtension('migrations', new DI\MigrationsExtension);
				foreach (self::SimpleToolkits as $name => $toolkit) {
					$framework = strncmp($toolkit, Nette\NetteApplication::class, 11) === 0 ? 'nette' : null; // by the namespace
					$compiler->addExtension($name, new DI\ToolkitExtension($toolkit, $framework));
				}

				$compiler->loadConfig($configFile);
				return null;
			}, [$configFile, self::Version, PHP_VERSION_ID, self::getSourceFingerprint()]);
		} catch (Exception|InvalidStateException|InvalidArgumentException $e) {
			throw new ConfigException("Configuration file '$configFile': " . $e->getMessage(), 0, $e);
		}

		return new $class;
	}


	/**
	 * Directory of the inspector's own files for the configuration file, created when missing.
	 */
	public static function getTempDirectory(string $configFile): string
	{
		// The compiled container is code that gets loaded, so on a shared machine it lives in a directory of the user.
		$user = function_exists('posix_geteuid') ? posix_geteuid() : null;
		$tempDir = sys_get_temp_dir()
			. '/lupa'
			. ($user === null ? '' : "-$user")
			. '/'
			. substr(md5((string) realpath($configFile)), 0, 12);
		// The server and a worker may start at once. Neither older nette/utils nor a recursive mkdir() copes with
		// another process creating a directory meanwhile, so each level is created separately.
		foreach ([dirname($tempDir), $tempDir] as $dir) {
			if (!is_dir($dir) && !@mkdir($dir, 0700) && !is_dir($dir)) { // @ the error is reported below
				throw new InspectorException("Cannot create directory '$dir'.");
			} elseif ($user !== null && fileowner($dir) !== $user) {
				throw new InspectorException("Directory '$dir' belongs to another user.");
			}
		}

		return $tempDir;
	}


	/**
	 * Changes whenever a source file of the inspector changes, so that a development checkout recompiles.
	 */
	private static function getSourceFingerprint(): string
	{
		$times = [];
		$files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(__DIR__, \FilesystemIterator::SKIP_DOTS));
		foreach ($files as $file) {
			if ($file instanceof \SplFileInfo) {
				$times[] = $file->getMTime();
			}
		}

		return md5(implode(',', $times));
	}
}
