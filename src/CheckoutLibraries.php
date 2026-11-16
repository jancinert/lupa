<?php declare(strict_types=1);

namespace Lupa;

use Composer\Autoload\ClassLoader;


/**
 * Lets a checkout of Lupa run next to an application that does not have it installed. The packages Lupa requires,
 * with theirs, come either all from the application or all from the vendor directory of the checkout, never some
 * from each, as versions resolved apart need not fit together. The checkout lends them only to an application
 * that has none of them, and never a package it has for its development, such as Nette, Latte or Dibi.
 */
final class CheckoutLibraries
{
	private const Instructions = "Update the dependencies of the checkout on the PHP of the application with 'composer update --no-dev',"
		. ' so that Composer picks versions it supports.';

	private static bool $registered = false;


	/**
	 * Called once the autoloader of the application is loaded.
	 */
	public static function register(string $vendor, string $applicationAutoload): void
	{
		if (self::$registered) {
			return;
		}

		$vendor = (string) realpath($vendor); // as the maps of Composer write it
		$checkout = self::readCheckout($vendor);
		$own = json_decode((string) file_get_contents(dirname($vendor) . '/composer.json'), true);
		$requirements = array_map(
			fn($key): string => strtolower((string) $key),
			array_keys(is_array($own) ? (array) ($own['require'] ?? []) : []),
		);
		[$packages, $neededBy] = self::collectRequired($checkout, $requirements);
		$classMap = (array) require "$vendor/composer/autoload_classmap.php";

		// what the application has, by the metadata of Composer, or by the classes of an application without it
		$application = ComposerMetadata::readInstalled($applicationAutoload, null, true); // bin/lupa loaded no other
		$present = [];
		foreach ($packages as $name => $package) {
			if ($application !== null
				? isset($application[$name])
				: self::isLoadable(self::findClasses($classMap, $package['directory']))) {
				$present[$name] = $name . (isset($neededBy[$name]) ? " (needed by $neededBy[$name])" : '');
			}
		}

		$missing = array_values(array_diff(array_intersect($requirements, array_keys($packages)), array_keys($present)));
		if (!$missing) {
			self::$registered = true;
			return; // the application has what Lupa requires, and its Composer what those require
		} elseif ($present) {
			throw new InspectorException(sprintf(
				"The application has %s but not %s, which Lupa needs, and Lupa does not mix its own versions with those of the application. Add the missing packages to the application, or install Lupa into it with 'composer require --dev jancinert/lupa', which picks versions that fit.",
				implode(', ', $present),
				implode(', ', $missing),
			));
		}

		self::checkPhpVersion($vendor);
		$packages += self::findExtensionProviders($packages, $checkout, $application ?? []);
		self::registerPackages($vendor, $packages, $classMap);
		self::$registered = true;
	}


	/**
	 * The packages Composer installed in the vendor directory of the checkout, by name.
	 * @return array<string, array{directory: string, require: list<string>, provide: list<string>}>
	 */
	private static function readCheckout(string $vendor): array
	{
		$packages = [];
		foreach (ComposerMetadata::readPackages("$vendor/composer/installed.json") ?? [] as $name => $package) {
			if (strncmp($package['path'], '../', 3) === 0) {
				$packages[$name] = ['directory' => $vendor . '/' . substr($package['path'], 3) . '/'] + $package;
			}
		}

		return $packages;
	}


	/**
	 * The packages of the requirements, with the packages they require, and for these which package needs them.
	 * @param array<string, array{directory: string, require: list<string>, provide: list<string>}> $installed
	 * @param list<string> $requirements
	 * @return array{array<string, array{directory: string, require: list<string>, provide: list<string>}>, array<string, string>}
	 */
	private static function collectRequired(array $installed, array $requirements): array
	{
		$required = $neededBy = [];
		$queue = array_fill_keys($requirements, null);
		while ($queue) {
			$parent = reset($queue);
			$name = (string) key($queue);
			unset($queue[$name]);
			if (isset($installed[$name]) && !isset($required[$name])) {
				$required[$name] = $installed[$name];
				if ($parent !== null) {
					$neededBy[$name] = $parent;
				}

				$queue += array_fill_keys($installed[$name]['require'], $name);
			}
		}

		return [$required, $neededBy];
	}


	/**
	 * Composer writes the lowest PHP version of the packages into platform_check.php, which older versions of it
	 * end by exit(), so it is read rather than run.
	 */
	private static function checkPhpVersion(string $vendor): void
	{
		$check = (string) @file_get_contents("$vendor/composer/platform_check.php"); // @ Composer may not write it
		if (preg_match('~PHP_VERSION_ID\s*>=\s*(\d+)~', $check, $m) && PHP_VERSION_ID < (int) $m[1]) {
			$required = sprintf('%d.%d.%d', intdiv((int) $m[1], 10000), intdiv((int) $m[1] % 10000, 100), (int) $m[1] % 100);
			throw new InspectorException("The Lupa checkout was installed for PHP $required or newer, this is PHP " . PHP_VERSION . '. ' . self::Instructions);
		}
	}


