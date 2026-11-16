<?php declare(strict_types=1);

namespace Lupa\Worker;

use Lupa\Application;
use Lupa\InspectorException;
use Lupa\Project;


/**
 * An application that did not start. Tools that read only files get the directories of its last start,
 * otherwise the usual ones under the project root, and a warning that says so.
 */
final class UnbootedApplication implements Application
{
	private const Directories = ['appDir', 'logDir', 'tempDir'];

	/** @var array<string, string> */
	private array $parameters;

	private string $warning;


	/**
	 * @param array<string, string> $parameters
	 */
	public function __construct(array $parameters, string $warning)
	{
		$this->parameters = $parameters;
		$this->warning = $warning;
	}


	/**
	 * Keeps the directories of an application that started, for a later start that fails.
	 */
	public static function rememberDirectories(Application $application, string $file): void
	{
		$json = (string) json_encode(self::readDirectories($application->getInfo()['parameters'] ?? null), JSON_UNESCAPED_SLASHES);
		if (@file_get_contents($file) !== $json) { // @ the file is missing before the first start
			@file_put_contents($file, $json, LOCK_EX); // @ a file that cannot be written only loses the directories
		}
	}


	public static function fromLastStart(string $file, Project $project, string $reason): self
	{
		$parameters = self::readDirectories(json_decode((string) @file_get_contents($file), true)); // @ the application may never have started
		$listed = [];
		foreach ($parameters as $name => $directory) {
			$listed[] = "$name " . $project->relativize($directory);
		}

		return new self($parameters, rtrim($reason, '.') . '.' . ($listed
			? ' Only files were read, in the directories of its last start: ' . implode(', ', $listed) . '.'
			: ' Only files were read, in the usual directories log and temp under the project root;'
				. ' the application has not started under Lupa before.'));
	}


	/**
	 * @param mixed $parameters
	 * @return array<string, string>
	 */
	private static function readDirectories($parameters): array
	{
		$directories = [];
		foreach (self::Directories as $name) {
			$directory = is_array($parameters) ? $parameters[$name] ?? null : null;
			if (is_string($directory) && is_dir($directory)) {
				$directories[$name] = $directory;
			}
		}

		return $directories;
	}


	public function getInfo(): array
	{
		return ['parameters' => $this->parameters];
	}


	public function getWarning(): string
	{
		return $this->warning;
	}


	public function getDatabaseTargets(): array
	{
		return [];
	}


	public function initialize(): void
	{
	}


	public function getDatabaseConnection(string $service): object
	{
		throw new InspectorException('The application did not start.');
	}


	public function getServiceByType(string $type): ?object
	{
		return null;
	}
}
