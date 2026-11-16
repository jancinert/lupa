<?php declare(strict_types=1);

namespace Lupa;


/**
 * Where the inspected application lives: its root, its Composer autoloader and the inspector configuration.
 */
final class Project
{
	public string $root;
	public string $autoload;
	public string $configFile;


	public function __construct(string $root, string $autoload, string $configFile)
	{
		$this->root = $root;
		$this->autoload = $autoload;
		$this->configFile = $configFile;
	}


	/**
	 * @param array<string, string> $options  values of --project, --autoload and --config
	 * @param ?string $composerAutoload  autoloader path the Composer bin proxy provides
	 */
	public static function locate(array $options, ?string $composerAutoload, string $cwd): self
	{
		if (isset($options['project'])) {
			$root = $options['project'];
		} elseif ($composerAutoload !== null) {
			$root = dirname($composerAutoload, 2);
		} else {
			$root = self::findRoot($cwd);
		}

		$root = rtrim($root, '/\\');
		return new self(
			$root,
			$options['autoload'] ?? $composerAutoload ?? $root . '/vendor/autoload.php',
			$options['config'] ?? $root . '/lupa.neon',
		);
	}


	public function loadAutoloader(): void
	{
		if (!is_file($this->autoload)) {
			throw new InspectorException("Composer autoloader '$this->autoload' not found. Use --project or --autoload.");
		}

		require_once $this->autoload;
	}


	/**
	 * Path relative to the project root when the file lies inside it, with forward slashes.
	 */
	public function relativize(string $file): string
	{
		$root = str_replace('\\', '/', (string) (realpath($this->root) ?: $this->root)) . '/';
		$path = str_replace('\\', '/', (string) (realpath($file) ?: $file));
		return self::startsWith($path, $root) ? substr($path, strlen($root)) : $path;
	}


	/**
	 * Resolves a path given by the user against the project root and refuses anything outside it.
	 */
	public function resolveInside(string $path): string
	{
		$absolute = preg_match('#^([a-z]:)?[/\\\\]#i', $path) ? $path : $this->root . '/' . $path;
		$real = realpath($absolute);
		$root = realpath($this->root);
		if ($real === false || $root === false) {
			throw new InspectorException("Path '$path' not found.");
		} elseif ($real !== $root && !self::startsWith($real, rtrim($root, '/\\') . DIRECTORY_SEPARATOR)) {
			throw new InspectorException("Path '$path' is outside the project.");
		}

		return $real;
	}


	/**
	 * Paths differ only in letter case on Windows; elsewhere /app/Project is not inside /app/project.
	 */
	private static function startsWith(string $path, string $prefix): bool
	{
		return PHP_OS_FAMILY === 'Windows'
			? strncasecmp($path, $prefix, strlen($prefix)) === 0
			: strncmp($path, $prefix, strlen($prefix)) === 0;
	}


	/**
	 * @return array<string, string>  options that make another process find the same project
	 */
	public function toOptions(): array
	{
		return ['project' => $this->root, 'autoload' => $this->autoload, 'config' => $this->configFile];
	}


	private static function findRoot(string $dir): string
	{
		for ($current = $dir;; $current = $parent) {
			if (is_file($current . '/lupa.neon') || is_file($current . '/composer.json')) {
				return $current;
			}

			$parent = dirname($current);
			if ($parent === $current) {
				throw new InspectorException("No project found above '$dir'. Use --project.");
			}
		}
	}
}