	/**
	 * The requirement of an extension the PHP does not have is met by the application, or else by a package
	 * of the checkout that provides it, such as a polyfill, which is then lent too, with what it requires.
	 * @param array<string, array{directory: string, require: list<string>, provide: list<string>}> $packages
	 * @param array<string, array{directory: string, require: list<string>, provide: list<string>}> $checkout
	 * @param array<string, true> $application
	 * @return array<string, array{directory: string, require: list<string>, provide: list<string>}>
	 */
	private static function findExtensionProviders(array $packages, array $checkout, array $application): array
	{
		$providers = [];
		$queue = $packages;
		while ($queue) {
			$package = reset($queue);
			$name = (string) key($queue);
			unset($queue[$name]);
			foreach ($package['require'] as $requirement) {
				if (
					strncmp($requirement, 'ext-', 4) !== 0
					|| extension_loaded(substr($requirement, 4))
					|| isset($application[$requirement])
				) {
					continue;
				}

				$provider = null;
				foreach ($checkout as $candidate => $installed) {
					if (in_array($requirement, $installed['provide'], true)) {
						$provider = $candidate;
						break;
					}
				}

				if ($provider === null) {
					throw new InspectorException(sprintf(
						'Package %s of the Lupa checkout needs the PHP extension %s. %s',
						$name,
						substr($requirement, 4),
						self::Instructions,
					));
				} elseif (!isset($packages[$provider]) && !isset($providers[$provider])) {
					[$lent] = self::collectRequired($checkout, [$provider]);
					$lent = array_diff_key($lent, $packages, $providers);
					$providers += $lent;
					$queue += $lent; // a polyfill may require an extension as well
				}
			}
		}

		return $providers;
	}


	/**
	 * A few classes of a package, the shortest names, which old versions are the most likely to have as well;
	 * only for an application without the metadata of Composer.
	 * @param array<mixed> $classMap
	 * @return list<string>
	 */
	private static function findClasses(array $classMap, string $directory): array
	{
		$classes = [];
		foreach ($classMap as $class => $file) {
			if (is_string($class) && is_string($file) && strncmp($file, $directory, strlen($directory)) === 0) {
				$classes[] = $class;
			}
		}

		usort($classes, fn(string $a, string $b): int => strlen($a) <=> strlen($b));
		return array_slice($classes, 0, 3);
	}


	/**
	 * @param list<string> $classes
	 */
	private static function isLoadable(array $classes): bool
	{
		foreach ($classes as $class) {
			if (class_exists($class) || interface_exists($class) || trait_exists($class)) {
				return true;
			}
		}

		return false;
	}


	/**
	 * Registers the class loader of Composer for the packages, after the autoloaders of the application,
	 * and includes the files they autoload.
	 * @param array<string, array{directory: string, require: list<string>, provide: list<string>}> $packages
	 * @param array<mixed> $classMap
	 */
	private static function registerPackages(string $vendor, array $packages, array $classMap): void
	{
		$isLent = function ($path) use ($packages): bool {
			foreach ($packages as $package) {
				if (is_string($path) && strncmp($path . '/', $package['directory'], strlen($package['directory'])) === 0) {
					return true;
				}
			}

			return false;
		};

		if (!class_exists(ClassLoader::class)) {
			require "$vendor/composer/ClassLoader.php";
		}

		// without the vendor directory, so that the InstalledVersions of the application do not list the checkout
		$loader = new ClassLoader;
		foreach (['autoload_psr4.php' => 'setPsr4', 'autoload_namespaces.php' => 'set'] as $map => $method) {
			foreach ((array) require "$vendor/composer/$map" as $prefix => $paths) {
				$paths = array_values(array_filter(array_filter((array) $paths, 'is_string'), $isLent));
				if ($paths) {
					$loader->$method((string) $prefix, $paths);
				}
			}
		}

		$own = [];
		foreach ($classMap as $class => $file) {
			if (is_string($class) && is_string($file) && $isLent($file)) {
				$own[$class] = $file;
			}
		}

		$loader->addClassMap($own);
		$loader->register(false); // after the autoloaders of the application

		$files = is_file("$vendor/composer/autoload_files.php") ? (array) require "$vendor/composer/autoload_files.php" : [];
		$included = (array) ($GLOBALS['__composer_autoload_files'] ?? []); // as Composer marks the files it has included
		foreach ($files as $id => $file) {
			if (is_string($file) && $isLent($file) && empty($included[$id])) {
				$included[$id] = true;
				$GLOBALS['__composer_autoload_files'] = $included;
				require $file;
			}
		}
	}
}
