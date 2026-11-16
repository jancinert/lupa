<?php declare(strict_types=1);

namespace Lupa;


/**
 * Settings of the inspector section of lupa.neon, with paths resolved against the directory of the file.
 */
final class Config
{
	/** PHP file returning the DI container or the Configurator of the application. */
	public string $bootstrap;

	public bool $enabled;

	/** Seconds a single tool call may take. */
	public int $timeout;

	/** JSON Lines file every tool call is written to. */
	public string $auditLog;

	/** @var list<string> */
	public array $disabledTools;

	/** @var list<string> NEON files added to the application's Configurator, e.g. a read-only database account */
	public array $applicationConfigs;

	/** Text added to the instructions the AI client gives the model on connect. */
	public string $instructions;

	/** 'nette', or 'none' for an application described by an array. */
	public string $framework;


	/**
	 * @param list<string> $disabledTools
	 * @param list<string> $applicationConfigs
	 */
	public function __construct(
		string $bootstrap,
		bool $enabled,
		int $timeout,
		string $auditLog,
		array $disabledTools,
		array $applicationConfigs = [],
		string $instructions = '',
		string $framework = 'nette'
	) {
		$this->bootstrap = $bootstrap;
		$this->enabled = $enabled;
		$this->timeout = $timeout;
		$this->auditLog = $auditLog;
		$this->disabledTools = $disabledTools;
		$this->applicationConfigs = $applicationConfigs;
		$this->instructions = $instructions;
		$this->framework = $framework;
	}
}
