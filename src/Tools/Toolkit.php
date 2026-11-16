<?php declare(strict_types=1);

namespace Lupa\Tools;


/**
 * A group of tools for one area of the application. It is created without the application,
 * so that the server can list the tools; the application is passed only to call().
 */
interface Toolkit
{
	/**
	 * @return list<Tool>
	 */
	function getTools(): array;

	/**
	 * Runs the tool inside the worker process. Throws InspectorException for an error the user should see.
	 * @param array<mixed> $arguments  arguments already validated against the input schema
	 */
	function call(string $tool, array $arguments, AppContext $context): ToolResult;
}
