<?php declare(strict_types=1);

namespace Lupa\Tools;

use Lupa\Application;
use Lupa\Guard\DatabaseTarget;
use Lupa\Project;


/**
 * The booted application as the worker passes it to a toolkit.
 */
final class AppContext
{
	public Application $application;
	public Project $project;
	public ToolCatalog $catalog;

	/** @var list<DatabaseTarget> connections the environment guard has checked */
	public array $databases;


	/**
	 * @param list<DatabaseTarget> $databases
	 */
	public function __construct(Application $application, Project $project, ToolCatalog $catalog, array $databases)
	{
		$this->application = $application;
		$this->project = $project;
		$this->catalog = $catalog;
		$this->databases = $databases;
	}


	/**
	 * Directory the application names by a parameter such as logDir or tempDir, otherwise the default under the project root.
	 */
	public function getDirectory(string $parameter, string $default): string
	{
		$parameters = $this->application->getInfo()['parameters'] ?? null;
		$dir = is_array($parameters) ? $parameters[$parameter] ?? null : null;
		return is_string($dir) && is_dir($dir)
			? rtrim($dir, '/\\')
			: $this->project->root . DIRECTORY_SEPARATOR . $default;
	}
}
