<?php declare(strict_types=1);

namespace Lupa\Worker;


/**
 * Runs one tool call against the application.
 */
interface Executor
{
	/**
	 * @param array<mixed> $arguments
	 */
	function execute(string $tool, array $arguments): WorkerResponse;
}
