<?php declare(strict_types=1);

namespace Lupa;

use Composer\Autoload\ClassLoader;


/**
 * What Composer installed for the application, read from its metadata without running any of its code.
 */
final class ComposerMetadata
{
	/**
	 * Names of the packages installed for the application, with the names they provide or replace;
	 * null when the application has no metadata of Composer.
	 * @param ?string $autoload  the autoloader of the application, which need not be loaded
	 * @param ?string $root  the root of the application, whose composer.json may name another vendor directory
	 * @param bool $registered  also the vendor directories of the registered class loaders of Composer, wherever they
	 *    are, for a process in which only the autoloader of the application registers them, as bin/lupa is
	 * @return ?array<string, true>
	 */
	public static function readInstalled(?string $autoload, ?string $root = null, bool $registered = false): ?array
	{
		$directories = [];
		if ($registered && class_exists(ClassLoader::class, false) && method_exists(ClassLoader::class, 'getRegisteredLoaders')) {
			$directories = array_map('strval', array_keys(ClassLoader::getRegisteredLoaders()));
		}

		if ($autoload !== null) {
			$directories[] = dirname($autoload); // Composer 1 registers no list, and an autoloader not yet loaded neither
		}

		if ($root !== null) {
			$composer = is_file("$root/composer.json") ? json_decode((string) file_get_contents("$root/composer.json"), true) : null;
			$config = is_array($composer) && is_array($composer['config'] ?? null) ? $composer['config'] : [];
			$vendor = is_string($config['vendor-dir'] ?? null) ? $config['vendor-dir'] : 'vendor';
			$directories[] = preg_match('~^([a-z]:)?[/\\\]~i', $vendor) ? $vendor : "$root/$vendor"; // absolute or relative
		}

		$names = null;
		foreach (array_unique($directories) as $directory) {
			$packages = self::readPackages("$directory/composer/installed.json");
			if ($packages !== null) {
				$names ??= []; // even with no packages, Composer installed for the application
				foreach ($packages as $name => $package) {
					$names[$name] = true;
					$names += array_fill_keys($package['provide'], true);
				}
			}
		}

		return $names;
	}


	/**
	 * The packages of an installed.json of Composer 1 or 2, by name; null when there is none.
	 * @return ?array<string, array{path: string, require: list<string>, provide: list<string>}>
	 */
	public static function readPackages(string $file): ?array
	{
		$installed = is_file($file) ? json_decode((string) file_get_contents($file), true) : null;
		if (!is_array($installed)) {
			return null;
		}

		$packages = [];
		foreach ((array) ($installed['packages'] ?? $installed) as $package) {
			$name = is_array($package) ? $package['name'] ?? null : null;
			if (is_string($name)) {
				$path = $package['install-path'] ?? null;
				$packages[strtolower($name)] = [
					'path' => is_string($path) ? $path : "../$name", // Composer 1 writes no path
					'require' => array_map(fn($key): string => strtolower((string) $key), array_keys((array) ($package['require'] ?? []))),
					'provide' => array_map(fn($key): string => strtolower((string) $key), array_merge(
						array_keys((array) ($package['provide'] ?? [])),
						array_keys((array) ($package['replace'] ?? [])),
					)),
				];
			}
		}

		return $packages;
	}
}
